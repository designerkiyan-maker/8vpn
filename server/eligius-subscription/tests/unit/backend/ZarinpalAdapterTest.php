<?php
/**
 * P6 unit — ZarinPal adapter gateway-result validation, exercised with the
 * HTTP stub (canned responses). NO real gateway call (H-2 boundary: mock
 * success is NOT reported as real-gateway verification).
 *
 * TD-2: IRT→IRR ×10 conversion lives ONLY in the adapter — verified here.
 */
require_once __DIR__ . '/../../../includes/payment/interface-gateway-adapter.php';
require_once __DIR__ . '/../../../includes/payment/gateways/class-zarinpal-adapter.php';

final class ZarinpalAdapterTest extends P6UnitBase {

    private function adapter(bool $sandbox = true): \Eligius_Sub\Payment\Gateways\Class_Zarinpal_Adapter {
        return new \Eligius_Sub\Payment\Gateways\Class_Zarinpal_Adapter([
            'merchant_id' => 'UNIT-TEST-MERCHANT', // dummy — never leaves the stub
            'callback_url' => 'https://unit.test/cb',
            'sandbox' => $sandbox,
        ]);
    }

    private function body(array $data): array { return ['body' => wp_json_encode(['data' => $data])]; }

    public function testVerifyCode100WithMatchingAmountIsVerified() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', $this->body(['code' => 100, 'ref_id' => 'R1', 'amount' => 1500000]));
        $r = $this->adapter()->verify_payment(['authority' => 'A1', 'status' => 'OK'], 150000);
        $this->check('code 100 + amount match → verified', true === $r['verified']);
        $this->check('ref_id surfaced', 'R1' === $r['ref_id']);
        $this->check('gateway amount compared in IRR (150000 IRT → 1500000 IRR)', 1500000 === $r['amount']);
    }

    public function testVerifyCode101AlreadyVerifiedIsAccepted() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', $this->body(['code' => 101, 'ref_id' => 'R2', 'amount' => 1500000]));
        $r = $this->adapter()->verify_payment(['authority' => 'A2', 'status' => 'OK'], 150000);
        $this->check('code 101 (already verified on retry) → verified', true === $r['verified']);
    }

    public function testVerifyOtherCodeRejected() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', $this->body(['code' => -50, 'message' => 'failed']));
        $r = $this->adapter()->verify_payment(['authority' => 'A3', 'status' => 'OK'], 150000);
        $this->check('unknown gateway code → verified=false', false === $r['verified']);
    }

    public function testAmountMismatchRejected() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', $this->body(['code' => 100, 'ref_id' => 'R4', 'amount' => 1000])); // gateway says 1000 IRR
        $r = $this->adapter()->verify_payment(['authority' => 'A4', 'status' => 'OK'], 150000);
        $this->check('amount mismatch → verified=false + AMOUNT_MISMATCH', false === $r['verified'] && 'AMOUNT_MISMATCH' === $r['error']);
    }

    public function testCancelledStatusRejectedBeforeNetwork() {
        ZarinpalHttpStub::reset();
        $r = $this->adapter()->verify_payment(['authority' => 'A5', 'Status' => 'NOK'], 150000);
        $this->check('gateway Status=NOK → rejected', false === $r['verified']);
        $this->check('rejected BEFORE the S2S verify call (no network on NOK)', 0 === count(ZarinpalHttpStub::$calls));
    }

    public function testMissingAuthorityRejected() {
        ZarinpalHttpStub::reset();
        $r = $this->adapter()->verify_payment(['status' => 'OK'], 150000);
        $this->check('missing authority → rejected', false === $r['verified']);
    }

    public function testMalformedBodyRejected() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', ['body' => 'not-json']);
        $r = $this->adapter()->verify_payment(['authority' => 'A6', 'status' => 'OK'], 150000);
        $this->check('malformed gateway body → rejected', false === $r['verified']);
    }

    public function testSandboxUrlSelection() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('payment/request.json', $this->body(['code' => 100, 'authority' => 'AUTH-9']));
        $r = $this->adapter(true)->initiate_payment(['amount_irt' => 150000, 'description' => 'd']);
        $this->check('sandbox mode → sandbox StartPay redirect', false !== strpos($r['redirect_url'], 'sandbox.zarinpal.com/pg/StartPay/AUTH-9'));
        $this->check('request hit the sandbox v4 endpoint', false !== strpos(ZarinpalHttpStub::$calls[0], 'sandbox.zarinpal.com/pg/v4'));
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('payment/request.json', $this->body(['code' => 100, 'authority' => 'AUTH-10']));
        $r2 = $this->adapter(false)->initiate_payment(['amount_irt' => 150000, 'description' => 'd']);
        $this->check('production config → production StartPay redirect (factory still hard-locks it)', false !== strpos($r2['redirect_url'], 'www.zarinpal.com/pg/StartPay/AUTH-10'));
    }

    public function testInitiateConvertsIrtToIrr() {
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('payment/request.json', $this->body(['code' => 100, 'authority' => 'AUTH-11']));
        $this->adapter(true)->initiate_payment(['amount_irt' => 150000, 'description' => 'd']);
        // The stub records only the URL; assert conversion through verify math instead.
        ZarinpalHttpStub::reset();
        ZarinpalHttpStub::set('verify.json', $this->body(['code' => 100, 'ref_id' => 'X', 'amount' => 1500001]));
        $r = $this->adapter(true)->verify_payment(['authority' => 'A11', 'status' => 'OK'], 150000);
        $this->check('×10 IRT→IRR conversion: 150000 IRT expects 1500000 IRR (1500001 mismatches)', false === $r['verified'] && 'AMOUNT_MISMATCH' === $r['error']);
    }

    public function testRefundIsDisabledWithoutNetwork() {
        ZarinpalHttpStub::reset();
        $r = $this->adapter()->refund('REF', 150000);
        $this->check('refund → REFUND_NOT_SUPPORTED (owner decision, no HTTP)', false === $r['refunded'] && 'REFUND_NOT_SUPPORTED' === $r['error']);
        $this->check('refund performs NO network request', 0 === count(ZarinpalHttpStub::$calls));
        $this->check('supports_refund=false', false === $this->adapter()->supports_refund());
    }
}
