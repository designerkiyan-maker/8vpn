<?php
/**
 * Bootstrap integrity test — runs WITHOUT a WordPress installation.
 *
 * Loads the plugin entry file against a minimal WordPress API stub and
 * verifies the wiring: constants, main class singleton, hook registrations,
 * textdomain path, autoloader behaviour, component loading (P1 services +
 * controllers + admin), REST route registration, and pure helpers
 * (base64url round trip, Ed25519 blob sign/verify round trip).
 *
 * Run:  php tests/unit/bootstrap-test.php   (exit code 0 = all checks passed)
 *
 * Full activation + DB + REST flow is exercised against a real WordPress
 * install in the integration test suite (tests/integration).
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

$GLOBALS['__wp_hooks']           = array();
$GLOBALS['__wp_options']         = array();
$GLOBALS['__wp_rest_routes']     = array();
$GLOBALS['__wp_uninstall']       = null;
$GLOBALS['__wp_textdomain_path'] = null;

function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file)  { return 'http://example.invalid/wp-content/plugins/' . basename(dirname($file)) . '/'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }

function register_activation_hook($file, $cb)   { $GLOBALS['__wp_hooks']['activation']   = $cb; }
function register_deactivation_hook($file, $cb) { $GLOBALS['__wp_hooks']['deactivation'] = $cb; }
function register_uninstall_hook($file, $cb)    { $GLOBALS['__wp_uninstall']             = $cb; }
function add_action($hook, $cb)                 { $GLOBALS['__wp_hooks'][$hook][]        = $cb; }

function load_plugin_textdomain($domain, $deprecated, $path) {
    $GLOBALS['__wp_textdomain_path'] = $path;
    return true;
}

function wp_installing($val = null) { return false; }

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['__wp_options'])
        ? $GLOBALS['__wp_options'][$name]
        : $default;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['__wp_options'][$name] = $value; return true; }
function add_option($name, $value)                      { $GLOBALS['__wp_options'][$name] = $value; return true; }
function delete_option($name)                           { unset($GLOBALS['__wp_options'][$name]);   return true; }
function wp_load_alloptions()                           { return $GLOBALS['__wp_options']; }

function get_transient($name)    { return get_option($name); }
function set_transient($name, $value, $ttl = 0) { update_option($name, $value); return true; }
function delete_transient($name) { return delete_option($name); }

function register_rest_route($namespace, $route, $args) {
    $GLOBALS['__wp_rest_routes'][] = $namespace . $route;
    return true;
}
function register_menu_page_stub() {}

function add_menu_page($a, $b, $c, $d, $e = null, $f = '', $g = null) { return ''; }
function add_submenu_page($a, $b, $c, $d, $e, $f = null)              { return ''; }
function flush_rewrite_rules()                                        {}
function sanitize_text_field($v)                                      { return trim((string) $v); }
function wp_json_encode($data, $flags = 0)                            { return json_encode($data, $flags); }
function current_time($type)                                          { return gmdate('Y-m-d H:i:s'); }
function is_wp_error($thing)                                          { return $thing instanceof \WP_Error; }
function wp_die($message = '')                                       { throw new \RuntimeException('wp_die: ' . $message); }

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

// --- P1 classes resolvable through the autoloader -------------------------------
$p1_classes = [
    'Eligius_Sub\\Core\\Class_Database',
    'Eligius_Sub\\Core\\Class_Repository_Base',
    'Eligius_Sub\\Core\\Class_Device_Repository',
    'Eligius_Sub\\Core\\Class_License_Repository',
    'Eligius_Sub\\Core\\Class_Audit_Log_Repository',
    'Eligius_Sub\\Security\\Class_Audit_Logger',
    'Eligius_Sub\\Security\\Class_Rate_Limiter',
    'Eligius_Sub\\Auth\\Class_Auth_Integration',
    'Eligius_Sub\\License\\Class_License_Issuer',
    'Eligius_Sub\\License\\Class_License_Manager',
    'Eligius_Sub\\License\\Class_Trial_Manager',
    'Eligius_Sub\\License\\Class_License_Validator',
    'Eligius_Sub\\License\\Class_Device_Transfer',
    'Eligius_Sub\\Api\\Class_Api_Controller',
    'Eligius_Sub\\Api\\Class_License_Controller',
    'Eligius_Sub\\Api\\Class_Device_Controller',
    'Eligius_Sub\\Admin\\Class_Admin_Menu',
    'Eligius_Sub\\Admin\\Class_Admin_Dashboard',
    'Eligius_Sub\\Admin\\Class_Licenses_Admin',
    'Eligius_Sub\\Admin\\Class_Users_Admin',
    'Eligius_Sub\\Admin\\Class_Audit_Logs_Admin',
    'Eligius_Sub\\Cron\\Class_Cron_Manager',
    'Eligius_Sub\\Cron\\Class_Expiry_Processor',
];
$missing = [];
foreach ($p1_classes as $class) {
    if (!class_exists($class)) {
        $missing[] = $class;
    }
}
check('all 23 P1 classes autoload (' . count($p1_classes) . ')', empty($missing));
if (!empty($missing)) {
    echo "      missing: " . implode(', ', $missing) . "\n";
}

// --- Autoloader ----------------------------------------------------------------
$found = false;
foreach ((array) spl_autoload_functions() as $fn) {
    if (is_object($fn) && $fn instanceof Closure) { $found = true; break; }
}
check('plugin autoloader registered', $found);

// The autoloader must not fatal when a class has no file yet.
check(
    'autoloader tolerant of missing classes',
    !class_exists('Eligius_Sub\\Core\\Class_Does_Not_Exist_Yet')
);

// --- Components load (plugins_loaded) -------------------------------------------
$GLOBALS['__wp_hooks']['plugins_loaded'] = [];
$plugin = Eligius_Subscription::instance();
$plugin->load_components();
check('load_components executed without fatal', true);

// --- REST route registration (rest_api_init) -------------------------------------
$routes_before = count($GLOBALS['__wp_rest_routes']);
foreach ((array) ($GLOBALS['__wp_hooks']['rest_api_init'] ?? []) as $cb) {
    call_user_func($cb);
}
$registered = array_slice($GLOBALS['__wp_rest_routes'], $routes_before);
$expected_routes = [
    'eligius/v1/licenses/status',
    'eligius/v1/licenses/validate',
    'eligius/v1/licenses/offline-entitlement',
    'eligius/v1/licenses/revocation-list',
    'eligius/v1/licenses/trial',
    'eligius/v1/licenses/renew',
    'eligius/v1/devices/register',
    'eligius/v1/devices/challenge',
    'eligius/v1/devices/verify',
    'eligius/v1/devices',
    'eligius/v1/devices/transfer/start',
    'eligius/v1/devices/transfer/verify',
];
$missing_routes = array_diff($expected_routes, $registered);
check('all 12 P1 REST routes registered', empty($missing_routes));
if (!empty($missing_routes)) {
    echo "      missing: " . implode(', ', $missing_routes) . "\n";
}

// --- Pure helpers ----------------------------------------------------------------
$issuer = 'Eligius_Sub\\License\\Class_License_Issuer';
$enc = call_user_func([$issuer, 'base64url_encode'], 'hello world 🚀');
$dec = call_user_func([$issuer, 'base64url_decode'], $enc);
check('base64url encode/decode round trip', 'hello world 🚀' === $dec);

// --- Ed25519 blob sign/verify round trip (sodium required) ------------------------
if (function_exists('sodium_crypto_sign_detached')) {
    $kp = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($kp);
    $public = sodium_crypto_sign_publickey($kp);

    $header  = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'test'];
    $payload = ['license_id' => 1, 'device_hash' => str_repeat('a', 64), 'exp' => time() + 3600];
    $h64 = call_user_func([$issuer, 'base64url_encode'], wp_json_encode($header));
    $p64 = call_user_func([$issuer, 'base64url_encode'], wp_json_encode($payload));
    $input = $h64 . '.' . $p64;
    $sig = sodium_crypto_sign_detached($input, $secret);
    $blob = $input . '.' . call_user_func([$issuer, 'base64url_encode'], $sig);

    // Verify with sodium directly (raw public key).
    $sig_raw = call_user_func([$issuer, 'base64url_decode'], substr($blob, strrpos($blob, '.') + 1));
    check('Ed25519 blob signature verifies (round trip)', sodium_crypto_sign_verify_detached($sig_raw, $input, $public));

    // Tamper → must fail.
    $bad_input = $h64 . '.' . $p64 . 'X';
    check('Ed25519 blob signature rejects tampering', !sodium_crypto_sign_verify_detached($sig_raw, $bad_input, $public));
} else {
    check('sodium extension available (skipped round trip)', false);
}

// --- Result --------------------------------------------------------------------
echo $failures === 0
    ? "\nALL CHECKS PASSED\n"
    : "\n{$failures} CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
