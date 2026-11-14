<?php
/**
 * Gateway adapter contract (docs 07 §P4 "Gateway Adapter Interface",
 * 05 §3.1, CD-3 — ZarinPal first, adapter-based extensibility).
 *
 * Unit-of-measure contract (TD-2, owner decision):
 *   - ALL internal amounts are IRT (Toman) — payments.amount column,
 *     plans.price_irt, and every parameter named *_irt below.
 *   - IRT→IRR conversion (×10) happens ONLY inside a concrete gateway
 *     adapter (initiate_payment / verify_payment), because that is where
 *     the gateway's expected unit is known. Nothing else converts.
 *
 * Secrets contract: merchant IDs / credentials come from wp-config
 * constants or admin options — never from source code, never logged.
 */

namespace Eligius_Sub\Payment;

defined('ABSPATH') || exit;

interface Interface_Gateway_Adapter {

    /** Gateway key: 'zarinpal', 'idpay', 'zibal', 'mock', … */
    public function get_key(): string;

    /** Display name (admin UI). */
    public function get_name(): string;

    /**
     * Start a payment. $params:
     *   amount_irt (int), description (string), user_id (int),
     *   plan_key (string), callback_url (string), metadata (array)
     * Returns ['authority' => string, 'redirect_url' => string].
     *
     * @throws Exception on gateway error (message must never contain secrets).
     */
    public function initiate_payment(array $params): array;

    /**
     * Server-to-Server verification of a gateway callback.
     * $callback: raw gateway parameters (Authority, Status, …).
     * $expected_amount_irt: authoritative amount from our payments row.
     * The adapter converts IRT→IRR internally (TD-2) and compares units.
     * Returns ['verified' => bool, 'amount' => int(IRR), 'ref_id' => string,
     *          'error' => string|null].
     */
    public function verify_payment(array $callback, int $expected_amount_irt): array;

    public function get_callback_url(): string;

    public function supports_refund(): bool;

    /** Admin-initiated refund. Returns ['refunded' => bool, 'error' => ?string]. */
    public function refund(string $gateway_ref, int $amount_irt): array;
}
