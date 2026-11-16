<?php
/**
 * JWT + user/device auth integration (doc 01 §2.1 auth/class-auth-integration.php).
 *
 * The JWT Authentication for WP-API plugin authenticates requests via the
 * Authorization: Bearer header before permission callbacks run (it sets the
 * current user). This class provides the shared permission checks used by
 * the REST controllers plus the X-Device-Hash resolution described in
 * doc 03 §1.2 / §3.1.
 */

namespace Eligius_Sub\Auth;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Device_Repository;

class Class_Auth_Integration {

    private static $instance = null;

    /** Device resolved for the current request (header-bound). */
    private $resolved_device = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Permission callback: authenticated user via JWT (or any WP auth).
     * Returns WP_Error shaped 401 per doc 03 §1.3 UNAUTHORIZED.
     */
    public function permission_user(\WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'eligius_unauthorized',
                'Missing or invalid authentication token.',
                ['status' => 401]
            );
        }
        return true;
    }

    /**
     * Read + normalize the X-Device-Hash header without resolving the row.
     *
     * @return string|null 64-char lowercase hex or null when absent/invalid.
     */
    public function get_device_hash(\WP_REST_Request $request) {
        return Class_Device_Repository::normalize_hash(
            $request->get_header('X-Device-Hash')
        );
    }

    /**
     * Resolve the device row for the request's X-Device-Hash header.
     * The device must exist, belong to the current user and be ACTIVE —
     * device-bound endpoints only accept the active device (doc 04 §5.1).
     * Phase 5: BLOCKED devices are rejected (backend-enforced).
     *
     * @return \WP_Error|\stdClass The device row or a typed error.
     */
    public function resolve_active_device(\WP_REST_Request $request) {
        $user_check = $this->permission_user($request);
        if (is_wp_error($user_check)) {
            return $user_check;
        }

        $device_hash = $this->get_device_hash($request);
        if (null === $device_hash) {
            return new \WP_Error(
                'eligius_device_hash_missing',
                'A valid 64-hex X-Device-Hash header is required.',
                ['status' => 400]
            );
        }

        $user_id = get_current_user_id();
        $device  = Class_Device_Repository::find_by_hash($user_id, $device_hash);

        if (!$device || !(int) $device->is_active) {
            return new \WP_Error(
                'eligius_device_not_bound',
                'Device is not registered or not active for this account.',
                ['status' => 403]
            );
        }

        // Phase 5: backend-enforced blocked check.
        if (!empty($device->blocked)) {
            return new \WP_Error(
                'eligius_device_blocked',
                'This device has been blocked by an administrator.',
                ['status' => 403]
            );
        }

        $this->resolved_device = $device;
        return $device;
    }

    /**
     * Resolve a REGISTERED device (active or inactive) — used by the
     * transfer-from-new-device flow where the calling device may not be
     * active yet. Blocked devices are still rejected (backend-enforced).
     *
     * @return \WP_Error|\stdClass The device row or a typed error.
     */
    public function resolve_registered_device(\WP_REST_Request $request) {
        $user_check = $this->permission_user($request);
        if (is_wp_error($user_check)) {
            return $user_check;
        }

        $device_hash = $this->get_device_hash($request);
        if (null === $device_hash) {
            return new \WP_Error(
                'eligius_device_hash_missing',
                'A valid 64-hex X-Device-Hash header is required.',
                ['status' => 400]
            );
        }

        $user_id = get_current_user_id();
        $device  = Class_Device_Repository::find_by_hash($user_id, $device_hash);

        if (!$device) {
            return new \WP_Error(
                'eligius_device_not_found',
                'Device is not registered for this account.',
                ['status' => 403]
            );
        }

        // Phase 5: backend-enforced blocked check.
        if (!empty($device->blocked)) {
            return new \WP_Error(
                'eligius_device_blocked',
                'This device has been blocked by an administrator.',
                ['status' => 403]
            );
        }

        $this->resolved_device = $device;
        return $device;
    }

    /** Device resolved during this request (after resolve_active_device). */
    public function current_device() {
        return $this->resolved_device;
    }
}
