<?php
/**
 * Deactivation hook (doc 01 §2.2).
 * Phase 1 scope: rewrite-rules flush + clear the plugin cron schedules.
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Deactivation {

    public static function run() {
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }

        if (class_exists('\\Eligius_Sub\\Cron\\Class_Cron_Manager')) {
            \Eligius_Sub\Cron\Class_Cron_Manager::unschedule();
        }
    }
}
