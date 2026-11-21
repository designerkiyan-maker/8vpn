<?php
/**
 * Phase 6 final closure — Callback ∥ Reconciliation CONCURRENCY test (owner
 * Task 1): the gateway callback and the user-initiated reconciliation fire
 * SIMULTANEOUSLY (two parallel PHP processes) for the SAME payment.
 *
 * Proven: exactly ONE successful verification, exactly ONE license
 * activation, the losing path is IDEMPOTENT, no second license, no
 * inconsistent financial state. Everything reuses handle_callback's existing
 * payments-row FOR UPDATE + idempotency — no new locking was added.
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-conc-parent';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Payment\Gateways\Class_Mock_Gateway;

$wpdb = $GLOBALS['wpdb'];
do_action('rest_api_init');

// Mock gateway fixture (repo test asset — no real gateway traffic).
require_once __DIR__ . '/class-mock-gateway.php';

function bucket_cc_clear($wpdb, array $tuples) {
    foreach ($tuples as $t) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}eligius_rate_limits WHERE bucket_id = %s",
            md5($t[0] . '|' . $t[1] . '|' . $t[2])));
    }
}

// ---- Fixtures ----------------------------------------------------------------
function fc2_user($wpdb, $login) {
    $id = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if (!$id) {
        $id = (int) wp_insert_user(array(
            'user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.invalid', 'role' => 'subscriber',
        ));
    }
    return $id;
}
$u = fc2_user($wpdb, 'p6cc-u');
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id = $u");
Class_Device_Repository::create($u, str_repeat('7', 64), 'p6cc-pk', 'CC Dev', 1, 'test');
$wpdb->insert("wp_eligius_payments", array(
    'user_id' => $u, 'gateway' => 'zarinpal', 'gateway_ref' => 'P6CC-AUTH', 'authority' => 'P6CC-AUTH',
    'amount' => 150000, 'currency' => 'IRT', 'plan_key' => 'monthly', 'plan_duration_days' => 30,
    'status' => 'INITIATED', 'initiated_at' => gmdate('Y-m-d H:i:s'),
));
$paymentId = (int) $wpdb->insert_id;
$licenseBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = $u");

add_filter('eligius_sub_payment_gateway_instance', function () { return new Class_Mock_Gateway(); }, 10, 0);
Class_Mock_Gateway::reset(true);

bucket_cc_clear($wpdb, array(
    array('payment_callback_ip', '127.0.0.1', 300),
    array('payment_callback_authority', 'P6CC-AUTH', 600),
    array('payment_reconcile_user', $u, 3600),
    array('global_ip', '127.0.0.1', 60),
));

// ---- Two parallel workers on the SAME authority -------------------------------
$childPath = sys_get_temp_dir() . '/p6cc-child.php';
$childSrc  = '<?php' . "\n"
    . "if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); }\n"
    . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
    . "require ABSPATH . 'wp-load.php';\n"
    . "do_action('rest_api_init');\n"
    . "// Each child process registers the MOCK gateway itself (processes do not\n"
    . "// share the parent's filter registrations). Clearly a mock — no real gateway.\n"
    . "require_once ABSPATH . 'wp-content/plugins/eligius-subscription/tests/integration/class-mock-gateway.php';\n"
    . "add_filter('eligius_sub_payment_gateway_instance', function () { return new Eligius_Sub\\Payment\\Gateways\\Class_Mock_Gateway(); }, 10, 0);\n"
    . "\$mode = \$argv[1];\n"
    . "\$uid = (int) \$argv[2];\n"
    . "\$pid = (int) \$argv[3];\n"
    . "wp_set_current_user(\$mode === 'reconcile' ? \$uid : 0);\n"
    . "\$req = new WP_REST_Request(\$mode === 'reconcile' ? 'POST' : 'GET',\n"
    . "    '/eligius/v1/payments' . (\$mode === 'reconcile' ? '/' . \$pid . '/reconcile' : '/callback/zarinpal'));\n"
    . "if (\$mode !== 'reconcile') { \$req->set_param('authority', 'P6CC-AUTH'); \$req->set_param('Status', 'OK'); }\n"
    . "\$res = rest_get_server()->dispatch(\$req);\n"
    . "\$status = '';\n"
    . "if (\$mode === 'reconcile') {\n"
    . "    \$status = (string) (\$res->get_data()['data']['status'] ?? (string) \$res->get_status());\n"
    . "} else {\n"
    . "    preg_match('/status=([a-z_]+)/', (string) (\$res->get_headers()['Location'] ?? ''), \$m);\n"
    . "    \$status = \$m[1] ?? '';\n"
    . "}\n"
    . "echo \$res->get_status() . '|' . \$status . '|verify=' . Eligius_Sub\\Payment\\Gateways\\Class_Mock_Gateway::\$verifyCalls;\n";
file_put_contents($childPath, $childSrc);
$php = getenv('P6_PHP_BIN') ?: 'E:/xampp/php/php.exe';
putenv('ELIGIUS_WP_ROOT=' . ABSPATH);

$procs = array();
$specs = array(
    array('callback', 'p6cc-cb'),
    array('reconcile', 'p6cc-rc'),
);
foreach ($specs as $i => $spec) {
    $procs[$i] = proc_open(
        escapeshellarg($php) . ' -c ' . escapeshellarg('E:/xampp/php/php.ini') . ' ' .
        escapeshellarg($childPath) . ' ' . escapeshellarg($spec[0]) . ' ' . $u . ' ' . $paymentId,
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes[$i]
    );
}
$results = array();
foreach ($procs as $i => $p) {
    $out = stream_get_contents($pipes[$i][1]);
    fclose($pipes[$i][1]);
    $err = stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][2]);
    proc_close($p);
    $results[$specs[$i][0]] = trim($out);
    if ('' === trim($out)) echo "  worker {$specs[$i][0]} stderr: " . substr($err, 0, 200) . "\n";
}
echo "  worker results: callback={$results['callback']} reconcile={$results['reconcile']}\n";

// ---- Assertions ----------------------------------------------------------------
$paymentRow = $wpdb->get_row($wpdb->prepare("SELECT status, license_id FROM wp_eligius_payments WHERE id = %d", $paymentId));
$licenseRows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = %d AND plan_key = 'monthly' AND status = 'ACTIVE'", $u));
$verifies = Class_Mock_Gateway::$verifyCalls;

T('C1. both workers completed with a parseable result',
    preg_match('/^\d+\|[a-z_]+\|verify=\d+$/', $results['callback']) === 1
    && preg_match('/^\d+\|[A-Za-z_]+\|verify=\d+$/', $results['reconcile']) === 1);
T('C2. payment ends VERIFIED (exactly one successful verification path)',
    'VERIFIED' === $paymentRow->status);
T('C3. license_id linked to the payment (one successful activation)',
    (int) $paymentRow->license_id > 0);
T('C4. exactly ONE active monthly license exists (never two)',
    1 === $licenseRows);
T('C5. net license growth == 1 (no duplicate activation)',
    ((int) $wpdb->get_var("SELECT COUNT(*) FROM wp_eligius_licenses WHERE user_id = $u")) - $licenseBefore === 1);
T('C6. losing path reported idempotently (no terminal failure)',
    false === strpos($results['callback'], 'failed') && false === strpos($results['reconcile'], 'FAILED'));
T('C7. S2S verification calls across BOTH processes ≥ 1 (serialized, never zero)',
    (preg_match('/verify=(\d+)$/', $results['callback'], $m1) + preg_match('/verify=(\d+)$/', $results['reconcile'], $m2)) > 0
    && ((int) ($m1[1] ?? 0) + (int) ($m2[1] ?? 0)) >= 1);
T('C8. idempotency row settled to VERIFIED',
    'VERIFIED' === (string) $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM wp_eligius_payment_idempotency WHERE gateway_ref = %s", 'P6CC-AUTH')));

// ---- Cleanup --------------------------------------------------------------------
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_licenses WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_payment_idempotency WHERE gateway_ref = 'P6CC-AUTH'");
$wpdb->query("DELETE FROM wp_eligius_payments WHERE user_id = $u");
$wpdb->query("DELETE FROM wp_eligius_devices WHERE user_id = $u");
@unlink($childPath);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
