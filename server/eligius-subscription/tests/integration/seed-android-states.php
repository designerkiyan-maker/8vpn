<?php
/**
 * Phase 6 — Android instrumented-test STATE SEEDER (CI-portable).
 * Flips the fixed test account's (androidkeystore, user 12) trial license to
 * REVOKED and BLOCKS its active device BEFORE a connectedAndroidTest run so
 * LicenseStoreTest.t12/t13 can prove the revoked/blocked behaviors.
 *
 * The POST-RUN restore is `--restore`: un-block the device and put the
 * license back to TRIAL (its original state) so later runs start healthy.
 *
 * Usage: php seed-android-states.php [--restore]
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-seed';
require ABSPATH . 'wp-load.php';

$restore = in_array('--restore', $argv ?: array(), true);
global $wpdb;
$user = (int) ($wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM wp_users WHERE user_login = %s", 'androidkeystore')) ?? 0);
if (!$user) { fwrite(STDERR, "androidkeystore not found\n"); exit(1); }

$licenseId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM wp_eligius_licenses WHERE user_id = %d ORDER BY id ASC LIMIT 1", $user));
$devices = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM wp_eligius_devices WHERE user_id = %d", $user));

if ($restore) {
    foreach ($devices as $id) {
        Eligius_Sub\Core\Class_Device_Repository::set_blocked((int) $id, false);
    }
    if ($licenseId) {
        $wpdb->query($wpdb->prepare(
            "UPDATE wp_eligius_licenses SET status = 'TRIAL' WHERE id = %d AND source = 'trial'", $licenseId));
    }
    echo "restored: license $licenseId → TRIAL, devices unblocked\n";
    exit(0);
}

// Seed: revoke the trial license + block ALL devices of the account.
if ($licenseId) {
    $wpdb->query($wpdb->prepare(
        "UPDATE wp_eligius_licenses SET status = 'REVOKED', updated_at = UTC_TIMESTAMP() WHERE id = %d", $licenseId));
    Eligius_Sub\Core\Class_Audit_Log_Repository::insert_audit(array(
        'user_id' => $user, 'license_id' => $licenseId,
        'event_type' => 'admin.license.revoked', 'severity' => 'WARNING',
        'details' => '{"reason":"p6-instrumented-seed"}',
    ));
}
foreach ($devices as $id) {
    Eligius_Sub\Core\Class_Device_Repository::set_blocked((int) $id, true);
}
echo "seeded: license $licenseId → REVOKED; " . count($devices) . " device(s) blocked\n";
