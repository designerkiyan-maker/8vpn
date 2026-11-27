<?php
/**
 * P9.2 — quota math (pure, WP-free so the unit suite can exercise it).
 *
 * Client-trusted metering (doc limitation, owner-approved): the app reports
 * rx+tx deltas; the server accumulates them monotonically on the license row
 * and gates at used >= quota. Conversions use binary GiB (1024^3).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_Volume {

    /** Per-report delta cap (1 TB) — mirrors the legacy eligius_usage_cap. */
    const MAX_REPORT_BYTES = 1099511627776; // 2^40

    /** GiB → bytes (null volume → null quota = unlimited). */
    public static function quota_bytes( $volume_gb ) {
        if ( null === $volume_gb || (int) $volume_gb < 1 ) {
            return null;
        }
        return (int) $volume_gb * 1073741824; // 1024^3
    }

    /** Monotonic-safe report delta (negative/absurd values clamped). */
    public static function clamp_delta( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes < 0 ) {
            return 0;
        }
        return min( $bytes, self::MAX_REPORT_BYTES );
    }

    /** 0..100, capped; null quota → null (no bar). */
    public static function percent( $used_bytes, $total_bytes ) {
        if ( null === $total_bytes || $total_bytes < 1 ) {
            return null;
        }
        $used = max( 0, (int) $used_bytes );
        return (int) min( 100, floor( ( $used * 100 ) / $total_bytes ) );
    }

    /** Quota gate: exhausted only when a real quota exists and is reached. */
    public static function is_exhausted( $used_bytes, $total_bytes ) {
        if ( null === $total_bytes || $total_bytes < 1 ) {
            return false;
        }
        return max( 0, (int) $used_bytes ) >= (int) $total_bytes;
    }
}
