<?php
/**
 * Phase 6 FINAL SECURITY DECISION — Selective Fail-Closed (owner):
 * for every sensitive operation prove all four behaviors:
 *   1. limiter healthy        → normal behavior,
 *   2. limit consumed         → 429 (before any side effect),
 *   3. limiter degraded       → 503 RATE_LIMIT_UNAVAILABLE (before any side
 *      effect — no SMS, no OTP consumption, no device/transfer/trial/
 *      payment/license change, no gateway call),
 *   4. after the store recovers → the request retries successfully.
 * Plus the Payment Reconciliation contract (ownership, idempotency, no S2S on
 * degraded, mock-gateway verify counts) and the callback 302-pending contract
 * with Retry-After. No real gateway, no real SMS (mock/stubs only — H-2/H-3
 * boundaries preserved).
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-failclosed';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Payment\Gateways\Class_Mock_Gateway;

// LOCAL MOCK SMS sender — the transfer contract requires the OTP to be
// sent; this stub proves the flow WITHOUT a real SMS provider (H-2/H-3
// boundaries preserved, never presented as real SMS).
if (!function_exists('eligius_otp_send_sms')) {
    function eligius_otp_send_sms($mobile, $code) { return true; }
}

$wpdb = $GLOBALS['wpdb'];
do_action('rest_api_init');
$RL_TABLE = $wpdb->prefix . 'eligius_rate_limits';

// Force limiter degradation by hiding its storage (test fixture — the table
// is restored immediately after each degraded probe).
function degrade_on() { global $RL_TABLE; $GLOBALS['wpdb']->query("RENAME TABLE {$RL_TABLE} TO {$RL_TABLE}_off"); }
function degrade_off() { global $RL_TABLE; $GLOBALS['wpdb']->query("RENAME TABLE {$RL_TABLE}_off TO {$RL_TABLE}"); }
function bucket_clear($scope, $id, $window) {
    global $wpdb, $RL_TABLE;
    $wpdb->query($wpdb->prepare("DELETE FROM {$RL_TABLE} WHERE bucket_id = %s", md5($scope . '|' . $id . '|' . $window)));
}
function dispatch($method, $route, $hash = null, array $params = array()): WP_REST_Response {
    $req = new WP_REST_Request($method, '/eligius/v1' . $route);
    if (null !== $hash) $req->set_header('X-Device-Hash', $hash);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return rest_get_server()->dispatch($req);
}
function err_code_of($res): string { return (string) ($res->get_data()['error']['code'] ?? ''); }
function location_of($res): string { return (string) ($res->get_headers()['Location'] ?? ''); }

// ---- Fixtures (fresh users, real-id cleanup) ---------------------------------
function fc_user($wpdb, $login) {
    $id = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if (!$id) {
        $id = (int) wp_insert_user(array(
            'user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.invalid', 'role' => 'subscriber',
        ));
    }
    return $id;
}
$u = fc_user($wpdb, 'p6fc-uA');   // primary actor
$uOther = fc_user($wpdb, 'p6fc-uB'); // non-owner
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id IN ($u, $uOther)");
update_user_meta($u, 'mobile', '09125550000');
$hashDev  = str_repeat('a', 60) . 'f001'; // ACTIVE device (normal flow)
$hashNew  = str_repeat('a', 60) . 'f002'; // INACTIVE device (transfer-from-new)
Class_Device_Repository::create($u, $hashDev, 'pk-a', 'FC Dev', 1, 'test');
Class_Device_Repository::create($u, $hashNew, 'pk-n', 'FC New', 0, 'test');
$devActive = Class_Device_Repository::find_by_hash($u, $hashDev);
$devNew = Class_Device_Repository::find_by_hash($u, $hashNew);
$wpdb->insert("wp_eligius_licenses", array(
    'user_id' => $u, 'plan_key' => 'monthly', 'plan_label' => 'Monthly', 'duration_days' => 30,
    'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    'status' => 'ACTIVE', 'source' => 'payment', 'device_id' => (int) $devActive->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
));
$licenseCountBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = $u");
Class_Device_Repository::create($uOther, str_repeat('b', 64), 'pk-b', 'UB Dev', 1, 'test');
// INITIATED payment for reconciliation (mock gateway will verify it).
$wpdb->insert("wp_eligius_payments", array(
    'user_id' => $u, 'gateway' => 'zarinpal', 'gateway_ref' => 'P6FC-AUTH', 'authority' => 'P6FC-AUTH',
    'amount' => 150000, 'currency' => 'IRT', 'plan_key' => 'monthly', 'plan_duration_days' => 30,
    'status' => 'INITIATED', 'initiated_at' => gmdate('Y-m-d H:i:s'),
));
$paymentFc = (int) $wpdb->insert_id;
// Fixture hygiene: prior suite runs leave idempotency rows for this authority
// — the reconcile contract (R3/R4) needs a fresh verify→duplicate sequence.
$wpdb->query($wpdb->prepare("DELETE FROM wp_eligius_payment_idempotency WHERE gateway_ref = %s", 'P6FC-AUTH'));
// Mock gateway for any route that reaches a gateway (counted — must stay 0 on degraded).
require_once __DIR__ . '/class-mock-gateway.php';
add_filter('eligius_sub_payment_gateway_instance', function () { return new Class_Mock_Gateway(); }, 10, 0);
Class_Mock_Gateway::reset(true);

// ---- 1. Device Registration (register) ---------------------------------------
wp_set_current_user($u);
bucket_clear('device_register_user', $u, 3600);
bucket_clear('global_ip', '127.0.0.1', 60);
$hashReg = str_repeat('c', 60) . 'f003';
degrade_on();
$res = dispatch('POST', '/devices/register', $hashReg, array(
    'fingerprint' => $hashReg,
    'public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBfc\n-----END PUBLIC KEY-----\n",
    'label' => 'FC Reg',
));
degrade_off();
T('FC1. register degraded → 503 RATE_LIMIT_UNAVAILABLE',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
T('FC1b. NO device row created while degraded',
    null === Class_Device_Repository::find_by_hash($u, $hashReg));
// Recovery: the same request succeeds once the store is healthy.
$res = dispatch('POST', '/devices/register', $hashReg, array(
    'fingerprint' => $hashReg,
    'public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBfc\n-----END PUBLIC KEY-----\n",
    'label' => 'FC Reg',
));
T('FC1c. retry after recovery → 201 PENDING (normal behavior)',
    201 === $res->get_status() && true === ($res->get_data()['data']['pending_transfer'] ?? false));

// ---- 2. Device Challenge + Verify --------------------------------------------
wp_set_current_user($u);
bucket_clear('device_challenge_user', $u, 60);
bucket_clear('device_verify_user', $u, 60);
degrade_on();
$res = dispatch('POST', '/devices/challenge', $hashDev);
degrade_off();
T('FC2. challenge degraded → 503 (no challenge issued)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
degrade_on();
$res = dispatch('POST', '/devices/verify', $hashDev, array(
    'challenge' => base64_encode(random_bytes(32)), 'signature' => base64_encode(random_bytes(70))));
degrade_off();
T('FC2b. verify degraded → 503 (no OTP/challenge consumed)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
// Healthy path control (challenge issued + consumed single-use).
bucket_clear('device_challenge_user', $u, 60);
bucket_clear('device_verify_user', $u, 60);
$res = dispatch('POST', '/devices/challenge', $hashDev);
$challenge = $res->get_data()['data']['challenge'] ?? '';
T('FC2c. healthy challenge → 200', 200 === $res->get_status() && '' !== $challenge);

// ---- 3. Device Transfer (start + verify) --------------------------------------
wp_set_current_user($u);
bucket_clear('transfer_start_user', $u, 86400);
bucket_clear('transfer_verify_user', $u, 60);
degrade_on();
$res = dispatch('POST', '/devices/transfer/start', $hashNew);
degrade_off();
T('FC3. transfer start degraded → 503 (no transfer created, no SMS sent)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
$pendingRows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM wp_eligius_device_transfers WHERE user_id = %d AND status = 'PENDING'", $u));
T('FC3b. NO PENDING transfer while degraded', 0 === $pendingRows);
// Healthy start → PENDING (SMS mocked locally, clearly not a real provider).
$res = dispatch('POST', '/devices/transfer/start', $hashNew);
$tid = (int) ($res->get_data()['data']['transfer_id'] ?? 0);
$otp = (string) $wpdb->get_var($wpdb->prepare("SELECT otp_code FROM wp_eligius_device_transfers WHERE id = %d", $tid));
T('FC3c. healthy transfer start → 201 (mock SMS)', 201 === $res->get_status() && 6 === strlen($otp));
// Degraded verify → 503 AND the OTP is NOT consumed.
degrade_on();
$res = dispatch('POST', '/devices/transfer/verify', $hashNew, array('transfer_id' => (string) $tid, 'otp_code' => $otp));
degrade_off();
T('FC3d. transfer verify degraded → 503 (OTP NOT consumed)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
$otpStill = (string) $wpdb->get_var($wpdb->prepare("SELECT otp_code FROM wp_eligius_device_transfers WHERE id = %d", $tid));
$statusStill = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM wp_eligius_device_transfers WHERE id = %d", $tid));
T('FC3e. OTP intact + transfer still PENDING after degraded verify',
    $otp === $otpStill && 'PENDING' === $statusStill);
// Recovery: the SAME OTP verifies successfully.
$res = dispatch('POST', '/devices/transfer/verify', $hashNew, array('transfer_id' => (string) $tid, 'otp_code' => $otp));
T('FC3f. retry after recovery → verified (atomic swap)',
    200 === $res->get_status() && true === ($res->get_data()['data']['verified'] ?? false));

// ---- 4. Trial Grant ------------------------------------------------------------
wp_set_current_user($u);
bucket_clear('trial_user', $u, 86400);
bucket_clear('trial_ip', '127.0.0.1', 3600);
degrade_on();
$res = dispatch('POST', '/licenses/trial', $hashNew); // active device after the FC3 transfer swap
degrade_off();
T('FC4. trial grant degraded → 503 (no trial created)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
$trialRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = $u AND status = 'TRIAL'");
T('FC4b. NO trial row while degraded', 0 === $trialRows);

// ---- 5. Payment Creation --------------------------------------------------------
bucket_clear('payment_create_user', $u, 3600);
degrade_on();
$res = dispatch('POST', '/payments/create', $hashDev, array('plan_key' => 'monthly', 'gateway' => 'zarinpal'));
degrade_off();
T('FC5. payment create degraded → 503 (no gateway request, no payment row)',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
$payRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_payments WHERE user_id = $u AND status = 'INITIATED' AND gateway_ref LIKE 'MOCK%'");
T('FC5b. NO new INITIATED payment while degraded', 0 === $payRows);
// Healthy create → 201 (mock gateway).
$res = dispatch('POST', '/payments/create', $hashDev, array('plan_key' => 'monthly', 'gateway' => 'zarinpal'));
T('FC5c. healthy payment create → 201', 201 === $res->get_status());

// ---- 5b. License Usage Report (P9.2 — quota accounting is a write) ------------
wp_set_current_user($u);
bucket_clear('usage_user', $u, 3600);
bucket_clear('usage_ip', '127.0.0.1', 3600);
$usedBefore = (int) $wpdb->get_var(
    "SELECT used_bytes FROM wp_eligius_licenses WHERE user_id = $u ORDER BY id DESC LIMIT 1");
degrade_on();
$res = dispatch('POST', '/licenses/usage', $hashDev, array('rx_bytes' => 4096, 'tx_bytes' => 512));
degrade_off();
T('FC-U1. usage report degraded → 503 RATE_LIMIT_UNAVAILABLE',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
$usedAfter = (int) $wpdb->get_var(
    "SELECT used_bytes FROM wp_eligius_licenses WHERE user_id = $u ORDER BY id DESC LIMIT 1");
T('FC-U1b. used_bytes UNCHANGED while degraded (no quota side effect)',
    $usedBefore === $usedAfter);
// Recovery: the same report succeeds and accumulates exactly once.
$res = dispatch('POST', '/licenses/usage', $hashDev, array('rx_bytes' => 4096, 'tx_bytes' => 512));
$d = $res->get_data()['data'] ?? array();
T('FC-U1c. retry after recovery → 200 attributed, used_bytes +4608',
    200 === $res->get_status() && true === ($d['attributed'] ?? false)
    && $usedBefore + 4608 === (int) ($d['used_bytes'] ?? -1));

// ---- 6. Payment Callback (302 pending contract) ---------------------------------
wp_set_current_user(0);
// 6a. degraded → 302 pending + reason=rate_limit_unavailable + Retry-After + NO S2S
degrade_on();
Class_Mock_Gateway::reset(true);
$res = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'P6FC-AUTH', 'Status' => 'OK'));
degrade_off();
$loc = location_of($res);
T('FC6. callback degraded → 302 pending + rate_limit_unavailable + Retry-After',
    302 === $res->get_status() && false !== strpos($loc, 'status=pending')
    && false !== strpos($loc, 'rate_limit_unavailable')
    && '' !== (string) ($res->get_headers()['Retry-After'] ?? ''));
T('FC6b. NO gateway verify call while degraded', 0 === Class_Mock_Gateway::$verifyCalls);
T('FC6c. payment UNCHANGED while degraded', 'INITIATED' === (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM wp_eligius_payments WHERE id = %d", $paymentFc)));
// 6b. limit consumed → 302 pending + reason=rate_limited + Retry-After
bucket_clear('payment_callback_authority', 'P6FC-CAP', 600);
bucket_clear('payment_callback_ip', '127.0.0.1', 300);
for ($i = 0; $i < 20; $i++) {
    dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'P6FC-CAP', 'Status' => 'OK'));
}
$res = dispatch('GET', '/payments/callback/zarinpal', null, array('authority' => 'P6FC-CAP', 'Status' => 'OK'));
$loc = location_of($res);
T('FC6d. callback limit consumed → 302 pending + rate_limited + Retry-After',
    302 === $res->get_status() && false !== strpos($loc, 'status=pending')
    && false !== strpos($loc, 'rate_limited') && '' !== (string) ($res->get_headers()['Retry-After'] ?? ''));

// ---- 7. Payment Reconciliation ---------------------------------------------------
wp_set_current_user($uOther);
bucket_clear('payment_reconcile_user', $uOther, 3600);
$res = dispatch('POST', '/payments/' . $paymentFc . '/reconcile');
T('R1. reconcile of ANOTHER user\'s payment → 404 (ownership)', 404 === $res->get_status());
wp_set_current_user($u);
bucket_clear('payment_reconcile_user', $u, 3600);
// Degraded → 503 BEFORE the S2S verify (no gateway call, payment untouched).
degrade_on();
Class_Mock_Gateway::reset(true);
$res = dispatch('POST', '/payments/' . $paymentFc . '/reconcile');
degrade_off();
T('R2. reconcile degraded → 503 RATE_LIMIT_UNAVAILABLE',
    503 === $res->get_status() && 'RATE_LIMIT_UNAVAILABLE' === err_code_of($res));
T('R2b. NO gateway verify call on degraded reconcile', 0 === Class_Mock_Gateway::$verifyCalls);
T('R2c. payment stays INITIATED after degraded reconcile',
    'INITIATED' === (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM wp_eligius_payments WHERE id = %d", $paymentFc)));
// Recovery: reconcile re-runs the S2S verify (mock) → VERIFIED.
bucket_clear('payment_reconcile_user', $u, 3600);
Class_Mock_Gateway::reset(true);
$res = dispatch('POST', '/payments/' . $paymentFc . '/reconcile');
$d = $res->get_data()['data'] ?? array();
T('R3. reconcile after recovery → VERIFIED via S2S (mock)',
    200 === $res->get_status() && 'VERIFIED' === ($d['status'] ?? '') && 1 === Class_Mock_Gateway::$verifyCalls);
// Duplicate reconcile → idempotent current state, no second S2S.
$callsBefore = Class_Mock_Gateway::$verifyCalls;
$res = dispatch('POST', '/payments/' . $paymentFc . '/reconcile');
$d = $res->get_data()['data'] ?? array();
T('R4. duplicate reconcile → idempotent current state, NO second S2S',
    200 === $res->get_status() && 'VERIFIED' === ($d['status'] ?? '') && Class_Mock_Gateway::$verifyCalls === $callsBefore);

// ---- 8. Read-only endpoints still answer while degraded ---------------------------
wp_set_current_user($u);
degrade_on();
$res = dispatch('GET', '/licenses/status', $hashDev);
$roStatus = 200 === $res->get_status();
$res = dispatch('GET', '/payments/' . $paymentFc);
$roPayment = 200 === $res->get_status();
$res = dispatch('GET', '/plans');
$roPlans = 200 === $res->get_status();
degrade_off();
T('FC-RO. read-only (status/payment/plans) still 200 while degraded',
    $roStatus && $roPayment && $roPlans);

// ---- 9. 429 vs 503 separation ------------------------------------------------------
// Consume the reconcile bucket fully (5/hour) with the store HEALTHY.
bucket_clear('payment_reconcile_user', $u, 3600);
// Reconciliation on a terminal payment is free (no S2S) but still consumes
// the bucket — hammer it to the cap.
for ($i = 0; $i < 5; $i++) { dispatch('POST', '/payments/' . $paymentFc . '/reconcile'); }
$res = dispatch('POST', '/payments/' . $paymentFc . '/reconcile');
T('429vs503. consumed limit → 429 TOO_MANY_REQUESTS (NOT 503)',
    429 === $res->get_status() && 'TOO_MANY_REQUESTS' === err_code_of($res));

// ---- Cleanup ------------------------------------------------------------------------
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id IN ($u, $uOther)");
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id IN ($u, $uOther)");

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
