<?php
/**
 * Phase 6 M-3 — Atomic rate limiter tests (owner acceptance list):
 *   • in-process precision (no overshoot within one process),
 *   • TRUE cross-process concurrency (N parallel PHP workers consume one
 *     bucket; accepted total must equal the limit EXACTLY — the owner's
 *     success criterion),
 *   • fixed-window rollover,
 *   • object-cache-flush immunity,
 *   • storage-failure fail-open (documented policy),
 *   • scope isolation.
 * The table migration itself is proven by the versioned maybe_upgrade run.
 */
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'p6-rl-test';
require ABSPATH . 'wp-load.php';

$pass = 0; $fail = 0;
function T($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

use Eligius_Sub\Security\Class_Rate_Limiter;
use Eligius_Sub\Core\Class_DB_Error;

$wpdb = $GLOBALS['wpdb'];
$rl   = Class_Rate_Limiter::instance();
$table = $wpdb->prefix . 'eligius_rate_limits';

// Clean test buckets.
$wpdb->query("DELETE FROM {$table} WHERE scope LIKE 'p6t_%'");

// ---- T0: migration state -----------------------------------------------------
T('T0. rate-limit table exists after versioned migration (1.1.0)',
    $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table
    && '1.1.0' === get_option('eligius_sub_db_version'));

// ---- T1: in-process precision (limit 5, 8 hits) ------------------------------
$allowed1 = 0;
for ($i = 0; $i < 8; $i++) {
    $r = $rl->hit('p6t_single', 'u1', 5, 600);
    if ($r['allowed']) $allowed1++;
}
$count1 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_single' AND bucket_key = 'u1'"));
T('T1. in-process: exactly 5 of 8 allowed (3 denied)', 5 === $allowed1);
T('T1b. stored counter equals accepted count exactly (no overshoot)', 5 === $count1);

// ---- T2: TRUE cross-process concurrency --------------------------------------
// 6 parallel PHP workers × 10 hits on ONE bucket with limit 30 (60 total
// attempts) → the accepted total MUST be exactly 30 (owner criterion).
$childPath = sys_get_temp_dir() . '/p6-rl-child.php';
file_put_contents($childPath, <<<PHP
<?php
if (!defined('ABSPATH')) { define('ABSPATH', getenv('ELIGIUS_WP_ROOT') ?: 'E:/xampp/htdocs/wp/'); } // CI-portable root (P6)
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require ABSPATH . 'wp-load.php';
\$rl = Eligius_Sub\Security\Class_Rate_Limiter::instance();
\$allowed = 0;
for (\$i = 0; \$i < (int) \$argv[1]; \$i++) {
    \$r = \$rl->hit(\$argv[2], \$argv[3], (int) \$argv[4], (int) \$argv[5]);
    if (\$r['allowed']) \$allowed++;
}
echo \$allowed;
PHP);
$php = getenv('P6_PHP_BIN') ?: 'E:/xampp/php/php.exe';
$procs = [];
for ($w = 0; $w < 6; $w++) {
    $procs[$w] = proc_open(
        escapeshellarg($php) . ' ' . escapeshellarg($childPath) . ' 10 p6t_conc shared 30 600',
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes[$w]
    );
}
$acceptedTotal = 0; $allOk = true;
foreach ($procs as $w => $p) {
    $out = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]);
    $err = stream_get_contents($pipes[$w][2]);
    fclose($pipes[$w][2]);
    proc_close($p);
    if (!is_numeric(trim($out))) { $allOk = false; echo "  worker $w err: " . substr($err, 0, 160) . "\n"; }
    else { $acceptedTotal += (int) trim($out); }
}
$countC = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_conc' AND bucket_key = 'shared'"));
T('T2. concurrency: 6 parallel workers × 10 hits, limit 30 → EXACTLY 30 accepted (60 attempts)',
    $allOk && 30 === $acceptedTotal) or print("  accepted sum = $acceptedTotal\n");
