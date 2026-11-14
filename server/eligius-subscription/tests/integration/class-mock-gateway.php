<?php
/**
 * Mock gateway for integration tests (docs: "تست‌ها را با Mock/Fixture معتبر
 * انجام بده") — implements the adapter contract with scripted results.
 * Registered via the eligius_sub_payment_gateway_instance filter; loaded ONLY
 * by test runs (never referenced by production code paths).
 */

namespace Eligius_Sub\Payment\Gateways;

use Eligius_Sub\Payment\Interface_Gateway_Adapter;

if (!class_exists('\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway')) {

class Class_Mock_Gateway implements Interface_Gateway_Adapter {

    /** Scripted outcome for the next verify call. */
    public static $verifyResult = ['verified' => true, 'amount' => 0, 'ref_id' => 'MOCK-REF-1', 'error' => null];
    public static $initiateError = null;
    public static $verifyCalls = 0;

    public static function reset($verify = true, $amount = 0, $ref = 'MOCK-REF-1') {
        self::$verifyResult = ['verified' => $verify, 'amount' => $amount, 'ref_id' => $ref, 'error' => $verify ? null : 'MOCK_REJECT'];
        self::$initiateError = null;
        self::$verifyCalls = 0;
    }

    public function get_key(): string { return 'mock'; }
    public function get_name(): string { return 'Mock Gateway'; }
    public function get_callback_url(): string { return 'http://example.invalid/callback'; }
    public function supports_refund(): bool { return false; } // mirrors the owner-disabled ZarinPal refund

    public function initiate_payment(array $params): array {
        if (self::$initiateError) {
            throw new \Exception('Mock initiate failed: ' . self::$initiateError);
        }
        $authority = 'MOCK-AUTH-' . strtoupper(bin2hex(random_bytes(6)));
        return ['authority' => $authority, 'redirect_url' => 'https://example.invalid/pay/' . $authority];
    }

    public function verify_payment(array $callback, int $expected_amount_irt): array {
        self::$verifyCalls++;
        // Mirror the real adapter: Status !== 'OK' → verified=false (docs 07
        // §P4 "Gateway reported a cancelled/failed payment").
        $status = (string) ($callback['status'] ?? $callback['Status'] ?? '');
        if ('OK' !== $status) {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '', 'error' => 'Gateway reported a cancelled/failed payment'];
        }
        $result = self::$verifyResult;
        // The adapter owns the IRT→IRR conversion (TD-2) — the mock mirrors
        // the real adapter: gateway unit = expected_irt * 10, UNLESS the test
        // scripted an explicit amount (mismatch scenarios).
        if (0 === (int) ($result['amount'] ?? 0)) {
            $result['amount'] = $expected_amount_irt * 10;
        }
        // Mirror the real adapter's amount check (ZarinPal adapter compares
        // in IRR): scripted-amount != expected IRR → verified=false.
        if ($result['verified'] && (int) $result['amount'] !== $expected_amount_irt * 10) {
            $result['verified'] = false;
            $result['error'] = 'AMOUNT_MISMATCH';
        }
        return $result;
    }

    public function refund(string $gateway_ref, int $amount_irt): array {
        unset($gateway_ref, $amount_irt);
        return ['refunded' => false, 'error' => 'REFUND_NOT_SUPPORTED']; // no network request
    }
}

}
