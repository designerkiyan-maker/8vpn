<?php
/**
 * Autoloader & initialization (doc 01-SYSTEM-ARCHITECTURE.md §2.2).
 *
 * Autoloader naming convention (derived from the mapping below, consistent with
 * the doc 01 §2.1 file listing):
 *
 *     Eligius_Sub\{Group}\Class_{Name}  →  includes/{group}/class-{name}.php
 *
 *     e.g. Eligius_Sub\Core\Class_Database       → includes/core/class-database.php
 *          Eligius_Sub\Api\Class_License_Controller → includes/api/class-license-controller.php
 *
 * Procedural bootstrap classes (Eligius_Sub_Activation, Eligius_Sub_Deactivation,
 * Eligius_Sub_Uninstall) are loaded with explicit require_once and do not go
 * through the autoloader.
 */

if (!defined('ABSPATH')) { exit; }

spl_autoload_register(function ($class) {
    $prefix = 'Eligius_Sub\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = ELIGIUS_SUB_PLUGIN_DIR . 'includes/' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) { require_once $file; }
});

class Eligius_Subscription {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->load_textdomain();
    }

    private function init_hooks() {
        register_activation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(ELIGIUS_SUB_PLUGIN_FILE, [__CLASS__, 'uninstall']);

        add_action('plugins_loaded', [$this, 'load_components']);
        // REST routes register on rest_api_init (WP-correct hook for
        // register_rest_route; doc 01 §2.2 sketch used 'init').
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function activate() {
        require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/activation.php';
        Eligius_Sub_Activation::run();
    }

    public function deactivate() {
        require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/deactivation.php';
        Eligius_Sub_Deactivation::run();
    }

    public static function uninstall() {
        // Only delete data if explicitly confirmed via option
        if (get_option('eligius_sub_confirm_uninstall', false)) {
            require_once ELIGIUS_SUB_PLUGIN_DIR . 'includes/uninstall.php';
            Eligius_Sub_Uninstall::run();
            delete_option('eligius_sub_confirm_uninstall');
        }
    }

    /**
     * Load the P1 component wiring (doc 01 §2.1). All classes resolve through
     * the autoloader; each service registers its own WP hooks in its
     * constructor (singleton pattern per the doc examples).
     */
    public function load_components() {
        if (wp_installing()) {
            return; // no hooks during WP install routines
        }

        // ALL plugin timestamps are stored and compared in UTC (doc 02 §2.2
        // + audit instruction). The wpdb connection's session timezone must be
        // UTC so DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP also
        // produce UTC values; otherwise those columns store the server-local
        // time (e.g. Asia/Tehran +03:30) and break every UTC comparison.
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->query("SET time_zone = '+00:00'");
        }

        // Services (hook themselves on construction).
        \Eligius_Sub\Security\Class_Audit_Logger::instance();
        \Eligius_Sub\Security\Class_Rate_Limiter::instance();
        \Eligius_Sub\Auth\Class_Auth_Integration::instance();
        \Eligius_Sub\License\Class_License_Manager::instance();
        \Eligius_Sub\License\Class_License_Issuer::instance();
        \Eligius_Sub\Auth\Class_Device_Auth::instance();
        \Eligius_Sub\License\Class_License_Validator::instance();
        \Eligius_Sub\License\Class_Trial_Manager::instance();
        \Eligius_Sub\License\Class_Device_Transfer::instance();
        \Eligius_Sub\Cron\Class_Cron_Manager::instance();

        // REST controllers (hook rest_api_init) + X-RateLimit-* headers on dispatch.
        \Eligius_Sub\Api\Class_Api_Controller::boot_rate_headers();
        \Eligius_Sub\Api\Class_License_Controller::instance();
        \Eligius_Sub\Api\Class_Device_Controller::instance();

        // Admin pages (hook admin_menu via Class_Admin_Menu).
        \Eligius_Sub\Admin\Class_Admin_Menu::instance();
    }

    /**
     * REST routes are registered by the controllers on rest_api_init
     * (see load_components); kept as the doc §2.2 hook target.
     */
    public function register_rest_routes() {
        // Handled by the controllers' own rest_api_init hooks.
    }

    /**
     * Admin menu is registered by Class_Admin_Menu on admin_menu
     * (see load_components); kept as the doc §2.2 hook target.
     */
    public function register_admin_menu() {
        // Handled by Class_Admin_Menu.
    }

    /**
     * P1 skeleton pages use inline styling; asset files arrive with the
     * dedicated admin UI pass (assets/css/admin.css, assets/js/admin.js).
     */
    public function enqueue_admin_assets() {
        // Phase 1: no dedicated asset files yet.
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'eligius-subscription',
            false,
            dirname(plugin_basename(ELIGIUS_SUB_PLUGIN_FILE)) . '/languages'
        );
    }
}
