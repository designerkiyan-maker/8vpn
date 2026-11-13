<?php
/**
 * Device data access (doc 01 §2.1 core/class-device-repository.php, doc 02 §2.1).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_Device_Repository extends Class_Repository_Base {

    protected static function table_suffix() {
        return 'eligius_devices';
    }

    /** Normalize a device hash to bare lowercase hex; null when invalid. */
    public static function normalize_hash($hash) {
        $hash = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $hash));
        return (64 === strlen($hash)) ? $hash : null;
    }

    public static function find_by_hash($user_id, $device_hash) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d AND device_hash = %s",
            (int) $user_id,
            $device_hash
        ));
    }

    /** The account's single active device (CD-9 — at most one row). */
    public static function find_active($user_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
            (int) $user_id
        ));
    }

    public static function find_active_id($user_id) {
        global $wpdb;
        $table = self::table();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
            (int) $user_id
        ));
        return $id ? (int) $id : 0;
    }

    /** Register a device row (caller enforces the CD-9 lock discipline). */
    public static function create($user_id, $device_hash, $public_key_pem, $label = '', $is_active = 1, $registered_via = '') {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'user_id'         => (int) $user_id,
            'device_hash'     => $device_hash,
            'public_key'      => (string) $public_key_pem,
            'label'           => sanitize_text_field((string) $label),
            'registered_via'  => sanitize_text_field((string) $registered_via),
            'is_active'       => $is_active ? 1 : 0,
            // Explicit UTC insert — do not rely on MySQL CURRENT_TIMESTAMP
            // (session timezone could make it server-local).
            'created_at'      => Class_UTC_Time::mysql_utc(),
        ]);
        return (false === $ok) ? 0 : (int) $wpdb->insert_id;
    }

    /** Set activation state of one device row. */
    public static function set_active($device_id, $is_active) {
        global $wpdb;
        $result = $wpdb->update(self::table(), ['is_active' => $is_active ? 1 : 0], ['id' => (int) $device_id]);
        // 0 rows matched is a valid outcome inside serialized transactions.
        return (false === $result) ? false : true;
    }

    /** All devices of a user, active first (doc 03 §3.4). */
    public static function list_for_user($user_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY is_active DESC, id ASC",
            (int) $user_id
        ));
    }

    /** Update last_seen_at (successful auth). */
    public static function touch($device_id) {
        global $wpdb;
        $result = $wpdb->update(self::table(), ['last_seen_at' => Class_UTC_Time::mysql_utc()], ['id' => (int) $device_id]);
        return (false === $result) ? false : true;
    }
}
