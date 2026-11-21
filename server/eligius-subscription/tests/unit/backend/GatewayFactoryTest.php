<?php
/**
 * P6 — Gateway Factory decision-logic coverage: option-driven adapter
 * construction, the PRODUCTION_LOCKED hard lock, and merchant resolution.
 * Options are shimmed (no WordPress/DB); NO gateway HTTP occurs.
 */
if (!function_exists('get_option')) {
    $GLOBALS['__p6_options'] = array();
    function get_option($name, $default = false) { return $GLOBALS['__p6_options'][$name] ?? $default; }
    function update_option($name, $value, $autoload = null) { $GLOBALS['__p6_options'][$name] = $value; return true; }
}
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require_once __DIR__ . '/../../../includes/payment/interface-gateway-adapter.php';
require_once __DIR__ . '/../../../includes/payment/gateways/class-zarinpal-adapter.php';
require_once __DIR__ . '/../../../includes/payment/class-gateway-factory.php';

use Eligius_Sub\Payment\Class_Gateway_Factory;

final class GatewayFactoryTest extends P6UnitBase {

    public function testDisabledGatewayReturnsNull() {
        $GLOBALS['__p6_options'] = array('eligius_zarinpal_enabled' => false);
        $this->check('disabled gateway → null', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
    }

    public function testEnabledSandboxBuildsZarinpalAdapter() {
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'sandbox',
            'eligius_zarinpal_merchant_id' => 'UNIT-MERCHANT',
        );
        $a = \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal');
        $this->check('enabled sandbox gateway → adapter instance', $a instanceof \Eligius_Sub\Payment\Gateways\Class_Zarinpal_Adapter);
        $this->check('adapter key is zarinpal', $a->get_key() === 'zarinpal');
        $this->check('callback url from rest route', strpos($a->get_callback_url(), 'payments/callback/zarinpal') !== false);
    }

    public function testProductionEnvironmentHardLocked() {
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'production',
            'eligius_zarinpal_merchant_id' => 'UNIT-MERCHANT',
        );
        $this->check('PRODUCTION_LOCKED → null even with full settings', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
    }

    public function testMissingMerchantReturnsNull() {
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'sandbox',
        );
        $this->check('enabled + no merchant → null', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
    }

    public function testUnknownGatewayReturnsNull() {
        $GLOBALS['__p6_options'] = array();
        $this->check('unknown gateway key → null', \Eligius_Sub\Payment\Class_Gateway_Factory::create('idpay') === null);
    }

    public function testMerchantOptionResolved() {
        $GLOBALS['__p6_options'] = array('eligius_zarinpal_merchant_id' => 'option-merchant');
        $ref = new ReflectionMethod(\Eligius_Sub\Payment\Class_Gateway_Factory::class, 'merchant_id');
        $ref->setAccessible(true);
        $this->check('merchant option fallback resolved', $ref->invoke(null, 'zarinpal') === 'option-merchant');
    }

    public function testSandboxEnabledDefaultsTrue() {
        $GLOBALS['__p6_options'] = array();
        $ref = new ReflectionMethod(\Eligius_Sub\Payment\Class_Gateway_Factory::class, 'sandbox_enabled');
        $ref->setAccessible(true);
        $this->check('sandbox defaults true when unset', $ref->invoke(null, 'zarinpal') === true);
    }

    // ---- A-3: the wp-config constant branch (merchant_id lines 27-30) -------
    // PHP constants cannot be undefined, so the ELIGIUS_ZARINPAL_MERCHANT_ID
    // branch is proven in a CHILD php process (same pattern as the
    // cross-process rate-limiter test): defining the constant here would
    // poison every later merchant_id() call in this suite.

