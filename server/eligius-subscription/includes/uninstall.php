<?php
/**
 * Uninstall cleanup (doc 01 §2.2, doc 02 §1.1 "No Auto-Deletion").
 *
 * Reached ONLY through Eligius_Subscription::uninstall(), which requires the
 * 'eligius_sub_confirm_uninstall' option to be set — data is never deleted
 * implicitly. Options + transients are removed; tables are dropped ONLY here
 * (confirmed uninstall), never on deactivation or upgrade.
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Uninstall {

    public static function run() {
        // Plugin options (doc 01 §2.3 defaults + key material).
        $options = [
            'eligius_sub_db_version',
            'eligius_sub_grace_period_hours',
            'eligius_sub_trial_days',
            'eligius_sub_max_devices',
            'eligius_sub_license_key_rotation_days',
            'eligius_sub_max_transfers_per_day',
            'eligius_sub_signing_private_key',
            'eligius_sub_confirm_uninstall',
        ];
        // Signing public keys per kid (v1, v2, v3 — doc 04 §6.1).
        foreach (wp_load_alloptions() as $name => $value) {
            if (0 === strpos($name, 'eligius_sub_pubkey_')) {
                $options[] = $name;
            }
        }

        foreach ($options as $option) {
            delete_option($option);
        }

        // Rate-limit + challenge + CRL transients (autoload=no options under
        // a non-persistent cache, or real transients otherwise).
        foreach (wp_load_alloptions() as $name => $value) {
            if (0 === strpos($name, '_transient_eligius_sub_') || 0 === strpos($name, '_transient_timeout_eligius_sub_')) {
                delete_option($name);
            }
        }

        // Drop the plugin tables (confirmed uninstall only — doc 02 §1.1).
        if (class_exists('\\Eligius_Sub\\Core\\Class_Database')) {
            \Eligius_Sub\Core\Class_Database::drop_tables();
        }
    }
}
