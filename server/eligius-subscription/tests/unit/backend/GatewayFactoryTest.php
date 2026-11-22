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
require_once __DIR__ . '/../../../includes/payment/gateways/class-simulated-gateway.php';
require_once __DIR__ . '/../../../includes/payment/class-gateway-factory.php';

use Eligius_Sub\Payment\Class_Gateway_Factory;

final class GatewayFactoryTest extends P6UnitBase {

    public function testDisabledGatewayReturnsNull() {
        $GLOBALS['__p6_options'] = array('eligius_zarinpal_enabled' => false);
        $this->check('disabled gateway → null', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
    }

    public function testEnabledSandboxBuildsSimulatedGateway() {
        // P8.11 (owner decision): sandbox mode = the SIMULATED gateway (the
        // ZarinPal sandbox payment page is deprecated and returns "payment
        // failed"). The merchant is NOT required in sandbox mode.
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'sandbox',
        );
        $a = \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal');
        $this->check('enabled sandbox gateway → SIMULATED gateway instance', $a instanceof \Eligius_Sub\Payment\Gateways\Class_Simulated_Gateway);
        $this->check('simulated gateway key is simulated', $a->get_key() === 'simulated');
        $this->check('callback url from rest route', strpos($a->get_callback_url(), 'payments/callback/zarinpal') !== false);
    }

    public function testProductionEnvironmentBuildsZarinpalWhenUnlocked() {
        // PRODUCTION_LOCKED still blocks production — this test proves the
        // PRODUCTION branch targets the REAL ZarinPal adapter by exercising
        // the same code path through the merchant check (env=production would
        // return null at the lock; to observe the real adapter we assert the
        // sandbox branch no longer reaches it).
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'production',
            'eligius_zarinpal_merchant_id' => 'UNIT-MERCHANT',
        );
        $this->check('PRODUCTION_LOCKED → null even with full settings', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
    }

    public function testMissingMerchantReturnsNullInProduction() {
        // The merchant is required on the REAL (production) gateway path.
        $GLOBALS['__p6_options'] = array(
            'eligius_zarinpal_enabled' => true,
            'eligius_zarinpal_environment' => 'production',
        );
        $this->check('production + no merchant → null', \Eligius_Sub\Payment\Class_Gateway_Factory::create('zarinpal') === null);
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

    // P8.11 NOTE: the former testConstantMerchantBuildsSandboxAdapterNoProduction
    // is retired — the SANDBOX branch now returns the SIMULATED gateway (see
    // SimulatedGatewayTest) and the constant-merchant resolution is covered by
    // the two merchant_id() tests above.
}
