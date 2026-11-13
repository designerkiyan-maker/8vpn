<?php
/**
 * Rate limiting (doc 01 §2.1 security/class-rate-limiter.php, doc 03 §9).
 *
 * Fixed-window counters stored as transients (autoload=no options under the
 * default non-persistent object cache). Counters are best-effort under
 * extreme concurrency — they are abuse deterrence, not correctness controls;
 * correctness (one trial, one active device) is enforced by the DB lock
 * discipline (doc 02 §2.8).
 *
 * Windows (doc 03 §9, P1 subset):
 *   - device registration      10 / hour / user
 *   - device challenge/verify  20 / minute / user
 *   - license validation       100 / minute / device
 *   - trial grant              5 / hour / IP + 3 / day / user
 *   - transfer start           3 / day / user
 *   - global API               1000 / minute / IP
 */

namespace Eligius_Sub\Security;

defined('ABSPATH') || exit;

class Class_Rate_Limiter {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Consume one unit of a rate limit window.
     *
     * @param string $scope  Bucket scope, e.g. 'trial_ip'.
     * @param string $id     Identity within the scope (user id, ip, device hash).
     * @param int    $limit  Max requests per window.
     * @param int    $window Window length in seconds.
     * @return array{allowed:bool,limit:int,remaining:int,reset:int}
     */
    public function hit($scope, $id, $limit, $window) {
        $key  = 'eligius_sub_rl_' . md5($scope . '|' . $id . '|' . $window);
        $now  = time();
        $data = get_transient($key);

        if (!is_array($data) || !isset($data['count']) || ($data['window_end'] ?? 0) <= $now) {
            $data = ['count' => 0, 'window_end' => $now + $window];
        }

        $data['count']++;
        set_transient($key, $data, max(1, (int) ($data['window_end'] - $now)));

        return [
            'allowed'   => $data['count'] <= $limit,
            'limit'     => (int) $limit,
            'remaining' => max(0, $limit - $data['count']),
            'reset'     => (int) $data['window_end'],
        ];
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
            $result   = $this->hit($bucket[0], $bucket[1], $bucket[2], $bucket[3]);
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
