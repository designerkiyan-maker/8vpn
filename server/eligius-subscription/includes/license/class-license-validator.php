<?php
/**
 * Online/offline validation + device challenge verification
 * (doc 01 §2.1 license/class-license-validator.php).
 *
 * Device challenge verification accepts BOTH signature algorithms —
 * Ed25519 (sodium) and ECDSA P-256 (openssl) — detected from the stored
 * PEM. The choice between them stays an open owner decision (TD-8, doc 04
 * §2.4) until confirmed; the server supporting both keeps that decision
 * deferred without forcing a premature pick.
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Device_Repository;

class Class_License_Validator {

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
            'challenge' => $challenge,
            'expires_in' => $this->challenge_ttl,
        ];
    }

    /**
     * Verify a signed challenge (doc 03 §3.3). Single-use: the transient is
     * consumed whether or not the signature matches (replay of a failed
     * attempt requires a fresh challenge).
     *
     * @param int    $user_id      Authenticated user.
     * @param string $device_hash  Device hash from the request.
     * @param string $challenge    Challenge as issued.
     * @param string $signature    Base64 signature over the challenge string.
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
     * CD-10: the ONLY permitted device-binding algorithm is ECDSA P-256 with
     * SHA256withECDSA. Ed25519 device keys are NOT accepted (Ed25519 is
     * reserved exclusively for server-side license/entitlement signing).
     *
     * @return bool|\WP_Error true/false, or a typed error for unusable keys.
     */
    public function verify_signature($public_key_pem, $data, $signature_raw) {
        $pem = (string) $public_key_pem;
        $der = self::pem_to_der($pem);
        if (null === $der) {
            return new \WP_Error('eligius_bad_public_key', 'Stored public key is not valid PEM.', ['status' => 500]);
        }

        // Algorithm gate (CD-10): Ed25519 keys rejected explicitly — fail-closed.
        $ed25519_prefix = hex2bin('302a300506032b6570032100');
        if (is_string($ed25519_prefix) && 0 === strncmp($der, $ed25519_prefix, strlen($ed25519_prefix))) {
            return new \WP_Error('eligius_algorithm_not_allowed', 'Device key algorithm is not permitted (CD-10: ECDSA P-256 only).', ['status' => 422]);
        }

        // ECDSA P-256 via OpenSSL (SHA-256 digest).
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
