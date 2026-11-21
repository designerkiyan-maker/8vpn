<?php
/**
 * Phase 5 + 5b transfer security tests — owner acceptance list.
 * State-independent (user 17 proc-level + users 20/21 REST-level), run twice
 * consecutively for proof.
 *
 * ⚠ SMS NOTE: SMS delivery here is a LOCAL MOCK (eligius_otp_send_sms stub
 * returning true). This suite does NOT exercise a real SMS provider — it
 * verifies the transfer contract, including the hard-fail path when SMS
 * cannot be delivered (S9).
 *
 * Phase 5b additions (owner decision):
 *  • a BRAND-NEW unregistered device registers as PENDING and initiates the
 *    transfer (REST E2E: R1-R4, R6-R8);
 *  • verify() is bound to the DESTINATION device (caller == to_device,
 *    fail-closed — S12/S13, R3);
 *  • the pending device is NEVER active before the OTP and CD-9 is never
 *    violated (R1/R2/R7);
 *  • Trial+Paid licenses and their dates survive the swap (R4).
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'eligius-test';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function dump_err($e) { return is_wp_error($e) ? $e->get_error_code() : json_encode($e); }

if (!function_exists('eligius_otp_send_sms')) {
    function eligius_otp_send_sms($mobile, $code) { return true; } // LOCAL MOCK — not a real SMS provider
}

use Eligius_Sub\License\Class_Device_Transfer;
use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

$proc = Class_Device_Transfer::instance();
$wpdb = $GLOBALS['wpdb'];

// ---- Clean independent state (fixture users created with EXPLICIT ids --
// portable across fresh WordPress installs) --------------------------------
// Resolve REAL user ids first (auto-increment ids are not 18/20/21), then
// clean by the resolved ids so repeated runs start truly clean.
function ensure_wp_user($wpdb, $id, $login) {
    if ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $id))) return;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->users} (ID, user_login, user_pass, user_email, user_registered)
         VALUES (%d, %s, %s, %s, %s)",
        $id, 'p6user' . $id, wp_hash_password('p6-pass-' . $id),
        'p6user' . $id . '@example.invalid', gmdate('Y-m-d H:i:s')));
}
function find_or_create_user($wpdb, $login) {
    $id = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if (!$id) {
        $id = (int) wp_insert_user([
            'user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.invalid', 'role' => 'subscriber',
        ]);
    }
    return $id;
}
ensure_wp_user($wpdb, 17, 'p6-user17');
ensure_wp_user($wpdb, 18, 'p5transfer-u18');
ensure_wp_user($wpdb, 20, 'p5newdevice-u20');
ensure_wp_user($wpdb, 21, 'p5react-u21');
$u18 = find_or_create_user($wpdb, 'p5transfer-u18');
$u20 = find_or_create_user($wpdb, 'p5newdevice-u20');
$u21 = find_or_create_user($wpdb, 'p5react-u21');
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN (17, $u18, $u20, $u21)");
$wpdb->query("DELETE FROM wp_eligius_licenses       WHERE user_id IN (17, $u18, $u20, $u21)");
$wpdb->query("DELETE FROM wp_eligius_devices        WHERE user_id IN (17, $u18, $u20, $u21)");
// Drop leftover mid-failure test triggers from a crashed previous run.
$wpdb->query("DROP TRIGGER IF EXISTS p5_block_audit");
$wpdb->query("DROP TRIGGER IF EXISTS p5_block_events");

function clear_rl_table($wpdb, array $tuples) {
    // Phase 6 M-3: limiter storage is the rate_limits TABLE — stale buckets
    // from previous runs must be cleared there (transients are dead storage).
    foreach ($tuples as $t) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_rate_limits WHERE bucket_id = %s",
            md5($t[0] . '|' . $t[1] . '|' . $t[2])));
    }
}
function otp_of($tid) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT otp_code FROM wp_eligius_device_transfers WHERE id = %d", $tid));
}

// =============== PART 1 — proc-level core guarantees (user 17) ==============
$oldHash = str_repeat('a', 64);
$newHash = str_repeat('b', 64);
$hashC   = str_repeat('c', 64);
$hashD   = str_repeat('d', 64);
$hashE   = str_repeat('e', 64);
update_user_meta(17, 'mobile', '09123456789');
Class_Device_Repository::create(17, $oldHash, 'test-pk-old', 'Old Device', 1, 'test');
Class_Device_Repository::create(17, $newHash, 'test-pk-new', 'New Device', 0, 'test');
Class_Device_Repository::create(17, $hashC, 'test-pk-c', 'Dev C', 0, 'test');
Class_Device_Repository::create(17, $hashD, 'test-pk-d', 'Dev D', 0, 'test');
Class_Device_Repository::create(17, $hashE, 'test-pk-e', 'Dev E', 0, 'test');
$oldDev = Class_Device_Repository::find_by_hash(17, $oldHash);
$newDev = Class_Device_Repository::find_by_hash(17, $newHash);
$devC   = Class_Device_Repository::find_by_hash(17, $hashC);
$devD   = Class_Device_Repository::find_by_hash(17, $hashD);
$devE   = Class_Device_Repository::find_by_hash(17, $hashE);

// ---- S1: transfer from NEW device (no access to old device needed) ---------
$t1 = $proc->start_from_new_device(17, $newHash);
T('1. transfer start from new device succeeds (old device found server-side)',
    !is_wp_error($t1) && $t1['otp_sent'] === true) or print('  err: ' . dump_err($t1) . "\n");
$tid1 = is_wp_error($t1) ? 0 : $t1['transfer_id'];
$otp1 = otp_of($tid1);
T('1b. OTP stored (6 digits)', strlen((string) $otp1) === 6 && ctype_digit((string) $otp1));
$verify1 = $proc->verify(17, $tid1, $otp1, $newDev->id);
T('1c. OTP verify succeeds (caller = destination device)', !is_wp_error($verify1) && $verify1['verified'] === true) or print('  err: ' . dump_err($verify1) . "\n");
$oldAfter = Class_Device_Repository::find($oldDev->id);
$newAfter = Class_Device_Repository::find($newDev->id);
T('1d. old deactivated, new activated (atomic swap)',
    (int) $oldAfter->is_active === 0 && (int) $newAfter->is_active === 1);

// ---- S2: wrong OTP ----------------------------------------------------------
$t2 = $proc->start_from_new_device(17, $hashC);
$tid2 = is_wp_error($t2) ? 0 : $t2['transfer_id'];
$otp2 = otp_of($tid2);
$wrong = $proc->verify(17, $tid2, '000000', $devC->id);
T('2. wrong OTP rejected', is_wp_error($wrong) && 'eligius_transfer_bad_otp' === $wrong->get_error_code());
$t2row = $wpdb->get_row($wpdb->prepare("SELECT status FROM wp_eligius_device_transfers WHERE id = %d", $tid2));
$stillActive = Class_Device_Repository::find($newAfter->id);
T('2b. wrong OTP changes nothing (transfer PENDING, active device intact)',
    $t2row && 'PENDING' === $t2row->status && (int) $stillActive->is_active === 1);

// ---- S3: OTP consumed on success, reuse rejected (single-use) --------------
$good = $proc->verify(17, $tid2, $otp2, $devC->id);
T('3. correct OTP verifies (consumes transfer)', !is_wp_error($good) && $good['verified'] === true) or print('  err: ' . dump_err($good) . "\n");
$reuse = $proc->verify(17, $tid2, $otp2, $devC->id);
T('3b. OTP reuse rejected (single-use)', is_wp_error($reuse) && 'eligius_transfer_not_pending' === $reuse->get_error_code());

// ---- S4: expired OTP --------------------------------------------------------
$t4 = $proc->start_from_new_device(17, $hashD);
$tid4 = is_wp_error($t4) ? 0 : $t4['transfer_id'];
$otp4 = otp_of($tid4);
$wpdb->query($wpdb->prepare("UPDATE wp_eligius_device_transfers SET expires_at = '2020-01-01 00:00:00' WHERE id = %d", $tid4));
$expired = $proc->verify(17, $tid4, $otp4, $devD->id);
T('4. expired OTP rejected', is_wp_error($expired) && 'eligius_transfer_not_pending' === $expired->get_error_code());

// ---- S5: blocked device cannot initiate -------------------------------------
$toBlock = Class_Device_Repository::find_by_hash(17, $hashD);
Class_Device_Repository::set_blocked($toBlock->id, true);
$bt = $proc->start_from_new_device(17, $hashD);
T('5. blocked device cannot initiate transfer', is_wp_error($bt) && 'eligius_device_blocked' === $bt->get_error_code());
Class_Device_Repository::set_blocked($toBlock->id, false);

// ---- S6: blocked DESTINATION device in verify -------------------------------
$t6 = $proc->start_from_new_device(17, $hashD);
$tid6 = is_wp_error($t6) ? 0 : $t6['transfer_id'];
$otp6 = otp_of($tid6);
Class_Device_Repository::set_blocked($toBlock->id, true); // destination device
$v6 = $proc->verify(17, $tid6, $otp6, $devD->id);
T('6. blocked destination device in verify → rejected',
    is_wp_error($v6) && 'eligius_device_blocked' === $v6->get_error_code());
Class_Device_Repository::set_blocked($toBlock->id, false);
// t6 stayed PENDING after the failed verify (ROLLBACK) — expire it so the
// pending guard does not mask later scenarios.
$wpdb->query($wpdb->prepare("UPDATE wp_eligius_device_transfers SET expires_at = '2020-01-01 00:00:00' WHERE id = %d", $tid6));

// ---- S7: concurrent transfer start → pending guard (CONFLICT) ---------------
$tA = $proc->start_from_new_device(17, $hashE);
T('7. first concurrent transfer starts', !is_wp_error($tA)) or print('  err: ' . dump_err($tA) . "\n");
$tB = $proc->start_from_new_device(17, $hashD);
T('7b. second concurrent transfer → CONFLICT (eligius_transfer_pending)',
    is_wp_error($tB) && 'eligius_transfer_pending' === $tB->get_error_code());
$tid7 = is_wp_error($tA) ? 0 : $tA['transfer_id'];
$wpdb->query($wpdb->prepare("UPDATE wp_eligius_device_transfers SET expires_at = '2020-01-01 00:00:00' WHERE id = %d", $tid7));

// ---- S8: Trial→Paid licenses survive transfer (dates preserved) -------------
$wpdb->insert("wp_eligius_licenses", [
    'user_id' => 17, 'plan_key' => 'trial', 'plan_label' => 'Free Trial', 'duration_days' => 30,
    'started_at' => '2026-11-01 00:00:00', 'expires_at' => '2026-12-01 00:00:00',
    'status' => 'TRIAL', 'source' => 'trial', 'device_id' => (int) $devC->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
$wpdb->insert("wp_eligius_licenses", [
    'user_id' => 17, 'plan_key' => 'monthly', 'plan_label' => 'Monthly', 'duration_days' => 30,
    'started_at' => '2026-12-01 00:00:00', 'expires_at' => '2026-12-31 00:00:00',
    'status' => 'ACTIVE', 'source' => 'payment', 'device_id' => (int) $devC->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
$t8 = $proc->start_from_new_device(17, $hashD); // from = dev C (active), to = dev D
$tid8 = is_wp_error($t8) ? 0 : $t8['transfer_id'];
$otp8 = otp_of($tid8);
$v8 = $proc->verify(17, $tid8, $otp8, $devD->id);
T('8. Trial→Paid transfer verify succeeds', !is_wp_error($v8) && $v8['verified'] === true) or print('  err: ' . dump_err($v8) . "\n");
$lics = $wpdb->get_results($wpdb->prepare(
    "SELECT status, device_id, started_at, expires_at FROM wp_eligius_licenses WHERE user_id = %d ORDER BY expires_at DESC", 17));
$hasTrial = false; $hasPaid = false; $allMoved = true; $datesKept = true;
foreach ($lics as $l) {
    if ('TRIAL' === $l->status) { $hasTrial = true; $datesKept = $datesKept && '2026-11-01 00:00:00' === $l->started_at && '2026-12-01 00:00:00' === $l->expires_at; }
    if ('ACTIVE' === $l->status) { $hasPaid = true; $datesKept = $datesKept && '2026-12-01 00:00:00' === $l->started_at && '2026-12-31 00:00:00' === $l->expires_at; }
    if ((int) $l->device_id !== (int) $devD->id) $allMoved = false;
}
T('8b. Trial + Paid coexist after transfer, bindings moved, dates preserved',
    $hasTrial && $hasPaid && $allMoved && $datesKept && 2 === count($lics));

// ---- S9: SMS provider unavailable (hard-fail) -------------------------------
delete_user_meta(17, 'mobile');
delete_user_meta(17, 'billing_phone');
$t9 = $proc->start_from_new_device(17, $hashE); // from = dev D (active)
T('9. SMS unavailable → transfer NOT created (eligius_sms_unavailable)',
    is_wp_error($t9) && 'eligius_sms_unavailable' === $t9->get_error_code());
$pending9 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM wp_eligius_device_transfers
      WHERE user_id = 17 AND status = 'PENDING' AND expires_at > %s",
    Class_UTC_Time::mysql_utc()));
T('9b. no live PENDING transfer left after SMS hard-fail', 0 === $pending9);
update_user_meta(17, 'mobile', '09123456789');

// ---- S10: another account cannot use a transfer_id --------------------------
$t10 = $proc->start_from_new_device(17, $hashC); // from = dev D (active), to = dev C
$tid10 = is_wp_error($t10) ? 0 : $t10['transfer_id'];
$otp10 = otp_of($tid10);
$crossUser = $proc->verify($u18, $tid10, $otp10, $devC->id);
T('10. other user cannot use the transfer_id (account-bound)',
    is_wp_error($crossUser) && 'eligius_transfer_not_pending' === $crossUser->get_error_code());
$v10 = $proc->verify(17, $tid10, $otp10, $devC->id);
T('10b. control: destination device with correct OTP succeeds', !is_wp_error($v10) && $v10['verified'] === true);

// ---- S12: third device of the SAME account rejected (owner decision) --------
$t12 = $proc->start_from_new_device(17, $hashE); // from = dev C (active), to = dev E
$tid12 = is_wp_error($t12) ? 0 : $t12['transfer_id'];
$otp12 = otp_of($tid12);
$vWrongCaller = $proc->verify(17, $tid12, $otp12, $devD->id); // caller ≠ to_device
T('12. verify from a different device of the SAME account → rejected (eligius_transfer_wrong_device)',
    is_wp_error($vWrongCaller) && 'eligius_transfer_wrong_device' === $vWrongCaller->get_error_code());
$t12row = $wpdb->get_row($wpdb->prepare("SELECT status FROM wp_eligius_device_transfers WHERE id = %d", $tid12));
$cStill = Class_Device_Repository::find($devC->id);
$eStill = Class_Device_Repository::find($devE->id);
T('12b. wrong-caller verify changes nothing (PENDING, devices untouched)',
    $t12row && 'PENDING' === $t12row->status && (int) $cStill->is_active === 1 && (int) $eStill->is_active === 0);

// ---- S13: fail-closed — no caller device id → rejected ----------------------
$vNoCaller = $proc->verify(17, $tid12, $otp12, 0);
T('13. missing caller device id → fail-closed rejected (transfer_id alone never trusted)',
    is_wp_error($vNoCaller) && 'eligius_transfer_wrong_device' === $vNoCaller->get_error_code());
$vGoodCaller = $proc->verify(17, $tid12, $otp12, $devE->id);
T('13b. control: destination device verify succeeds', !is_wp_error($vGoodCaller) && $vGoodCaller['verified'] === true);

// ---- S11: mid-failure → full ROLLBACK ---------------------------------------
$t11 = $proc->start_from_new_device(17, $hashE); // from = dev E? — dev E now ACTIVE (13b); from must be ACTIVE
// After 13b: dev E is ACTIVE. Start a transfer to dev D (inactive).
$t11 = $proc->start_from_new_device(17, $hashD);
$tid11 = is_wp_error($t11) ? 0 : $t11['transfer_id'];
$otp11 = otp_of($tid11);
// Test fixture: force BOTH audit inserts to fail mid-transaction (after the
// transfer/devices/licenses have already been written) — the verify() must
// ROLLBACK everything. Triggers only affect NEW inserts and are dropped
// immediately after this scenario.
$wpdb->query("CREATE TRIGGER p5_block_audit BEFORE INSERT ON wp_eligius_audit_logs FOR EACH ROW BEGIN IF NEW.event_type = 'device.transferred' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p5-test-block'; END IF; END");
$wpdb->query("CREATE TRIGGER p5_block_events BEFORE INSERT ON wp_eligius_license_events FOR EACH ROW BEGIN IF NEW.event_type = 'device.transferred' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p5-test-block'; END IF; END");
$v11 = $proc->verify(17, $tid11, $otp11, $devD->id);
T('11. audit failure mid-transaction → ROLLBACK (eligius_db_audit_failed)',
    is_wp_error($v11) && 'eligius_db_audit_failed' === $v11->get_error_code());
$wpdb->query("DROP TRIGGER IF EXISTS p5_block_audit");
$wpdb->query("DROP TRIGGER IF EXISTS p5_block_events");
$t11row = $wpdb->get_row($wpdb->prepare("SELECT status FROM wp_eligius_device_transfers WHERE id = %d", $tid11));
$fromAfter = Class_Device_Repository::find($devE->id);
$toAfter = Class_Device_Repository::find($devD->id);
$licBind = $wpdb->get_var($wpdb->prepare(
    "SELECT device_id FROM wp_eligius_licenses WHERE user_id = 17 AND status = 'ACTIVE' LIMIT 1"));
T('11b. ROLLBACK preserved everything (PENDING, devices, license binding)',
    $t11row && 'PENDING' === $t11row->status
    && (int) $fromAfter->is_active === 1 && (int) $toAfter->is_active === 0
    && (int) $licBind === (int) $devE->id);

// =============== PART 2 — REST E2E: brand-new UNREGISTERED device ===========
// Owner flow: login → device identity (hash = fixture) → register (PENDING,
// slot taken) → transfer start from the pending device → OTP → verify ON the
// destination → atomic swap. Real REST dispatch, real routes.
do_action('rest_api_init');

update_user_meta($u20, 'mobile', '09122220000');
wp_set_current_user($u20);

$hashOld20 = str_repeat('6', 64);
$hashBrandNew = str_repeat('4', 64);   // never registered anywhere before
$hashThird20  = str_repeat('5', 64);   // a third device of the same account
Class_Device_Repository::create($u20, $hashOld20, 'pk-u20-old', 'U20 Old', 1, 'test');
Class_Device_Repository::create($u20, $hashThird20, 'pk-u20-third', 'U20 Third', 0, 'test');
$old20 = Class_Device_Repository::find_by_hash($u20, $hashOld20);
$third20 = Class_Device_Repository::find_by_hash($u20, $hashThird20);

$wpdb->insert("wp_eligius_licenses", [
    'user_id' => $u20, 'plan_key' => 'trial', 'plan_label' => 'Free Trial', 'duration_days' => 30,
    'started_at' => '2026-11-10 00:00:00', 'expires_at' => '2026-12-10 00:00:00',
    'status' => 'TRIAL', 'source' => 'trial', 'device_id' => (int) $old20->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
$wpdb->insert("wp_eligius_licenses", [
    'user_id' => $u20, 'plan_key' => 'monthly', 'plan_label' => 'Monthly', 'duration_days' => 30,
    'started_at' => '2026-12-10 00:00:00', 'expires_at' => '2027-01-09 00:00:00',
    'status' => 'ACTIVE', 'source' => 'payment', 'device_id' => (int) $old20->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);

// Rate-limit counters are per-window transients — clear them so repeated
// acceptance runs do not trip caps (limiter behavior itself is NOT modified).
clear_rl_table($wpdb, array(
    array('transfer_start_user', $u20, 86400), array('transfer_verify_user', $u20, 60),
    array('device_register_user', $u20, 3600), array('trial_user', $u20, 86400),
    array('device_register_user', $u21, 3600), array('global_ip', '127.0.0.1', 60),
));

function rest_post($route, $hash, array $params = []) {
    $req = new WP_REST_Request('POST', '/eligius/v1' . $route);
    if (null !== $hash) $req->set_header('X-Device-Hash', $hash);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return rest_get_server()->dispatch($req);
}

// ---- R1: brand-new device registers as PENDING (slot taken) -----------------
$resReg = rest_post('/devices/register', $hashBrandNew, [
    'fingerprint' => $hashBrandNew,
    'public_key'  => "-----BEGIN PUBLIC KEY-----\nMIIBpk-u20-new\n-----END PUBLIC KEY-----\n",
    'label'       => 'Brand New Device',
]);
$dReg = $resReg->get_data();
$brandNewRow = Class_Device_Repository::find_by_hash($u20, $hashBrandNew);
T('R1. brand-new device → register → 201 PENDING (pending_transfer=true, is_active=false)',
    201 === $resReg->get_status() && true === ($dReg['data']['pending_transfer'] ?? false)
    && false === ($dReg['data']['is_active'] ?? true));
T('R1b. pending row exists INACTIVE + real key stored (binding ready for challenge)',
    $brandNewRow && (int) $brandNewRow->is_active === 0 && false !== strpos((string) $brandNewRow->public_key, 'BEGIN PUBLIC KEY'));
T('R1c. CD-9 intact: exactly ONE active device (the old one), licenses NOT moved',
    (int) Class_Device_Repository::find($old20->id)->is_active === 1
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = $u20 AND device_id = {$old20->id} AND status IN ('ACTIVE','TRIAL')") === 2);

// ---- R2: transfer start from the PENDING device; state unchanged pre-OTP ----
$resStart = rest_post('/devices/transfer/start', $hashBrandNew);
$dStart = $resStart->get_data();
$tidR = (int) ($dStart['data']['transfer_id'] ?? 0);
$otpR = otp_of($tidR);
T('R2. pending device → transfer/start → 201 (otp_sent, old device found server-side)',
    201 === $resStart->get_status() && true === ($dStart['data']['otp_sent'] ?? false) && $tidR > 0);
$oldStill = Class_Device_Repository::find($old20->id);
$newStill = Class_Device_Repository::find($brandNewRow->id);
T('R2b. BEFORE OTP: old device still ACTIVE, pending device still INACTIVE (never activated early)',
    (int) $oldStill->is_active === 1 && (int) $newStill->is_active === 0);

// ---- R3: verify from a THIRD device of the same account → rejected ----------
$resWrong = rest_post('/devices/transfer/verify', $hashThird20, [
    'transfer_id' => (string) $tidR, 'otp_code' => (string) $otpR,
]);
T('R3. REST verify from third device → 403 eligius_transfer_wrong_device',
    403 === $resWrong->get_status() && 'eligius_transfer_wrong_device' === ($resWrong->get_data()['error']['code'] ?? ''));
$oldR3 = Class_Device_Repository::find($old20->id);
$newR3 = Class_Device_Repository::find($brandNewRow->id);
T('R3b. rejected caller changed nothing (PENDING, devices untouched)',
    (int) $oldR3->is_active === 1 && (int) $newR3->is_active === 0);

// ---- R4: verify ON the destination (brand-new device) → atomic swap ---------
$resVerify = rest_post('/devices/transfer/verify', $hashBrandNew, [
    'transfer_id' => (string) $tidR, 'otp_code' => (string) $otpR,
]);
T('R4. REST verify from DESTINATION device → 200 verified',
    200 === $resVerify->get_status() && true === ($resVerify->get_data()['data']['verified'] ?? false));
$oldR4 = Class_Device_Repository::find($old20->id);
$newR4 = Class_Device_Repository::find($brandNewRow->id);
T('R4b. atomic swap: old deactivated, brand-new device ACTIVATED',
    (int) $oldR4->is_active === 0 && (int) $newR4->is_active === 1);
$licsR = $wpdb->get_results("SELECT status, device_id, started_at, expires_at FROM wp_eligius_licenses WHERE user_id = $u20 ORDER BY expires_at DESC");
$movedAll = true; $datesKept = true;
foreach ($licsR as $l) {
    if ((int) $l->device_id !== (int) $newR4->id) $movedAll = false;
    if ('TRIAL' === $l->status) $datesKept = $datesKept && '2026-11-10 00:00:00' === $l->started_at;
    if ('ACTIVE' === $l->status) $datesKept = $datesKept && '2026-12-10 00:00:00' === $l->started_at;
}
T('R4c. Trial+Paid moved to the new device with dates preserved',
    2 === count($licsR) && $movedAll && $datesKept);

// ---- R5: re-register of an existing PENDING row → 201 pending (no CONFLICT) -
// (fresh slot-taken state: old device is now INACTIVE, brand-new is ACTIVE —
// use the third device? third is registered+inactive too. Create a 4th hash.)
$hashFourth = str_repeat('7', 64);
$resReg2 = rest_post('/devices/register', $hashFourth, [
    'fingerprint' => $hashFourth,
    'public_key'  => "-----BEGIN PUBLIC KEY-----\nMIIBpk-u20-fourth\n-----END PUBLIC KEY-----\n",
    'label'       => 'Fourth Device',
]);
$resReg3 = rest_post('/devices/register', $hashFourth, [
    'fingerprint' => $hashFourth,
    'public_key'  => "-----BEGIN PUBLIC KEY-----\nMIIBpk-u20-fourth\n-----END PUBLIC KEY-----\n",
    'label'       => 'Fourth Device',
]);
T('R5. re-register of a PENDING row → 201 pending again (key refreshed, no CONFLICT)',
    201 === $resReg2->get_status() && 201 === $resReg3->get_status()
    && false === ($resReg3->get_data()['data']['is_active'] ?? true));
T('R5b. still exactly ONE active device after two pending registrations',
    1 === (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_devices WHERE user_id = $u20 AND is_active = 1"));

// ---- R6: trial grant from a PENDING device → rejected (not trial path) ------
$resTrial = rest_post('/licenses/trial', $hashFourth);
T('R6. trial grant from a pending device → rejected (device not active — no trial path)',
    $resTrial->get_status() >= 400);

// ---- R7: admin-release recovery — reactivation when the slot is FREE --------
$hashX = str_repeat('8', 64);
wp_set_current_user($u21);
Class_Device_Repository::create($u21, $hashX, 'pk-x', 'U21 Dev', 1, 'test');
$xRow = Class_Device_Repository::find_by_hash($u21, $hashX);
$wpdb->insert("wp_eligius_licenses", [
    'user_id' => $u21, 'plan_key' => 'trial', 'plan_label' => 'Free Trial', 'duration_days' => 30,
    'started_at' => '2026-11-01 00:00:00', 'expires_at' => '2026-12-01 00:00:00',
    'status' => 'TRIAL', 'source' => 'trial', 'device_id' => (int) $xRow->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
// Admin releases the device (is_active=0 + license unbind) — as Class_Devices_Admin does.
Class_Device_Repository::set_active($xRow->id, false);
$wpdb->query("UPDATE wp_eligius_licenses SET device_id = NULL WHERE device_id = {$xRow->id}");

$resRe = rest_post('/devices/register', $hashX, [
    'fingerprint' => $hashX,
    'public_key'  => "-----BEGIN PUBLIC KEY-----\nMIIBpk-x\n-----END PUBLIC KEY-----\n",
    'label'       => 'U21 Dev',
]);
$dRe = $resRe->get_data();
$xRow2 = Class_Device_Repository::find($xRow->id);
$licRebind = $wpdb->get_var("SELECT device_id FROM wp_eligius_licenses WHERE user_id = $u21 AND status = 'TRIAL'");
T('R7. re-register with FREE slot → 201 reactivated (is_active=true)',
    201 === $resRe->get_status() && true === ($dRe['data']['is_active'] ?? false)
    && true === ($dRe['data']['reactivated'] ?? false) && (int) $xRow2->is_active === 1);
T('R7b. licenses re-bound to the reactivated device',
    (int) $licRebind === (int) $xRow->id);

// ---- R8: GET /devices surfaces pending_transfer for the destination ---------
// (old flow: the ACTIVE old device of user 17 starts a transfer TO dev E;
//  then the DESTINATION's device list must report pending_transfer.)
$tOld = $proc->start(17, Class_Device_Repository::find($devE->id), $hashD); // from = dev E (active), to = dev D
$tidOld = is_wp_error($tOld) ? 0 : $tOld['transfer_id'];
$reqList = new WP_REST_Request('GET', '/eligius/v1/devices');
$reqList->set_header('X-Device-Hash', $hashD); // destination device
$resList = rest_get_server()->dispatch($reqList);
$dList = $resList->get_data();
T('R8. destination device list reports pending_transfer (OTP entry belongs THERE)',
    200 === $resList->get_status()
    && ($dList['data']['pending_transfer']['transfer_id'] ?? 0) === $tidOld);
$reqList2 = new WP_REST_Request('GET', '/eligius/v1/devices');
$reqList2->set_header('X-Device-Hash', $hashE); // initiating device
$resList2 = rest_get_server()->dispatch($reqList2);
T('R8b. initiating device does NOT get the OTP entry (verify is destination-only)',
    200 === $resList2->get_status()
    && null === ($resList2->get_data()['data']['pending_transfer'] ?? null));
$wpdb->query($wpdb->prepare("UPDATE wp_eligius_device_transfers SET expires_at = '2020-01-01 00:00:00' WHERE id = %d", $tidOld));

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
