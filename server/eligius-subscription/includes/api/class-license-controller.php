<?php
/**
 * License REST endpoints (doc 03 §7 + Roadmap P1 "API Endpoints" table).
 *
 * P1 routes (namespace eligius/v1):
 *   GET  /licenses/status             — status + offline entitlement (doc 04 §5.1)
 *   GET  /licenses/validate           — fast boolean for the VPN gate
 *   GET  /licenses/offline-entitlement — signed 72h blob (doc 04 §4.1/§4.2)
 *   GET  /licenses/revocation-list    — CRL, 5-minute freshness (doc 04 §8.2)
 *   POST /licenses/trial              — one trial per account (doc 05 §3.2)
 *   POST /licenses/renew              — registered; payment gateways arrive
 *                                       in Phase 4 → typed GATEWAY_UNAVAILABLE
 */

namespace Eligius_Sub\Api;

defined('ABSPATH') || exit;

use Eligius_Sub\Auth\Class_Auth_Integration;
use Eligius_Sub\Core\Class_UTC_Time;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\License\Class_License_Issuer;
use Eligius_Sub\License\Class_License_Manager;
use Eligius_Sub\License\Class_Trial_Manager;
use Eligius_Sub\Security\Class_Rate_Limiter;

class Class_License_Controller extends Class_Api_Controller {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        // GET /licenses/status (doc 03 §7.1, doc 04 §5.1).
        register_rest_route(self::NAMESPACE_V1, '/licenses/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_status'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // GET /licenses/validate (Roadmap P1 — fast boolean for the VPN gate).
        register_rest_route(self::NAMESPACE_V1, '/licenses/validate', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_validate'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // GET /licenses/offline-entitlement (doc 03 §5.4 semantics).
        register_rest_route(self::NAMESPACE_V1, '/licenses/offline-entitlement', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_offline_entitlement'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // GET /licenses/revocation-list (doc 04 §8.2).
        register_rest_route(self::NAMESPACE_V1, '/licenses/revocation-list', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_revocation_list'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // POST /licenses/trial (doc 05 §3.2, Roadmap P1 AC).
        register_rest_route(self::NAMESPACE_V1, '/licenses/trial', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_trial'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // GET /plans (docs 03 §4.1 — public catalog; used by the P3/P4 UI).
        register_rest_route(self::NAMESPACE_V1, '/plans', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_plans'],
            'permission_callback' => '__return_true',
        ]);

        // POST /licenses/renew (Roadmap P1 route list; payment flow is P4).
        register_rest_route(self::NAMESPACE_V1, '/licenses/renew', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_renew'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);
    }

    /** Validation-category limits: 100/min/device (doc 03 §9). */
    private static function validation_buckets($device_hash) {
        return [
            ['validation_device', (string) $device_hash, 100, 60],
        ];
    }

    public function route_status(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash));
        if (null !== $exceeded) {
            return $exceeded;
        }

        $status = Class_License_Manager::instance()->get_status(get_current_user_id(), $device);
        return self::ok($status);
    }

    public function route_validate(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash));
        if (null !== $exceeded) {
            return $exceeded;
        }

        $result = Class_License_Manager::instance()->validate(get_current_user_id(), $device);

        if (!empty($result['valid'])) {
            return self::ok($result);
        }

        // Invalid gate response (doc 03 §5.3 — 403 with typed code).
        $code   = ('NO_ACTIVE_LICENSE' === ($result['error'] ?? '')) ? 'SUBSCRIPTION_EXPIRED' : 'DEVICE_NOT_BOUND';
        $detail = isset($result['expires_at']) ? null : null;
        return self::fail($code, 'License is not valid for this device.', 403, $detail);
    }

    public function route_offline_entitlement(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash));
        if (null !== $exceeded) {
            return $exceeded;
        }

        $license = Class_License_Repository::get_current_for_device(get_current_user_id(), (int) $device->id)
            ?: Class_License_Repository::get_active_trial(get_current_user_id());

        if (!$license) {
            return self::fail('NOT_FOUND', 'No active license for this device.', 404);
        }

        $entitlement = Class_License_Issuer::instance()->issue_offline_entitlement(
            (int) $license->id,
            (int) $device->id
        );
        if (is_wp_error($entitlement)) {
            return self::fail_wp_error($entitlement);
        }

        return self::ok($entitlement);
    }

    public function route_revocation_list(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash));
        if (null !== $exceeded) {
            return $exceeded;
        }

        // Server-side 5-minute cache (doc 04 §8.2 — device caches the same TTL).
        $cache_key = 'eligius_sub_crl';
        $cached = get_transient($cache_key);
        if (false === $cached || !is_array($cached)) {
            $ids = [];
            foreach (Class_License_Repository::revoked_ids() as $id) {
                $ids[] = (int) $id;
            }
            $cached = ['revoked_license_ids' => $ids, 'computed_at' => Class_UTC_Time::unix_utc()];
            set_transient($cache_key, $cached, 5 * MINUTE_IN_SECONDS);
        }

        $now = Class_UTC_Time::unix_utc();
        return self::ok([
            'revoked_license_ids' => $cached['revoked_license_ids'],
            'updated_at'          => gmdate('c', $cached['computed_at']),
            'next_update'         => gmdate('c', $cached['computed_at'] + 300),
            'now'                 => gmdate('c', $now),
        ]);
    }

    public function route_trial(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        // Trial limits (doc 05 §3.1): 5/hour/IP + 3/day/user.
        $exceeded = self::rate_limit_or_fail([
            ['trial_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 5, 3600],
            ['trial_user', (string) get_current_user_id(), 3, 86400],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $result = Class_Trial_Manager::instance()->grant(
            get_current_user_id(),
            (string) $device->device_hash
        );
        if (is_wp_error($result)) {
            // TRIAL_ALREADY_USED → 409 CONFLICT envelope (doc 03 §1.3).
            return self::fail_wp_error($result);
        }

        Class_Audit_Log_Repository::insert_audit([
            'user_id'    => get_current_user_id(),
            'license_id' => $result['license_id'],
            'device_id'  => (int) $device->id,
            'event_type' => 'trial.granted',
            'details'    => ['plan_key' => 'trial'],
        ]);

        return self::ok($result, 201);
    }

    /** GET /plans — public catalog (docs 03 §4.1). */
    public function route_plans() {
        $manager = \Eligius_Sub\License\Class_License_Manager::instance();
        $rows = $manager->list_plans();
        $plans = [];
        foreach ($rows as $r) {
            $plans[] = [
                'key'              => (string) $r->plan_key,
                'label'            => (string) $r->label,
                'duration_days'    => (int) $r->duration_days,
                'price_irt'        => (int) $r->price_irt,
                'discount_percent' => (int) $r->discount_percent,
                'is_trial'         => (bool) $r->is_trial,
            ];
        }
        return self::ok(['plans' => $plans]);
    }

    public function route_renew(\WP_REST_Request $request) {
        // Route registered per the Roadmap P1 endpoint table; the actual
        // renewal flow requires the payment-gateway adapters (Phase 4).
        // Answer with the honest typed error instead of a fake success.
        return self::fail(
            'GATEWAY_UNAVAILABLE',
            'Payment gateways are introduced in Phase 4; license renewal is not operational yet.',
            503
        );
    }
}
