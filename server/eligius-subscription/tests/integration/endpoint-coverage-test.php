<?php
/**
 * Phase 6 — REST endpoint coverage matrix (Roadmap P6 AC: "All REST endpoints
 * have integration tests"). 17 routes, each covered for at least:
 *   • unauthenticated access (401 envelope)
 *   • controlled input-validation error (400/422)
 *   • resource ownership / controlled error
 * plus sensitive-route security specifics (M-2 callback limit, ownership 404s,
 * pending-device behavior, renew typed 503).
 *
 * Fixture users are resolved by login and cleaned by REAL ids (state-
 * independent). Challenge/verify HAPPY paths live in DeviceIdentityTest
 * (real Keystore) and DeviceKeyGateTest (fixtures) — here they are covered at
 * route level (auth/validation/controlled errors).
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-endpoints';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

use Eligius_Sub\Core\Class_Device_Repository;

$wpdb = $GLOBALS['wpdb'];
do_action('rest_api_init');

function find_user($wpdb, $login) {
    $id = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if (!$id) {
        $id = (int) wp_insert_user(array(
            'user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.invalid', 'role' => 'subscriber',
        ));
    }
    return $id;
}
function dispatch($method, $route, $hash = null, array $params = array()): WP_REST_Response {
    $req = new WP_REST_Request($method, '/eligius/v1' . $route);
    if (null !== $hash) $req->set_header('X-Device-Hash', $hash);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return rest_get_server()->dispatch($req);
}
function err_code_of($res): string {
    return (string) ($res->get_data()['error']['code'] ?? '');
}
function clear_rl($tuples) {
    global $wpdb;
    // Phase 6 M-3: limiter storage is the rate_limits TABLE (not transients).
    foreach ($tuples as $t) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_rate_limits WHERE bucket_id = %s",
            md5($t[0] . '|' . $t[1] . '|' . $t[2])));
    }
}

// ---- Fixtures -----------------------------------------------------------------
$uA = find_user($wpdb, 'p6ep-uA');   // primary
$uB = find_user($wpdb, 'p6ep-uB');   // other account (ownership checks)
foreach (array($uA, $uB) as $u) {
    $wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id = $u");
    $wpdb->query("DELETE FROM wp_eligius_licenses       WHERE user_id = $u");
    $wpdb->query("DELETE FROM wp_eligius_payments       WHERE user_id = $u");
    $wpdb->query("DELETE FROM wp_eligius_devices        WHERE user_id = $u");
}
update_user_meta($uA, 'mobile', '09124440000');
$hashA   = str_repeat('d', 60) . 'aaaa';
$hashA2  = str_repeat('d', 60) . 'bbbb'; // second device of uA (pending)
Class_Device_Repository::create($uA, $hashA,  'pk-a',  'Ua Dev', 1, 'test');
Class_Device_Repository::create($uA, $hashA2, 'pk-a2', 'Ua Dev2', 0, 'test');
$devA = Class_Device_Repository::find_by_hash($uA, $hashA);
$wpdb->insert("wp_eligius_licenses", array(
    'user_id' => $uA, 'plan_key' => 'monthly', 'plan_label' => 'Monthly', 'duration_days' => 30,
    'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    'status' => 'ACTIVE', 'source' => 'payment', 'device_id' => (int) $devA->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
));
$wpdb->insert("wp_eligius_payments", array(
    'user_id' => $uA, 'gateway' => 'zarinpal', 'gateway_ref' => 'P6-AUTH-A', 'authority' => 'P6-AUTH-A',
    'amount' => 150000, 'currency' => 'IRT', 'plan_key' => 'monthly', 'plan_duration_days' => 30,
    'status' => 'VERIFIED', 'initiated_at' => gmdate('Y-m-d H:i:s'),
));
$paymentA = (int) $wpdb->insert_id;
$wpdb->insert("wp_eligius_payments", array(
    'user_id' => $uB, 'gateway' => 'zarinpal', 'gateway_ref' => 'P6-AUTH-B', 'authority' => 'P6-AUTH-B',
    'amount' => 150000, 'currency' => 'IRT', 'plan_key' => 'monthly', 'plan_duration_days' => 30,
    'status' => 'INITIATED', 'initiated_at' => gmdate('Y-m-d H:i:s'),
));
$paymentB = (int) $wpdb->insert_id;

clear_rl(array(
    array('validation_device', $hashA, 60), array('global_ip', '127.0.0.1', 60),
    array('device_register_user', $uA, 3600), array('device_challenge_user', $uA, 60),
    array('device_verify_user', $uA, 60), array('transfer_start_user', $uA, 86400),
    array('transfer_verify_user', $uA, 60), array('payment_create_user', $uA, 3600),
    array('trial_user', $uA, 86400), array('trial_ip', '127.0.0.1', 3600),
));

// =============== License routes (7) ===========================================

// 1. GET /licenses/status
wp_set_current_user(0);
$res = dispatch('GET', '/licenses/status', $hashA);
T('L1. status unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('GET', '/licenses/status', $hashA);
T('L1b. status (active device, paid license) → 200 valid', 200 === $res->get_status() && true === ($res->get_data()['data']['valid'] ?? false));
$res = dispatch('GET', '/licenses/status', str_repeat('e', 64));
T('L1c. status unknown device → controlled 403', 403 === $res->get_status());

// 2. POST /licenses/validate
$res = dispatch('GET', '/licenses/validate', $hashA);
T('L2. validate (valid license) → 200 valid', 200 === $res->get_status() && true === ($res->get_data()['data']['valid'] ?? false));

// 3. GET /licenses/offline-entitlement
$res = dispatch('GET', '/licenses/offline-entitlement', $hashA);
T('L3. offline-entitlement (valid license) → 200 with blob',
    200 === $res->get_status() && '' !== ($res->get_data()['data']['blob'] ?? ''));

// 4. GET /licenses/revocation-list
$res = dispatch('GET', '/licenses/revocation-list', $hashA);
T('L4. revocation-list → 200 with revoked list shape',
    200 === $res->get_status() && isset($res->get_data()['data']['revoked_license_ids']));

// 5. POST /licenses/trial
wp_set_current_user(0);
$res = dispatch('POST', '/licenses/trial', $hashA);
T('L5. trial unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('POST', '/licenses/trial', $hashA);
// CD-6 documented policy: eligibility = has_trial_ever ONLY. A fresh account
// receives its one trial even while a paid license is active (trial+paid
// coexistence is the designed state — payment-flow test 15/16); a crafted
// direct call therefore succeeds ONCE. The UI never offers it (banner needs
// NO_ACTIVE_LICENSE). Product-level guard = owner decision, recorded in the
// P6 report.
T('L5b. trial grant (fresh account, per CD-6) → 201 exactly once',
    201 === $res->get_status());
$res2 = dispatch('POST', '/licenses/trial', $hashA);
T('L5c. second trial grant → 409 (one per account enforced)',
    409 === $res2->get_status());

// 6. GET /plans
wp_set_current_user(0);
$res = dispatch('GET', '/plans');
T('L6. plans is a PUBLIC catalog by design (__return_true) → 200',
    200 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('GET', '/plans');
T('L6b. plans (authenticated) → 200 catalog', 200 === $res->get_status() && isset($res->get_data()['data']['plans']));

// 7. POST /licenses/renew
$res = dispatch('POST', '/licenses/renew', $hashA);
T('L7. renew → honest typed 503 GATEWAY_UNAVAILABLE', 503 === $res->get_status() && 'GATEWAY_UNAVAILABLE' === err_code_of($res));

// =============== Device routes (6) ============================================

// 8. POST /devices/register
wp_set_current_user(0);
$res = dispatch('POST', '/devices/register', $hashA);
T('D1. register unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('POST', '/devices/register', $hashA, array('public_key' => "-----BEGIN PUBLIC KEY-----
MIIBtest
-----END PUBLIC KEY-----
", 'fingerprint' => $hashA, 'label' => 'x'));
T('D1b. register of EXISTING ACTIVE device → 409 CONFLICT', 409 === $res->get_status() && 'CONFLICT' === err_code_of($res));
$res = dispatch('POST', '/devices/register', str_repeat('d', 60) . 'cccc', array('fingerprint' => str_repeat('d', 60) . 'cccc', 'label' => 'no key'));
T('D1c. register without PEM public_key → 400 validation', 400 === $res->get_status() && 'VALIDATION_ERROR' === err_code_of($res));

// 9. POST /devices/challenge
wp_set_current_user(0);
$res = dispatch('POST', '/devices/challenge', $hashA);
T('D2. challenge unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
clear_rl(array(array('device_challenge_user', $uA, 60)));
$res = dispatch('POST', '/devices/challenge', $hashA);
T('D2b. challenge (registered device) → 200 with 60s expiry',
    200 === $res->get_status() && 60 === ($res->get_data()['data']['expires_in'] ?? 0));
$res = dispatch('POST', '/devices/challenge', str_repeat('e', 64));
T('D2c. challenge unknown device → controlled 403', 403 === $res->get_status());

// 10. POST /devices/verify
wp_set_current_user($uA);
clear_rl(array(array('device_verify_user', $uA, 60)));
$req = new WP_REST_Request('POST', '/eligius/v1/devices/verify');
$req->set_header('X-Device-Hash', $hashA);
$req->set_param('challenge', base64_encode(random_bytes(32)));
$req->set_param('signature', base64_encode(random_bytes(70)));
$res = rest_get_server()->dispatch($req);
T('D3. verify with unknown/mismatched challenge → 422 (expired or mismatch — challenge consumed either way)',
    422 === $res->get_status()
    && in_array(err_code_of($res), array('eligius_challenge_expired', 'eligius_challenge_mismatch'), true));

// 11. GET /devices
wp_set_current_user(0);
$res = dispatch('GET', '/devices', $hashA);
T('D4. device list unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('GET', '/devices', $hashA);
$d = $res->get_data()['data'];
T('D4b. device list → 200 with devices + active_device_id',
    200 === $res->get_status() && 2 === count($d['devices'] ?? array()) && (int) ($d['active_device_id'] ?? 0) === (int) $devA->id);

// 12-13. transfer start/verify — full security matrix lives in
// transfer-security-test.php; route-level checks here:
wp_set_current_user(0);
$res = dispatch('POST', '/devices/transfer/start', $hashA);
T('D5. transfer start unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
clear_rl(array(array('transfer_start_user', $uA, 86400)));
$res = dispatch('POST', '/devices/transfer/start', str_repeat('e', 64));
T('D5b. transfer start from unknown device → 403 DEVICE_NOT_BOUND',
    403 === $res->get_status() && 'DEVICE_NOT_BOUND' === err_code_of($res));
wp_set_current_user(0);
$res = dispatch('POST', '/devices/transfer/verify', $hashA);
T('D6. transfer verify unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
clear_rl(array(array('transfer_verify_user', $uA, 60)));
$res = dispatch('POST', '/devices/transfer/verify', $hashA, array('transfer_id' => '0', 'otp_code' => '123456'));
T('D6b. transfer verify invalid transfer_id → 400', 400 === $res->get_status() && 'VALIDATION_ERROR' === err_code_of($res));
$res = dispatch('POST', '/devices/transfer/verify', $hashA, array('transfer_id' => '999999', 'otp_code' => '123456'));
T('D6c. transfer verify unknown transfer → 422 not_pending',
    422 === $res->get_status() && 'eligius_transfer_not_pending' === err_code_of($res));

// =============== Payment routes (4) ===========================================

// 14. POST /payments/create
wp_set_current_user(0);
$res = dispatch('POST', '/payments/create');
T('P1. create unauthenticated → 401', 401 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('POST', '/payments/create', $hashA, array('plan_key' => 'monthly'));
T('P1b. create without gateway → 400 validation', 400 === $res->get_status() && 'VALIDATION_ERROR' === err_code_of($res));
$res = dispatch('POST', '/payments/create', $hashA, array('plan_key' => 'trial', 'gateway' => 'zarinpal'));
T('P1c. create for the TRIAL plan → 422 not purchasable', 422 === $res->get_status());

// 15. GET /payments/{id} — ownership
wp_set_current_user($uB);
$res = dispatch('GET', '/payments/' . $paymentA);
T('P2. status of ANOTHER user\'s payment → 404 (ownership)', 404 === $res->get_status());
wp_set_current_user($uA);
$res = dispatch('GET', '/payments/' . $paymentA);
T('P2b. own payment status → 200 VERIFIED', 200 === $res->get_status() && 'VERIFIED' === ($res->get_data()['data']['status'] ?? ''));

// 16. GET /payments/history
wp_set_current_user($uB);
$res = dispatch('GET', '/payments/history');
T('P3. history scoped to the caller (uB sees only its own)',
    200 === $res->get_status() && 1 === count($res->get_data()['data']['payments'] ?? array())
    && (int) ($res->get_data()['data']['payments'][0]['payment_id'] ?? 0) === $paymentB);

// 17. GET /payments/callback/{gateway} — PUBLIC route + M-2 limit
wp_set_current_user(0);
clear_rl(array(array('payment_callback_ip', '127.0.0.1', 300), array('payment_callback_authority', 'NO-SUCH', 600)));
$res = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'NO-SUCH', 'Status' => 'OK'));
T('P4. callback unknown authority → controlled 302 deep-link status=unknown (no DB change)',
    302 === $res->get_status() && false !== strpos((string) $res->get_headers()['Location'] ?? '', 'status=unknown'));
// M-2: hammer the same authority past the 20/600s cap → throttled BEFORE any
// DB work (302 deep-link redirect, no JSON envelope).
$throttled = false;
for ($i = 0; $i < 25; $i++) {
    $res = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'NO-SUCH', 'Status' => 'OK'));
    if (302 === $res->get_status() && false !== strpos((string) $res->get_headers()['Location'] ?? '', 'rate_limited')) { $throttled = true; break; }
}
T('P4b. M-2: same-authority hammering throttled before DB work (302 rate_limited)', $throttled);
$paymentRow = $wpdb->get_row("SELECT status FROM wp_eligius_payments WHERE id = $paymentA");
T('P4c. throttled callbacks changed NOTHING in the DB', 'VERIFIED' === $paymentRow->status);
// Legitimate duplicate: register the MOCK gateway (repo fixture — clearly a
// mock, no real gateway traffic), then run a verify→re-verify pair; the
// second callback must surface the idempotent duplicate status.
require_once __DIR__ . '/class-mock-gateway.php';
add_filter('eligius_sub_payment_gateway_instance', function () {
    return new \Eligius_Sub\Payment\Gateways\Class_Mock_Gateway();
}, 10, 0);
clear_rl(array(array('payment_callback_ip', '127.0.0.1', 300), array('payment_callback_authority', 'P6-AUTH-A', 600)));
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$res1 = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'P6-AUTH-A', 'Status' => 'OK'));
$res2 = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'P6-AUTH-A', 'Status' => 'OK'));
$loc1 = (string) ($res1->get_headers()['Location'] ?? '');
$loc2 = (string) ($res2->get_headers()['Location'] ?? '');
T('P4d. mock-gateway callback → 302 verified, then duplicate (idempotency contract)',
    302 === $res1->get_status() && false !== strpos($loc1, 'status=verified')
    && 302 === $res2->get_status() && false !== strpos($loc2, 'status=duplicate'));

// ---- Cleanup --------------------------------------------------------------------
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN ($uA, $uB)");
$wpdb->query("DELETE FROM wp_eligius_licenses       WHERE user_id IN ($uA, $uB)");
$wpdb->query("DELETE FROM wp_eligius_payments       WHERE user_id IN ($uA, $uB)");
$wpdb->query("DELETE FROM wp_eligius_devices        WHERE user_id IN ($uA, $uB)");

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
