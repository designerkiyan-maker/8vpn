<?php
/**
 * License data access (doc 01 §2.1 core/class-license-repository.php, doc 02 §2.2).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_License_Repository extends Class_Repository_Base {

    protected static function table_suffix() {
        return 'eligius_licenses';
    }

    /** Latest ACTIVE or TRIAL license of a user (non-expired). */
    public static function get_current($user_id) {
        global $wpdb;
        $table = self::table();
        // Trial-first consumption (doc 05 §3.1): when a TRIAL and a purchased
        // license coexist (paid starts at trial expiry), serve the TRIAL until
        // it expires so the paid license is never usable early.
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}`
              WHERE user_id = %d
                AND status IN ('ACTIVE','TRIAL')
                AND expires_at > %s
              ORDER BY (status = 'TRIAL') DESC, expires_at DESC, id DESC
              LIMIT 1",
            (int) $user_id,
            Class_UTC_Time::mysql_utc()
        ));
    }

    /** ACTIVE/TRIAL license bound to a specific device (doc 04 §5.1). */
    public static function get_current_for_device($user_id, $device_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}`
              WHERE user_id = %d AND device_id = %d
                AND status IN ('ACTIVE','TRIAL')
                AND expires_at > %s
              ORDER BY (status = 'TRIAL') DESC, expires_at DESC, id DESC
              LIMIT 1",
            (int) $user_id,
            (int) $device_id,
            Class_UTC_Time::mysql_utc()
        ));
    }

    /** Any non-expired TRIAL license of the user. */
    public static function get_active_trial($user_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}`
              WHERE user_id = %d AND status = 'TRIAL' AND expires_at > %s
              ORDER BY expires_at DESC, id DESC LIMIT 1",
            (int) $user_id,
            Class_UTC_Time::mysql_utc()
        ));
    }

    /** Most recent license row regardless of status (renewal chains). */
    public static function get_latest($user_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            (int) $user_id
        ));
    }

    /** One trial per ACCOUNT (doc 05 §3.2 primary control — any status). */
    public static function has_trial_ever($user_id) {
        global $wpdb;
        $table = self::table();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE user_id = %d AND source = 'trial' LIMIT 1",
            (int) $user_id
        ));
        return !empty($id);
    }

    /** One free-plan activation per ACCOUNT (P9.2, owner decision — any status). */
    public static function has_free_ever($user_id) {
        global $wpdb;
        $table = self::table();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE user_id = %d AND source = 'free' LIMIT 1",
            (int) $user_id
        ));
        return !empty($id);
    }

    /**
     * P9.2: monotonic quota accounting — single atomic statement (same
     * discipline as the rate limiter's counter), never decreases.
     * P9.2.1: also accumulates the per-period stats (rx/tx/duration/sessions)
     * the Account usage card renders — a fresh purchase row starts at zero.
     * Returns the new row usage values, or null when the row vanished.
     */
    public static function increment_usage($license_id, $rx, $tx, $duration_ms = 0, $sessions = 0) {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET
                used_bytes = used_bytes + %d,
                used_rx_bytes = used_rx_bytes + %d,
                used_tx_bytes = used_tx_bytes + %d,
                used_duration_ms = used_duration_ms + %d,
                used_sessions = used_sessions + %d,
                updated_at = %s
             WHERE id = %d",
            Class_Volume::clamp_delta((int) $rx + (int) $tx),
            Class_Volume::clamp_delta($rx),
            Class_Volume::clamp_delta($tx),
            max(0, (int) $duration_ms),
            max(0, (int) $sessions),
            Class_UTC_Time::mysql_utc(),
            (int) $license_id
        ));
        if ('' !== $wpdb->last_error) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT used_bytes, used_rx_bytes, used_tx_bytes, used_duration_ms, used_sessions
               FROM `{$table}` WHERE id = %d",
            (int) $license_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Back-compat shim (older tests call the bytes-only form). */
    public static function increment_used_bytes($license_id, $delta) {
        $row = self::increment_usage($license_id, (int) $delta, 0, 0, 0);
        return null === $row ? null : (int) $row['used_bytes'];
    }

    /** Create a license row; returns insert id or 0 on failure. */
    public static function create(array $data) {
        $defaults = [
            'plan_key'      => '',
            'plan_label'    => '',
            'duration_days' => 0,
            'started_at'    => Class_UTC_Time::mysql_utc(),
            'expires_at'    => Class_UTC_Time::mysql_utc(),
            'status'        => 'PENDING_PAYMENT',
            'source'        => 'payment',
            // Explicit UTC — MySQL CURRENT_TIMESTAMP defaults would use the
            // server/session timezone instead of UTC (doc 02 §2.2 audit).
            'created_at'    => Class_UTC_Time::mysql_utc(),
            'updated_at'    => Class_UTC_Time::mysql_utc(),
        ];
        return self::insert_row(array_merge($defaults, $data));
    }

    /** Update a license row (status transitions, blob storage, binding). */
    public static function update($license_id, array $data) {
        return self::update_row($data, ['id' => (int) $license_id]);
    }

    /** License history of a user (admin + /subscriptions/history later). */
    public static function list_for_user($user_id, $limit = 50) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d",
            (int) $user_id,
            (int) $limit
        ));
    }

    /** Revoked license ids for the CRL (doc 04 §8.2). */
    public static function revoked_ids($limit = 1000) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE status = 'REVOKED' ORDER BY updated_at DESC LIMIT %d",
            (int) $limit
        ));
    }

    /** Licenses whose expiry has passed but status is still ACTIVE/TRIAL (cron sweep). */
    public static function find_expired_unprocessed($now_mysql, $limit = 500) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}`
              WHERE status IN ('ACTIVE','TRIAL') AND expires_at <= %s
              ORDER BY expires_at ASC LIMIT %d",
            $now_mysql,
            (int) $limit
        ));
    }

    /** Admin listing with filters (doc 03 §8.3). */
    public static function admin_list($filters = [], $page = 1, $per_page = 20) {
        global $wpdb;
        $table = self::table();

        $where  = 'WHERE 1=1';
        $params = [];
        if (!empty($filters['user_id'])) {
            $where   .= ' AND user_id = %d';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where   .= ' AND status = %s';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['plan_key'])) {
            $where   .= ' AND plan_key = %s';
            $params[] = (string) $filters['plan_key'];
        }

        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $sql    = "SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        array_push($params, (int) $per_page, $offset);

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** Dashboard counters (doc 03 §8 admin stats). */
    public static function stats() {
        global $wpdb;
        $table = self::table();
        $utc = Class_UTC_Time::mysql_utc();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'ACTIVE' AND expires_at > %s THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'TRIAL' AND expires_at > %s THEN 1 ELSE 0 END) AS trials,
                SUM(CASE WHEN status = 'REVOKED' THEN 1 ELSE 0 END) AS revoked,
                SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END) AS expired,
                COUNT(*) AS total
             FROM `{$table}`",
            $utc,
            $utc
        ), ARRAY_A);
        if (self::had_error() || null === $row) {
            return null;
        }
        return array_map('intval', $row);
    }
}