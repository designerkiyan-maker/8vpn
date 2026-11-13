<?php
/**
 * UTC Time helper for Eligius Subscription.
 *
 * All database timestamps MUST be stored and compared in UTC.
 * WordPress's current_time('mysql') returns the site's timezone time,
 * which causes timezone mismatches when the server/db runs in a different TZ.
 *
 * This helper provides explicit UTC timestamps in MySQL format (Y-m-d H:i:s).
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_UTC_Time {

    /**
     * Get current UTC time in MySQL DATETIME format (Y-m-d H:i:s).
     * This is the ONLY function that should be used for database timestamps.
     *
     * @return string UTC timestamp in 'Y-m-d H:i:s' format
     */
    public static function mysql_utc() {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Get current UTC timestamp (Unix epoch).
     *
     * @return int Unix timestamp (UTC)
     */
    public static function unix_utc() {
        return time(); // time() is always UTC
    }

    /**
     * Get current UTC time in ISO 8601 format (for API responses).
     *
     * @return string UTC timestamp in ISO 8601 format (c)
     */
    public static function iso_utc() {
        return gmdate('c');
    }

    /**
     * Convert a Unix timestamp to ISO 8601 UTC format.
     *
     * @param int $timestamp Unix timestamp
     * @return string UTC timestamp in ISO 8601 format (c)
     */
    public static function iso_utc_from_timestamp($timestamp) {
        return gmdate('c', $timestamp);
    }

    /**
     * Add seconds to a UTC MySQL timestamp.
     *
     * @param string $mysql_utc UTC timestamp in 'Y-m-d H:i:s' format
     * @param int $seconds Seconds to add
     * @return string New UTC timestamp in 'Y-m-d H:i:s' format
     */
    public static function add_seconds($mysql_utc, $seconds) {
        return gmdate('Y-m-d H:i:s', strtotime($mysql_utc) + $seconds);
    }

    /**
     * Add days to a UTC MySQL timestamp.
     *
     * @param string $mysql_utc UTC timestamp in 'Y-m-d H:i:s' format
     * @param int $days Days to add
     * @return string New UTC timestamp in 'Y-m-d H:i:s' format
     */
    public static function add_days($mysql_utc, $days) {
        return gmdate('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($mysql_utc)));
    }

    /**
     * Check if a UTC MySQL timestamp is in the future (with optional buffer).
     *
     * @param string $mysql_utc UTC timestamp to check
     * @param int $buffer_seconds Optional buffer in seconds
     * @return bool True if timestamp is in the future (strictly greater than now + buffer)
     */
    public static function is_future($mysql_utc, $buffer_seconds = 0) {
        return $mysql_utc > self::add_seconds(self::mysql_utc(), $buffer_seconds);
    }

    /**
     * Check if a UTC MySQL timestamp is in the past or now.
     *
     * @param string $mysql_utc UTC timestamp to check
     * @return bool True if timestamp is past or now
     */
    public static function is_past_or_now($mysql_utc) {
        return $mysql_utc <= self::mysql_utc();
    }
}