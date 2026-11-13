<?php
/**
 * License creation & offline-entitlement signing
 * (doc 04 §4.1/§4.2 Ed25519-signed JWT, §6 key management).
 *
 * Key strategy (doc 04 §6.2 + project secret convention):
 *  - Preferred: ELIGIUS_SUB_SIGNING_PRIVATE_KEY defined in wp-config as the
 *    base64-encoded 64-byte Ed25519 secret key (never leaves the server).
 *  - Fallback (dev): the key is auto-generated on first use and stored in the
 *    options table; an admin notice then recommends the wp-config define.
 *
 * Public keys are stored per kid (eligius_sub_pubkey_v1/v2/…) so the embedded
 * key set in the Android APK can accept multiple key versions during rotation
 * (doc 04 §6.1 — rotation itself is a later-phase operational task).
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

class Class_License_Issuer {

    private static $instance = null;

    /** Current signing key id (doc 04 §4.2: kid 'v2'). */
    private $signing_key_id = 'v2';

    /** Max offline window in hours (doc 04 §4.1: 72). */
    private $max_offline_hours = 72;

    /** Resolved 64-byte secret key (binary). */
    private $private_key = null;

    /** True when the private key came from the DB fallback. */
    private $key_is_fallback = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (defined('ELIGIUS_SUB_SIGNING_KEY_ID') && '' !== ELIGIUS_SUB_SIGNING_KEY_ID) {
            $this->signing_key_id = ELIGIUS_SUB_SIGNING_KEY_ID;
        }
        $this->load_private_key();
    }

    /**
     * Check if the issuer is ready to sign (has a valid private key).
     * In production, this requires a valid ELIGIUS_SUB_SIGNING_PRIVATE_KEY constant.
     *
     * @return bool True if ready to sign
     */
    public function is_ready() {
        return null !== $this->private_key;
    }

    /**
     * Check if we're running with a development/fallback key.
     *
     * @return bool True if using a fallback/generated key (not production-ready)
     */
    public function is_fallback_key() {
        return $this->key_is_fallback;
    }

    public function key_id() {
        return $this->signing_key_id;
    }

    /**
     * Detect the current environment using the official WordPress standard.
     *
     * Safety policy (audit):
     *   local/development → Development Key allowed
     *   staging/production → Development Key FORBIDDEN
     *   unknown/unrecognized → FORBIDDEN (WordPress defaults to production,
     *   and `WP_ENVIRONMENT_TYPE` being absent must NOT mean development).
     *
     * `ELIGIUS_SUB_PRODUCTION` (explicit) forces production mode.
     *
     * @return string 'local' | 'development' | 'staging' | 'production'
     */
    private function detect_environment() {
        if (defined('ELIGIUS_SUB_PRODUCTION') && ELIGIUS_SUB_PRODUCTION) {
            return 'production';
        }

        if (function_exists('wp_get_environment_type')) {
            $env = wp_get_environment_type();
            if (in_array($env, ['local', 'development', 'staging', 'production'], true)) {
                return $env;
            }
        }

        return 'production'; // safe default
    }

    /**
     * Resolve the private key: wp-config constant first.
     *
     * Rules:
     *  - A VALID constant is always used (any environment).
     *  - An INVALID constant is a hard failure everywhere (never silently
     *    fall back to the option or to auto-generation).
     *  - When no constant is defined: local/development may use the stored
     *    option or auto-generate; staging/production/unknown MUST NOT
     *    (private key stays null → typed 503 on issuance).
     */
    private function load_private_key() {
        $env       = $this->detect_environment();
        $dev_allowed = in_array($env, ['local', 'development'], true);

        // 1. wp-config constant — when defined it is authoritative.
        if (defined('ELIGIUS_SUB_SIGNING_PRIVATE_KEY') && '' !== ELIGIUS_SUB_SIGNING_PRIVATE_KEY) {
            $raw = base64_decode((string) ELIGIUS_SUB_SIGNING_PRIVATE_KEY, true);
            if (false !== $raw && 64 === strlen($raw)) {
                $this->private_key = $raw;
                $this->ensure_public_key_stored($raw);
                return;
            }
            // Invalid constant: hard failure in EVERY environment (no fallback).
            error_log('eligius-subscription: ELIGIUS_SUB_SIGNING_PRIVATE_KEY is defined but not a valid base64 64-byte Ed25519 secret key.');
            $this->private_key = null;
            return;
        }

        // 2. No constant defined.
        if (!$dev_allowed) {
            // staging / production / unknown → production key isolation.
            $this->private_key = null;
            return;
        }

        // 3. local/development fallback: stored option, then auto-generate.
        $stored = get_option('eligius_sub_signing_private_key', '');
        if (is_string($stored) && '' !== $stored) {
            $raw = base64_decode($stored, true);
            if (false !== $raw && 64 === strlen($raw)) {
                $this->private_key  = $raw;
                $this->key_is_fallback = true;
                return;
            }
        }

        $this->generate_keypair();
    }

    /**
     * Ensure the public key for the current kid exists in options
     * (verify_entitlement reads it by kid — doc 04 §6.1).
     */
    private function ensure_public_key_stored($secret_key) {
        $option = 'eligius_sub_pubkey_' . $this->signing_key_id;
        if (get_option($option)) {
            return;
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($secret_key);
        update_option($option, base64_encode($public), false);
    }

    /** Generate an Ed25519 keypair; public key stored per kid, private in options. */
    private function generate_keypair() {
        $keypair = sodium_crypto_sign_keypair();
        $secret  = sodium_crypto_sign_secretkey($keypair);
        $public  = sodium_crypto_sign_publickey($keypair);

        update_option('eligius_sub_signing_private_key', base64_encode($secret), false);
        update_option('eligius_sub_pubkey_' . $this->signing_key_id, base64_encode($public), false);

        $this->private_key     = $secret;
        $this->key_is_fallback = true;
    }

    /** Public key (base64) for a kid, or null. */
    public function public_key($kid = '') {
        $kid = '' === $kid ? $this->signing_key_id : $kid;
        return get_option('eligius_sub_pubkey_' . $kid, null);
    }

    /** All known kids → base64 public keys (rotation support, doc 04 §6.1). */
    public function public_keys() {
        global $wpdb;
        $keys = [];
        foreach (wp_load_alloptions() as $name => $value) {
            if (0 === strpos($name, 'eligius_sub_pubkey_')) {
                $keys[substr($name, strlen('eligius_sub_pubkey_'))] = $value;
            }
        }
        return $keys;
    }

    /**
     * Issue the signed offline entitlement for a bound license
     * (doc 04 §4.2 — payload per §4.1, exp = iat + 72h max).
     *
     * @return array{blob:string, expires_at:string, public_key_id:string}|\WP_Error
     */
    public function issue_offline_entitlement($license_id, $device_id) {
        if (!$this->is_ready()) {
            $is_production = defined('ELIGIUS_SUB_PRODUCTION') && ELIGIUS_SUB_PRODUCTION
                || defined('WP_ENV') && 'production' === WP_ENV;
            if ($is_production) {
                error_log('eligius-subscription: Cannot issue entitlement — no valid signing key in production.');
            }
            return new \WP_Error(
                'eligius_signing_key_unavailable',
                'Signing key is not configured. Define ELIGIUS_SUB_SIGNING_PRIVATE_KEY in wp-config.php.',
                ['status' => 503]
            );
        }

        $license = Class_License_Repository::find($license_id);
        $device  = Class_Device_Repository::find($device_id);

        if (!$license || !$device) {
            return new \WP_Error('eligius_license_or_device_missing', 'License or device not found.', ['status' => 404]);
        }
        if ((int) $license->device_id !== (int) $device->id) {
            return new \WP_Error('eligius_device_not_bound', 'Device not bound to license.', ['status' => 409]);
        }

        $now = Class_UTC_Time::unix_utc();
        $exp = $now + ($this->max_offline_hours * 3600);

        $payload = [
            'iss'         => 'eligius-subscription',
            'sub'         => (string) $license->user_id,
            'aud'         => 'eligius-android',
            'jti'         => bin2hex(random_bytes(16)),
            'iat'         => $now,
            'exp'         => $exp,
            'nbf'         => $now,
            'license_id'  => (int) $license->id,
            'plan_key'    => (string) $license->plan_key,
            'permissions' => ['vpn.connect', 'vpn.split_tunnel', 'vpn.lan_share'],
            'device_hash' => (string) $device->device_hash,
            'device_id'   => (int) $device->id,
        ];

        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->signing_key_id];

        $header_b64  = self::base64url_encode(wp_json_encode($header));
        $payload_b64 = self::base64url_encode(wp_json_encode($payload));
        $signing_input = $header_b64 . '.' . $payload_b64;

        $signature = sodium_crypto_sign_detached($signing_input, $this->private_key);
        $blob = $signing_input . '.' . self::base64url_encode($signature);

        // Store the blob reference on the license row (doc 04 §4.2).
        Class_License_Repository::update($license_id, [
            'signed_blob'    => $blob,
            'blob_expires_at' => Class_UTC_Time::add_seconds(Class_UTC_Time::mysql_utc(), $this->max_offline_hours * 3600),
            'public_key_id'  => $this->signing_key_id,
        ]);

        return [
            'blob'          => $blob,
            'expires_at'    => Class_UTC_Time::iso_utc_from_timestamp($exp),
            'public_key_id' => $this->signing_key_id,
        ];
    }

    /**
     * Verify an entitlement blob's Ed25519 signature (server-side round trip;
     * the Android verifier arrives in Phase 3 — doc 06 §3.6).
     *
     * @return array Decoded payload|\WP_Error
     */
    public function verify_entitlement($blob) {
        $parts = explode('.', (string) $blob);
        if (3 !== count($parts)) {
            return new \WP_Error('eligius_blob_malformed', 'Malformed entitlement blob.', ['status' => 400]);
        }

        $header = json_decode(self::base64url_decode($parts[0]), true);
        if (!is_array($header) || 'EdDSA' !== ($header['alg'] ?? '')) {
            return new \WP_Error('eligius_blob_bad_header', 'Unsupported blob header.', ['status' => 400]);
        }

        $kid     = $header['kid'] ?? '';
        $pub_b64 = $this->public_key((string) $kid);
        if (!$pub_b64) {
            return new \WP_Error('eligius_blob_unknown_kid', "Unknown key ID: {$kid}", ['status' => 400]);
        }

        $raw_public = base64_decode($pub_b64, true);
        $signature  = self::base64url_decode($parts[2]);
        if (false === $raw_public || false === $signature) {
            return new \WP_Error('eligius_blob_malformed', 'Malformed blob encoding.', ['status' => 400]);
        }

        try {
            $verified = sodium_crypto_sign_verify_detached($signature, $parts[0] . '.' . $parts[1], $raw_public);
        } catch (\SodiumException $e) {
            $verified = false;
        }
        if (!$verified) {
            return new \WP_Error('eligius_blob_bad_signature', 'Entitlement signature verification failed.', ['status' => 400]);
        }

        $payload = json_decode(self::base64url_decode($parts[1]), true);
        if (!is_array($payload)) {
            return new \WP_Error('eligius_blob_malformed', 'Malformed blob payload.', ['status' => 400]);
        }
        return $payload;
    }

    /** Base64url encode (RFC 4648 §5, unpadded — JWT convention). */
    public static function base64url_encode($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    /** Base64url decode. */
    public static function base64url_decode($data) {
        return base64_decode(strtr((string) $data, '-_', '+/') . str_repeat('=', (4 - strlen((string) $data) % 4) % 4), true);
    }
}
