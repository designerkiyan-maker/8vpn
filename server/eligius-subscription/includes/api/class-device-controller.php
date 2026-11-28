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
            return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), 500);
        }
        if ($existing) {
            // Phase 5: blocked device cannot re-register (backend-enforced).
            if (!empty($existing->blocked)) {
                $wpdb->query('COMMIT');
                return self::fail(
                    'DEVICE_BLOCKED',
                    'This device has been blocked by an administrator.',
                    403,
                    ['device_id' => (int) $existing->id]
                );
            }

            if ((int) $existing->is_active) {
                $wpdb->query('COMMIT');
                return self::fail(
                    'CONFLICT',
                    'This exact device is already registered.',
                    409,
                    ['device_id' => (int) $existing->id, 'is_active' => true]
                );
            }

            // Phase 5b (owner decision): re-registering an INACTIVE row.
            // Refresh the stored public key to the caller's current Keystore
            // key (covers re-installs and old-flow placeholder rows), then:
            //   • CD-9 slot FREE  → re-activate + bind licenses (register
            //     semantics apply whenever the slot is free);
            //   • CD-9 slot TAKEN → keep the row PENDING — the device is
            //     NEVER activated here; activation happens exclusively via
            //     the OTP transfer verify.
            $slot_active_id = (int) Class_Device_Repository::find_active_id($user_id);
            if ('' !== $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), 500);
            }
            $upd = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}eligius_devices
                  SET public_key = %s, label = %s
                  WHERE id = %d AND is_active = 0 AND blocked = 0",
                $public_key,
                $label,
                (int) $existing->id
            ));
            if (false === $upd || '' !== $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error ?: 'Device key refresh failed.'), 500);
            }

            if (0 === $slot_active_id) {
                $upd = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}eligius_devices SET is_active = 1 WHERE id = %d",
                    (int) $existing->id
                ));
                if (false === $upd || '' !== $wpdb->last_error) {
                    $wpdb->query('ROLLBACK');
                    return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error ?: 'Device reactivation failed.'), 500);
                }
                $bind_err = self::bind_licenses_and_audit($user_id, (int) $existing->id);
                if (null !== $bind_err) {
                    return self::fail_wp_error($bind_err); // helper already rolled back
                }
                $wpdb->query('COMMIT');
                $device = Class_Device_Repository::find((int) $existing->id);
                return self::ok([
                    'device_id'   => (int) $existing->id,
                    'device_hash' => $device_hash,
                    'label'       => (string) ($device->label ?? $label),
                    'created_at'  => (string) ($device->created_at ?? Class_UTC_Time::mysql_utc()),
                    'is_active'   => true,
                    'reactivated' => true,
                ], 201);
            }

            $audit_id = Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $existing->id,
                'event_type' => 'device.register_pending',
                'details'    => ['active_device_id' => $slot_active_id, 'key_refreshed' => true],
            ]);
            if (0 === $audit_id) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', 'Pending registration audit insert failed.', 500);
            }
            $wpdb->query('COMMIT');
            return self::ok([
                'device_id'        => (int) $existing->id,
                'device_hash'      => $device_hash,
                'label'            => $label,
                'created_at'       => (string) ($existing->created_at ?? Class_UTC_Time::mysql_utc()),
                'is_active'        => false,
                'pending_transfer' => true,
                'active_device_id' => $slot_active_id,
            ], 201);
        }

        // Step 2b: one-active-device under the lock (CD-9) — never auto-replace,
        // the OTP transfer is the only path (doc 02 §2.8.3).
        $active_id = Class_Device_Repository::find_active_id($user_id);
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), 500);
        }
        if ($active_id > 0) {
            // Phase 5b (owner decision): a BRAND-NEW device on an account
            // whose slot is occupied registers as PENDING (inactive). The
            // device is NEVER activated here — activation happens
            // exclusively via the OTP transfer verify; licenses stay with
            // the active device. CD-9 stays intact (row created with
            // is_active = 0) and the register rate limit (10/h/user)
            // still applies.
            $device_id = Class_Device_Repository::create($user_id, $device_hash, $public_key, $label, 0, 'app');
            if (0 === $device_id || '' !== $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error ?: 'Device insert failed.'), 500);
            }
            // NO license binding — the active device keeps its licenses;
            // they move atomically in transfer verify only.
            $audit_id = Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $device_id,
                'event_type' => 'device.register_pending',
                'details'    => ['active_device_id' => (int) $active_id],
            ]);
            if (0 === $audit_id) {
                $wpdb->query('ROLLBACK');
                return self::fail('INTERNAL_ERROR', 'Pending registration audit insert failed.', 500);
            }
            $wpdb->query('COMMIT');
            $device = Class_Device_Repository::find((int) $device_id);
            return self::ok([
                'device_id'        => (int) $device_id,
                'device_hash'      => $device_hash,
                'label'            => (string) ($device->label ?? $label),
                'created_at'       => (string) ($device->created_at ?? Class_UTC_Time::mysql_utc()),
                'is_active'        => false,
                'pending_transfer' => true,
                'active_device_id' => (int) $active_id,
            ], 201);
        }

        // Step 3: insert the new device (explicit result check).
        $device_id = Class_Device_Repository::create($user_id, $device_hash, $public_key, $label, 1, 'app');
        if (0 === $device_id || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error ?: 'Device insert failed.'), 500);
        }

        // Steps 4-5: bind ACTIVE/TRIAL licenses + audited bind (doc 02 §2.8.3).
        $bind_err = self::bind_licenses_and_audit($user_id, (int) $device_id);
        if (null !== $bind_err) {
            return self::fail_wp_error($bind_err); // helper already rolled back
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

    /**
     * Bind ACTIVE/TRIAL licenses to a device and write the audited-bind
     * record (doc 02 §2.8.3) — MUST be called inside an OPEN transaction.
     * On any failure the transaction is rolled back and a typed error is
     * returned (the caller responds with fail_wp_error).
     */
    private static function bind_licenses_and_audit($user_id, $device_id): ?\WP_Error {
        global $wpdb;

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
            return new \WP_Error('eligius_internal_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
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
                return new \WP_Error('eligius_internal_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
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
                return new \WP_Error('eligius_internal_error', 'Device audit insert failed.', ['status' => 500]);
            }
        }
        return null;
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

        // P9.2.1 (owner request): keep the device label fresh with the real
        // phone model — legacy rows registered as "Android" get renamed on
        // the next successful verify.
        $label = sanitize_text_field((string) $request->get_param('label'));
        if ('' !== $label && $label !== (string) $device->label) {
            \Eligius_Sub\Core\Class_Device_Repository::update_row(
                ['label' => mb_substr($label, 0, 64)],
                ['id' => (int) $device->id]
            );
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
            return self::fail('INTERNAL_ERROR', \Eligius_Sub\Core\Class_DB_Error::message('db', $GLOBALS['wpdb']->last_error), 500);
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

        // Phase 5b: when the CALLING device is the DESTINATION of a pending
        // OTP transfer (e.g. the old device initiated the transfer), surface
        // it so the destination device can offer the OTP entry UI — verify
        // must come from the destination device (transfer_id alone is never
        // accepted from anywhere else).
        $pending_transfer = null;
        $caller_hash = Class_Auth_Integration::instance()->get_device_hash($request);
        if (null !== $caller_hash) {
            $caller = Class_Device_Repository::find_by_hash(get_current_user_id(), $caller_hash);
            if ($caller && '' === $GLOBALS['wpdb']->last_error) {
                $pending = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
                    "SELECT id, expires_at FROM {$GLOBALS['wpdb']->prefix}eligius_device_transfers
                      WHERE user_id = %d AND status = 'PENDING' AND expires_at > %s AND to_device_id = %d
                      ORDER BY id DESC LIMIT 1",
                    (int) get_current_user_id(),
                    Class_UTC_Time::mysql_utc(),
                    (int) $caller->id
                ));
                if ($pending && '' === $GLOBALS['wpdb']->last_error) {
                    $pending_transfer = [
                        'transfer_id' => (int) $pending->id,
                        'expires_at'  => (string) $pending->expires_at,
                        'expires_in'  => max(0, strtotime((string) $pending->expires_at) - time()),
                    ];
                }
            }
        }

        return self::ok([
            'devices'          => $list,
            'active_device_id' => $active_id,
            'pending_transfer' => $pending_transfer,
        ]);
    }

    public function route_transfer_start(\WP_REST_Request $request) {
        // Phase 5 (owner decision): the transfer is initiated from the NEW
        // device. The calling device (identified by X-Device-Hash) may be
        // ACTIVE (old flow — targets to_device_hash from body) or INACTIVE
        // (new flow — the server finds the currently ACTIVE device as the
        // "from" device and targets the caller).
        $auth = Class_Auth_Integration::instance();
        $user_check = $auth->permission_user($request);
        if (is_wp_error($user_check)) {
            return self::fail_wp_error($user_check);
        }

        $device_hash = $auth->get_device_hash($request);
        if (null === $device_hash) {
            return self::fail('VALIDATION_ERROR', 'X-Device-Hash must be 64 hex chars.', 400);
        }

        $device = \Eligius_Sub\Core\Class_Device_Repository::find_by_hash(get_current_user_id(), $device_hash);
        if (!$device) {
            return self::fail('DEVICE_NOT_BOUND', 'Device is not registered for this account.', 403);
        }
        if (!empty($device->blocked)) {
            return self::fail('DEVICE_BLOCKED', 'This device has been blocked by an administrator.', 403);
        }

        // 3 transfers/day/user (doc 05 §6 security controls).
        $exceeded = self::rate_limit_or_fail([
            ['transfer_start_user', (string) get_current_user_id(), 3, 86400],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        if ((int) $device->is_active) {
            // Old flow: active device initiates to a target hash (requires to_device_hash in body).
            $to_hash = Class_Device_Repository::normalize_hash($request->get_param('to_device_hash'));
            if (null === $to_hash) {
                return self::fail('VALIDATION_ERROR', 'to_device_hash must be 64 hex chars.', 400);
            }
            $result = Class_Device_Transfer::instance()->start(get_current_user_id(), $device, $to_hash);
        } else {
            // New flow (Phase 5): the calling device is INACTIVE → it wants to
            // take over from the currently ACTIVE device.
            $result = Class_Device_Transfer::instance()->start_from_new_device(
                get_current_user_id(),
                $device_hash
            );
        }

        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok($result, 201);
    }

    public function route_transfer_verify(\WP_REST_Request $request) {
        $auth = Class_Auth_Integration::instance();
        $auth_check = $auth->permission_user($request);
        if (is_wp_error($auth_check)) {
            return self::fail_wp_error($auth_check);
        }

        // Phase 5b (owner decision): the verify MUST come from the DESTINATION
        // device. Resolve the caller's device row from X-Device-Hash (blocked
        // devices rejected here too) and enforce it against to_device_id
        // inside the transaction — transfer_id alone is never trusted.
        $caller_device_check = $auth->resolve_registered_device($request);
        if (is_wp_error($caller_device_check)) {
            return self::fail_wp_error($caller_device_check);
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

        $result = Class_Device_Transfer::instance()->verify(
            get_current_user_id(),
            $transfer_id,
            $otp_code,
            (int) $caller_device_check->id
        );
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok($result);
    }
}
