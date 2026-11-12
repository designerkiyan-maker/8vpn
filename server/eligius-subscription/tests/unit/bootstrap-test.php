<?php
/**
 * Bootstrap integrity test — runs WITHOUT a WordPress installation.
 *
 * Loads the plugin entry file against a minimal WordPress API stub and
 * verifies the Phase 0 wiring: constants, main class singleton, hook
 * registrations, textdomain path, autoloader behaviour, and a fatal-free
 * activation/deactivation/uninstall pass.
 *
 * Run:  php tests/unit/bootstrap-test.php   (exit code 0 = all checks passed)
 *
 * This is the local/CI equivalent of the Phase 0 acceptance criterion
 * "WordPress plugin activates without fatal errors"; a full wp-cli activation
 * test against a real WordPress install is run separately in the dev
 * environment.
 */

error_reporting(E_ALL);

$failures = 0;

function check($label, $condition) {
    global $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
    }
}

// --- Minimal WordPress API stub --------------------------------------------
define('ABSPATH', sys_get_temp_dir() . '/eligius-sub-test' . DIRECTORY_SEPARATOR);

$GLOBALS['__wp_hooks']         = array();
$GLOBALS['__wp_options']       = array();
$GLOBALS['__wp_uninstall']     = null;
$GLOBALS['__wp_textdomain_path'] = null;

function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file)  { return 'http://example.invalid/wp-content/plugins/' . basename(dirname($file)) . '/'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }

function register_activation_hook($file, $cb)   { $GLOBALS['__wp_hooks']['activation']   = $cb; }
function register_deactivation_hook($file, $cb) { $GLOBALS['__wp_hooks']['deactivation'] = $cb; }
function register_uninstall_hook($file, $cb)    { $GLOBALS['__wp_uninstall']             = $cb; }
function add_action($hook, $cb)                 { $GLOBALS['__wp_hooks'][$hook]          = $cb; }

function load_plugin_textdomain($domain, $deprecated, $path) {
    $GLOBALS['__wp_textdomain_path'] = $path;
    return true;
}

function flush_rewrite_rules() {}

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['__wp_options'])
        ? $GLOBALS['__wp_options'][$name]
        : $default;
}
function add_option($name, $value) { $GLOBALS['__wp_options'][$name] = $value; return true; }
function delete_option($name)      { unset($GLOBALS['__wp_options'][$name]);    return true; }

function deactivate_plugins($plugin) { $GLOBALS['__wp_deactivated'] = $plugin; }

// --- Load the plugin ---------------------------------------------------------
require dirname(__DIR__, 2) . '/eligius-subscription.php';

// --- Constants ----------------------------------------------------------------
check(
    'plugin constants defined',
    defined('ELIGIUS_SUB_VERSION')
        && defined('ELIGIUS_SUB_DB_VERSION')
        && defined('ELIGIUS_SUB_PLUGIN_FILE')
        && defined('ELIGIUS_SUB_PLUGIN_DIR')
        && defined('ELIGIUS_SUB_PLUGIN_URL')
);

// --- Main class & singleton ----------------------------------------------------
check('main class loaded', class_exists('Eligius_Subscription'));
check('singleton returns same instance', Eligius_Subscription::instance() === Eligius_Subscription::instance());

// --- Hook registrations --------------------------------------------------------
$hooks = $GLOBALS['__wp_hooks'];
check('activation hook registered',       isset($hooks['activation']));
check('deactivation hook registered',     isset($hooks['deactivation']));
check('uninstall hook registered',        $GLOBALS['__wp_uninstall'] !== null);
check('plugins_loaded hook registered',   isset($hooks['plugins_loaded']));
check('init (REST routes) registered',    isset($hooks['init']));
check('admin_menu hook registered',       isset($hooks['admin_menu']));
check('admin_enqueue_scripts registered', isset($hooks['admin_enqueue_scripts']));

check(
    'textdomain path points at plugin languages dir',
    is_string($GLOBALS['__wp_textdomain_path'])
        && strpos($GLOBALS['__wp_textdomain_path'], 'eligius-subscription/languages') !== false
);

// --- Autoloader ----------------------------------------------------------------
$found = false;
foreach ((array) spl_autoload_functions() as $fn) {
    if (is_object($fn) && $fn instanceof Closure) { $found = true; break; }
}
check('plugin autoloader registered', $found);

// The autoloader must not fatal when a P1 class has no file yet.
check(
    'autoloader tolerant of missing P1 classes',
    !class_exists('Eligius_Sub\\Core\\Class_Does_Not_Exist_Yet')
);

// --- Activation / deactivation / uninstall pass --------------------------------
$hooks = $GLOBALS['__wp_hooks'];
call_user_func($hooks['activation']);
check('activation runs without fatal', true);

call_user_func($hooks['deactivation']);
check('deactivation runs without fatal', true);

call_user_func($GLOBALS['__wp_uninstall']); // no confirm option → guarded no-op path
check(
    'uninstall guard no-ops without confirm option',
    !array_key_exists('eligius_sub_confirm_uninstall', $GLOBALS['__wp_options'])
);

// --- Result --------------------------------------------------------------------
echo $failures === 0
    ? "\nALL CHECKS PASSED\n"
    : "\n{$failures} CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
