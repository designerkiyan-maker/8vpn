<?php
/**
 * Rate limiting (doc 01 §2.1 security/class-rate-limiter.php, doc 03 §9).
 *
 * Phase 6 M-3 (owner decision): counters moved from get/set transients to the
 * dedicated `wp_eligius_rate_limits` table with ATOMIC insert-or-increment —
 * one statement per hit under the PRIMARY KEY, so concurrent requests can
 * never lose increments (the old read-modify-write race allowed overshoot),
 * and an object-cache flush can no longer reset counters.
 *
 * Fixed-window semantics are preserved exactly: the FIRST hit opens the
 * window (window_end = now + window); subsequent hits increment while
 * window_end is still in the future; the first hit AFTER expiry opens a new
 * window. All existing bucket definitions and limits are unchanged.
 *
 * Fail-safe policy (owner requirement): when the rate-limit store itself is
 * unavailable (DB failure), the request is ALLOWED (fail-open) and the error
 * is logged server-side — availability of the auth/payment flows takes
 * precedence; correctness controls (one trial, one active device, payment
 * idempotency) never rely on this limiter (doc 02 §2.8).
 *
 * Windows (doc 03 §9 + Phase 6 M-2):
 *   - device registration      10 / hour / user
 *   - device challenge/verify  20 / minute / user
 *   - license validation       100 / minute / device
 *   - trial grant              5 / hour / IP + 3 / day / user
 *   - transfer start           3 / day / user
 *   - transfer verify          20 / minute / user
 *   - payment create           5 / hour / user
 *   - payment callback         60 / 5 min / IP + 20 / 10 min / authority (M-2)
 *   - global API               1000 / minute / IP
 */

namespace Eligius_Sub\Security;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_DB_Error;

class Class_Rate_Limiter {

    private static $instance = null;

    /** True while the store is (or was last seen) unavailable — drives the
     *  recovery log line (owner requirement: degraded must be observable,
     *  including when it recovers). */
    private static $degraded_active = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Observability (owner section 7): log degraded ONCE when the store
     * becomes unavailable and log recovery when it comes back. Logged items:
     * operation type (scope), storage error, timestamp — via error_log's own
     * timestamp. NEVER logs OTPs, JWTs, credentials or private keys.
     */
    private function observe_degradation(bool $degraded, string $scope, string $db_error): void {
        if ($degraded && !self::$degraded_active) {
            self::$degraded_active = true;
            Class_DB_Error::log('rate-limiter.degraded [' . $scope . ']', $db_error);
        } elseif (!$degraded && self::$degraded_active) {
            self::$degraded_active = false;
            Class_DB_Error::log('rate-limiter.recovered [' . $scope . ']', 'store healthy again');
        }
    }

