<?php
/**
 * Device transfer workflow (doc 05 §6, doc 02 §3.2 transaction, doc 03 §3.5,
 * Phase 5 owner decision: transfer initiated from the NEW device).
 *
 * start_from_new_device() — the NEW (inactive) device initiates the transfer;
 *            the currently ACTIVE device is found server-side, an OTP is
 *            generated (10-minute TTL) and MUST be delivered via SMS —
 *            a hard requirement: if SMS cannot be sent, the transfer is NOT
 *            created (owner decision — audit-only fallback is NOT accepted).
 * verify() — the shared wp_users FOR UPDATE lock is acquired FIRST (doc 02
 *            §2.8.1), then OTP verification (hash_equals), then the swap:
 *            old device is_active=0, new device is_active=1, license
 *            device_id moves — one transaction, explicit per-statement
 *            checks, ROLLBACK on any failure (doc 02 §2.8.3 discipline).
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

class Class_Device_Transfer {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Phase 5 (owner decision): transfer initiated from the NEW device.
     * The caller is authenticated (JWT) and already registered (may be
     * inactive — another device holds the active slot). The currently ACTIVE
     * device is found server-side; the OTP is sent to the user's registered
     * mobile and MUST be delivered (hard requirement — no audit-only fallback).
     *
     * @param int    $user_id       Authenticated user.
     * @param string $new_device_hash  The calling device's hash (must exist and be inactive).
     * @return array{transfer_id:int,otp_sent:bool,expires_in:int}|\WP_Error
     */
    public function start_from_new_device($user_id, $new_device_hash) {
        global $wpdb;

        $new_device = Class_Device_Repository::find_by_hash($user_id, $new_device_hash);
        if (!$new_device) {
            return new \WP_Error('eligius_device_not_found', 'Device not found for this account.', ['status' => 404]);
        }
        if ((int) $new_device->is_active) {
            return new \WP_Error('eligius_transfer_target_active', 'This device is already active.', ['status' => 409]);
        }
        // Phase 5: blocked device cannot initiate transfer.
        if (!empty($new_device->blocked)) {
            return new \WP_Error('eligius_device_blocked', 'This device has been blocked by an administrator.', ['status' => 403]);
        }

        // Find the currently ACTIVE device (the one to be replaced).
        $from_device = Class_Device_Repository::find_active($user_id);
        if (!$from_device) {
            return new \WP_Error('eligius_no_active_device', 'No active device found for this account.', ['status' => 422]);
        }

        // Pending transfer guard — one at a time.
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}eligius_device_transfers
              WHERE user_id = %d AND status = 'PENDING' AND expires_at > %s
              LIMIT 1",
            (int) $user_id,
            Class_UTC_Time::mysql_utc()
        ));
        if ($pending) {
            return new \WP_Error('eligius_transfer_pending', 'A transfer is already pending.', ['status' => 409]);
        }
        if ('' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        $otp     = sprintf('%06d', random_int(0, 999999));
        $expires = date('Y-m-d H:i:s', strtotime(Class_UTC_Time::mysql_utc()) + 600);

        $ok = $wpdb->insert($wpdb->prefix . 'eligius_device_transfers', [
            'user_id'       => (int) $user_id,
            'from_device_id' => (int) $from_device->id,
            'to_device_id'  => (int) $new_device->id,
            'otp_code'      => $otp,
            'status'        => 'PENDING',
            'expires_at'    => $expires,
            'initiated_at'  => Class_UTC_Time::mysql_utc(),
        ]);
        if (false === $ok || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_insert_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }
        $transfer_id = (int) $wpdb->insert_id;

        // Phase 5 (owner decision): SMS delivery is a HARD REQUIREMENT.
        // If SMS cannot be sent, the transfer is deleted immediately and a
        // typed error is returned — no audit-only fallback.
        $otp_sent = $this->send_otp($user_id, $otp);
        if (!$otp_sent) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}eligius_device_transfers WHERE id = " . $transfer_id);
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'event_type' => 'device.transfer_sms_failed',
                'severity'   => 'WARNING',
                'details'    => ['transfer_id' => $transfer_id, 'reason' => 'SMS delivery failed — transfer not created'],
            ]);
            return new \WP_Error(
                'eligius_sms_unavailable',
                'Could not send the verification SMS. Please try again later.',
                ['status' => 503]
            );
        }

        // Find the active license for the audit event (FK requires valid license_id).
        $activeLicense = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}eligius_licenses
              WHERE user_id = %d AND device_id = %d AND status IN ('ACTIVE','TRIAL') AND expires_at > %s
              ORDER BY expires_at DESC LIMIT 1",
            (int) $user_id,
            (int) $from_device->id,
            Class_UTC_Time::mysql_utc()
        ));
        $licId = $activeLicense ? (int) $activeLicense->id : null;

        // Phase 5: use insert_license_event when a valid license exists (FK
        // requires non-zero license_id); use insert_audit when the user has
        // no license yet (transfer may happen before first purchase).
        if (null !== $licId && $licId > 0) {
            Class_Audit_Log_Repository::insert_license_event([
                'user_id'    => (int) $user_id,
                'license_id' => $licId,
                'device_id'  => (int) $new_device->id,
                'event_type' => 'device.transfer_started',
                'details'    => [
                    'transfer_id'    => $transfer_id,
                    'from_device_id' => (int) $from_device->id,
                    'otp_sent'       => true,
                ],
            ]);
        } else {
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $new_device->id,
                'event_type' => 'device.transfer_started',
                'details'    => [
                    'transfer_id'    => $transfer_id,
                    'from_device_id' => (int) $from_device->id,
                    'otp_sent'       => true,
                ],
            ]);
        }

        return [
            'transfer_id' => $transfer_id,
            'otp_sent'    => true,
            'expires_in'  => 600,
        ];
    }

    /**
     * Start a transfer from the requesting (active) device to $to_device_hash.
     *
     * @return array{transfer_id:int,otp_sent:bool,expires_in:int}|\WP_Error
     */
    public function start($user_id, $from_device, $to_device_hash) {
        global $wpdb;

        if (!is_object($from_device) || !(int) $from_device->is_active) {
            return new \WP_Error('eligius_device_not_active', 'Transfers must be initiated from the active device.', ['status' => 403]);
        }
        // Phase 5: blocked devices cannot initiate or receive transfers.
        if (!empty($from_device->blocked)) {
            return new \WP_Error('eligius_device_blocked', 'This device has been blocked by an administrator.', ['status' => 403]);
        }

        $to_device = Class_Device_Repository::find_by_hash($user_id, $to_device_hash);
        if ($to_device && (int) $to_device->id === (int) $from_device->id) {
            return new \WP_Error('eligius_transfer_same_device', 'Target device equals the active device.', ['status' => 422]);
        }

        // Create the target row when the new device never registered
        // (registration on the new device fails with DEVICE_LIMIT_REACHED
        // while the old one is still active — doc 03 §3.1/§3.5 flow).
        if (!$to_device) {
            // The new device's public key arrives with its own registration
            // attempt; a transfer-created row keeps a placeholder key that
            // the client refreshes via /devices/register after activation.
            $new_id = Class_Device_Repository::create($user_id, $to_device_hash, '', '', 0, 'transfer');
            if (0 === $new_id || '' !== $wpdb->last_error) {
                return new \WP_Error('eligius_db_insert_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
            }
            $to_device = Class_Device_Repository::find_by_hash($user_id, $to_device_hash);
        } elseif ((int) $to_device->is_active) {
            return new \WP_Error('eligius_transfer_target_active', 'Target device is already active.', ['status' => 409]);
        }
        // Phase 5: blocked target device cannot receive a transfer.
        if (!empty($to_device->blocked)) {
            return new \WP_Error('eligius_device_blocked', 'Target device has been blocked by an administrator.', ['status' => 403]);
        }

        // Pending transfer guard (doc 03 §3.5 CONFLICT — one at a time).
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}eligius_device_transfers
              WHERE user_id = %d AND status = 'PENDING' AND expires_at > %s
              LIMIT 1",
            (int) $user_id,
            Class_UTC_Time::mysql_utc()
        ));
        if ($pending) {
            return new \WP_Error('eligius_transfer_pending', 'A transfer is already pending.', ['status' => 409]);
        }
        if ('' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        $otp       = sprintf('%06d', random_int(0, 999999));
        $expires   = date('Y-m-d H:i:s', strtotime(Class_UTC_Time::mysql_utc()) + 600); // 10-minute TTL (doc 05 §6).

        $ok = $wpdb->insert($wpdb->prefix . 'eligius_device_transfers', [
            'user_id'       => (int) $user_id,
            'from_device_id' => (int) $from_device->id,
            'to_device_id'  => (int) $to_device->id,
            'otp_code'      => $otp,
            'status'        => 'PENDING',
            'expires_at'    => $expires,
            // Explicit UTC (initiated_at has DEFAULT CURRENT_TIMESTAMP).
            'initiated_at'  => Class_UTC_Time::mysql_utc(),
        ]);
        if (false === $ok || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_insert_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }
        $transfer_id = (int) $wpdb->insert_id;

        // Phase 5 (owner decision): SMS delivery is a HARD REQUIREMENT.
        // If SMS cannot be sent, the transfer is NOT created — no audit-only
        // fallback, no "otp_sent: false" acceptance.
        $otp_sent = $this->send_otp($user_id, $otp);
        if (!$otp_sent) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}eligius_device_transfers WHERE id = " . $transfer_id);
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'event_type' => 'device.transfer_sms_failed',
                'severity'   => 'WARNING',
                'details'    => ['transfer_id' => $transfer_id, 'reason' => 'SMS delivery failed'],
            ]);
            return new \WP_Error(
                'eligius_sms_unavailable',
                'Could not send the verification SMS. Please try again later.',
                ['status' => 503]
            );
        }

        Class_Audit_Log_Repository::insert_license_event([
            'user_id'    => (int) $user_id,
            'device_id'  => (int) $to_device->id,
            'event_type' => 'device.transfer_started',
            'details'    => [
                'transfer_id'   => $transfer_id,
                'from_device_id' => (int) $from_device->id,
                'otp_sent'      => $otp_sent,
            ],
        ]);

        return [
            'transfer_id' => $transfer_id,
            'otp_sent'    => true,
            'expires_in'  => 600,
        ];
    }

    /**
     * Verify OTP and atomically swap the binding (doc 02 §3.2 verbatim flow).
     *
     * Phase 5b (owner decision): the verify MUST come from the DESTINATION
     * device. $caller_device_id is the caller's device row id (resolved from
     * X-Device-Hash by the REST route) and is enforced INSIDE the transaction
     * against transfer.to_device_id — a third device of the SAME account is
     * rejected (fail-closed: a missing caller id is also rejected, transfer_id
     * alone is never trusted).
     *
     * @param int    $user_id          Authenticated user (JWT).
     * @param int    $transfer_id      Pending transfer id.
     * @param string $otp_code         6-digit OTP from SMS.
     * @param int    $caller_device_id Caller's device row id (must be to_device).
     * @return array{verified:bool,new_active_device_id:int}|\WP_Error
     */
    public function verify($user_id, $transfer_id, $otp_code, $caller_device_id) {
        global $wpdb;

        // ---- STEP 0: THE SHARED LOCK (doc 02 §3.2) -------------------------
        $wpdb->query('START TRANSACTION');

        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            (int) $user_id
        ));
        if (null === $locked || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_lock_failed', 'Could not lock the account row.', ['status' => 500]);
        }

        // ---- STEP 1: locate the pending transfer --------------------------
        // Phase 5b (owner decision): transfer_id alone is NEVER trusted —
        // the caller must BE the destination device. Fail-closed on a
        // missing caller id.
        if ((int) $caller_device_id < 1) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_transfer_wrong_device', 'Transfer verify must come from the destination device.', ['status' => 403]);
        }

        $transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}eligius_device_transfers
              WHERE id = %d AND user_id = %d AND status = 'PENDING' AND expires_at > %s",
            (int) $transfer_id,
            (int) $user_id,
            Class_UTC_Time::mysql_utc()
        ));
        if (!$transfer || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_transfer_not_pending', 'Transfer not found, expired or already completed.', ['status' => 422]);
        }

        if ((int) $transfer->to_device_id !== (int) $caller_device_id) {
            $wpdb->query('ROLLBACK');
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'event_type' => 'auth.failed',
                'severity'   => 'WARNING',
                'details'    => [
                    'reason'            => 'device_transfer_wrong_caller',
                    'transfer_id'       => (int) $transfer_id,
                    'caller_device_id'  => (int) $caller_device_id,
                    'to_device_id'      => (int) $transfer->to_device_id,
                ],
            ]);
            return new \WP_Error('eligius_transfer_wrong_device', 'Transfer verify must come from the destination device.', ['status' => 403]);
        }

        // Phase 5: blocked devices cannot complete transfers (backend-enforced).
        $blockedCheck = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}eligius_devices
              WHERE id IN (%d, %d) AND blocked = 1",
            (int) $transfer->from_device_id,
            (int) $transfer->to_device_id
        ));
        if ('' !== $wpdb->last_error || (int) $blockedCheck > 0) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_device_blocked', 'Transfer blocked: device has been blocked by an administrator.', ['status' => 403]);
        }

        if (!hash_equals((string) $transfer->otp_code, (string) $otp_code)) {
            $wpdb->query('ROLLBACK');
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'event_type' => 'auth.failed',
                'severity'   => 'WARNING',
                'details'    => ['reason' => 'device_transfer_otp_mismatch', 'transfer_id' => (int) $transfer_id],
            ]);
            return new \WP_Error('eligius_transfer_bad_otp', 'Invalid OTP.', ['status' => 422]);
        }

        // ---- STEP 2: mark the transfer verified ---------------------------
        $updated = $wpdb->update(
            $wpdb->prefix . 'eligius_device_transfers',
            ['status' => 'VERIFIED', 'completed_at' => Class_UTC_Time::mysql_utc()],
            ['id' => (int) $transfer->id]
        );
        if (false === $updated || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_update_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        // ---- STEP 3: swap device activation (old deauthorized immediately) -
        $ok = Class_Device_Repository::set_active((int) $transfer->from_device_id, false);
        if (false === $ok || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_update_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        $ok = Class_Device_Repository::set_active((int) $transfer->to_device_id, true);
        if (false === $ok || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_update_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        // ---- STEP 4: move the license binding ------------------------------
        $moved = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_licenses
              SET device_id = %d, updated_at = %s
              WHERE device_id = %d AND user_id = %d AND status IN ('ACTIVE','TRIAL')",
            (int) $transfer->to_device_id,
            Class_UTC_Time::mysql_utc(),
            (int) $transfer->from_device_id,
            (int) $user_id
        ));
        if (false === $moved || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_bind_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        // ---- STEP 5: audit (an unaudited transfer must not commit) ---------
        // license_events.license_id carries a FK — re-read the moved license
        // inside the transaction and store its REAL id (doc 02 §2.8.3 note).
        // A transfer with no license yet falls back to audit_logs (nullable).
        $bound_license_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}eligius_licenses
              WHERE user_id = %d AND device_id = %d AND status IN ('ACTIVE','TRIAL')
              ORDER BY expires_at DESC LIMIT 1",
            (int) $user_id,
            (int) $transfer->to_device_id
        ));
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        $event_ok = false;
        if ($bound_license_id > 0) {
            $event = Class_Audit_Log_Repository::insert_license_event([
                'license_id' => $bound_license_id,
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $transfer->to_device_id,
                'event_type' => 'device.transferred',
                'details'    => [
                    'transfer_id'    => (int) $transfer->id,
                    'from_device_id' => (int) $transfer->from_device_id,
                    'to_device_id'   => (int) $transfer->to_device_id,
                ],
            ]);
            $event_ok = (0 !== $event);
        }
        if (!$event_ok) {
            $audit_id = Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $transfer->to_device_id,
                'event_type' => 'device.transferred',
                'details'    => [
                    'transfer_id'    => (int) $transfer->id,
                    'from_device_id' => (int) $transfer->from_device_id,
                    'to_device_id'   => (int) $transfer->to_device_id,
                    'no_license_yet' => (0 === $bound_license_id),
                ],
            ]);
            $event_ok = (0 !== $audit_id);
        }
        if (!$event_ok) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_audit_failed', 'Transfer event insert failed.', ['status' => 500]);
        }

        $wpdb->query('COMMIT');

        return [
            'verified'             => true,
            'new_active_device_id' => (int) $transfer->to_device_id,
        ];
    }

    /**
     * Send the OTP via the existing eligius-otp sender — HARD REQUIREMENT
     * (Phase 5 owner decision: no audit-only fallback, transfer cannot be
     * created if SMS cannot be delivered).
     *
     * @return bool true = SMS sent successfully; false = delivery failed.
     */
    private function send_otp($user_id, $otp) {
        $mobile = get_user_meta($user_id, 'mobile', true);
        if (!is_string($mobile) || '' === $mobile) {
            $mobile = get_user_meta($user_id, 'billing_phone', true);
        }

        if (function_exists('eligius_otp_send_sms') && is_string($mobile) && preg_match('/^09\d{9}$/', $mobile)) {
            $result = eligius_otp_send_sms($mobile, $otp);
            return (bool) $result;
        }

        // SMS service unavailable — Phase 5 owner decision: transfer cannot
        // proceed. No fallback, no audit-only acceptance.
        return false;
    }
}
