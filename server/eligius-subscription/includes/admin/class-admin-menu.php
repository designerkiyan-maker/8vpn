<?php
/**
 * Admin menu registration (doc 01 §2.1 admin/class-admin-menu.php,
 * doc 03 §8 — Admin UI skeleton, Roadmap P1 "Admin UI").
 *
 * Pages: Dashboard (stats), Licenses (revoke/extend actions — P1 AC),
 * Users (subscription overview), Audit Logs viewer.
 */

namespace Eligius_Sub\Admin;

defined('ABSPATH') || exit;

use Eligius_Sub\Api\Class_Api_Controller;

class Class_Admin_Menu {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_eligius_sub_revoke_license', [$this, 'handle_revoke']);
        add_action('admin_post_eligius_sub_extend_license', [$this, 'handle_extend']);
        add_action('admin_init', [\Eligius_Sub\Core\Class_Database::class, 'maybe_upgrade']);
    }

    public function register_menu() {
        $capability = 'manage_options';

        add_menu_page(
            'Eligius',
            'Eligius',
            $capability,
            'eligius-sub',
            [Class_Admin_Dashboard::class, 'render'],
            'dashicons-shield',
            58
        );

        add_submenu_page('eligius-sub', 'Dashboard', 'Dashboard', $capability, 'eligius-sub', [Class_Admin_Dashboard::class, 'render']);
        add_submenu_page('eligius-sub', 'Licenses', 'Licenses', $capability, 'eligius-licenses', [Class_Licenses_Admin::class, 'render']);
        add_submenu_page('eligius-sub', 'Users', 'Users', $capability, 'eligius-users', [Class_Users_Admin::class, 'render']);
        add_submenu_page('eligius-sub', 'Audit Logs', 'Audit Logs', $capability, 'eligius-audit-logs', [Class_Audit_Logs_Admin::class, 'render']);
        add_submenu_page('eligius-sub', 'Devices', 'Devices', $capability, 'eligius-devices', [Class_Devices_Admin::class, 'render_all']);
    }

    /** Shared guard for admin_post actions (nonce + capability). */
    private function guard() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'eligius-subscription'));
        }
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'eligius_sub_admin_action')) {
            wp_die(esc_html__('Invalid nonce.', 'eligius-subscription'));
        }
    }

    /** Shared guard for admin_post device actions (nonce + capability). */
    public function device_guard() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'eligius-subscription'));
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eligius_sub_device_admin')) {
            wp_die(esc_html__('Invalid nonce.', 'eligius-subscription'));
        }
    }

    public function handle_revoke() {
        $this->guard();
        $license_id = isset($_POST['license_id']) ? (int) $_POST['license_id'] : 0;
        $reason     = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        $result = \Eligius_Sub\License\Class_License_Manager::instance()->revoke(
            $license_id,
            $reason,
            get_current_user_id()
        );

        $redirect = admin_url('admin.php?page=eligius-licenses');
        $redirect = is_wp_error($result)
            ? add_query_arg(['eligius_msg' => rawurlencode($result->get_error_message())], $redirect)
            : add_query_arg(['eligius_msg' => 'license_revoked'], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_extend() {
        $this->guard();
        $license_id = isset($_POST['license_id']) ? (int) $_POST['license_id'] : 0;
        $days       = isset($_POST['days']) ? (int) $_POST['days'] : 0;
        $reason     = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        $result = \Eligius_Sub\License\Class_License_Manager::instance()->extend(
            $license_id,
            $days,
            $reason,
            get_current_user_id()
        );

        $redirect = admin_url('admin.php?page=eligius-licenses');
        $redirect = is_wp_error($result)
            ? add_query_arg(['eligius_msg' => rawurlencode($result->get_error_message())], $redirect)
            : add_query_arg(['eligius_msg' => 'license_extended'], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }
}
