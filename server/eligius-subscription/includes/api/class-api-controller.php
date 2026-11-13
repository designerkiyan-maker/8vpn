<?php
/**
 * REST base controller (doc 01 §2.1 api/class-api-controller.php,
 * doc 03 §1.1 response envelope + §1.3 error codes).
 *
 * Namespace: eligius/v1 (base URL per doc 03 §1: …/wp-json/eligius/v1/).
 *
 * Response envelope (doc 03 §1.1):
 *   success → 200 { success:true, data:{…}, meta:{timestamp} }
 *   failure → HTTP status + { success:false, error:{code,message,details} }
 *
 * Permission failures happen before handlers run and keep the WP core
 * WP_Error shape ({code,message,data:{status}}) — the doc envelope applies
 * to handler-level business errors.
 */

namespace Eligius_Sub\Api;

defined('ABSPATH') || exit;

use Eligius_Sub\Auth\Class_Auth_Integration;
use Eligius_Sub\Security\Class_Rate_Limiter;

abstract class Class_Api_Controller {

    const NAMESPACE_V1 = 'eligius/v1';

    /** Rate-limit result of the current request (for X-RateLimit-* headers). */
    private static $rate_headers = null;

    /**
     * Register the dispatch hook that attaches X-RateLimit-* headers
     * (doc 03 §9 "Headers Returned") to the outgoing response. Called once
     * per request from Eligius_Subscription::load_components().
     */
    public static function boot_rate_headers() {
        add_action('rest_post_dispatch', [self::class, 'dispatch_rate_headers'], 10, 2);
    }

    /** rest_post_dispatch hook: attach the stored rate-limit headers. */
    public static function dispatch_rate_headers($response, $server) {
        if (null !== self::$rate_headers && $response instanceof \WP_REST_Response) {
            $response->header('X-RateLimit-Limit', (string) self::$rate_headers['limit'], true);
            $response->header('X-RateLimit-Remaining', (string) self::$rate_headers['remaining'], true);
            $response->header('X-RateLimit-Reset', (string) self::$rate_headers['reset'], true);
            self::$rate_headers = null;
        }
        return $response;
    }

    /** Child controllers declare their routes here (hooked on rest_api_init). */
    abstract public function register_routes();

    /** Envelope: success (doc 03 §1.1). */
    public static function ok($data, $status = 200) {
        $response = rest_ensure_response([
            'success' => true,
            'data'    => $data,
            'meta'    => ['timestamp' => gmdate('c')],
        ]);
        $response->set_status($status);
        return $response;
    }

    /** Envelope: typed business error (doc 03 §1.1/§1.3). */
    public static function fail($code, $message, $status = 400, $details = null) {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $details) {
            $error['details'] = $details;
        }
        $response = rest_ensure_response([
            'success' => false,
            'error'   => $error,
        ]);
        $response->set_status($status);
        return $response;
    }

    /** Normalize a \WP_Error (singletons' typed errors) into the envelope. */
    public static function fail_wp_error(\WP_Error $error) {
        $status = (int) ($error->get_error_data()['status'] ?? 500);
        return self::fail(
            $error->get_error_code(),
            $error->get_error_message(),
            $status
        );
    }

    /** Shared permission: JWT-authenticated user (doc 03 §1.2). */
    public static function permission_user(\WP_REST_Request $request) {
        return Class_Auth_Integration::instance()->permission_user($request);
    }

    /** Global per-IP limiter (doc 03 §9 — 1000/min/IP) + user auth. */
    public static function permission_user_global(\WP_REST_Request $request) {
        $auth = Class_Auth_Integration::instance()->permission_user($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $exceeded = Class_Rate_Limiter::instance()->hit('global_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 1000, 60);
        if (!$exceeded['allowed']) {
            return Class_Rate_Limiter::instance()->too_many_error($exceeded);
        }
        return true;
    }

    /**
     * Convenience: resolve the active device for the request.
     * Returns the device row or a \WP_Error (convert with fail_wp_error()).
     */
    protected static function device_or_error(\WP_REST_Request $request) {
        return Class_Auth_Integration::instance()->resolve_active_device($request);
    }

    /**
     * Convenience: consume the request's rate-limit buckets. Returns the 429
     * envelope error when a bucket is exceeded; otherwise stores the last
     * bucket result so dispatch_rate_headers() can attach the headers.
     */
    protected static function rate_limit_or_fail(array $buckets) {
        $outcome = Class_Rate_Limiter::instance()->hit_all($buckets);
        if (null !== $outcome['exceeded']) {
            return self::fail('TOO_MANY_REQUESTS', 'Rate limit exceeded.', 429, [
                'limit' => $outcome['exceeded']['limit'],
                'reset' => $outcome['exceeded']['reset'],
            ]);
        }
        self::$rate_headers = $outcome['last'];
        return null;
    }
}
