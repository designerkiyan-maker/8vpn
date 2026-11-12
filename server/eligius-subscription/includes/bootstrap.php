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
        $this->init_database();
        $this->load_textdomain();
    }

    private function init_hooks() {
        register_activation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(ELIGIUS_SUB_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(ELIGIUS_SUB_PLUGIN_FILE, [__CLASS__, 'uninstall']);

        add_action('plugins_loaded', [$this, 'load_components']);
        add_action('init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * P1: compare get_option('eligius_sub_db_version') with ELIGIUS_SUB_DB_VERSION
     * and run schema migrations (doc 02-DATABASE-DESIGN.md §2).
     *
     * Phase 0: no database schema work is authorized.
     */
    private function init_database() {
        // Phase 0: intentionally empty.
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
     * P1: load core classes (repositories, managers, controllers per doc 01 §2.1).
     */
    public function load_components() {
        // Phase 0: no components exist yet.
    }

    /**
     * P1: register REST API endpoints (doc 03-API-SPECIFICATION.md).
     */
    public function register_rest_routes() {
        // Phase 0: no operational endpoints are authorized.
    }

    /**
     * P1: register wp-admin menu (doc 08-ADMIN-PANEL-SPECIFICATION.md).
     */
    public function register_admin_menu() {
        // Phase 0: no admin pages yet.
    }

    /**
     * P1: enqueue admin assets (assets/css/admin.css, assets/js/admin.js).
     */
    public function enqueue_admin_assets() {
        // Phase 0: no assets exist yet.
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'eligius-subscription',
            false,
            dirname(plugin_basename(ELIGIUS_SUB_PLUGIN_FILE)) . '/languages'
        );
    }
}
