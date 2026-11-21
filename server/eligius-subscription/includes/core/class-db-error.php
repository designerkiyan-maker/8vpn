<?php
/**
 * Central internal-error reporting (Phase 6 M-1 — owner requirement:
 * "هیچ SQL Error، نام جدول، نام ستون یا اطلاعات دیتابیس نباید به کاربر
 * نمایش داده شود؛ خطای داخلی باید در سمت سرور ثبت شود").
 *
 * Usage inside a failure branch:
 *   Class_DB_Error::log('transfer.verify', $wpdb->last_error);
 *   return new \WP_Error('eligius_db_error', Class_DB_Error::CLIENT_MESSAGE, ['status' => 500]);
 *
 * or, in one step (logs and returns the generic client message):
 *   new \WP_Error('eligius_db_error', Class_DB_Error::message('transfer.verify', $wpdb->last_error), …);
 *
 * The raw MySQL text is written to the PHP error log WITH the calling
 * function captured for traceability; the client only ever receives
 * CLIENT_MESSAGE. Never log JWTs, OTPs, private keys, merchant
 * credentials or other session-abusable secrets alongside DB errors.
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

final class Class_DB_Error {

    /** The ONLY database-failure message ever sent to API clients. */
    public const CLIENT_MESSAGE = 'An internal error occurred. Please try again later.';

    /** One-step helper: log the raw error server-side, return the generic client message. */
    public static function message(string $context, string $db_error = ''): string {
        self::log($context, $db_error);
        return self::CLIENT_MESSAGE;
    }

    /** Server-side log with the calling function captured for traceability. */
    public static function log(string $context, string $db_error = ''): void {
        $caller = '(unknown caller)';
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (isset($frames[1]['function'])) {
            $caller = ($frames[1]['class'] ?? '') . ($frames[1]['class'] ?? '' !== '' ? '::' : '') . $frames[1]['function'];
        }
        error_log('eligius-subscription [' . $context . ' via ' . $caller . '] db error: '
            . ('' !== $db_error ? $db_error : '(none reported by wpdb)'));
    }

    private function __construct() {}
}
