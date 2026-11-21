<?php
/**
 * Payment REST endpoints (docs 03 §6 + §4.4 callback, Roadmap P4).
 *
 *  POST /payments/create            — User JWT (per-user rate-limited)
 *  GET  /payments/{payment_id}      — User JWT (owner-only status)
 *  GET  /payments/history           — User JWT
 *  GET  /payments/callback/{gw}     — Public (gateway redirect) → 302 deep link
 */

namespace Eligius_Sub\Api;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\Payment\Class_Payment_Processor;
use Eligius_Sub\Security\Class_Rate_Limiter;

class Class_Payment_Controller extends Class_Api_Controller {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route(self::NAMESPACE_V1, '/payments/create', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_create'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/payments/(?P<payment_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_status'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/payments/history', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_history'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);

        // Gateway callback — PUBLIC (the gateway has no JWT; authenticity is
        // proven by the Server-to-Server verification inside the processor).
        register_rest_route(self::NAMESPACE_V1, '/payments/callback/(?P<gateway>[a-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_callback'],
            'permission_callback' => '__return_true',
        ]);

        // Phase 6 — Payment Reconciliation (owner final decision): an
        // AUTHENTICATED user-initiated re-verification for INITIATED payments
        // whose callback never landed. Authority + amount come ONLY from the
        // stored payment row (never from the client); the S2S verification,
        // FOR UPDATE locking and idempotency live in the processor. Fails
        // closed with 503 when the rate limiter is degraded.
        register_rest_route(self::NAMESPACE_V1, '/payments/(?P<payment_id>\d+)/reconcile', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_reconcile'],
            'permission_callback' => [self::class, 'permission_user_global'],
        ]);
    }

    /** POST /payments/create (docs 03 §6.1). */
    public function route_create(\WP_REST_Request $request) {
        $auth = \Eligius_Sub\Auth\Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth)) {
            return self::fail_wp_error($auth);
        }

        // 5/hour/user (docs 03 §9 — Payment create).
        $exceeded = self::rate_limit_or_fail([
            ['payment_create_user', (string) get_current_user_id(), 5, 3600],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $plan_key = sanitize_key((string) $request->get_param('plan_key'));
        $gateway  = sanitize_key((string) $request->get_param('gateway'));

        if ('' === $plan_key || '' === $gateway) {
            return self::fail('VALIDATION_ERROR', 'plan_key and gateway are required.', 400);
        }

        $result = Class_Payment_Processor::instance()->create_payment(
            get_current_user_id(),
            $plan_key,
            $gateway
        );
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok($result, 201);
    }

    /** GET /payments/{id} — owner-only (docs 03 §6.2). */
    public function route_status(\WP_REST_Request $request) {
        global $wpdb;
        $auth = \Eligius_Sub\Auth\Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth)) {
            return self::fail_wp_error($auth);
        }

        $payment_id = (int) $request['payment_id'];
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, gateway, amount, currency, plan_key, status, initiated_at, verified_at, license_id
              FROM {$wpdb->prefix}eligius_payments WHERE id = %d AND user_id = %d",
            $payment_id,
            get_current_user_id()
        ));
        if (!$row) {
            return self::fail('NOT_FOUND', 'Payment not found.', 404);
        }

        return self::ok([
            'payment_id'   => (int) $row->id,
            'gateway'      => (string) $row->gateway,
            'amount_irt'   => (int) $row->amount,
            'plan_key'     => (string) $row->plan_key,
            'status'       => (string) $row->status,
            'initiated_at' => (string) $row->initiated_at,
            'verified_at'  => $row->verified_at,
            'license_id'   => $row->license_id ? (int) $row->license_id : null,
        ]);
    }

    /** GET /payments/history (docs 03 §6.3). */
    public function route_history(\WP_REST_Request $request) {
        global $wpdb;
        $auth = \Eligius_Sub\Auth\Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth)) {
            return self::fail_wp_error($auth);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, gateway, amount, currency, plan_key, status, initiated_at, verified_at, license_id
              FROM {$wpdb->prefix}eligius_payments WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            get_current_user_id(),
            20,
            max(0, ((int) $request->get_param('page') - 1) * 20)
        ));

        $payments = [];
        foreach ($rows as $r) {
            $payments[] = [
                'payment_id'   => (int) $r->id,
                'gateway'      => (string) $r->gateway,
                'amount_irt'   => (int) $r->amount,
                'plan_key'     => (string) $r->plan_key,
                'status'       => (string) $r->status,
                'initiated_at' => (string) $r->initiated_at,
                'verified_at'  => $r->verified_at,
                'license_id'   => $r->license_id ? (int) $r->license_id : null,
            ];
        }

        return self::ok(['payments' => $payments, 'page' => max(1, (int) $request->get_param('page'))]);
    }

    /**
     * GET /payments/callback/{gateway} — public gateway redirect (docs 03 §6.4).
     * Processes the callback, then 302-redirects to the Android deep link.
     * Deep-link parameters are informational only; the app re-polls the real
     * payment status from the backend (docs 07 §P4 flow).
     */
    public function route_callback(\WP_REST_Request $request) {
        $gateway = sanitize_key((string) $request['gateway']);

        // Phase 6 M-2 + Final Security Decision: rate limit BEFORE any
        // expensive verification / DB work. The callback is a BROWSER redirect
        // of the payer (not a gateway S2S call), so the IP bucket stays
        // generous — payers behind shared IPs (CGNAT) must never be
        // collectively blocked; a normal payment produces 1–3 callback hits.
        // The AUTHORITY bucket is the precise anti-amplification control.
        // REMOTE_ADDR is used deliberately — client-supplied X-Forwarded-For
        // is NOT trusted (no trusted proxy configured in this deployment).
        //
        // BOTH throttle outcomes keep the deep-link contract with
        // status=pending (NOT failed): the payment may still complete through
        // another callback or the user-initiated reconcile endpoint, and the
        // app polls the authoritative status afterwards. Retry-After is sent
        // in both cases. NO S2S verification and NO database change happen on
        // either path.
        $authority = (string) ($request['authority'] ?? $request['Authority'] ?? '');
        $outcome = Class_Rate_Limiter::instance()->hit_all(array(
            array('payment_callback_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 60, 300),
            array('payment_callback_authority', '' !== $authority ? $authority : 'none', 20, 600),
        ));
        if (null !== $outcome['exceeded']) {
            // Limit consumed → 429-equivalent: throttled, retry later.
            return $this->callback_throttled_redirect('rate_limited', max(1, (int) $outcome['exceeded']['reset'] - time()));
        }
        if (!empty($outcome['last']['degraded'])) {
            // Rate limiter store unavailable → fail-closed: no S2S, no DB
            // touch; the payment stays INITIATED and remains reconcilable.
            \Eligius_Sub\Core\Class_DB_Error::log('payment-callback.degraded', $GLOBALS['wpdb']->last_error ?: 'rate limiter store unavailable');
            return $this->callback_throttled_redirect('rate_limit_unavailable', 60);
        }

        $result = Class_Payment_Processor::instance()->handle_callback(
            $gateway,
            $request->get_query_params()
        );

        $status = 'failed';
        $paymentId = 0;
        if (is_wp_error($result)) {
            if ('eligius_callback_unknown' === $result->get_error_code()) {
                $status = 'unknown';
            }
        } else {
            $status = $result['duplicate'] ? 'duplicate' : strtolower($result['status']);
            $paymentId = (int) $result['payment_id'];
        }

        $deepLink = 'eligius://payment/result?status=' . rawurlencode($status) . '&payment_id=' . $paymentId;

        // 302 redirect to the deep link (docs 07 §P4: "Redirect to app").
        $response = rest_ensure_response('');
        $response->set_status(302);
        $response->header('Location', $deepLink);
        return $response;
    }

    /**
     * Phase 6 — POST /payments/{id}/reconcile: safe user-initiated
     * re-verification of an INITIATED payment.
     *
     * Security contract (owner requirements):
     *  - authenticated + OWNERSHIP (payment row must belong to the caller);
     *  - authority comes ONLY from the stored database row — client-supplied
     *    authority/amount is never trusted;
     *  - the whole flow reuses the processor's S2S verification, amount
     *    check, codes 100/101, FOR UPDATE locking and idempotency;
     *  - terminal states (VERIFIED/FAILED) return the current state without
     *    any gateway traffic (idempotent, no duplicate side effects);
     *  - fail-closed on a degraded rate limiter: 503 RATE_LIMIT_UNAVAILABLE
     *    BEFORE any S2S call or DB change; the payment stays retryable.
     */
    public function route_reconcile(\WP_REST_Request $request) {
        global $wpdb;
        $auth = \Eligius_Sub\Auth\Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth)) {
            return self::fail_wp_error($auth);
        }

        // Dedicated reconciliation limit (owner: "Rate Limit مستقل و مناسب")
        // — 5/hour/user, fail-closed on degraded (default).
        $exceeded = self::rate_limit_or_fail([
            ['payment_reconcile_user', (string) get_current_user_id(), 5, 3600],
        ]);
        if (null !== $exceeded) {
            return $exceeded;
        }

        $payment_id = (int) $request['payment_id'];
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, gateway, gateway_ref, authority, amount, status, plan_key, license_id
              FROM {$wpdb->prefix}eligius_payments WHERE id = %d AND user_id = %d",
            $payment_id,
            get_current_user_id()
        ));
        if (!$payment) {
            return self::fail('NOT_FOUND', 'Payment not found.', 404);
        }

        if ('VERIFIED' === $payment->status || 'FAILED' === $payment->status || 'REFUNDED' === $payment->status) {
            // Terminal state — no gateway traffic, just the authoritative
            // current status (idempotent reconciliation).
            return self::ok([
                'payment_id' => (int) $payment->id,
                'status'     => (string) $payment->status,
                'duplicate'  => true,
                'license_id' => $payment->license_id ? (int) $payment->license_id : null,
            ]);
        }

        if ('INITIATED' !== $payment->status) {
            return self::fail('RECONCILE_NOT_ALLOWED', 'Payment is not in a reconcilable state.', 422);
        }

        // S2S re-verification with the STORED authority only (the processor
        // takes the amount from the same row; codes 100/101 handled there).
        $result = Class_Payment_Processor::instance()->handle_callback(
            (string) $payment->gateway,
            array('authority' => (string) $payment->authority, 'Status' => 'OK')
        );
        if (is_wp_error($result)) {
            return self::fail_wp_error($result);
        }

        return self::ok([
            'payment_id' => (int) $result['payment_id'],
            'status'     => (string) $result['status'],
            'duplicate'  => (bool) $result['duplicate'],
            'license_id' => $result['license_id'],
        ]);
    }

    /**
     * Phase 6 final decision: throttled/degraded callback answer. The
     * deep-link contract is preserved (302) but the STATUS is `pending` —
     * not `failed`: the payment may still complete via another callback or
     * the user-initiated reconcile endpoint. Retry-After guides the retry.
     * Neither path touches the database or the gateway.
     */
    private function callback_throttled_redirect(string $reason, int $retry_after_seconds) {
        $response = rest_ensure_response('');
        $response->set_status(302);
        $response->header('Location', 'eligius://payment/result?status=pending&payment_id=0&reason=' . rawurlencode($reason));
        $response->header('Retry-After', (string) max(1, $retry_after_seconds));
        return $response;
    }
}
