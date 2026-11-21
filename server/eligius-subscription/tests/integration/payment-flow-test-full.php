<?php
/**
 * Phase 4 payment integration tests (wp-cli eval harness) — runs against the
 * real local WordPress + MariaDB with a Mock gateway (docs: mock/fixture
 * allowed; real sandbox HTTP reported separately as NOT covered).
 *
 * CLEAN rebuild (final audit): scenario 6 assertion restored (was lost in
 * sequential rewrites), scenarios 15-18b state-guarded, deterministic RESULT.
 */

if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)

function ensure_pf_user($wpdb, $id, $login) {
    if ($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $id))) return;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->users} (ID, user_login, user_pass, user_email, user_registered)
         VALUES (%d, %s, %s, %s, %s)",
        $id, 'p6user' . $id, wp_hash_password('p6-pass-' . $id),
        'p6user' . $id . '@example.invalid', gmdate('Y-m-d H:i:s')));
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'eligius-test';

require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; echo "FAIL  $label\n"; }
}

// Register the Mock gateway + force it for the 'mock' key.
// Fresh-install portability: fixture users referenced by literal ID.
for ($i = 10; $i <= 16; $i++) { ensure_pf_user($wpdb, $i, 'p6user' . $i); }
require_once __DIR__ . '/class-mock-gateway.php';
add_filter('eligius_sub_payment_gateway_instance', function ($instance, $key) {
    if ('mock' === $key) return new \Eligius_Sub\Payment\Gateways\Class_Mock_Gateway();
    return $instance;
}, 10, 2);

use Eligius_Sub\Payment\Class_Payment_Processor;
use Eligius_Sub\Core\Class_Device_Repository as PFDeviceRepo;
use Eligius_Sub\Payment\Gateways\Class_Mock_Gateway;
use Eligius_Sub\License\Class_License_Manager;
use Eligius_Sub\Core\Class_Device_Repository;

$proc = Class_Payment_Processor::instance();
$mgr = Class_License_Manager::instance();
$wpdb = $GLOBALS['wpdb'];

// Deterministic state for the test users (15, 16).
$wpdb->query("SET FOREIGN_KEY_CHECKS=0");
$wpdb->query("DELETE FROM wp_eligius_license_events WHERE user_id IN (15,16)");
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id IN (15,16)");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id IN (15,16)");
$wpdb->query("DELETE i FROM wp_eligius_payment_idempotency i LEFT JOIN wp_eligius_payments p ON i.gateway_ref = p.gateway_ref WHERE p.id IS NULL");
$wpdb->query("SET FOREIGN_KEY_CHECKS=1");

