<?php
/**
 * Audit logging service (doc 01 §2.1 security/class-audit-logger.php).
 *
 * Thin facade over Class_Audit_Log_Repository used by managers and
 * controllers. Writes go to wp_eligius_audit_logs by default; lifecycle
 * events (license status transitions, device bindings) additionally go to
 * wp_eligius_license_events via log_license_event().
 */

namespace Eligius_Sub\Security;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;

class Class_Audit_Logger {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Log a security/financial event to audit_logs.
     * Expected keys: event_type, severity (INFO|WARNING|CRITICAL),
     * user_id, device_id, license_id, actor_user_id, details, ip.
     */
    public function log(array $args) {
        return Class_Audit_Log_Repository::insert_audit($args);
    }

    /**
     * Log a license lifecycle event to license_events.
     * Expected keys: license_id, user_id, device_id, event_type,
     * old_status, new_status, details.
     */
    public function log_license_event(array $args) {
        return Class_Audit_Log_Repository::insert_license_event($args);
    }
}
