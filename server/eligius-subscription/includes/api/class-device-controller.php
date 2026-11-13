<?php
/**
 * Device REST endpoints (doc 03 §3, Roadmap P1 "API Endpoints" table).
 *
 * P1 routes (namespace eligius/v1):
 *   POST /devices/register          — bind_device transaction (doc 02 §2.8.3)
 *   POST /devices/challenge         — single-use challenge (doc 03 §3.2)
 *   POST /devices/verify            — signature verification (doc 03 §3.3)
 *   GET  /devices                   — device list (doc 03 §3.4)
 *   POST /devices/transfer/start    — OTP initiation (doc 05 §6)
 *   POST /devices/transfer/verify   — atomic binding swap (doc 02 §3.2)
 */

namespace Eligius_Sub\Api;

defined('ABSPATH') || exit;

use Eligius_Sub\Auth\Class_Auth_Integration;
use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_UTC_Time;
use Eligius_Sub\License\Class_Device_Transfer;
use Eligius_Sub\Auth\Class_Device_Auth;
use Eligius_Sub\Security\Class_Rate_Limiter;

class Class_Device_Controller extends Class_Api_Controller {

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
        register_rest_route(self::NAMESPACE_V1, '/devices/register', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_register'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/devices/challenge', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_challenge'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/devices/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_verify'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/devices', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_list'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/devices/transfer/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_transfer_start'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/devices/transfer/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_transfer_verify'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);
    }

    public function route_register(\WP_REST_Request $request) {
        global $wpdb;

        $auth = Class_Auth_Integration::instance();
        $user_check = $auth->permission_user($request);
        if (is_wp_error($user_check)) {
            return self::fail_wp_error($user_check);
        }

        // 10/hour/user (doc 03 §9 — Device registration).
        $exceeded = self::rate_limit_or_fail([
            ['device_register_user', (string) get_current_user_id(), 10, 3600],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $device_hash = $auth->get_device_hash($request);
        $body_hash   = Class_Device_Repository::normalize_hash($request->get_param('fingerprint'));
        $public_key  = (string) $request->get_param('public_key');
        $label       = (string) $request->get_param('label');

        if (null === $device_hash) {
            return self::fail('VALIDATION_ERROR', 'X-Device-Hash must be 64 hex chars.', 400);
        }
        if (null !== $body_hash && !hash_equals($device_hash, $body_hash)) {
            return self::fail('VALIDATION_ERROR', 'fingerprint does not match X-Device-Hash.', 400);
        }
        if (false === strpos($public_key, '-----BEGIN')) {
            return self::fail('VALIDATION_ERROR', 'public_key must be a PEM block.', 400);
        }

        $user_id = get_current_user_id();

        // ---- Single connection, single transaction (doc 02 §2.8.3) --------
        $wpdb->query('START TRANSACTION');

        // Step 1: THE LOCK — the account's wp_users row.
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            (int) $user_id
        ));
        if (null === $locked || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', 'Could not lock the account row.', 500);
        }

        // Step 2: exact device already registered → CONFLICT (doc 03 §3.1).
        $existing = Class_Device_Repository::find_by_hash($user_id, $device_hash);
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', $wpdb->last_error, 500);
        }
        if ($existing) {
            $wpdb->query('COMMIT');
            return self::fail(
                'CONFLICT',
                'This exact device is already registered.',
                409,
                ['device_id' => (int) $existing->id, 'is_active' => (bool) $existing->is_active]
            );
        }

        // Step 2b: one-active-device under the lock (CD-9) — never auto-replace,
        // the OTP transfer is the only path (doc 02 §2.8.3).
        $active_id = Class_Device_Repository::find_active_id($user_id);
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', $wpdb->last_error, 500);
        }
        if ($active_id > 0) {
            $wpdb->query('COMMIT');
            return self::fail(
                'DEVICE_LIMIT_REACHED',
                'This account already has an active device. Transfer it first (OTP).',
                409,
                ['active_device_id' => $active_id]
            );
        }

        // Step 3: insert the new device (explicit result check).
        $device_id = Class_Device_Repository::create($user_id, $device_hash, $public_key, $label, 1, 'app');
        if (0 === $device_id || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', $wpdb->last_error ?: 'Device insert failed.', 500);
        }

        // Step 4: bind ACTIVE/TRIAL licenses to this device (0 rows is valid).
        $bound = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_licenses
              SET device_id = %d, updated_at = %s
              WHERE user_id = %d AND status IN ('ACTIVE','TRIAL')",
            (int) $device_id,
            Class_UTC_Time::mysql_utc(),
            (int) $user_id
        ));
        if (false === $bound || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', $wpdb->last_error ?: 'License bind failed.', 500);
        }

        // Step 5: audit row — an unaudited bind must not commit (doc 02 §2.8.3).
        // license_events.license_id carries a FK, so the doc's warning applies:
        // re-read the bound license inside the transaction and store its REAL id.
        // A first registration with no license yet goes to audit_logs instead
        // (its license_id column is nullable).
        $license_events_ok = false;
        if ((int) $bound > 0) {
            $bound_license_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}eligius_licenses
                  WHERE user_id = %d AND device_id = %d AND status IN ('ACTIVE','TRIAL')
                  ORDER BY expires_at DESC LIMIT 1",
                (int) $user_id,
                (int) $device_id
            ));
            if ('' !== $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', $wpdb->last_error, 500);
            }
            if ($bound_license_id > 0) {
                $event = Class_Audit_Log_Repository::insert_license_event([
                    'license_id' => $bound_license_id,
                    'user_id'    => (int) $user_id,
                    'device_id'  => (int) $device_id,
                    'event_type' => 'device.bound',
                    'details'    => ['license_rows_bound' => (int) $bound],
                ]);
                $license_events_ok = (0 !== $event);
            }
        }
        if (!$license_events_ok) {
            $audit_id = Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $device_id,
                'event_type' => 'device.bound',
                'details'    => ['license_rows_bound' => (int) $bound, 'no_license_yet' => ((int) $bound === 0)],
            ]);
            if (0 === $audit_id) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', 'Device audit insert failed.', 500);
            }
        }

        $wpdb->query('COMMIT');

        $device = Class_Device_Repository::find((int) $device_id);
        return self::ok([
            'device_id'   => (int) $device_id,
            'device_hash' => $device_hash,
            'label'       => (string) ($device->label ?? $label),
            'created_at'  => (string) ($device->created_at ?? Class_UTC_Time::mysql_utc()),
        ], 201);
    }

    public function route_challenge(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        // 20/min/user (doc 03 §9 — Device challenge/verify).
        $exceeded = self::rate_limit_or_fail([
            ['device_challenge_user', (string) get_current_user_id(), 20, 60],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $challenge = Class_Device_Auth::instance()->create_challenge(
            get_current_user_id(),
            (string) $device->device_hash
        );
        return self::ok($challenge);
    }

    public function route_verify(\WP_REST_Request $request) {
        $device_hash = Class_Auth_Integration::instance()->get_device_hash($request);
        if (null === $device_hash) {
            return self::fail('VALIDATION_ERROR', 'X-Device-Hash must be 64 hex chars.', 400);
        }

        // Verify works on the registered device even when inactive.
        $device = Class_Device_Repository::find_by_hash(get_current_user_id(), $device_hash);
        if (!$device) {
            return self::fail('DEVICE_NOT_BOUND', 'Device is not registered for this account.', 403);
        }

        $exceeded = self::rate_limit_or_fail([
            ['device_verify_user', (string) get_current_user_id(), 20, 60],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $challenge = (string) $request->get_param('challenge');
        $signature = (string) $request->get_param('signature');

        $result = Class_Device_Auth::instance()->verify_challenge(
            get_current_user_id(),
            $device_hash,
            $challenge,
            $signature
        );
        if (is_wp_error($result)) {
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => get_current_user_id(),
                'device_id'  => (int) $device->id,
                'event_type' => 'auth.failed',
                'severity'   => 'WARNING',
                'details'    => ['reason' => $result->get_error_code()],
            ]);
            return self::fail_wp_error($result);
        }

        return self::ok($result);
    }

    public function route_list(\WP_REST_Request $request) {
        $auth_check = Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth_check)) {
            return self::fail_wp_error($auth_check);
        }

        $devices = Class_Device_Repository::list_for_user(get_current_user_id());
        if (null === $devices && '' !== $GLOBALS['wpdb']->last_error) {
            return self::fail('INTERNAL_ERROR', $GLOBALS['wpdb']->last_error, 500);
        }

        $active_id = Class_Device_Repository::find_active_id(get_current_user_id());
        $list = [];
        foreach ($devices as $device) {
            $list[] = [
                'device_id'    => (int) $device->id,
                'device_hash'  => (string) $device->device_hash,
                'label'        => (string) $device->label,
                'is_active'    => (bool) $device->is_active,
                'created_at'   => (string) $device->created_at,
                'last_seen_at' => (string) ($device->last_seen_at ?? ''),
            ];
        }

        return self::ok([
            'devices'         => $list,
            'active_device_id' => $active_id,
        ]);
    }

    public function route_transfer_start(\WP_REST_Request $request) {
        $device = self::device_or_error($request);
        if (is_wp_error($device)) {
            return self::fail_wp_error($device);
        }

        $to_hash = Class_Device_Repository::normalize_hash($request->get_param('to_device_hash'));
        if (null === $to_hash) {
            return self::fail('VALIDATION_ERROR', 'to_device_hash must be 64 hex chars.', 400);
        }

        // 3 transfers/day/user (doc 05 §6 security controls).
        $exceeded = self::rate_limit_or_fail([
            ['transfer_start_user', (string) get_current_user_id(), 3, 86400],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $result = Class_Device_Transfer::instance()->start(get_current_user_id(), $device, $to_hash);
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok($result, 201);
    }

    public function route_transfer_verify(\WP_REST_Request $request) {
        $auth_check = Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth_check)) {
            return self::fail_wp_error($auth_check);
        }

        $transfer_id = (int) $request->get_param('transfer_id');
        $otp_code    = (string) $request->get_param('otp_code');

        if ($transfer_id < 1) {
            return self::fail('VALIDATION_ERROR', 'transfer_id is required.', 400);
        }
        if (!preg_match('/^\d{6}$/', $otp_code)) {
            return self::fail('VALIDATION_ERROR', 'otp_code must be 6 digits.', 400);
        }

        // 20/min/user (verification category, doc 03 §9).
        $exceeded = self::rate_limit_or_fail([
            ['transfer_verify_user', (string) get_current_user_id(), 20, 60],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $result = Class_Device_Transfer::instance()->verify(get_current_user_id(), $transfer_id, $otp_code);
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok($result);
    }
}
