<?php
/**
 * Phase 5b trial-eligibility tests (owner acceptance list):
 *  - a FRESH account on an ACTIVE device is trial-eligible (real backend flag);
 *  - a second trial is rejected (409) and eligibility flips to false forever;
 *  - a PENDING device (subscription on another device) is NOT the trial path
 *    (DEVICE_LIMIT_REACHED + trial_eligible=false);
 *  - banner data comes from the SERVER (has_trial_ever gate), never from
 *    local cache emptiness.
 * State-independent (dedicated users, resolved by login before cleanup).
 */
if (!defined('ABSPATH')) { define('ABSPATH', 'E:/xampp/htdocs/wp/'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'eligius-trial-test';
require 'E:/xampp/htdocs/wp/wp-load.php';

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

// ---- Cleanup (by REAL resolved user ids) -------------------------------------
$u30 = find_or_create_user($wpdb, 'p5trial-u30');
$u31 = find_or_create_user($wpdb, 'p5trial-u31');
$wpdb->query("DELETE FROM wp_eligius_device_transfers WHERE user_id IN ($u30, $u31)");
$wpdb->query("DELETE FROM wp_eligius_licenses       WHERE user_id IN ($u30, $u31)");
$wpdb->query("DELETE FROM wp_eligius_devices        WHERE user_id IN ($u30, $u31)");

function rest_req($method, $route, $hash, array $params = array()) {
    $req = new WP_REST_Request($method, '/eligius/v1' . $route);
    if (null !== $hash) $req->set_header('X-Device-Hash', $hash);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return rest_get_server()->dispatch($req);
}
function status_of($hash) {
    $req = new WP_REST_Request('GET', '/eligius/v1/licenses/status');
    $req->set_header('X-Device-Hash', $hash);
    $res = rest_get_server()->dispatch($req);
    return array($res->get_status(), $res->get_data()['data'] ?? array());
}

// ---- E1: fresh account on an ACTIVE device: trial_eligible = true ------------
update_user_meta($u30, 'mobile', '09123330000');
wp_set_current_user($u30);
$hash30 = str_repeat('b', 60) . '0000';
Class_Device_Repository::create($u30, $hash30, 'pk-u30', 'U30 Dev', 1, 'test');
delete_transient('eligius_sub_rl_' . md5('trial_user|' . $u30 . '|86400'));
delete_transient('eligius_sub_rl_' . md5('trial_ip|127.0.0.1|3600'));
delete_transient('eligius_sub_rl_' . md5('validation_device|' . $hash30 . '|60'));

list($code1, $st1) = status_of($hash30);
T('E1. fresh account, active device, no licenses: NO_ACTIVE_LICENSE',
    200 === $code1 && 'NO_ACTIVE_LICENSE' === ($st1['error'] ?? ''));
T('E1b. trial_eligible = TRUE from the backend (has_trial_ever gate)',
    true === ($st1['trial_eligible'] ?? null));

// ---- E2: grant the trial: valid TRIAL (banner condition disappears) ----------
$resTrial = rest_req('POST', '/licenses/trial', $hash30);
list($code2, $st2) = status_of($hash30);
T('E2. trial granted: status serves TRIAL',
    201 === $resTrial->get_status() && true === ($st2['valid'] ?? false) && 'TRIAL' === ($st2['status'] ?? ''));

// ---- E3: second trial rejected + eligibility flips to false forever ----------
$resTrial2 = rest_req('POST', '/licenses/trial', $hash30);
T('E3. second trial: 409 CONFLICT', 409 === $resTrial2->get_status());
// Expire the trial so the status falls into the NO_ACTIVE_LICENSE branch.
$wpdb->query("UPDATE wp_eligius_licenses SET expires_at = '2020-01-01 00:00:00' WHERE user_id = $u30 AND status = 'TRIAL'");
list($code3, $st3) = status_of($hash30);
T('E3b. after the trial is gone, trial_eligible = FALSE (previous trial - no new offer)',
    200 === $code3 && 'NO_ACTIVE_LICENSE' === ($st3['error'] ?? '')
    && false === ($st3['trial_eligible'] ?? true));

// ---- E4: PENDING device with a paid subscription elsewhere: transfer path ----
update_user_meta($u31, 'mobile', '09123331111');
wp_set_current_user($u31);
$hashOld31 = str_repeat('c', 60) . '1111';
$hashPend31 = str_repeat('c', 60) . '2222';
Class_Device_Repository::create($u31, $hashOld31, 'pk-u31-old', 'U31 Old', 1, 'test');
Class_Device_Repository::create($u31, $hashPend31, 'pk-u31-pend', 'U31 Pending', 0, 'test');
$old31 = Class_Device_Repository::find_by_hash($u31, $hashOld31);
$wpdb->insert("wp_eligius_licenses", array(
    'user_id' => $u31, 'plan_key' => 'monthly', 'plan_label' => 'Monthly', 'duration_days' => 30,
    'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    'status' => 'ACTIVE', 'source' => 'payment', 'device_id' => (int) $old31->id,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
));
delete_transient('eligius_sub_rl_' . md5('validation_device|' . $hashPend31 . '|60'));

list($code4, $st4) = status_of($hashPend31);
T('E4. pending device: DEVICE_LIMIT_REACHED (transfer flow, never trial path)',
    200 === $code4 && 'DEVICE_LIMIT_REACHED' === ($st4['error'] ?? ''));
T('E4b. pending device trial_eligible = FALSE (subscription lives on another device)',
    false === ($st4['trial_eligible'] ?? true) && (int) ($st4['active_device_id'] ?? 0) === (int) $old31->id);
list($code4c, $st4c) = status_of($hashOld31);
T('E4c. the ACTIVE device still serves the paid license normally',
    200 === $code4c && true === ($st4c['valid'] ?? false) && 'ACTIVE' === ($st4c['status'] ?? ''));

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
