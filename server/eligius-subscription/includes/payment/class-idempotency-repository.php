<?php
/**
 * Idempotency repository (docs 02 §2.7, 05 §3.1 — wp_eligius_payment_idempotency).
 *
 * The row is the callback serializer: SELECT … FOR UPDATE on gateway_ref
 * guarantees that two concurrent callbacks for the same payment cannot both
 * pass the duplicate check (docs 02 §2.8.3 explicit-error discipline).
 */

namespace Eligius_Sub\Payment;

defined('ABSPATH') || exit;

class Class_Idempotency_Repository {

    /** Fetch a row FOR UPDATE (call inside an open transaction). */
    public static function find_for_update(string $gateway_ref) {
        global $wpdb;
        $table = $wpdb->prefix . 'eligius_payment_idempotency';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE gateway_ref = %s FOR UPDATE",
            $gateway_ref
        ));
        if ('' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_error', $wpdb->last_error, ['status' => 500]);
        }
        return $row;
    }

    /** Insert a placeholder row (status PENDING) for a fresh callback. */
    public static function insert_pending(string $gateway_ref, string $gateway): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'eligius_payment_idempotency';
        $ok = $wpdb->insert($table, [
            'gateway_ref'     => $gateway_ref,
            'gateway'         => $gateway,
            'status'          => 'PENDING',
            'request_payload' => null,
            'response_payload' => null,
            'processed_at'    => Class_UTC_Time_Helper::mysql_utc(),
        ]);
        if (false === $ok || '' !== $wpdb->last_error) {
            return false;
        }
        return true;
    }

    /** Mark the row's final status + payloads. */
    public static function mark(string $gateway_ref, string $status, $request_payload, $response_payload): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'eligius_payment_idempotency';
        $ok = $wpdb->update($table, [
            'status'           => $status,
            'request_payload'  => $request_payload ? wp_json_encode($request_payload) : null,
            'response_payload' => $response_payload ? wp_json_encode($response_payload) : null,
            'processed_at'     => Class_UTC_Time_Helper::mysql_utc(),
        ], ['gateway_ref' => $gateway_ref]);
        if (false === $ok || '' !== $wpdb->last_error) {
            return false;
        }
        return true;
    }

    /** Delete a stale PENDING row (retryable after 10 minutes). */
    public static function delete_stale_pending(string $gateway_ref): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'eligius_payment_idempotency';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}`
              WHERE gateway_ref = %s AND status = 'PENDING'
                AND processed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)",
            $gateway_ref
        ));
        return ('' === $wpdb->last_error);
    }
}

/** Small UTC-time helper (mirrors Core\Class_UTC_Time without the autoload hop). */
class Class_UTC_Time_Helper {
    public static function mysql_utc(): string {
        return gmdate('Y-m-d H:i:s');
    }
}
