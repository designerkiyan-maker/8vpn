<?php
/**
 * P6 — Key Rotation readiness (owner item 10): verify the kid machinery
 * accepts multiple signing keys simultaneously, rejects unknown kids and
 * tampered blobs. The keypairs here are EPHEMERAL THROWAWAY fixtures created
 * inside the test process only — they are never written anywhere and never
 * become production keys (owner rule).
 *
 * NOTE: Class_License_Issuer is a singleton that reads its key ONCE from the
 * wp-config constants — the harness defines a per-run test key before the
 * first issuer access. Each test creates its own secondary key directly via
 * sodium_crypto_sign_keypair and verifies cross-verification behavior.
 */
require_once __DIR__ . '/../../../includes/license/class-license-issuer.php';

// Test-scope constants — defined BEFORE the issuer singleton is touched.
// (WP option shims come from the harness get_option/update_option stubs.)
if (!defined('ELIGIUS_SUB_SIGNING_KEY_ID')) {
    // EPHEMERAL throwaway Ed25519 keypair generated in-process for this test
    // run only — never stored, never deployed (owner rule: no new production
    // private keys).
    $__p6_kp = sodium_crypto_sign_keypair();
    define('ELIGIUS_SUB_SIGNING_KEY_ID', 'vTEST');
    define('ELIGIUS_SUB_SIGNING_PRIVATE_KEY', base64_encode(sodium_crypto_sign_secretkey($__p6_kp)));
}
if (!function_exists('get_option')) {
    $GLOBALS['__p6_options'] = [];
    function get_option($name, $default = false) {
        return $GLOBALS['__p6_options'][$name] ?? $default;
    }
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__p6_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d) { return json_encode($d); }
}

final class KeyRotationTest extends P6UnitBase {

    private function issuer(): \Eligius_Sub\License\Class_License_Issuer {
        return \Eligius_Sub\License\Class_License_Issuer::instance();
    }

    /** A SECOND throwaway Ed25519 keypair + its base64 public key. */
    private function secondaryKey(): array {
        static $pair = null;
        if (null === $pair) {
            $kp = sodium_crypto_sign_keypair();
            $pair = array(
                'secret' => sodium_crypto_sign_secretkey($kp),
                'public_b64' => base64_encode(sodium_crypto_sign_publickey($kp)),
            );
        }
        return $pair;
    }

    private function makeBlob($secret, $kid, $payloadOverride = array()): string {
        $now = time();
        $payload = array_merge(array(
            'iss' => 'eligius-subscription', 'sub' => '999', 'aud' => 'eligius-android',
            'jti' => bin2hex(random_bytes(8)), 'iat' => $now, 'exp' => $now + 3600,
            'nbf' => $now, 'license_id' => 7, 'plan_key' => 'monthly',
            'device_hash' => str_repeat('k', 64), 'device_id' => 3,
        ), $payloadOverride);
        $h = \Eligius_Sub\License\Class_License_Issuer::base64url_encode(wp_json_encode(array('alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid)));
        $p = \Eligius_Sub\License\Class_License_Issuer::base64url_encode(wp_json_encode($payload));
        $sig = sodium_crypto_sign_detached($h . '.' . $p, $secret);
        return $h . '.' . $p . '.' . \Eligius_Sub\License\Class_License_Issuer::base64url_encode($sig);
    }

    public function testIssuerSignsWithItsKidAndStoresPublicKey() {
        $iss = $this->issuer();
        $this->check('issuer resolved the test kid', 'vTEST' === $iss->key_id());
        $this->check('public key stored per kid (rotation registry)', false !== $iss->public_key('vTEST'));
    }

    public function testBlobFromIssuerKidVerifies() {
        $blob = $this->makeBlob(base64_decode(ELIGIUS_SUB_SIGNING_PRIVATE_KEY, true), 'vTEST');
        $payload = $this->issuer()->verify_entitlement($blob);
        $this->check('blob signed with the active kid → verified payload', is_array($payload) && 7 === (int) ($payload['license_id'] ?? 0));
    }

    public function testSecondKidAcceptedWhenRegistered() {
        $second = $this->secondaryKey();
        // Rotation step 1: the NEW public key is registered alongside the old
        // one (update_option('eligius_sub_pubkey_vNEW', ...) — the runbook).
        update_option('eligius_sub_pubkey_vNEW', $second['public_b64'], false);
        $blob = $this->makeBlob($second['secret'], 'vNEW');
        $payload = $this->issuer()->verify_entitlement($blob);
        $this->check('blob signed with the NEW registered kid → verified (old + new coexist)',
            is_array($payload) && 7 === (int) ($payload['license_id'] ?? 0));
    }

    public function testUnknownKidRejected() {
        $second = $this->secondaryKey();
        $blob = $this->makeBlob($second['secret'], 'vUNKNOWN');
        $r = $this->issuer()->verify_entitlement($blob);
        $this->check('unknown kid → typed eligius_blob_unknown_kid (fail-closed)',
            is_wp_error($r) && 'eligius_blob_unknown_kid' === $r->get_error_code());
    }

    public function testCrossKeyVerificationRejected() {
        // Blob signed with key NEW but verified against kid vTEST's registry
        // entry would never happen (kid selects the key); the direct analogue:
        // swap the payload AFTER signing → signature mismatch.
        $blob = $this->makeBlob(base64_decode(ELIGIUS_SUB_SIGNING_PRIVATE_KEY, true), 'vTEST');
        $parts = explode('.', $blob);
        $payload = json_decode(\Eligius_Sub\License\Class_License_Issuer::base64url_decode($parts[1]), true);
        $payload['license_id'] = 999; // tampered
        $tampered = $parts[0] . '.' . \Eligius_Sub\License\Class_License_Issuer::base64url_encode(wp_json_encode($payload)) . '.' . $parts[2];
        $r = $this->issuer()->verify_entitlement($tampered);
        $this->check('tampered payload → eligius_blob_bad_signature', is_wp_error($r) && 'eligius_blob_bad_signature' === $r->get_error_code());
    }

    public function testAlgDowngradeRejected() {
        $blob = $this->makeBlob(base64_decode(ELIGIUS_SUB_SIGNING_PRIVATE_KEY, true), 'vTEST');
        $parts = explode('.', $blob);
        $header = json_decode(\Eligius_Sub\License\Class_License_Issuer::base64url_decode($parts[0]), true);
        $header['alg'] = 'HS256'; // downgrade attempt
        $downgraded = \Eligius_Sub\License\Class_License_Issuer::base64url_encode(wp_json_encode($header)) . '.' . $parts[1] . '.' . $parts[2];
        $r = $this->issuer()->verify_entitlement($downgraded);
        $this->check('alg downgrade → eligius_blob_bad_header (EdDSA only)',
            is_wp_error($r) && 'eligius_blob_bad_header' === $r->get_error_code());
    }
}
