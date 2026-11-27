<?php
/**
 * Phase 6 — zero-dependency backend unit-test runner (owner constraint:
 * "از اضافه‌کردن Dependencyهای سنگین و غیرضروری خودداری کن").
 *
 * Runs every *Test.php in this directory. Test classes extend P6UnitBase and
 * declare public methods starting with `test`. Exit code is non-zero on any
 * failure (CI-ready).
 *
 * NO WordPress and NO database is loaded: WordPress functions used by the
 * classes under test are shimmed in this bootstrap (wp_json_encode and the
 * ZarinPal HTTP layer), so the gateway/result logic runs as pure PHP with a
 * canned-response stub — clearly a MOCK, never a real gateway call (H-2).
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/'); // plugin files guard: defined('ABSPATH') || exit

// Mirror the WordPress runtime: wp-settings.php forces PHP timezone to UTC,
// so the plugin's date math (Class_UTC_Time) assumes UTC. Without this, the
// host php.ini timezone (e.g. Asia/Tehran) would skew strtotime()-based tests.
date_default_timezone_set('UTC');

// ---- Minimal WordPress shims (unit scope only) -------------------------------
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function apply_filters($tag, $value) { return $value; } // test shim: no filter hooks in unit scope
function rest_url($path = '') { return 'http://unit.test/wp-json/' . ltrim($path, '/'); }

/** Minimal WP_Error stand-in (unit scope) — matches the shape the plugin uses. */
class WP_Error {
    public array $errors = [];
    public array $error_data = [];
    public function __construct($code = '', $message = '', $data = []) {
        if ('' !== $code) { $this->errors[$code] = $message; }
        if ('' !== $code && is_array($data)) { $this->error_data[$code] = $data; } // WP semantics: keyed by code
    }
    public function get_error_code() { return (string) (array_key_first($this->errors) ?? ''); }
    public function get_error_message($code = '') { return $this->errors[$code ?: $this->get_error_code()] ?? ''; }
    public function get_error_data($code = '') { return $this->error_data[$code ?: $this->get_error_code()] ?? null; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

/**
 * HTTP stub: tests register canned responses via ZarinpalHttpStub::set().
 * The adapter under test calls wp_remote_post(); this shim returns the
 * canned array verbatim — no network is touched.
 */
class ZarinpalHttpStub {
    public static $responses = []; // url => array|WP_Error-like
    public static $calls = [];
    public static function set($urlFragment, $response) { self::$responses[$urlFragment] = $response; }
    public static function reset() { self::$responses = []; self::$calls = []; }
}
function wp_remote_post($url, $args = []) {
    ZarinpalHttpStub::$calls[] = $url;
    foreach (ZarinpalHttpStub::$responses as $frag => $resp) {
        if (false !== strpos($url, $frag)) return $resp;
    }
    return ['body' => '{}'];
}
function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }

/**
 * Transient shim with REAL TTL + single-use-friendly semantics (A-2):
 * expiration is stored per key (`now + ttl`) and enforced on read — a key
 * whose TTL has elapsed returns false AND is removed, exactly like the WP
 * object-cache/runtime transient store. `$GLOBALS['__p6_clock']` lets tests
 * travel forward in time to prove the 60s challenge TTL genuinely expires
 * (no fabricated "expired" fixtures).
 */
$GLOBALS['__p6_transients'] = [];
$GLOBALS['__p6_clock'] = null; // null → real time(); int → simulated clock
if (!function_exists('set_transient')) {
    function __p6_now() { return $GLOBALS['__p6_clock'] ?? time(); }
    function set_transient($transient, $value, $expiration = 0) {
        $GLOBALS['__p6_transients'][$transient] = [$value, __p6_now() + (int) $expiration];
        return true;
    }
    function get_transient($transient) {
        if (!array_key_exists($transient, $GLOBALS['__p6_transients'])) return false;
        [$value, $expires_at] = $GLOBALS['__p6_transients'][$transient];
        if (__p6_now() >= $expires_at) { // genuinely expired → gone
            unset($GLOBALS['__p6_transients'][$transient]);
            return false;
        }
        return $value;
    }
    function delete_transient($transient) {
        unset($GLOBALS['__p6_transients'][$transient]);
        return true;
    }
}

// ---- Runner -------------------------------------------------------------------
class P6Runner {
    public static int $pass = 0;
    public static int $fail = 0;
    public static function check(bool $cond, string $label): void {
        if ($cond) { self::$pass++; echo "PASS  $label\n"; }
        else { self::$fail++; echo "FAIL  $label\n"; }
    }
}

abstract class P6UnitBase {
    protected function check(string $label, bool $cond): void { P6Runner::check($cond, $label); }
}

$pluginRoot = dirname(__DIR__, 3); // .../eligius-subscription
require $pluginRoot . '/includes/core/class-db-error.php';
require $pluginRoot . '/includes/core/class-utc-time.php';
require $pluginRoot . '/includes/payment/interface-gateway-adapter.php';
require $pluginRoot . '/includes/payment/gateways/class-zarinpal-adapter.php';
require $pluginRoot . '/includes/auth/class-device-auth.php';
require $pluginRoot . '/includes/core/class-volume.php';

foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    require $testFile;
    $class = basename($testFile, '.php');
    if (!class_exists($class) || !is_subclass_of($class, P6UnitBase::class)) continue;
    echo "--- $class ---\n";
    $instance = new $class();
    foreach (get_class_methods($class) as $method) {
        if (0 !== strpos($method, 'test')) continue;
        $instance->$method();
    }
}

echo "\nRESULT: " . P6Runner::$pass . " passed, " . P6Runner::$fail . " failed\n";
exit(P6Runner::$fail === 0 ? 0 : 1);