    /** Run a one-shot factory snippet in a child php process; returns stdout. */
    private function factory_child(string $snippet, array $paths): string {
        $script = sys_get_temp_dir() . '/p6-gf-' . getmypid() . '-' . mt_rand() . '.php';
        file_put_contents($script, $snippet);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($paths['factory'])
            . ' ' . escapeshellarg($paths['interface'])
            . ' ' . escapeshellarg($paths['adapter']);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out  = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
        $err  = is_resource($proc) ? stream_get_contents($pipes[2]) : '';
        if (is_resource($proc)) { fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); }
        unlink($script);
        if ('' !== trim((string) $err)) fwrite(STDERR, "gateway-factory child stderr: $err\n");
        return (string) $out;
    }

    public function testMerchantConstantWinsOverOption() {
        $root = realpath(__DIR__ . '/../../../includes/payment');
        $out  = $this->factory_child(<<<'CODE'
<?php
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// The admin option is SET — the constant must still win.
$GLOBALS['__o'] = array('eligius_zarinpal_merchant_id' => 'option-merchant');
function get_option($n, $d = false) { return $GLOBALS['__o'][$n] ?? $d; }
define('ELIGIUS_ZARINPAL_MERCHANT_ID', 'constant-merchant');
require $argv[1];
echo Eligius_Sub\Payment\Class_Gateway_Factory::merchant_id('zarinpal');
CODE, array('factory' => $root . '/class-gateway-factory.php', 'interface' => '', 'adapter' => ''));
        $this->check('ELIGIUS_ZARINPAL_MERCHANT_ID constant WINS over the admin option', 'constant-merchant' === trim($out));
    }

    public function testMerchantOptionUsedWhenNoConstant() {
        $root = realpath(__DIR__ . '/../../../includes/payment');
        $out  = $this->factory_child(<<<'CODE'
<?php
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
$GLOBALS['__o'] = array('eligius_zarinpal_merchant_id' => 'option-merchant');
function get_option($n, $d = false) { return $GLOBALS['__o'][$n] ?? $d; }
require $argv[1];
echo Eligius_Sub\Payment\Class_Gateway_Factory::merchant_id('zarinpal');
CODE, array('factory' => $root . '/class-gateway-factory.php', 'interface' => '', 'adapter' => ''));
        $this->check('no constant → admin option is the merchant source', 'option-merchant' === trim($out));
    }

    public function testConstantMerchantBuildsSandboxAdapterNoProduction() {
        $root = realpath(__DIR__ . '/../../../includes/payment');
        $out  = $this->factory_child(<<<'CODE'
<?php
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
$GLOBALS['__o'] = array(
    'eligius_zarinpal_enabled' => true,
    'eligius_zarinpal_environment' => 'sandbox',
);
function get_option($n, $d = false) { return $GLOBALS['__o'][$n] ?? $d; }
function apply_filters($tag, $value) { return $value; }
function rest_url($path = '') { return 'http://unit.test/wp-json/' . ltrim($path, '/'); }
define('ELIGIUS_ZARINPAL_MERCHANT_ID', 'constant-merchant');
require $argv[1]; // factory
require $argv[2]; // adapter interface
require $argv[3]; // zarinpal adapter
$a = Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal');
if (!$a instanceof Eligius_Sub\Payment\Gateways\Class_Zarinpal_Adapter) { echo 'NO-ADAPTER'; exit(0); }
$sandbox = new ReflectionProperty($a, 'sandbox');
$sandbox->setAccessible(true);
$merchant = new ReflectionProperty($a, 'merchant_id');
$merchant->setAccessible(true);
echo 'ADAPTER|', ($sandbox->getValue($a) ? 'sandbox' : 'production'), '|', $merchant->getValue($a);
CODE, array(
            'factory'   => $root . '/class-gateway-factory.php',
            'interface' => $root . '/interface-gateway-adapter.php',
            'adapter'   => $root . '/gateways/class-zarinpal-adapter.php',
        ));
        $parts = explode('|', trim($out));
        $this->check('constant merchant + enabled sandbox → adapter is built', 'ADAPTER' === $parts[0]);
        $this->check('the built adapter is SANDBOX (constant path must not imply production)', 'sandbox' === ($parts[1] ?? ''));
        $this->check('the adapter carries the CONSTANT merchant id', 'constant-merchant' === ($parts[2] ?? ''));
    }
}
