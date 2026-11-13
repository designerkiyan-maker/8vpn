<?php
/**
 * Activation hook (doc 01 §2.3, doc 02 §5.1 initial migration).
 *
 * Phase 1 scope: InnoDB engine verification (doc 02 §2.8.2), table
 * creation, default plans, default options, cron scheduling.
 */

if (!defined('ABSPATH')) { exit; }

class Eligius_Sub_Activation {

    public static function run() {
        // Environment guard: PHP floor per the plugin header (Requires PHP: 7.4).
        // sodium is REQUIRED for Ed25519 signing (doc 04 §4.2).
        if (version_compare(PHP_VERSION, '7.4', '<') || !function_exists('sodium_crypto_sign_detached')) {
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(plugin_basename(ELIGIUS_SUB_PLUGIN_FILE));
            }
            if (function_exists('wp_die')) {
                wp_die(
                    esc_html__('Eligius Subscription requires PHP 7.4+ with the sodium extension (Ed25519 signing).', 'eligius-subscription')
                );
            }
            return;
        }

        // Full initial migration (doc 02 §5.1): engines, tables, plans, options.
        $result = \Eligius_Sub\Core\Class_Database::install();
        if (is_wp_error($result)) {
            // Refusal path (e.g. non-InnoDB wp_users that cannot be converted —
            // doc 02 §2.8.5): surface the typed error and abort activation.
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(plugin_basename(ELIGIUS_SUB_PLUGIN_FILE));
            }
            if (function_exists('wp_die')) {
                wp_die(esc_html($result->get_error_message()));
            }
            return;
        }

        // Cron jobs (doc 01 §2.3 / doc 05 §4.1).
        \Eligius_Sub\Cron\Class_Cron_Manager::schedule();

        flush_rewrite_rules();
    }
}
