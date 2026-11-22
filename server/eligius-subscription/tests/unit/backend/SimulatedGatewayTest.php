<?php
/**
 * P8.11 — Simulated gateway unit tests (sandbox mode, owner decision).
 *
 * The simulated gateway lets the full payment flow complete WITHOUT the
 * deprecated ZarinPal sandbox payment page. Proven here:
 *   • initiate: SIM-prefixed authority + redirect to OUR callback with
 *     Authority/Status=OK;
 *   • verify: Status=OK + SIM authority → verified, IRT→IRR conversion
 *     (TD-2 mirrored), ref_id SIMULATED-…;
 *   • Status≠OK → verified=false (mirrors the real adapter);
 *   • the sandbox-mode gate: with the environment flipped to production the
 *     simulated gateway REFUSES (defense in depth);
 *   • refund disabled.
 * NOTE: get_option is shimmed by GatewayFactoryTest.php (loads earlier in the
 * alphabetical run order) reading $GLOBALS['__p6_options'].
 */

require_once __DIR__ . '/../../../includes/payment/gateways/class-simulated-gateway.php';

final class SimulatedGatewayTest extends P6UnitBase {

    private $gw;

    public function __construct() {
        $this->gw = new \Eligius_Sub\Payment\Gateways\Class_Simulated_Gateway(
            'http://unit.test/wp-json/eligius/v1/payments/callback/zarinpal'
        );
        $GLOBALS['__p6_options'] = array('eligius_zarinpal_environment' => 'sandbox');
    }

    public function testInitiateIssuesSimAuthorityAndCallbackRedirect() {
        $r = $this->gw->initiate_payment(array('amount_irt' => 89000, 'plan_key' => 'monthly'));
        $this->check('initiate → authority starts with SIM', 0 === strpos($r['authority'], 'SIM'));
        $this->check('initiate → redirect_url is OUR callback with Authority+Status=OK',
            false !== strpos($r['redirect_url'], 'payments/callback/zarinpal')
            && false !== strpos($r['redirect_url'], 'Authority=' . $r['authority'])
            && false !== strpos($r['redirect_url'], 'Status=OK'));
    }

    public function testVerifyAcceptsOwnSimAuthority() {
        $r = $this->gw->initiate_payment(array('amount_irt' => 89000));
        $v = $this->gw->verify_payment(
            array('Authority' => $r['authority'], 'Status' => 'OK'), 89000);
        $this->check('verify → verified=true for the SIM authority', true === $v['verified']);
        $this->check('verify → amount mirrored to IRR (IRT×10)', 890000 === $v['amount']);
        $this->check('verify → ref_id is SIMULATED-…', 0 === strpos((string) $v['ref_id'], 'SIMULATED-'));
    }

    public function testVerifyRejectsNonOkStatus() {
        $r = $this->gw->initiate_payment(array('amount_irt' => 89000));
        $v = $this->gw->verify_payment(
            array('Authority' => $r['authority'], 'Status' => 'NOK'), 89000);
        $this->check('verify → Status=NOK → verified=false', false === $v['verified']);
    }

    public function testVerifyRejectsForeignAuthority() {
        // An authority NOT issued by this gateway (no SIM prefix) → rejected.
        $v = $this->gw->verify_payment(
            array('Authority' => 'S000000000000000000000000000000pgeqg', 'Status' => 'OK'), 89000);
        $this->check('verify → foreign (non-SIM) authority → verified=false', false === $v['verified']);
    }

    public function testProductionEnvDisablesSimulatedGateway() {
        // Defense in depth: with the environment flipped to production, the
        // simulated gateway refuses to issue or verify (defense against
        // flipping the env mid-flight while SIM payments are pending).
        $GLOBALS['__p6_options']['eligius_zarinpal_environment'] = 'production';
        $init = null;
        try { $this->gw->initiate_payment(array('amount_irt' => 89000)); }
        catch (\Throwable $e) { $init = $e->getMessage(); }
        $this->check('production env → initiate throws (sandbox-only)',
            is_string($init) && false !== strpos($init, 'sandbox mode'));
        $v = $this->gw->verify_payment(array('Authority' => 'SIMDEADBEEF', 'Status' => 'OK'), 89000);
        $this->check('production env → verify refuses', false === $v['verified']);
        $GLOBALS['__p6_options']['eligius_zarinpal_environment'] = 'sandbox';
    }
}
