<?php
/**
 * Deactivation hook (doc 01-SYSTEM-ARCHITECTURE.md §2.2).
 *
 * Phase 0 scope: standard rewrite-rules flush only.
 * P1 will additionally clear scheduled cron events (doc 01 §2.1 cron/).
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Deactivation {

    public static function run() {
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }

        // Phase 0: no cron events exist yet to clear.
    }
}
