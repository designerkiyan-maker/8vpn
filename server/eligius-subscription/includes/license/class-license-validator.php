<?php
/**
 * License validation FACADE (Phase 6 M-4 — owner decision "Facade سازگار").
 *
 * Device-challenge verification lives in EXACTLY ONE canonical place:
 *   Eligius_Sub\Auth\Class_Device_Auth (CD-10: ECDSA P-256 with
 *   SHA256withECDSA only — Ed25519 is reserved for server-side license/
 *   entitlement signing and is explicitly rejected for device keys).
 *
 * This class previously duplicated the whole challenge implementation, which
 * risked the two copies drifting apart. It is now a thin, backward-compatible
 * facade: every call delegates to Class_Device_Auth, so any legacy consumer
 * keeps working while only one implementation exists. No REST route or other
 * consumer uses the old duplicated bodies (verified: the only reference is
 * the bootstrap instantiation).
 *
 * Online/offline license validation itself remains in
 * Class_License_Manager::get_status()/validate() — nothing here.
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Auth\Class_Device_Auth;

class Class_License_Validator {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /** Facade → Class_Device_Auth (canonical, 60s single-use challenge). */
    public function create_challenge($user_id, $device_hash) {
        return Class_Device_Auth::instance()->create_challenge($user_id, $device_hash);
    }

    /** Facade → Class_Device_Auth (canonical, hash_equals + signature verify). */
    public function verify_challenge($user_id, $device_hash, $challenge, $signature) {
        return Class_Device_Auth::instance()->verify_challenge($user_id, $device_hash, $challenge, $signature);
    }

    /** Facade → Class_Device_Auth (canonical CD-10 algorithm gate). */
    public function verify_signature($public_key_pem, $data, $signature_raw) {
        return Class_Device_Auth::instance()->verify_signature($public_key_pem, $data, $signature_raw);
    }
}
