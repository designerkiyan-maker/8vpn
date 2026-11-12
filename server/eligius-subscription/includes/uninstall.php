<?php
/**
 * Uninstall cleanup (doc 01-SYSTEM-ARCHITECTURE.md §2.2).
 *
 * Reached ONLY through Eligius_Subscription::uninstall(), which requires the
 * 'eligius_sub_confirm_uninstall' option to be set — data is never deleted
 * implicitly.
 *
 * Phase 0 scope: plugin options only.
 * P1 will add wp_eligius_* table drops (doc 02-DATABASE-DESIGN.md §2) —
 * deliberately absent here until the schema exists.
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Uninstall {

    public static function run() {
        // Options removed on confirmed uninstall. The list grows in later
        // phases; never drop tables here before P1 implements the schema.
        $options = [
            // 'eligius_sub_db_version',        // set in P1
            // 'eligius_sub_confirm_uninstall', // deleted by the caller after run()
        ];

        foreach ($options as $option) {
            delete_option($option);
        }
    }
}
