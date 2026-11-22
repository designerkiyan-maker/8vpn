<?php
/**
 * SIMULATED gateway for SANDBOX mode (Phase 8.11 — owner decision).
 *
 * WHY: ZarinPal's sandbox PAYMENT PAGE is deprecated and returns "پرداخت
 * ناموفق" even for valid authorities (its request/verify JSON APIs still
 * work). In sandbox mode the factory now returns THIS gateway so the full
 * payment flow completes without real money and without ZarinPal's page:
 *
 *   initiate_payment → authority "SIM…" + redirect_url = OUR callback with
 *                      Authority + Status=OK (the app opens it; the callback
 *                      verifies the SIM authority server-side)
 *   verify_payment   → accepts Status=OK + an authority that starts with
 *                      "SIM" (issued by this same gateway), mirrored amount
 *                      conversion IRT→IRR like the real adapter
 *
 * SECURITY (stated explicitly for the owner): while sandbox mode is enabled
 * on a live host, ANY registered user can complete a payment without paying
 * (the simulated gateway accepts its own authorities). Enable sandbox ONLY
 * while testing. Production mode (PRODUCTION_LOCKED + real merchant) never
 * returns this gateway, and a SIM authority is rejected by the real ZarinPal
 * verify endpoint (fail-closed).
 *
 * Defense in depth: every money-moving method re-checks that the environment
 * option is still 'sandbox' — flipping the environment mid-flight invalidates
 * pending SIM authorities.
 */

namespace Eligius_Sub\Payment\Gateways;

use Eligius_Sub\Payment\Interface_Gateway_Adapter;

if (!class_exists('\Eligius_Sub\Payment\Gateways\Class_Simulated_Gateway')) {

class Class_Simulated_Gateway implements Interface_Gateway_Adapter {

    private $callback_url;

    public function __construct(string $callback_url) {
        $this->callback_url = (string) $callback_url;
    }

    public function get_key(): string { return 'simulated'; }
    public function get_name(): string { return 'Simulated (Sandbox)'; }
    public function get_callback_url(): string { return $this->callback_url; }
    public function supports_refund(): bool { return false; }

    /** Sandbox-mode gate — true only while the environment stays sandbox. */
    private function sandbox_active(): bool {
        return 'sandbox' === (string) get_option('eligius_zarinpal_environment', 'sandbox');
    }

    public function initiate_payment(array $params): array {
        if (!$this->sandbox_active()) {
            throw new \Exception('Simulated gateway is only available in sandbox mode.');
        }
        // "SIM" prefix: clearly distinguishable from real ZarinPal authorities
        // (which never start with SIM) — and rejected by the real gateway.
        $authority = 'SIM' . strtoupper(bin2hex(random_bytes(10)));
        $sep = (false === strpos($this->callback_url, '?')) ? '?' : '&';
        return [
            'authority'    => $authority,
            'redirect_url' => $this->callback_url . $sep . 'Authority=' . urlencode($authority) . '&Status=OK',
        ];
    }

    public function verify_payment(array $callback, int $expected_amount_irt): array {
        if (!$this->sandbox_active()) {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '',
                    'error' => 'Simulated gateway is only available in sandbox mode'];
        }
        $authority = (string) ($callback['authority'] ?? $callback['Authority'] ?? '');
        $status    = (string) ($callback['Status'] ?? '');
        if ('OK' !== $status || 0 !== strpos($authority, 'SIM')) {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '',
                    'error' => 'Gateway reported a cancelled/failed payment'];
        }
        // Mirror the real adapter's unit contract (TD-2): gateway unit = IRR
        // (expected IRT × 10) so the processor's amount comparison passes.
        return [
            'verified' => true,
            'amount'   => $expected_amount_irt * 10,
            'ref_id'   => 'SIMULATED-' . $authority,
            'error'    => null,
        ];
    }

    public function refund(string $gateway_ref, int $amount_irt): array {
        unset($gateway_ref, $amount_irt);
        return ['refunded' => false, 'error' => 'REFUND_NOT_SUPPORTED'];
    }
}

}
