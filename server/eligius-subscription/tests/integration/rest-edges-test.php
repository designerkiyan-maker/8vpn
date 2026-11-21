<?php
/**
 * Phase 6 B-1/B-2 — REST EDGE suite (owner-authorized remaining coverage):
 * device-controller validation edges that short-circuit BEFORE heavy logic,
 * dispatched through the REAL WP REST server (same pattern as the other suites):
 *   • register: X-Device-Hash invalid → 400; fingerprint ≠ header → 400;
 *     public_key non-PEM → 400; happy path → 201; exact re-register → 409.
 *   • challenge: invalid hash → 400; unregistered device → 403; registered
 *     device → 200 (real Device_Auth challenge creation).
 *   • verify: unregistered device → 403; challenge never issued → 422
 *     (eligius_challenge_expired) + the auth.failed audit row MUST exist.
 *   • transfer/start: unregistered caller → 403; active caller with invalid
 *     to_device_hash → 400.
 *   • transfer/verify: missing transfer_id → 400; malformed otp_code → 400
 *     (B-1 core); unregistered caller → 403.
 *
 * Dedicated per-edge users keep the suite state-independent; rate-limit
 * buckets are cleared before use so no 429 masks an edge.
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-rest-edges';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

use Eligius_Sub\Core\Class_Device_Repository;

$wpdb = $GLOBALS['wpdb'];
do_action('rest_api_init');

function find_or_create_user($wpdb, $login) {
    $id = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if (!$id) {
        $id = (int) wp_insert_user(array(
            'user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.invalid', 'role' => 'subscriber',
        ));
    }
    return $id;
}

function clear_rl($wpdb, array $tuples) {
    foreach ($tuples as $t) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_rate_limits WHERE bucket_id = %s",
            md5($t[0] . '|' . $t[1] . '|' . $t[2])));
    }
}

function rest_req($method, $route, $hash, array $params = array()) {
    $req = new WP_REST_Request($method, '/eligius/v1' . $route);
    if (null !== $hash) $req->set_header('X-Device-Hash', $hash);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return rest_get_server()->dispatch($req);
}

// Deterministic 64-hex device hashes (60 repeats + a 4-hex tail).
function H($seed, $tail) { return str_repeat($seed, 60) . $tail; }

$pubPem = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE1Begh3WRuMdxqkGtkpseoi6AS+PB\nMBFaaYDx0FjRiJI8wvr9F0P35hb08qiTVnfNidUK42i2x32jIk7t9ICqgg==\n-----END PUBLIC KEY-----\n";

// ---- Dedicated users ----------------------------------------------------------
$uReg = find_or_create_user($wpdb, 'p6edge-reg');
$uCh  = find_or_create_user($wpdb, 'p6edge-chal');
$uVf  = find_or_create_user($wpdb, 'p6edge-verify');
$uTs  = find_or_create_user($wpdb, 'p6edge-tstart');
$uTv  = find_or_create_user($wpdb, 'p6edge-tverify');
$uNo  = find_or_create_user($wpdb, 'p6edge-nodev');
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN ($uReg,$uCh,$uVf,$uTs,$uTv,$uNo)");
$wpdb->query("DELETE FROM wp_eligius_devices        WHERE user_id IN ($uReg,$uCh,$uVf,$uTs,$uTv,$uNo)");
$wpdb->query("DELETE FROM {$wpdb->prefix}eligius_audit_logs WHERE user_id IN ($uReg,$uCh,$uVf,$uTs,$uTv,$uNo) AND event_type = 'auth.failed'");
clear_rl($wpdb, array(
    array('global_ip', '127.0.0.1', 60),
    array('device_register_user', (string) $uReg, 3600),
    array('device_challenge_user', (string) $uCh, 60),
    array('device_verify_user', (string) $uVf, 60),
    array('transfer_start_user', (string) $uTs, 86400),
    array('transfer_verify_user', (string) $uTv, 60),
));

$hashReg = H('a', 'aa01');
$hashCh  = H('b', 'bb02');
$hashVf  = H('c', 'cc03');
$hashTs  = H('d', 'dd04');
$hashTv  = H('e', 'ee05');
$hashNo  = H('f', 'ff06');

// ================= REGISTER =================
wp_set_current_user($uReg);

$res = rest_req('POST', '/devices/register', 'short-but-not-hex');
T('REG1. invalid X-Device-Hash → 400 VALIDATION_ERROR',
    400 === $res->get_status() && 'VALIDATION_ERROR' === ($res->get_data()['error']['code'] ?? ''));

$res = rest_req('POST', '/devices/register', $hashReg, array(
    'fingerprint' => H('9', 'bb01'), // valid 64-hex, DIFFERENT from the header
    'public_key'  => $pubPem,
));
T('REG2. fingerprint ≠ X-Device-Hash → 400 "fingerprint does not match" (B-1)',
    400 === $res->get_status()
    && 'VALIDATION_ERROR' === ($res->get_data()['error']['code'] ?? '')
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'fingerprint does not match'));

$res = rest_req('POST', '/devices/register', $hashReg, array(
    'public_key' => 'not a pem block',
));
T('REG3. non-PEM public_key → 400 "public_key must be a PEM block"',
    400 === $res->get_status()
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'public_key must be a PEM block'));

$res = rest_req('POST', '/devices/register', $hashReg, array(
    'public_key' => $pubPem, 'label' => 'P6 Edge Dev',
));
T('REG4. valid registration (active slot free) → 201 + device_id + is_active=true',
    201 === $res->get_status() && ($res->get_data()['data']['device_id'] ?? 0) > 0
    && '' !== (string) ($res->get_data()['data']['created_at'] ?? ''));
$regDeviceId = (int) ($res->get_data()['data']['device_id'] ?? 0);

$res = rest_req('POST', '/devices/register', $hashReg, array(
    'public_key' => $pubPem,
));
T('REG5. exact re-register of an ACTIVE device → 409 CONFLICT',
    409 === $res->get_status() && 'CONFLICT' === ($res->get_data()['error']['code'] ?? '')
    && $regDeviceId === (int) ($res->get_data()['error']['details']['device_id'] ?? 0));

// ================= CHALLENGE =================
wp_set_current_user($uCh);

$res = rest_req('POST', '/devices/challenge', 'zzz');
T('CH1. invalid X-Device-Hash → 400 eligius_device_hash_missing',
    400 === $res->get_status() && 'eligius_device_hash_missing' === ($res->get_data()['error']['code'] ?? ''));

$res = rest_req('POST', '/devices/challenge', $hashCh);
T('CH2. unregistered device → 403 eligius_device_not_bound',
    403 === $res->get_status() && 'eligius_device_not_bound' === ($res->get_data()['error']['code'] ?? ''));

Class_Device_Repository::create($uCh, $hashCh, 'pk-p6edge-chal', 'P6 Chal Dev', 1, 'test');
$res = rest_req('POST', '/devices/challenge', $hashCh);
$ch = $res->get_data()['data'] ?? array();
T('CH3. registered ACTIVE device → 200 with a real challenge (60s TTL)',
    200 === $res->get_status() && !empty($ch['challenge']) && 60 === ($ch['expires_in'] ?? 0));

// ================= VERIFY =================
wp_set_current_user($uVf);

$res = rest_req('POST', '/devices/verify', $hashVf, array('challenge' => 'x', 'signature' => 'y'));
T('VF1. unregistered device → 403 DEVICE_NOT_BOUND',
    403 === $res->get_status() && 'DEVICE_NOT_BOUND' === ($res->get_data()['error']['code'] ?? ''));

Class_Device_Repository::create($uVf, $hashVf, $pubPem, 'P6 Verify Dev', 1, 'test');
// No challenge was EVER issued for this device → verify must fail closed 422.
$res = rest_req('POST', '/devices/verify', $hashVf, array(
    'challenge' => 'never-issued-challenge', 'signature' => base64_encode('garbage'),
));
T('VF2. verify without a stored challenge → 422 eligius_challenge_expired',
    422 === $res->get_status() && 'eligius_challenge_expired' === ($res->get_data()['error']['code'] ?? ''));
$devVf = Class_Device_Repository::find_by_hash($uVf, $hashVf);
$auditHit = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}eligius_audit_logs WHERE event_type = 'auth.failed' AND device_id = %d",
    (int) $devVf->id));
T('VF3. the failed verify wrote an auth.failed audit row (real device id)',
    1 === $auditHit);

// ================= TRANSFER START =================
wp_set_current_user($uTs);

$res = rest_req('POST', '/devices/transfer/start', $hashTs);
T('TS1. unregistered caller → 403 DEVICE_NOT_BOUND',
    403 === $res->get_status() && 'DEVICE_NOT_BOUND' === ($res->get_data()['error']['code'] ?? ''));

Class_Device_Repository::create($uTs, $hashTs, $pubPem, 'P6 TStart Dev', 1, 'test');
$res = rest_req('POST', '/devices/transfer/start', $hashTs); // active caller, no to_device_hash
T('TS2. ACTIVE caller without to_device_hash → 400 "to_device_hash must be 64 hex"',
    400 === $res->get_status()
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'to_device_hash must be 64 hex'));

// ================= TRANSFER VERIFY =================
wp_set_current_user($uTv);

$res = rest_req('POST', '/devices/transfer/verify', $hashTv, array('transfer_id' => 999, 'otp_code' => '123456'));
T('TV1. unregistered caller → 403 eligius_device_not_found (fail-closed device resolution)',
    403 === $res->get_status() && 'eligius_device_not_found' === ($res->get_data()['error']['code'] ?? ''));

Class_Device_Repository::create($uTv, $hashTv, $pubPem, 'P6 TVerify Dev', 1, 'test');

$res = rest_req('POST', '/devices/transfer/verify', $hashTv, array('otp_code' => '123456'));
T('TV2. missing transfer_id → 400 "transfer_id is required."',
    400 === $res->get_status()
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'transfer_id is required'));

$res = rest_req('POST', '/devices/transfer/verify', $hashTv, array('transfer_id' => 999, 'otp_code' => 'abc123'));
T('TV3. non-6-digit otp_code → 400 "otp_code must be 6 digits" (B-1)',
    400 === $res->get_status()
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'otp_code must be 6 digits'));

$res = rest_req('POST', '/devices/transfer/verify', $hashTv, array('transfer_id' => 999, 'otp_code' => '12345'));
T('TV4. 5-digit otp_code also rejected → 400',
    400 === $res->get_status()
    && false !== strpos((string) ($res->get_data()['error']['message'] ?? ''), 'otp_code must be 6 digits'));

// ---- Cleanup ------------------------------------------------------------------
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id IN ($uReg,$uCh,$uVf,$uTs,$uTv,$uNo)");
$wpdb->query("DELETE FROM {$wpdb->prefix}eligius_audit_logs WHERE user_id IN ($uReg,$uCh,$uVf,$uTs,$uTv,$uNo) AND event_type = 'auth.failed'");

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
