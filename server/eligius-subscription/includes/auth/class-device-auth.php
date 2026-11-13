<?php
/**
 * Device authentication (doc 01 §2.1 auth/class-device-auth.php, Roadmap P2).
 *
 * Challenge generation + signature verification for device binding (CD-10):
 *   - ECDSA P-256 (secp256r1) with SHA256withECDSA — the confirmed mechanism
 *   - Ed25519 (sodium) also accepted when a device ever presents it
 *   - challenge TTL 60s, single-use (consumed on first verify attempt)
 *
 * The server NEVER sees or stores a private key; it only stores the public key
 * (PEM) registered via /devices/register.
 */

namespace Eligius_Sub\Auth;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Device_Repository;

class Class_Device_Auth {

    private static $instance = null;

    /** Challenge TTL in seconds (doc 03 §3.2 expires_in 60). */
    private $challenge_ttl = 60;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Create a single-use challenge for a device (doc 03 §3.2).
     *
     * @return array{challenge:string, expires_in:int}
     */
    public function create_challenge($user_id, $device_hash) {
        $challenge = base64_encode(random_bytes(32));
        $key       = $this->transient_key($user_id, $device_hash);
        set_transient($key, $challenge, $this->challenge_ttl);

        return [
            'challenge'  => $challenge,
            'expires_in' => $this->challenge_ttl,
        ];
    }

    /**
     * Verify a signed challenge (doc 03 §3.3). Single-use: the transient is
     * consumed whether or not the signature matches (replay requires a fresh
     * challenge).
     *
     * @return array{verified:bool, device_id:int, is_active:bool}|\WP_Error
     */
    public function verify_challenge($user_id, $device_hash, $challenge, $signature) {
        global $wpdb;

        $key = $this->transient_key($user_id, $device_hash);
        $stored = get_transient($key);
        delete_transient($key);

        if (false === $stored || !is_string($stored) || '' === $stored) {
            return new \WP_Error('eligius_challenge_expired', 'Challenge expired or unknown.', ['status' => 422]);
        }
        if (!hash_equals((string) $stored, (string) $challenge)) {
            return new \WP_Error('eligius_challenge_mismatch', 'Challenge does not match.', ['status' => 422]);
        }

        $device = Class_Device_Repository::find_by_hash($user_id, $device_hash);
        if (!$device) {
            return new \WP_Error('eligius_device_not_found', 'Device not found.', ['status' => 404]);
        }

        $signature_raw = base64_decode((string) $signature, true);
        if (false === $signature_raw) {
            return new \WP_Error('eligius_bad_signature', 'Signature is not valid base64.', ['status' => 422]);
        }

        $verified = $this->verify_signature((string) $device->public_key, (string) $challenge, $signature_raw);
        if (is_wp_error($verified)) {
            return $verified;
        }
        if (!$verified) {
            return new \WP_Error('eligius_bad_signature', 'Signature verification failed.', ['status' => 422]);
        }

        Class_Device_Repository::touch((int) $device->id);

        return [
            'verified'  => true,
            'device_id' => (int) $device->id,
            'is_active' => (bool) $device->is_active,
        ];
    }

    /**
     * Verify $signature over $data with the stored PEM public key.
     *
     * CD-10 (confirmed): the ONLY permitted device-binding algorithm is
     * ECDSA P-256 (secp256r1) with SHA256withECDSA — Android Keystore
     * produces DER ECDSA signatures that openssl_verify accepts directly.
     * Ed25519 device keys are NOT accepted here: Ed25519 is reserved
     * exclusively for server-side license/entitlement signing (independent
     * responsibility). A device presenting an Ed25519 key fails verification.
     *
     * @return bool|\WP_Error true/false, or a typed error for unusable keys.
     */
    public function verify_signature($public_key_pem, $data, $signature_raw) {
        $pem = (string) $public_key_pem;
        $der = self::pem_to_der($pem);
        if (null === $der) {
            return new \WP_Error('eligius_bad_public_key', 'Stored public key is not valid PEM.', ['status' => 500]);
        }

        // Algorithm gate (CD-10): Ed25519 SubjectPublicKeyInfo DER starts with
        // 302a300506032b6570032100 — that algorithm is not permitted for device
        // binding, so reject it explicitly (fail-closed, no fallback).
        $ed25519_prefix = hex2bin('302a300506032b6570032100');
        if (is_string($ed25519_prefix) && 0 === strncmp($der, $ed25519_prefix, strlen($ed25519_prefix))) {
            return new \WP_Error('eligius_algorithm_not_allowed', 'Device key algorithm is not permitted (CD-10: ECDSA P-256 only).', ['status' => 422]);
        }

        // ECDSA P-256 via OpenSSL (SHA-256 digest — matches SHA256withECDSA).
        $key = @openssl_pkey_get_public($pem);
        if (false === $key) {
            return new \WP_Error('eligius_bad_public_key', 'Public key could not be parsed.', ['status' => 500]);
        }
        $result = openssl_verify((string) $data, $signature_raw, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);
        if (-1 === $result) {
            return new \WP_Error('eligius_bad_public_key', 'Signature verification errored.', ['status' => 500]);
        }
        return (1 === $result);
    }

    /** Extract DER bytes from a PEM string. */
    private static function pem_to_der($pem) {
        if (!preg_match('/-----BEGIN ([A-Z ]+)-----([A-Za-z0-9+\/=\s]+)-----END \1-----/', (string) $pem, $m)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        return (false === $der) ? null : $der;
    }

    private function transient_key($user_id, $device_hash) {
        return 'eligius_sub_chal_' . md5($user_id . '|' . $device_hash);
    }
}
