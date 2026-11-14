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
}
