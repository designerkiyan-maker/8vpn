<?php
/**
 * Device transfer workflow (doc 05 §6, doc 02 §3.2 transaction, doc 03 §3.5).
 *
 * start()  — old ACTIVE device initiates a transfer to a target device hash;
 *            the target row is created (inactive) if it does not exist yet,
 *            an OTP is generated (10-minute TTL) and delivered via the SMS
 *            sender of the existing eligius-otp plugin when available.
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
     * Start a transfer from the requesting (active) device to $to_device_hash.
     *
     * @return array{transfer_id:int,otp_sent:bool,expires_in:int}|\WP_Error
     */
    public function start($user_id, $from_device, $to_device_hash) {
        global $wpdb;

        if (!is_object($from_device) || !(int) $from_device->is_active) {
            return new \WP_Error('eligius_device_not_active', 'Transfers must be initiated from the active device.', ['status' => 403]);
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
                return new \WP_Error('eligius_db_insert_failed', $wpdb->last_error ?: 'Could not create target device row.', ['status' => 500]);
            }
            $to_device = Class_Device_Repository::find_by_hash($user_id, $to_device_hash);
        } elseif ((int) $to_device->is_active) {
            return new \WP_Error('eligius_transfer_target_active', 'Target device is already active.', ['status' => 409]);
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
            return new \WP_Error('eligius_db_error', $wpdb->last_error, ['status' => 500]);
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
            return new \WP_Error('eligius_db_insert_failed', $wpdb->last_error ?: 'Transfer insert failed.', ['status' => 500]);
        }
        $transfer_id = (int) $wpdb->insert_id;

        // Deliver the OTP: reuse the existing OTP plugin's sender when it is
        // loaded (doc 01 §2.1 auth/class-otp-manager.php — reuse existing).
        $otp_sent = $this->send_otp($user_id, $otp);

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
            'otp_sent'    => $otp_sent,
            'expires_in'  => 600,
        ];
    }

    /**
     * Verify OTP and atomically swap the binding (doc 02 §3.2 verbatim flow).
     *
     * @return array{verified:bool,new_active_device_id:int}|\WP_Error
     */
    public function verify($user_id, $transfer_id, $otp_code) {
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
            return new \WP_Error('eligius_db_update_failed', $wpdb->last_error ?: 'Transfer update failed.', ['status' => 500]);
        }

        // ---- STEP 3: swap device activation (old deauthorized immediately) -
        $ok = Class_Device_Repository::set_active((int) $transfer->from_device_id, false);
        if (false === $ok || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_update_failed', $wpdb->last_error ?: 'Old-device deactivation failed.', ['status' => 500]);
        }

        $ok = Class_Device_Repository::set_active((int) $transfer->to_device_id, true);
        if (false === $ok || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_update_failed', $wpdb->last_error ?: 'New-device activation failed.', ['status' => 500]);
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
            return new \WP_Error('eligius_db_bind_failed', $wpdb->last_error ?: 'License binding move failed.', ['status' => 500]);
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
            return new \WP_Error('eligius_db_error', $wpdb->last_error, ['status' => 500]);
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
     * Send the OTP through the existing eligius-otp sender when available.
     * Falls back to audit-only storage when the SMS plugin is not loaded,
     * keeping the transfer flow testable in isolation.
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

        // Fallback: log the OTP delivery need for the admin (dev/test mode).
        Class_Audit_Log_Repository::insert_audit([
            'user_id'    => (int) $user_id,
            'event_type' => 'device.transfer_otp_fallback',
            'severity'   => 'WARNING',
            'details'    => ['note' => 'SMS sender unavailable; OTP generated without delivery.'],
        ]);
        return false;
    }
}
