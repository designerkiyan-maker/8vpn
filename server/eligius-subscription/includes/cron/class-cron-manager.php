<?php
/**
 * Cron job management (doc 01 §2.1 cron/class-cron-manager.php).
 *
 * Schedules: daily expiry sweep (doc 05 §4.1) + weekly cleanup
 * (doc 02 §4). Cleared on deactivation.
 */

namespace Eligius_Sub\Cron;

defined('ABSPATH') || exit;

class Class_Cron_Manager {

    private static $instance = null;

    const DAILY_HOOK  = 'eligius_sub_daily_expiry_check';
    const WEEKLY_HOOK = 'eligius_sub_weekly_audit_cleanup';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(Class_Expiry_Processor::DAILY_HOOK, [Class_Expiry_Processor::class, 'process_expirations']);
        add_action(Class_Expiry_Processor::WEEKLY_HOOK, [Class_Expiry_Processor::class, 'cleanup_old_records']);
    }

    /** Register the schedules (doc 01 §2.3 activation). */
    public static function schedule() {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::DAILY_HOOK);
        }
        if (!wp_next_scheduled(self::WEEKLY_HOOK)) {
            wp_schedule_event(strtotime('next sunday 04:00'), 'weekly', self::WEEKLY_HOOK);
        }
    }

    /** Clear the schedules (deactivation). */
    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::DAILY_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::DAILY_HOOK);
        }
        $timestamp = wp_next_scheduled(self::WEEKLY_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::WEEKLY_HOOK);
        }
    }
}
