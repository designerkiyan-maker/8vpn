<?php
/**
 * Phase 8.2 — TD-10 OTP registration compliance tests (live eligius-otp.php).
 *
 * Policy proven (owner decision, CD-6):
 *   • registration WITHOUT national_code succeeds and writes NO national_code
 *     usermeta (no identity data is collected);
 *   • LEGACY clients that still send a national_code get the exact old
 *     behavior: valid → stored, invalid → 400 eligius_bad_national_code,
 *     duplicate → 400 eligius_nc_exists;
 *   • mobile/email/password/name validation paths are UNCHANGED.
 *
 * SMS safety: the IPPanel edge call is intercepted with the WordPress
 * pre_http_request filter — NO real SMS is sent, NO real IPPanel credentials
 * exist anywhere (the plugin's ELIGIUS_IPPANEL_* constants are pre-defined as
 * obviously-fake TEST values before the plugin file loads). Everything runs
 * on the isolated test WordPress (dedicated mobiles/emails, cleaned up).
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p8-otp-test';
require ABSPATH . 'wp-load.php';

/* Fake SMS gateway credentials BEFORE the plugin loads (its define() calls
 * are guarded) — obviously-not-secrets; the HTTP layer is stubbed anyway. */
if (!defined('ELIGIUS_IPPANEL_KEY'))  { define('ELIGIUS_IPPANEL_KEY', 'TEST-NO-SECRET'); }
if (!defined('ELIGIUS_IPPANEL_FROM')) { define('ELIGIUS_IPPANEL_FROM', '+9830000000'); }

/* Load the LIVE plugin from the repo (guarded — never redeclare if the host
 * happens to have the plugin active). Routes register on rest_api_init. */
$otpPlugin = dirname(__DIR__, 3) . '/eligius-otp.php'; // tests/integration → eligius-subscription → server/eligius-otp.php
if (!function_exists('eligius_otp_send_sms')) {
    require_once $otpPlugin;
}

/* Intercept the IPPanel edge send — the SMS never leaves the machine. */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (is_string($url) && strpos($url, 'edge.ippanel.com') !== false) {
        return array(
            'headers'     => array(),
            'response'    => array('code' => 200, 'message' => 'OK'),
            'body'        => wp_json_encode(array('meta' => array('status' => true))),
            'status_code' => 200,
            'success'     => true,
        );
    }
    return $pre;
}, 10, 3);

do_action('rest_api_init'); // register the plugin routes in this process

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

global $wpdb;

/* Valid Iranian codes (mod-11 checksum passes); 1234567890 fails it. */
const NC_VALID_A = '1370983425';
const NC_VALID_B = '0012345679';
const NC_INVALID = '1234567890';

function otp_req(array $params) {
    $req = new WP_REST_Request('POST', '/eligius/v1/register/otp-start');
    foreach ($params as $k => $v) { if (null !== $v) $req->set_param($k, $v); }
    return rest_get_server()->dispatch($req);
}

/* Dedicated, self-cleaning identities (run twice-safe). */
$mobBase = '091234500';
for ($i = 1; $i <= 9; $i++) {
    $login = $mobBase . str_pad((string) $i, 2, '0', STR_PAD_LEFT); // 091234500 01 …
    $uid = (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
    if ($uid) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}eligius_rate_limits WHERE bucket_key = %s", md5($login)));
}

function otp_body($mobile, $email, $password, $first, $last, $nc = null, $hash = null) {
    $b = array('mobile' => $mobile, 'email' => $email, 'password' => $password,
               'first_name' => $first, 'last_name' => $last);
    if (null !== $nc)   $b['national_code'] = $nc;
    if (null !== $hash) $b['hash'] = $hash;
    return $b;
}
function uid_by_login($wpdb, $login) {
    return (int) ($wpdb->get_var($wpdb->prepare("SELECT ID FROM wp_users WHERE user_login = %s", $login)) ?? 0);
}

/* ---- T0: environment sanity ------------------------------------------------ */
T('T0. OTP plugin functions loaded + routes registered',
    function_exists('eligius_otp_send_sms')
    && rest_get_server()->dispatch(new WP_REST_Request('OPTIONS', '/eligius/v1/register/otp-start'))->get_status() < 300);

/* ---- T1: registration WITHOUT national_code (the new app path) ------------- */
$m1 = '09123450001';
$res = otp_req(otp_body($m1, 'p8otp1@example.invalid', 'SecurePass123', 'Ali', 'Rezaei'));
T('T1. register WITHOUT national_code → 200 + SMS sent (stubbed)',
    200 === $res->get_status() && true === ($res->get_data()['sent'] ?? false));
