<?php
/**
 * P6 unit — device-key algorithm gate (CD-10) and ECDSA P-256 verification,
 * against DETERMINISTIC fixtures (two pre-generated P-256 keypairs + fixed
 * signatures over the fixed challenge "p6-unit-challenge"). Environment-
 * independent: no openssl_pkey_new() and no runtime key generation, so the
 * suite runs identically on Windows dev and GitHub CI.
 *
 * Fixture provenance: keypairs generated with OpenSSL (prime256v1); private
 * keys stay in the local temp fixture dir and are NEVER committed — only the
 * PUBLIC key + signatures are embedded here (no secret material in Git).
 */
require_once __DIR__ . '/../../../includes/auth/class-device-auth.php';

final class DeviceKeyGateTest extends P6UnitBase {

    private const CHALLENGE   = 'p6-unit-challenge';
    private const PUB_A_PEM   = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE1Begh3WRuMdxqkGtkpseoi6AS+PB\nMBFaaYDx0FjRiJI8wvr9F0P35hb08qiTVnfNidUK42i2x32jIk7t9ICqgg==\n-----END PUBLIC KEY-----\n";
    private const SIG_A_B64   = 'MEYCIQDuHDtmuWNkk/CC1CqphnJFwU5kxR/MnLtNaWsfiA5RHQIhAJaMk8LN+HuwVSHx5KLuoMHPlbuT76BEeI0iq43dH/1q';
    private const SIG_B_B64   = 'MEQCIAFBArzxc1tPY89NypH1V6sRN2e2TK/u0ge6xJqzhxD9AiAoIS0dHD5QfXzm9sD040nh8o/55ccXppgAnpZNNVPd6Q==';

    public function testValidEcdsaSignatureVerifies() {
        $r = \Eligius_Sub\Auth\Class_Device_Auth::instance()->verify_signature(
            self::PUB_A_PEM, self::CHALLENGE, base64_decode(self::SIG_A_B64, true));
        $this->check('deterministic valid ECDSA P-256 signature -> true', true === $r);
    }

    public function testTamperedChallengeRejected() {
        $r = \Eligius_Sub\Auth\Class_Device_Auth::instance()->verify_signature(
            self::PUB_A_PEM, self::CHALLENGE . 'x', base64_decode(self::SIG_A_B64, true));
        $this->check('tampered challenge -> false (no exception)', false === $r);
    }

    public function testWrongKeyRejected() {
        $r = \Eligius_Sub\Auth\Class_Device_Auth::instance()->verify_signature(
            self::PUB_A_PEM, self::CHALLENGE, base64_decode(self::SIG_B_B64, true));
        $this->check('signature by ANOTHER device key -> false', false === $r);
    }

    public function testEd25519DeviceKeyExplicitlyRejected() {
        // Craft a SubjectPublicKeyInfo DER with the Ed25519 algorithm OID
        // prefix (302a300506032b6570032100) + 32-byte key - CD-10 forbids it.
        $der = hex2bin('302a300506032b6570032100') . random_bytes(32);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $r = \Eligius_Sub\Auth\Class_Device_Auth::instance()->verify_signature($pem, 'data', random_bytes(64));
        $this->check('Ed25519 device key -> typed eligius_algorithm_not_allowed (fail-closed)',
            is_wp_error($r) && 'eligius_algorithm_not_allowed' === $r->get_error_code());
    }

    public function testGarbagePemTypedError() {
        $r = \Eligius_Sub\Auth\Class_Device_Auth::instance()->verify_signature('not a pem', 'data', 'sig');
        $this->check('invalid PEM -> typed eligius_bad_public_key', is_wp_error($r) && 'eligius_bad_public_key' === $r->get_error_code());
    }
}