T('T2b. stored counter matches accepted total exactly', 30 === $countC) or print("  stored = $countC\n");

// ---- T3: fixed-window rollover -----------------------------------------------
$wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET window_end = %d WHERE scope = 'p6t_single' AND bucket_key = 'u1'",
    time() - 1));
$r3 = $rl->hit('p6t_single', 'u1', 5, 600);
$count3 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_single' AND bucket_key = 'u1'"));
T('T3. expired window opens anew (allowed, count resets to 1)',
    $r3['allowed'] && 1 === $count3 && $r3['reset'] > time());

// ---- T4: object-cache-flush immunity ------------------------------------------
wp_cache_flush();
$count4a = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_single' AND bucket_key = 'u1'"));
$rl->hit('p6t_single', 'u1', 5, 600);
$count4b = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_single' AND bucket_key = 'u1'"));
T('T4. counters survive an object-cache flush (1 before, 2 after the flush-window hit)',
    1 === $count4a && 2 === $count4b);

// ---- T5: storage failure → documented fail-open --------------------------------
$wpdb->query("RENAME TABLE {$table} TO {$table}_hidden");
$r5 = $rl->hit('p6t_failopen', 'u1', 1, 600);
$wpdb->query("RENAME TABLE {$table}_hidden TO {$table}");
T('T5. storage failure → fail-open ALLOWED with degraded flag',
    $r5['allowed'] && !empty($r5['degraded']));

// ---- T6: scope isolation --------------------------------------------------------
$rl->hit('p6t_iso_a', 'u9', 100, 600);
$rl->hit('p6t_iso_a', 'u9', 100, 600);
$r6 = $rl->hit('p6t_iso_b', 'u9', 100, 600);
$count6 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT `count` FROM {$table} WHERE scope = 'p6t_iso_b' AND bucket_key = 'u9'"));
T('T6. scopes are isolated (b starts at 1 regardless of a)', $r6['allowed'] && 1 === $count6);

// ---- T7: pure response helpers (B-3) --------------------------------------------
// apply_headers()/too_many_error() touch no storage — proven here against a
// REAL hit result and a REAL WP_REST_Response (doc 03 §9 header contract).
$hdrResult = $rl->hit('p6t_hdr', 'u1', 7, 600);
$resp      = new WP_REST_Response(['ok' => true]);
$rl->apply_headers($resp, $hdrResult);
$headers = $resp->get_headers();
T('T7. apply_headers sets X-RateLimit-Limit/Remaining/Reset (real hit result)',
    isset($headers['X-RateLimit-Limit'], $headers['X-RateLimit-Remaining'], $headers['X-RateLimit-Reset'])
    && (string) $hdrResult['limit'] === $headers['X-RateLimit-Limit']
    && (string) $hdrResult['reset'] === $headers['X-RateLimit-Reset']);

class P6_RL_NoHeader { public $v = 1; }
$passThrough = $rl->apply_headers(new P6_RL_NoHeader(), $hdrResult);
T('T7b. apply_headers passes a non-response object through untouched',
    $passThrough instanceof P6_RL_NoHeader && 1 === $passThrough->v);

$rlErr = $rl->too_many_error($hdrResult);
T('T7c. too_many_error → typed eligius_rate_limited 429 carrying limit/reset',
    is_wp_error($rlErr) && 'eligius_rate_limited' === $rlErr->get_error_code()
    && 429 === (int) ($rlErr->get_error_data()['status'] ?? 0)
    && (int) $hdrResult['limit'] === (int) ($rlErr->get_error_data()['limit'] ?? 0)
    && (int) $hdrResult['reset'] === (int) ($rlErr->get_error_data()['reset'] ?? 0));

// ---- Cleanup --------------------------------------------------------------------
$wpdb->query("DELETE FROM {$table} WHERE scope LIKE 'p6t_%'");
@unlink($childPath);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