    /**
     * Consume one unit of a rate limit window.
     *
     * Phase 6 M-3 semantics (owner criterion): the stored counter counts ONLY
     * ACCEPTED requests — a denied request never increments, so the stored
     * count always equals exactly the number of accepted requests and the
     * limit can never be violated, even under full concurrency.
     *
     * Every state transition is a single ATOMIC statement (InnoDB row lock):
     *   1. `UPDATE … SET count=count+1 WHERE bucket fresh AND count < limit`
     *      → accepted (concurrent updates serialize on the row lock; the
     *      second worker sees count == limit and is denied — never overshot).
     *   2. expired window → `UPDATE … SET count=1, window_end=new WHERE
     *      window_end <= now` → accepted (new window opens exactly once).
     *   3. row missing → `INSERT IGNORE` a zero row, then re-run step 1.
     *   4. otherwise → DENIED with NO counter change.
     *
     * Fail-safe policy (owner requirement): when the store itself is
     * unavailable (DB failure), the request is ALLOWED (fail-open) and the
     * error is logged server-side — availability of the auth/payment flows
     * takes precedence; correctness controls (one trial, one active device,
     * payment idempotency) never rely on this limiter (doc 02 §2.8).
     *
     * @param string $scope  Bucket scope, e.g. 'trial_ip'.
     * @param string $id     Identity within the scope (user id, ip, device hash).
     * @param int    $limit  Max requests per window.
     * @param int    $window Window length in seconds.
     * @return array{allowed:bool,limit:int,remaining:int,reset:int,degraded?:bool}
     */
    public function hit($scope, $id, $limit, $window) {
        global $wpdb;

        $table     = $wpdb->prefix . 'eligius_rate_limits';
        $bucket_id = md5($scope . '|' . $id . '|' . $window);
        $now       = time();
        $new_end   = $now + (int) $window;
        $limit     = (int) $limit;

        // Step 1: atomic increment of an OPEN, non-exhausted window.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}`
              SET `count` = `count` + 1, `updated_at` = UTC_TIMESTAMP()
              WHERE `bucket_id` = %s AND `window_end` > %d AND `count` < %d",
            $bucket_id, $now, $limit
        ));
        if (1 === $updated) {
            $this->observe_degradation(false, $scope, '');
            return $this->result($wpdb, $table, $bucket_id, $limit, true);
        }

        // Step 2: the window expired → open a NEW window atomically (the
        // first request that finds the stale row wins; every other concurrent
        // request takes the fresh-window branch afterwards).
        if (false !== $updated) {
            $rolled = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}`
                  SET `count` = 1, `window_end` = %d, `updated_at` = UTC_TIMESTAMP()
                  WHERE `bucket_id` = %s AND `window_end` <= %d",
                $new_end, $bucket_id, $now
            ));
            if (1 === $rolled) {
                $this->observe_degradation(false, $scope, '');
                return array('allowed' => true, 'limit' => $limit, 'remaining' => max(0, $limit - 1), 'reset' => $new_end);
            }
        }

        // Step 3: the row does not exist yet → create a zero row and retry
        // step 1 once. INSERT IGNORE makes concurrent creators harmless (the
        // loser's insert is a no-op and its retry lands on the winner's row).
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}`
                (`bucket_id`, `scope`, `bucket_key`, `window_end`, `count`, `updated_at`)
             VALUES (%s, %s, %s, %d, 0, UTC_TIMESTAMP())",
            $bucket_id, (string) $scope, (string) $id, $new_end
        ));
        if (false !== $inserted) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}`
                  SET `count` = `count` + 1, `updated_at` = UTC_TIMESTAMP()
                  WHERE `bucket_id` = %s AND `window_end` > %d AND `count` < %d",
                $bucket_id, $now, $limit
            ));
            if (1 === $updated) {
                $this->observe_degradation(false, $scope, '');
                return $this->result($wpdb, $table, $bucket_id, $limit, true);
            }
        }

        // Step 4: denied (window fresh, limit exhausted) — the counter is NOT
        // changed; OR a storage failure occurred (false from wpdb) — the
        // limiter itself stays permissive and FLAGS degradation; the
        // fail-closed policy is applied by the sensitive callers
        // (owner final decision — selective fail-closed).
        if (false === $updated) {
            $this->observe_degradation(true, $scope, $wpdb->last_error);
            return array('allowed' => true, 'limit' => $limit, 'remaining' => $limit, 'reset' => $new_end, 'degraded' => true);
        }
        return array('allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset' => $new_end);
    }

    /** Informational readback for the X-RateLimit-* headers. */
    private function result($wpdb, $table, $bucket_id, $limit, $allowed) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT `count`, `window_end` FROM `{$table}` WHERE `bucket_id` = %s", $bucket_id));
        return array(
            'allowed'   => $allowed,
            'limit'     => $limit,
            'remaining' => max(0, $limit - (int) ($row->count ?? 0)),
            'reset'     => (int) ($row->window_end ?? 0),
        );
    }

    /**
     * Check several limits in order; stops at the first exceeded bucket.
     * $limits = [ [scope, id, limit, window], ... ]
     *
     * @return array{exceeded:array|null, last:array} 'exceeded' is the first
     *         exceeded bucket result or null; 'last' is the most recent
     *         consumed bucket result (for X-RateLimit-* headers).
     */
    public function hit_all(array $limits) {
        $results = [];
        foreach ($limits as $bucket) {
            $result    = $this->hit($bucket[0], $bucket[1], $bucket[2], $bucket[3]);
            $results[] = $result;
            if (!$result['allowed']) {
                return ['exceeded' => $result, 'last' => $result];
            }
        }
        $last = end($results);
        return ['exceeded' => null, 'last' => false === $last ? null : $last];
    }

    /** Attach X-RateLimit-* headers (doc 03 §9) to a response. */
    public function apply_headers($response, array $result) {
        if (!method_exists($response, 'header')) {
            return $response;
        }
        $response->header('X-RateLimit-Limit', (string) $result['limit'], true);
        $response->header('X-RateLimit-Remaining', (string) $result['remaining'], true);
        $response->header('X-RateLimit-Reset', (string) $result['reset'], true);
        return $response;
    }

    /** Typed WP_Error for a 429 response (doc 03 §1.3 TOO_MANY_REQUESTS). */
    public function too_many_error(array $result) {
        return new \WP_Error(
            'eligius_rate_limited',
            'Too many requests. Please try again later.',
            [
                'status' => 429,
                'limit'  => $result['limit'],
                'reset'  => $result['reset'],
            ]
        );
    }
}
