<?php
/**
 * ZarinPal adapter (CD-3 reference implementation, Roadmap P4).
 *
 * TD-2 (owner decision): amounts arrive as IRT (Toman); the adapter converts
 * to IRR (×10) for every gateway call and comparison. Conversion lives ONLY
 * here.
 *
 * TD-6 (owner decision): Sandbox-first. base_url is config-driven
 * (sandbox.zarinpal.com vs api.zarinpal.com); the factory refuses to build a
 * production adapter while production payments are locked (see
 * Class_Gateway_Factory::PRODUCTION_LOCKED).
 *
 * Secrets: merchant ID comes from the ELIGIUS_ZARINPAL_MERCHANT_ID wp-config
 * constant or the eligius_zarinpal_merchant_id option — never hardcoded,
 * never logged, never echoed.
 */

namespace Eligius_Sub\Payment\Gateways;

defined('ABSPATH') || exit;

use Eligius_Sub\Payment\Interface_Gateway_Adapter;

class Class_Zarinpal_Adapter implements Interface_Gateway_Adapter {

    private $merchant_id;
    private $callback_url;
    private $sandbox;
    private $base_url;

    public function __construct($config) {
        $this->merchant_id = (string) ($config['merchant_id'] ?? '');
        $this->callback_url = (string) ($config['callback_url'] ?? '');
        $this->sandbox = !empty($config['sandbox']);
        $this->base_url = $this->sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4'
            : 'https://api.zarinpal.com/pg/v4';
    }

    public function get_key(): string { return 'zarinpal'; }
    public function get_name(): string { return 'ZarinPal'; }
    public function get_callback_url(): string { return $this->callback_url; }
    public function supports_refund(): bool { return false; } // DISABLED by owner decision (Phase-4 final audit)

    /** TD-2: the single IRT→IRR conversion point for this gateway. */
    private function to_irr(int $amount_irt): int {
        return $amount_irt * 10;
    }

    public function initiate_payment(array $params): array {
        $amount_irr = $this->to_irr((int) $params['amount_irt']);

        $response = wp_remote_post("{$this->base_url}/payment/request.json", [
            'timeout'    => 20,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => wp_json_encode([
                'merchant_id'  => $this->merchant_id,
                'amount'       => $amount_irr,
                'callback_url' => $this->callback_url,
                'description'  => (string) ($params['description'] ?? 'Eligius subscription'),
                'metadata'     => (array) ($params['metadata'] ?? []),
            ]),
        ]);

        if (is_wp_error($response)) {
            // Never include request internals/credentials in the error.
            throw new \Exception('ZarinPal request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = (int) ($body['data']['code'] ?? 0);
        $authority = (string) ($body['data']['authority'] ?? '');

        if ($code !== 100 || $authority === '') {
            $message = (string) ($body['errors']['message'] ?? $body['data']['message'] ?? 'Unknown gateway error');
            throw new \Exception('ZarinPal request failed (code ' . $code . '): ' . $message);
        }

        $start = $this->sandbox
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://www.zarinpal.com/pg/StartPay/';

        return [
            'authority'    => $authority,
            'redirect_url' => $start . $authority,
        ];
    }

    public function verify_payment(array $callback, int $expected_amount_irt): array {
        $authority = (string) ($callback['authority'] ?? $callback['Authority'] ?? '');
        $status    = (string) ($callback['status'] ?? $callback['Status'] ?? '');

        if ($status !== 'OK' || $authority === '') {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '', 'error' => 'Gateway reported a cancelled/failed payment'];
        }

        $response = wp_remote_post("{$this->base_url}/payment/verify.json", [
            'timeout'    => 20,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => wp_json_encode([
                'merchant_id' => $this->merchant_id,
                'amount'      => $this->to_irr($expected_amount_irt),
                'authority'   => $authority,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '', 'error' => 'ZarinPal verify failed: ' . $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = (int) ($body['data']['code'] ?? 0);
        $ref  = (string) ($body['data']['ref_id'] ?? '');
        $gwAmount = (int) ($body['data']['amount'] ?? 0);

        // 100 = verified; 101 = already verified (gateway re-confirm on retry).
        if ($code !== 100 && $code !== 101) {
            return ['verified' => false, 'amount' => 0, 'ref_id' => '', 'error' => 'ZarinPal verify rejected (code ' . $code . ')'];
        }

        // TD-2: compare in IRR — the gateway unit (conversion above).
        $expected_irr = $this->to_irr($expected_amount_irt);
        if ($gwAmount !== $expected_irr) {
            return ['verified' => false, 'amount' => $gwAmount, 'ref_id' => $ref, 'error' => 'AMOUNT_MISMATCH'];
        }

        return ['verified' => true, 'amount' => $gwAmount, 'ref_id' => $ref, 'error' => null];
    }

    /**
     * Automatic refunds are DISABLED by owner decision (Phase-4 final audit —
     * the `payment/refund.json` endpoint is not part of ZarinPal's officially
     * documented v4 API and was never verified against the real gateway).
     *
     * This method performs NO network request and always returns the typed
     * error REFUND_NOT_SUPPORTED. Manual license revocation by an
     * administrator (Class_License_Manager::revoke) remains available.
     */
    public function refund(string $gateway_ref, int $amount_irt): array {
        // No HTTP request — owner decision (Phase-4 final audit). The
        // signature stays for the Interface contract.
        unset($gateway_ref, $amount_irt);
        return ['refunded' => false, 'error' => 'REFUND_NOT_SUPPORTED'];
    }
}
