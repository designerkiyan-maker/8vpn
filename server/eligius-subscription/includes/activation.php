<?php
/**
 * Activation hook (doc 01-SYSTEM-ARCHITECTURE.md §2.3).
 *
 * Phase 0 scope: environment verification ONLY.
 *
 * Deliberately NOT implemented in this phase (Phase 0 constraints forbid
 * schema changes):
 *   - table creation via Eligius_Sub_Database::create_tables()      → P1
 *   - default plan seeding                                          → P1
 *   - persistent options (grace period, trial days, max devices, …) → P1
 *   - cron scheduling                                               → P1
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Activation {

    public static function run() {
        // Environment guard: PHP floor per the plugin header (Requires PHP: 7.4).
        // WordPress ≥ 5.8 already blocks activation below this floor, so this
        // is a defensive backstop, not the primary gate.
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(plugin_basename(ELIGIUS_SUB_PLUGIN_FILE));
            }
            return;
        }

        // Phase 0: nothing else. No tables, no migrations, no cron, no options.
    }
}
