<?php
/**
 * Expiry processing (doc 01 §2.1 cron/class-expiry-processor.php,
 * doc 05 §4.1 daily expiry cron, doc 02 §4 retention cleanup).
 */

namespace Eligius_Sub\Cron;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

class Class_Expiry_Processor {

    /** Hook constants mirrored here for readability. */
    const DAILY_HOOK  = 'eligius_sub_daily_expiry_check';
    const WEEKLY_HOOK = 'eligius_sub_weekly_audit_cleanup';

    /**
     * ACTIVE/TRIAL licenses past expires_at → EXPIRED + lifecycle event
     * (doc 05 §4.1 — TRIAL → EXPIRED and ACTIVE → EXPIRED transitions).
     */
    public static function process_expirations() {
        global $wpdb;

        $now_mysql = Class_UTC_Time::mysql_utc();
        $expired   = Class_License_Repository::find_expired_unprocessed($now_mysql, 500);
        if ('' !== $wpdb->last_error || empty($expired)) {
            return;
        }

        foreach ($expired as $license) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}eligius_licenses
                  SET status = 'EXPIRED', updated_at = %s
                  WHERE id = %d AND status IN ('ACTIVE','TRIAL') AND expires_at <= %s",
                Class_UTC_Time::mysql_utc(),
                (int) $license->id,
                $now_mysql
            ));
            if (false === $updated || '' !== $wpdb->last_error) {
                continue; // next sweep retries (status still ACTIVE/TRIAL)
            }

            Class_Audit_Log_Repository::insert_license_event([
                'license_id' => (int) $license->id,
                'user_id'    => (int) $license->user_id,
                'device_id'  => $license->device_id,
                'event_type' => 'license.expired',
                'old_status' => (string) $license->status,
                'new_status' => 'EXPIRED',
            ]);
            Class_Audit_Log_Repository::insert_audit([
                'license_id' => (int) $license->id,
                'user_id'    => (int) $license->user_id,
                'event_type' => 'license.expired',
                'severity'   => 'WARNING',
            ]);
        }
    }

    /**
     * Retention cleanup (doc 02 §4): idempotency > 90 days, finished
     * transfers older than 1 year. Never deletes user data.
     */
    public static function cleanup_old_records() {
        global $wpdb;

        $cutoff_90d = Class_UTC_Time::add_seconds(Class_UTC_Time::mysql_utc(), -90 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_payment_idempotency
              WHERE processed_at < %s",
            $cutoff_90d
        ));
        $cutoff_1y = Class_UTC_Time::add_seconds(Class_UTC_Time::mysql_utc(), -365 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_device_transfers
              WHERE status IN ('EXPIRED','CANCELLED')
                AND completed_at < %s",
            $cutoff_1y
        ));
    }
}