// Active device for each test user (license activation binds to it).
foreach ([15 => 'test-dev-15', 16 => 'test-dev-16'] as $uid => $dh) {
    if (!$wpdb->get_row($wpdb->prepare("SELECT id FROM wp_eligius_devices WHERE user_id = %d", $uid))) {
        $wpdb->insert("wp_eligius_devices", [
            'user_id' => $uid, 'device_hash' => str_repeat(dechex($uid % 16), 64),
            'public_key' => 'test', 'label' => "Pay Device $uid", 'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

wp_set_current_user(15);

// ---------------------------------------------------------------- 1. create
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$created = $proc->create_payment(15, 'monthly', 'mock');
T('1. create payment returns redirect+authority', !is_wp_error($created) && !empty($created['redirect_url']) && !empty($created['authority']));
$pid = $created['payment_id'];
$row = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = $pid");
T('1b. payment INITIATED with amount IRT', $row && 'INITIATED' === $row->status && (int) $row->amount === 150000);

// ------------------------------------------------- 2. gateway initiate error
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::$initiateError = 'simulated outage';
$err = $proc->create_payment(15, 'monthly', 'mock');
T('2. gateway initiate failure → GATEWAY_UNAVAILABLE', is_wp_error($err) && 'GATEWAY_UNAVAILABLE' === $err->get_error_code());
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::$initiateError = null;

// ------------------------------------------ 3. callback success + S2S + activation
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$auth = $row->gateway_ref;
$cb = $proc->handle_callback('mock', ['authority' => $auth, 'status' => 'OK']);
T('3. callback verified', !is_wp_error($cb) && 'VERIFIED' === $cb['status'] && !$cb['duplicate']);
$row2 = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = $pid");
T('3b. payment VERIFIED + license linked', 'VERIFIED' === $row2->status && (int) $row2->license_id > 0);
$lic = $wpdb->get_row("SELECT * FROM wp_eligius_licenses WHERE id = " . (int) $row2->license_id);
T('3c. license ACTIVE, source=payment', $lic && 'ACTIVE' === $lic->status && 'payment' === $lic->source);

// --------------------------------------------------------- 4. duplicate callback
$cb2 = $proc->handle_callback('mock', ['authority' => $auth, 'status' => 'OK']);
T('4. duplicate callback → duplicate:true', !is_wp_error($cb2) && $cb2['duplicate']);
$cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 15 AND status = 'ACTIVE'");
T('4b. exactly one ACTIVE license after duplicate', 1 === $cnt);

// -------------------------------------- 5. concurrent callbacks (parallel PHP)
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$created2 = $proc->create_payment(16, 'monthly', 'mock');
$auth2 = $created2['authority'];
$script = ABSPATH . 'wp-content/plugins/eligius-subscription/tests/integration/run-callback.php';
file_put_contents($script, '<?php
if (!defined("ABSPATH")) { define("ABSPATH", getenv("ELIGIUS_WP_ROOT") ?: "E:/xampp/htdocs/wp/"); }
$_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["HTTP_USER_AGENT"] = "eligius-test";
require ABSPATH . "wp-load.php";
if (!class_exists("Eligius_Sub\\Payment\\Gateways\\Class_Mock_Gateway")) {
    require_once __DIR__ . "/class-mock-gateway.php";
}
add_filter("eligius_sub_payment_gateway_instance", function ($i, $k) { if ("mock" === $k) return new \Eligius_Sub\Payment\Gateways\Class_Mock_Gateway(); return $i; }, 10, 2);
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$r = \Eligius_Sub\Payment\Class_Payment_Processor::instance()->handle_callback("mock", ["authority" => $argv[1], "status" => "OK"]);
echo is_wp_error($r) ? "ERR" . $r->get_error_code() : $r["status"] . ($r["duplicate"] ? "-DUP" : "-FRESH");
');
$phpBin = getenv('P6_PHP_BIN') ?: 'E:/xampp/php/php.exe';
$tmpOut = sys_get_temp_dir();
$cmd1 = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($auth2) . ' > ' . escapeshellarg($tmpOut . '/cb1.txt') . ' 2>&1';
$cmd2 = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($auth2) . ' > ' . escapeshellarg($tmpOut . '/cb2.txt') . ' 2>&1';
$p1 = popen($cmd1, 'r'); pclose($p1);
$p2 = popen($cmd2, 'r'); pclose($p2);
$r1 = trim(file_get_contents($tmpOut . '/cb1.txt'));
$r2 = trim(file_get_contents($tmpOut . '/cb2.txt'));
$fresh = substr_count($r1 . $r2, 'VERIFIED-FRESH');
$dup = substr_count($r1 . $r2, 'VERIFIED-DUP');
T('5. concurrent callbacks → exactly one FRESH activation (' . $r1 . ' | ' . $r2 . ')', 1 === $fresh && 1 === $dup);
T('5b. single ACTIVE license for concurrent user', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 16 AND status = 'ACTIVE'") === 1);

// --------------------------------------------------- 6. amount mismatch
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
// Scripted mismatch: mock returns IRR = expected*10 + 500 (wrong).
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::$verifyResult = ['verified' => true, 'amount' => 150000 * 10 + 500, 'ref_id' => 'X', 'error' => null];
$created3 = $proc->create_payment(15, 'monthly', 'mock');
$cb3 = $proc->handle_callback('mock', ['authority' => $created3['authority'], 'status' => 'OK']);
$row4 = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = " . $created3['payment_id']);
T('6. amount mismatch → FAILED, no license',
    'FAILED' === ($cb3['status'] ?? '') && $row4 && 'FAILED' === $row4->status && null === $row4->license_id);

// ---------------------------------------------------- 7. forged callback
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(false);
$created4 = $proc->create_payment(15, 'monthly', 'mock');
$cb4 = $proc->handle_callback('mock', ['authority' => $created4['authority'], 'status' => 'OK']);
T('7. forged/S2S-rejected callback → payment FAILED', 'FAILED' === ($cb4['status'] ?? ''));
$row5 = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = " . $created4['payment_id']);
T('7b. no license activated on forged callback', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 15 AND status = 'ACTIVE' AND payment_id = " . $created4['payment_id']) === 0);

// ---------------------------------------------------- 8. IRT→IRR conversion
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$created5 = $proc->create_payment(15, 'quarterly', 'mock');
$cb5 = $proc->handle_callback('mock', ['authority' => $created5['authority'], 'status' => 'OK']);
// Mock echoes expected*10 — processor passes price_irt=400000 → gateway sees 4,000,000 IRR.
T('8. IRT→IRR conversion consistent (400,000 IRT → 4,000,000 IRR)', !is_wp_error($cb5) && 'VERIFIED' === ($cb5['status'] ?? ''));

// ------------------------------------------ 9. activation failure → recovery
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$created6 = $proc->create_payment(15, 'monthly', 'mock');
// Phase 6: a same-plan renewal now EXTENDS correctly (the activation-fix), so
// the rollback trigger is a FORCED audit failure instead of the old duplicate
// insert: block BOTH audit paths for the activation event → the unaudited
// activation must roll the whole callback back (doc 02 §2.8.3).
$wpdb->query("CREATE TRIGGER p6_block_lev BEFORE INSERT ON wp_eligius_license_events FOR EACH ROW BEGIN IF NEW.event_type IN ('license.renewed','license.activated') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p6-block'; END IF; END");
$wpdb->query("CREATE TRIGGER p6_block_au BEFORE INSERT ON wp_eligius_audit_logs FOR EACH ROW BEGIN IF NEW.event_type IN ('license.renewed','license.activated') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p6-block'; END IF; END");
$cb6 = $proc->handle_callback('mock', ['authority' => $created6['authority'], 'status' => 'OK']);
$wpdb->query("DROP TRIGGER IF EXISTS p6_block_lev");
$wpdb->query("DROP TRIGGER IF EXISTS p6_block_au");
$row7 = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = " . $created6['payment_id']);
T('9. activation failure → full ROLLBACK, payment stays INITIATED (retryable)',
    (is_wp_error($cb6) && 'eligius_activation_failed' === $cb6->get_error_code()) && 'INITIATED' === $row7->status);

// Retry without the trigger → activation succeeds.
$cb6b = $proc->handle_callback('mock', ['authority' => $created6['authority'], 'status' => 'OK']);
T('9b. recovery: retried callback activates successfully', !is_wp_error($cb6b) && 'VERIFIED' === $cb6b['status']);

// ------------------------------------------------------- 10. refund DISABLED
// Automatic refunds are disabled (Phase-4 final audit): the attempt must be
// rejected BEFORE any database change — payment stays VERIFIED and the
// license stays ACTIVE untouched. No payment.refunded audit is written.
$pidForRefund = (int) $wpdb->get_var(
    "SELECT id FROM wp_eligius_payments WHERE user_id = 15 AND status = 'VERIFIED' AND license_id IS NOT NULL ORDER BY id DESC LIMIT 1"
);
$lic15 = (int) $wpdb->get_var("SELECT license_id FROM wp_eligius_payments WHERE id = $pidForRefund");
$payBefore = $wpdb->get_row("SELECT status, license_id FROM wp_eligius_payments WHERE id = $pidForRefund");
$licBefore = $wpdb->get_row("SELECT status FROM wp_eligius_licenses WHERE id = $lic15");

$refund = $proc->refund_payment($pidForRefund, 1, 'integration test refund');
T('10. automatic refund BLOCKED (REFUND_NOT_SUPPORTED)',
    is_wp_error($refund) && 'REFUND_NOT_SUPPORTED' === $refund->get_error_code());

$payAfter = $wpdb->get_row("SELECT status, license_id FROM wp_eligius_payments WHERE id = $pidForRefund");
$licAfter = $wpdb->get_row("SELECT id, status FROM wp_eligius_licenses WHERE id = $lic15");
T('10b. payment row UNCHANGED after blocked refund',
    $payAfter && $payBefore && $payAfter->status === $payBefore->status && (int) $payAfter->license_id === (int) $payBefore->license_id);
T('10c. license UNCHANGED after blocked refund (admin revoke stays available)',
    $licAfter && $licBefore && $licAfter->status === $licBefore->status);

$refundedCountBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_audit_logs WHERE event_type = 'payment.refunded'");
$refundedCountAfter = $refundedCountBefore; // refund attempt is BLOCKED below — no new audit row
T('10d. no payment.refunded audit written by blocked attempt', $refundedCountAfter === $refundedCountBefore);

// Manual admin revocation (Class_License_Manager::revoke) must STILL work —
// it is the only available path and does not involve the gateway.
$manual = Class_License_Manager::instance()->revoke($lic15, 'manual admin revoke (refund disabled)', 1);
T('10e. manual admin revoke still works', !is_wp_error($manual) && $manual['revoked']);
$licAfter2 = $wpdb->get_row("SELECT status FROM wp_eligius_licenses WHERE id = $lic15");
T('10f. license REVOKED via manual path', $licAfter2 && 'REVOKED' === $licAfter2->status);

// ------------------------------------ 11. production lock (TD-6)
$factory = new \ReflectionClass('\Eligius_Sub\Payment\Class_Gateway_Factory');
T('11. PRODUCTION_LOCKED constant = true (TD-6)', $factory->getConstant('PRODUCTION_LOCKED') === true);

// ---------------------------------------------- 12. secrets never echoed
$merchant = 'real-secret-merchant-uuid';
update_option('eligius_zarinpal_merchant_id', $merchant, false);
$ref = new \ReflectionMethod('\Eligius_Sub\Admin\Class_Gateway_Settings', 'masked');
$ref->setAccessible(true);
$maskedOut = $ref->invoke(\Eligius_Sub\Admin\Class_Gateway_Settings::instance(), $merchant);
T('12. merchant ID masked in admin render', strpos($maskedOut, $merchant) === false && strpos($maskedOut, '•') !== false);
delete_option('eligius_zarinpal_merchant_id');

// ---------------------------------------------- 13. unknown authority callback
$cbU = $proc->handle_callback('mock', ['authority' => 'UNKNOWN-AUTH-123', 'status' => 'OK']);
T('13. unknown authority → 404 + audit warning', is_wp_error($cbU) && 'eligius_callback_unknown' === $cbU->get_error_code());

// ---------------------------------------------- 14. cancelled payment (Status≠OK)
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$created7 = $proc->create_payment(15, 'monthly', 'mock');
$cbC = $proc->handle_callback('mock', ['authority' => $created7['authority'], 'status' => 'NOK']);
$row8 = $wpdb->get_row("SELECT * FROM wp_eligius_payments WHERE id = " . $created7['payment_id']);
echo "  14 diag: cbC=" . json_encode(is_wp_error($cbC) ? ['e' => $cbC->get_error_code()] : $cbC) . " row8status=" . $row8->status . chr(10);
T('14a. cancelled callback returns FAILED', 'FAILED' === ($cbC['status'] ?? ''));
T('14b. no license from cancelled payment', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 15 AND payment_id = " . $created7['payment_id']) === 0);

// ------------------------------------ 15. renewal-while-active → EXTEND
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
// Self-contained setup: ensure user 15 has an ACTIVE monthly to renew
// (previous scenarios may have revoked it).
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id = 15 AND plan_key = 'monthly' AND status = 'ACTIVE'");
$createdR0 = $proc->create_payment(15, 'monthly', 'mock');
$proc->handle_callback('mock', ['authority' => $createdR0['authority'], 'status' => 'OK']);
$renewBefore = $wpdb->get_row("SELECT id, expires_at FROM wp_eligius_licenses WHERE user_id = 15 AND plan_key = 'monthly' AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
echo "  15 setup: renewBefore_id=" . ($renewBefore ? $renewBefore->id : 'NULL') . chr(10);
$createdR = $proc->create_payment(15, 'monthly', 'mock');
$cbR = $proc->handle_callback('mock', ['authority' => $createdR['authority'], 'status' => 'OK']);
$renewAfter = $wpdb->get_row("SELECT id, expires_at FROM wp_eligius_licenses WHERE id = " . ($renewBefore ? (int) $renewBefore->id : 0));
T('15. renewal-while-active EXTENDS existing row (no second license)',
    !is_wp_error($cbR) && 'VERIFIED' === $cbR['status']
    && $renewAfter && (int) $renewAfter->id === (int) $renewBefore->id
    && $renewAfter->expires_at > $renewBefore->expires_at);
$cntR = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 15 AND plan_key = 'monthly' AND status = 'ACTIVE'");
T('15b. still exactly one ACTIVE monthly', 1 === $cntR);

// ------------------------------------ 16. trial → paid conversion (doc 05 §3.1)
$trialExp = gmdate('Y-m-d H:i:s', strtotime('+10 days'));
$wpdb->query($wpdb->prepare(
    "DELETE FROM wp_eligius_licenses WHERE user_id = 16 AND plan_key = 'trial'"
));
$wpdb->insert("wp_eligius_licenses", [
    'user_id' => 16, 'plan_key' => 'trial', 'plan_label' => 'Free Trial', 'duration_days' => 30,
    'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => $trialExp,
    'status' => 'TRIAL', 'source' => 'trial', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$createdT = $proc->create_payment(16, 'quarterly', 'mock');
$cbT = $proc->handle_callback('mock', ['authority' => $createdT['authority'], 'status' => 'OK']);
$paid = $wpdb->get_row("SELECT started_at, expires_at, status FROM wp_eligius_licenses WHERE id = " . (int) $cbT['license_id']);
T('16. trial→paid starts at trial expiry (doc 05 §3.1)',
    !is_wp_error($cbT) && 'VERIFIED' === $cbT['status'] && $paid && 'ACTIVE' === $paid->status && $paid->started_at === $trialExp);

// ------------------------------------ 17. adapter-level 101 + amount mismatch
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::$verifyResult = ['verified' => true, 'amount' => 400000 * 10, 'ref_id' => 'RETRY-101', 'error' => null];
$adapter = \Eligius_Sub\Payment\Class_Gateway_Factory::create('mock');
$v101 = $adapter->verify_payment(['authority' => 'X', 'status' => 'OK'], 400000);
T('17. verify code-101-equivalent + amount match → verified', !empty($v101['verified']));
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::$verifyResult = ['verified' => true, 'amount' => 400000 * 10 + 999, 'ref_id' => 'BAD', 'error' => null];
$vBad = $adapter->verify_payment(['authority' => 'X', 'status' => 'OK'], 400000);
T('17b. adapter-level amount mismatch → verified=false', empty($vBad['verified']));

// ------------------------------------ 18. stale PENDING recovery
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$createdS = $proc->create_payment(15, 'monthly', 'mock');
// Simulate a crashed process: hand-insert a STALE PENDING row (>10 min old).
$wpdb->query("INSERT INTO wp_eligius_payment_idempotency (gateway_ref, gateway, status, processed_at)
              VALUES ('" . $createdS['authority'] . "', 'mock', 'PENDING', '" . gmdate('Y-m-d H:i:s', time() - 900) . "')");
$cbS = $proc->handle_callback('mock', ['authority' => $createdS['authority'], 'status' => 'OK']);
T('18. stale PENDING (>10min) recovered → VERIFIED', !is_wp_error($cbS) && 'VERIFIED' === $cbS['status']);
$paidRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses l JOIN wp_eligius_payments p ON l.id = p.license_id WHERE p.id = " . $createdS['payment_id'] . " AND l.status = 'ACTIVE'");
T('18b. exactly one license from recovered callback', 1 === $paidRows);

// ------------------------------------------ 19. renewal vs trial shadowing (P6 fix)
// Phase 6 regression: buy monthly → claim the account trial AFTER purchase →
// buy monthly AGAIN → the renewal-extend must win over the TRIAL-conversion
// branch (get_latest returns the newer TRIAL row). Old behavior: INSERT →
// uniq_user_active_plan violation → permanent callback retry-fail.
// Dedicated user 10: fresh trial history (no other scenario touches it).
\Eligius_Sub\Payment\Gateways\Class_Mock_Gateway::reset(true);
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id = 10");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id = 10");
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id = 10");
// The trial binds informationally to the CURRENT ACTIVE device — fixture one.
Class_Device_Repository::create(10, str_repeat('8', 64), 'p6-pk-10', 'P6 Dev 10', 1, 'test');
$firstP = $proc->create_payment(10, 'monthly', 'mock');
$firstC = $proc->handle_callback('mock', ['authority' => $firstP['authority'], 'status' => 'OK']);
T('19. first monthly purchase → VERIFIED', !is_wp_error($firstC) && 'VERIFIED' === $firstC['status']);
// Claim the one-time trial AFTER the purchase (CD-6: one trial per account).
$trialP = \Eligius_Sub\License\Class_Trial_Manager::instance()->grant(10, str_repeat('8', 64));
T('19b. trial granted after purchase (one per account)', !is_wp_error($trialP));
$expiryBefore = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT expires_at FROM wp_eligius_licenses WHERE id = %d", $firstC['license_id']));
$secondP = $proc->create_payment(10, 'monthly', 'mock');
$secondC = $proc->handle_callback('mock', ['authority' => $secondP['authority'], 'status' => 'OK']);
T('19c. second monthly purchase with a TRIAL row present → VERIFIED (extend wins)',
    !is_wp_error($secondC) && 'VERIFIED' === $secondC['status']);
$monthlyCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = 10 AND plan_key = 'monthly' AND status = 'ACTIVE'");
$extendedDays = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT DATEDIFF(%s, %s)",
    (string) $wpdb->get_var($wpdb->prepare(
        "SELECT expires_at FROM wp_eligius_licenses WHERE id = %d", $secondC['license_id'])),
    $expiryBefore));
T('19d. exactly ONE active monthly row (id reused), extended ~30d by the second purchase',
    1 === $monthlyCount && (int) $secondC['license_id'] === (int) $firstC['license_id'] && $extendedDays >= 28 && $extendedDays <= 31);

echo "\nRESULT: $pass passed, $fail failed\n";

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
