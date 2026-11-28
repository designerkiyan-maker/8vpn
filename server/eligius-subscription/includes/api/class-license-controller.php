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
use Eligius_Sub\Core\Class_Audit_Log_Repository;
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

        // GET+POST /licenses/usage — ONE registration for both methods (WP
        // overwrites same-URI registrations, so a second register_rest_route
        // call would silently drop the first method — learned the hard way).
        register_rest_route(self::NAMESPACE_V1, '/licenses/usage', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'route_usage_dispatch'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // POST /licenses/activate-free (P9.2 — free plan, price 0, no gateway).
        register_rest_route(self::NAMESPACE_V1, '/licenses/activate-free', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_activate_free'],
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
        // Phase 5b: status works for REGISTERED devices (active OR pending) —
        // a pending device must learn it is in the transfer flow, not fall
        // into the trial/purchase path. Blocked devices are still rejected.
        $device = Class_Auth_Integration::instance()->resolve_registered_device($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        // Read-only (Phase 6 final decision): may answer while the limiter is
        // degraded — no side effect here.
        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash), false);
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

        // Read-only (Phase 6 final decision): may answer while degraded.
        $exceeded = self::rate_limit_or_fail(self::validation_buckets($device->device_hash), false);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $result = Class_License_Manager::instance()->validate(get_current_user_id(), $device);

        if (!empty($result['valid'])) {
            return self::ok($result);
        }

        // Invalid gate response (doc 03 §5.3 — 403 with typed code).
        // P9.2: VOLUME_EXHAUSTED passes through so the client can tell a
        // consumed quota apart from expiry/device issues. (PHP 7.4-safe —
        // no match expression; the Plugin CI lints across 7.4+.)
        static $code_map = [
            'NO_ACTIVE_LICENSE' => 'SUBSCRIPTION_EXPIRED',
            'VOLUME_EXHAUSTED'  => 'VOLUME_EXHAUSTED',
        ];
        $error  = (string) ($result['error'] ?? '');
        $code   = isset($code_map[$error]) ? $code_map[$error] : 'DEVICE_NOT_BOUND';
        $message = 'VOLUME_EXHAUSTED' === $code
            ? 'Volume quota is exhausted for this subscription.'
            : 'License is not valid for this device.';
        $detail = isset($result['expires_at']) ? null : null;
        return self::fail($code, $message, 403, $detail);
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

    /** GET+POST share ONE registration — dispatch on the HTTP method. */
    public function route_usage_dispatch(\WP_REST_Request $request) {
        return 'GET' === $request->get_method()
            ? $this->route_usage_get($request)
            : $this->route_usage($request);
    }

    /**
     * GET /licenses/usage — P9.2.1 per-subscription-period stats for the
     * Account usage card. Read-only: answers 200 while the limiter is
     * degraded (FC-RO discipline, same as /licenses/status).
     */
    public function route_usage_get(\WP_REST_Request $request) {
        $license = \Eligius_Sub\Core\Class_License_Repository::get_current(get_current_user_id());
        if (!$license) {
            return self::ok($this->usage_payload(null, 0, 0, 0, 0, 0, false));
        }
        return self::ok($this->usage_payload(
            $license,
            (int) ($license->used_bytes ?? 0),
            (int) ($license->used_rx_bytes ?? 0),
            (int) ($license->used_tx_bytes ?? 0),
            (int) ($license->used_duration_ms ?? 0),
            (int) ($license->used_sessions ?? 0),
            true
        ));
    }

    /** P9.2: usage payload builder (shared by GET + POST). $used is passed
     *  EXPLICITLY — the caller's license row may be stale after an increment. */
    private function usage_payload($license, $used, $rx, $tx, $durationMs, $sessions, $attributed) {
        $used = max(0, (int) $used);
        $vol = $license
            ? \Eligius_Sub\License\Class_License_Manager::instance()->volume_fields_for($license->plan_key, $used)
            : ['volume_total_bytes' => null, 'volume_percent' => null, 'volume_exhausted' => false];
        return [
            'attributed'         => (bool) $attributed,
            'license_id'         => $license ? (int) $license->id : null,
            'used_bytes'         => $used,
            'used_rx_bytes'      => max(0, (int) $rx),
            'used_tx_bytes'      => max(0, (int) $tx),
            'used_duration_ms'   => max(0, (int) $durationMs),
            'used_sessions'      => max(0, (int) $sessions),
            'volume_total_bytes' => $vol['volume_total_bytes'],
            'volume_percent'     => $vol['volume_percent'],
            'volume_exhausted'   => (bool) $vol['volume_exhausted'],
        ];
    }

    /**
     * POST /licenses/usage — P9.2 monotonic quota accounting (client-trusted
     * metering, doc limitation; swap to 3x-ui later). One atomic increment on
     * the account's current license; the volume-exhausted crossing is audited
     * once. No current license → 200 attributed:false (session may have
     * outlived its subscription — never an app-facing error).
     */
    public function route_usage(\WP_REST_Request $request) {
        $exceeded = self::rate_limit_or_fail([
            ['usage_user', (string) get_current_user_id(), 60, 3600],
            ['usage_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 300, 3600],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $rx = (int) $request->get_param('rx_bytes');
        $tx = (int) $request->get_param('tx_bytes');
        $durationMs = (int) $request->get_param('duration_ms');
        $sessions = $request->get_param('sessions') !== null ? (int) $request->get_param('sessions') : 1;

        $license = \Eligius_Sub\Core\Class_License_Repository::get_current(get_current_user_id());
        if (!$license) {
            return self::ok($this->usage_payload(null, 0, 0, 0, 0, 0, false));
        }

        $before = max(0, (int) ($license->used_bytes ?? 0));
        $row = \Eligius_Sub\Core\Class_License_Repository::increment_usage(
            (int) $license->id,
            $rx,
            $tx,
            $durationMs,
            $sessions
        );
        if (null === $row) {
            return self::fail('eligius_db_error', 'Usage accounting failed.', 500);
        }
        $used = (int) $row['used_bytes'];

        // Quota totals from the plan row (same logic as build_response).
        $vol = \Eligius_Sub\License\Class_License_Manager::instance()->volume_fields_for($license->plan_key, $used);
        $exhausted = (bool) $vol['volume_exhausted'];

        // Crossing audit — fire once per license when used crosses the quota.
        if ($exhausted && $before < (int) $vol['volume_total_bytes']) {
            \Eligius_Sub\Core\Class_Audit_Log_Repository::insert_license_event([
                'license_id' => (int) $license->id,
                'user_id'    => get_current_user_id(),
                'event_type' => 'volume.exhausted',
                'details'    => [
                    'used_bytes'  => $used,
                    'total_bytes' => (int) $vol['volume_total_bytes'],
                ],
            ]);
            \Eligius_Sub\Core\Class_Audit_Log_Repository::insert_audit([
                'user_id'    => get_current_user_id(),
                'license_id' => (int) $license->id,
                'event_type' => 'volume.exhausted',
                'severity'   => 'WARNING',
                'details'    => ['used_bytes' => $used, 'total_bytes' => (int) $vol['volume_total_bytes']],
            ]);
        }

        return self::ok($this->usage_payload(
            $license,
            (int) $row['used_bytes'],
            (int) $row['used_rx_bytes'],
            (int) $row['used_tx_bytes'],
            (int) $row['used_duration_ms'],
            (int) $row['used_sessions'],
            true
        ));
    }

    /**
     * POST /licenses/activate-free — P9.2 free-plan activation (price 0, no
     * gateway; once per account, no active subscription — owner decision).
     */
    public function route_activate_free(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $exceeded = self::rate_limit_or_fail([
            ['free_activate_user', (string) get_current_user_id(), 5, 3600],
            ['free_activate_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 10, 3600],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $plan_key = sanitize_text_field((string) $request->get_param('plan_key'));
        if ('' === $plan_key) {
            return self::fail('eligius_plan_key_required', 'Plan key is required.', 422);
        }

        $result = \Eligius_Sub\License\Class_License_Manager::instance()->activate_free_license(
            get_current_user_id(),
            (string) $device->device_hash,
            $plan_key
        );
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        Class_Audit_Log_Repository::insert_audit([
            'user_id'    => get_current_user_id(),
            'license_id' => $result['license_id'],
            'device_id'  => (int) $device->id,
            'event_type' => 'free.activated',
            'details'    => ['plan_key' => $plan_key],
        ]);

        return self::ok($result, 201);
    }

    /** GET /plans — public catalog (docs 03 §4.1) + P9.1 plan_type/volume_gb
     *  برای کارت‌های خرید اپ (حجمی / زمان‌دار-نامحدود / رایگان — P9.1.3). */
    public function route_plans() {
        $manager = \Eligius_Sub\License\Class_License_Manager::instance();
        $rows = $manager->list_plans();
        $plans = [];
        foreach ($rows as $r) {
            $type = (string) ($r->plan_type ?? 'timed');
            if (!in_array($type, ['timed', 'volume', 'free'], true)) {
                $type = 'timed';
            }
            $plans[] = [
                'key'              => (string) $r->plan_key,
                'label'            => (string) $r->label,
                'plan_type'        => $type,
                'volume_gb'        => ('timed' === $type || null === $r->volume_gb) ? null : (int) $r->volume_gb,
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