$u1 = uid_by_login($wpdb, $m1);
T('T1b. account created (username = mobile)', $u1 > 0);
T('T1c. NO national_code usermeta is written when absent',
    '' === (string) get_user_meta($u1, 'national_code', true));
T('T1d. OTP transient was created (SMS stub path ran)',
    false !== get_transient('eligius_otp_' . md5($m1)));

/* ---- T2: unverified re-registration without the field --------------------- */
$res = otp_req(otp_body($m1, 'p8otp1@example.invalid', 'SecurePass123', 'Ali', 'Rezaei'));
T('T2. unverified re-registration WITHOUT the field → 200 (reuse path intact)',
    200 === $res->get_status() && uid_by_login($wpdb, $m1) === $u1);

/* ---- T3: LEGACY client sends a VALID national_code (backward compat) ------ */
$m3 = '09123450003';
$res = otp_req(otp_body($m3, 'p8otp3@example.invalid', 'SecurePass123', 'Sara', 'Karimi', NC_VALID_A));
T('T3. legacy client WITH a valid national_code → 200 (backward compatible)',
    200 === $res->get_status());
$u3 = uid_by_login($wpdb, $m3);
T('T3b. provided national_code IS stored for legacy clients',
    NC_VALID_A === (string) get_user_meta($u3, 'national_code', true));

/* ---- T4: legacy client sends an INVALID national_code → rejected ---------- */
$env = function ($res) { // robust typed-error extraction from the REST envelope
    $d = $res->get_data();
    return is_wp_error($d) ? $d->get_error_code() : ($d['error']['code'] ?? ($d['code'] ?? ''));
};
$res = otp_req(otp_body('09123450004', 'p8otp4@example.invalid', 'SecurePass123', 'Reza', 'Moradi', NC_INVALID));
T('T4. invalid national_code (checksum fails) → 400 eligius_bad_national_code',
    400 === $res->get_status() && 'eligius_bad_national_code' === $env($res));

/* ---- T5: duplicate VALID national_code from a different mobile ------------ */
$res = otp_req(otp_body('09123450005', 'p8otp5@example.invalid', 'SecurePass123', 'Nima', 'Ahmadi', NC_VALID_A));
T('T5. duplicate national_code from another mobile → eligius_nc_exists',
    400 === $res->get_status() && 'eligius_nc_exists' === $env($res));

/* ---- T6: mobile/email/password/name validation paths UNCHANGED ------------ */
$res = otp_req(otp_body('12345', 'p8otp6@example.invalid', 'SecurePass123', 'Ali', 'Rezaei')); // invalid mobile shape
T('T6a. invalid mobile → eligius_bad_mobile (unchanged)',
    400 === $res->get_status() && 'eligius_bad_mobile' === $env($res));
$res = otp_req(otp_body('09123450006', 'not-an-email', 'SecurePass123', 'Ali', 'Rezaei'));
T('T6b. invalid email → eligius_bad_email (unchanged)',
    400 === $res->get_status() && 'eligius_bad_email' === $env($res));
$res = otp_req(otp_body('09123450007', 'p8otp7@example.invalid', 'SecurePass123', 'Ali', 'Rezaei'));
$res2 = otp_req(otp_body('09123450007', 'p8otp7@example.invalid', 'short', 'Ali', 'Rezaei'));
T('T6c. weak password → eligius_weak_password (unchanged)',
    400 === $res2->get_status() && 'eligius_weak_password' === $env($res2));
$res = otp_req(otp_body('09123450008', 'p8otp8@example.invalid', 'SecurePass123', '', 'Rezaei'));
T('T6d. missing first name → eligius_missing_name (unchanged)',
    400 === $res->get_status() && 'eligius_missing_name' === $env($res));

/* ---- T7: second legacy registration with ANOTHER valid code --------------- */
$m7 = '09123450009';
$res = otp_req(otp_body($m7, 'p8otp9@example.invalid', 'SecurePass123', 'Mina', 'Hosseini', NC_VALID_B));
T('T7. a DIFFERENT valid national_code → 200 (dup-check is per-value, not global)',
    200 === $res->get_status()
    && NC_VALID_B === (string) get_user_meta(uid_by_login($wpdb, $m7), 'national_code', true));

/* ---- Cleanup (dedicated identities only) ---------------------------------- */
for ($i = 1; $i <= 9; $i++) {
    $login = $mobBase . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $uid = uid_by_login($wpdb, $login);
    if ($uid) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); }
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
