<?php
/**
 * Audit trail data access (doc 02 §2.4 license_events + §2.6 audit_logs).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_Audit_Log_Repository extends Class_Repository_Base {

    protected static function table_suffix() {
        return 'eligius_audit_logs';
    }

    private static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'eligius_license_events';
    }

    /** Binary IP for the VARBINARY(16) columns (IPv4 or IPv6). */
    public static function ip_binary($ip = '') {
        $ip = '' !== $ip ? $ip : ($_SERVER['REMOTE_ADDR'] ?? '');
        $bin = @inet_pton((string) $ip);
        return false === $bin ? null : $bin;
    }

    /** Insert into wp_eligius_audit_logs (security/financial trail). */
    public static function insert_audit(array $args) {
        global $wpdb;

        $data = [
            'event_type' => (string) $args['event_type'],
            'severity'   => in_array($args['severity'] ?? 'INFO', ['INFO', 'WARNING', 'CRITICAL'], true)
                ? ($args['severity'] ?? 'INFO') : 'INFO',
            'ip'         => self::ip_binary($args['ip'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ($args['user_agent'] ?? '')), 0, 255),
        ];
        foreach (['user_id', 'device_id', 'license_id', 'actor_user_id'] as $key) {
            if (!empty($args[$key])) {
                $data[$key] = (int) $args[$key];
            }
        }
        if (!empty($args['details'])) {
            $data['details'] = wp_json_encode($args['details']);
        }

        $ok = $wpdb->insert(self::table(), $data);
        return (false === $ok) ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Insert into wp_eligius_license_events (license lifecycle trail).
     * The schema has no severity column (doc 02 §2.4) — severity belongs to
     * audit_logs; callers wanting both call both methods explicitly.
     */
    public static function insert_license_event(array $args) {
        global $wpdb;

        $data = [
            'license_id' => (int) ($args['license_id'] ?? 0),
            'user_id'    => (int) ($args['user_id'] ?? 0),
            'event_type' => (string) $args['event_type'],
            'ip'         => self::ip_binary($args['ip'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ($args['user_agent'] ?? '')), 0, 255),
        ];
        if (!empty($args['device_id'])) {
            $data['device_id'] = (int) $args['device_id'];
        }
        foreach (['old_status', 'new_status'] as $key) {
            if (isset($args[$key]) && '' !== $args[$key]) {
                $data[$key] = (string) $args[$key];
            }
        }
        if (!empty($args['details'])) {
            $data['details'] = wp_json_encode($args['details']);
        }

        $ok = $wpdb->insert(self::events_table(), $data);
        return (false === $ok) ? 0 : (int) $wpdb->insert_id;
    }

    /** Audit log viewer query (doc 03 §8.4). */
    public static function admin_list($filters = [], $page = 1, $per_page = 30) {
        global $wpdb;
        $table = self::table();

        $where  = 'WHERE 1=1';
        $params = [];
        if (!empty($filters['event_type'])) {
            $where   .= ' AND event_type = %s';
            $params[] = (string) $filters['event_type'];
        }
        if (!empty($filters['severity'])) {
            $where   .= ' AND severity = %s';
            $params[] = (string) $filters['severity'];
        }
        if (!empty($filters['user_id'])) {
            $where   .= ' AND user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $sql    = "SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        array_push($params, (int) $per_page, $offset);

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** License event history for one license (admin viewer). */
    public static function events_for_license($license_id) {
        global $wpdb;
        $table = self::events_table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE license_id = %d ORDER BY id ASC",
            (int) $license_id
        ));
    }
}
