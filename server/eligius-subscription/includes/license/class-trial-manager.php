<?php
/**
 * Trial logic (doc 05 §3.2 grant flow with doc 02 §2.8.3 lock discipline).
 *
 * One trial per ACCOUNT (user_id) — immune to reinstall by design (CD-6).
 * The grant acquires the same shared wp_users FOR UPDATE lock as device
 * binding and transfer, then applies the explicit per-statement error
 * checking + ROLLBACK discipline of doc 02 §2.8.3.
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

class Class_Trial_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Grant the 30-day trial for $user_id bound (informationally) to the
     * ACTIVE device with $device_hash (doc 05 §3.2).
     *
     * @param int    $user_id     Authenticated account id.
     * @param string $device_hash Normalized active-device hash.
     * @return array{license_id:int,expires_at:string,offline_entitlement:array}|\WP_Error
     */
    public function grant($user_id, $device_hash) {
        global $wpdb;

        $device = Class_Device_Repository::find_by_hash($user_id, $device_hash);
        if (!$device || !(int) $device->is_active) {
            return new \WP_Error('eligius_device_not_found', 'Active device not found for this account.', ['status' => 403]);
        }

        // ---- Single connection, single transaction -------------------------
        $wpdb->query('START TRANSACTION');

        // THE LOCK (doc 02 §2.8.1 — shared by bind / transfer / trial grant).
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            (int) $user_id
        ));
        if (null === $locked || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_lock_failed', 'Could not lock the account row.', ['status' => 500]);
        }

        // PRIMARY CONTROL: one trial per account — any status, any device.
        if (Class_License_Repository::has_trial_ever($user_id)) {
            $wpdb->query('COMMIT'); // read-only tx → lock release
            return new \WP_Error('eligius_trial_already_used', 'Trial already used for this account.', ['status' => 409]);
        }
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        // DETERRENCE: same device_hash with a trial on ANOTHER account → flag
        // for admin review, never auto-block (doc 05 §3.2 step 3).
        $other_trial = $wpdb->get_var($wpdb->prepare(
            "SELECT l.id FROM {$wpdb->prefix}eligius_licenses l
              JOIN {$wpdb->prefix}eligius_devices d ON l.device_id = d.id
              WHERE d.device_hash = %s AND l.source = 'trial' AND l.user_id != %d
              LIMIT 1",
            $device_hash,
            (int) $user_id
        ));
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }
        if ($other_trial) {
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $user_id,
                'device_id'  => (int) $device->id,
                'event_type' => 'trial.device_correlation_flag',
                'severity'   => 'WARNING',
                'details'    => ['other_license_id' => (int) $other_trial],
            ]);
            // Flagged, NOT rejected (doc 05 §3.2 — soft signal).
        }

        // Create the trial license (account-keyed, device-bound informationally).
        $trial_days = (int) get_option('eligius_sub_trial_days', 30);
        $started    = Class_UTC_Time::mysql_utc();
        $expires    = gmdate('Y-m-d H:i:s', strtotime("+{$trial_days} days", strtotime($started)));

        $license_id = Class_License_Repository::create([
            'user_id'         => (int) $user_id,
            'device_id'       => (int) $device->id,
            'plan_key'        => 'trial',
            'plan_label'      => 'Free Trial',
            'duration_days'   => $trial_days,
            'started_at'      => $started,
            'expires_at'      => $expires,
            'status'          => 'TRIAL',
            'source'          => 'trial',
        ]);
        if (0 === $license_id || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_insert_failed', \Eligius_Sub\Core\Class_DB_Error::message('db', $wpdb->last_error), ['status' => 500]);
        }

        // Lifecycle event — an unaudited grant must not commit (doc 02 §2.8.3 step 5).
        $event = Class_Audit_Log_Repository::insert_license_event([
            'license_id' => $license_id,
            'user_id'    => (int) $user_id,
            'device_id'  => (int) $device->id,
            'event_type' => 'trial.granted',
            'new_status' => 'TRIAL',
            'details'    => ['duration_days' => $trial_days],
        ]);
        if (0 === $event) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_audit_failed', 'Trial event insert failed.', ['status' => 500]);
        }

        $wpdb->query('COMMIT');

        // Offline entitlement is issued after commit (blob issuance does its
        // own license-row update and never runs inside the grant transaction).
        $entitlement = Class_License_Issuer::instance()->issue_offline_entitlement($license_id, (int) $device->id);

        return [
            'license_id'         => $license_id,
            'expires_at'         => $expires,
            'offline_entitlement' => is_wp_error($entitlement) ? null : $entitlement,
        ];
    }
}
