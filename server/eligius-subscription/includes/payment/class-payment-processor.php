<?php
/**
 * Payment orchestration (docs 07 §P4 flow, 05 §3.1/§3.2, 04 §9).
 *
 * Security invariants (owner's Phase-4 critical list):
 *  1. A gateway callback alone never proves payment — activation happens
 *     only after successful Server-to-Server verification.
 *  2. Amounts are compared in the gateway unit (IRR) with the IRT→IRR
 *     conversion performed inside the adapter (TD-2).
 *  3. Duplicate/concurrent callbacks are serialized by the payments-row
 *     FOR UPDATE lock + the idempotency table (docs 02 §2.7/§2.8.3).
 *  4. Everything runs in ONE transaction: any failure (including license
 *     activation) rolls the whole callback back — the payment stays
 *     INITIATED and remains retryable (requirement 9: recovery).
 *  5. Secrets never enter API responses or logs.
 */

namespace Eligius_Sub\Payment;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\License\Class_License_Manager;

class Class_Payment_Processor {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ---------------------------------------------------------------- create

    /**
     * Create an INITIATED payment and ask the gateway for a redirect URL.
     *
     * @return array{payment_id:int, gateway:string, authority:string,
     *               redirect_url:string, amount_irt:int, expires_in:int}|\WP_Error
     */
    public function create_payment(int $user_id, string $plan_key, string $gateway_key) {
        global $wpdb;

        $manager = Class_License_Manager::instance();
        $plan = $manager->get_plan($plan_key);
        if (!$plan) {
            return new \WP_Error('eligius_plan_not_found', 'Unknown or inactive plan.', ['status' => 422]);
        }
        if ($plan->is_trial) {
            return new \WP_Error('eligius_plan_not_purchasable', 'The trial plan cannot be purchased.', ['status' => 422]);
        }

        $adapter = Class_Gateway_Factory::create($gateway_key);
        if (!$adapter) {
            return new \WP_Error(
                'GATEWAY_UNAVAILABLE',
                'Payment gateway is not available (disabled, unconfigured, or locked).',
                ['status' => 503]
            );
        }

        // Trial guard: an account that already used the trial cannot buy the
        // trial again — and cannot pay FOR the trial plan (blocked above).
        $amount_irt = (int) $plan->price_irt;
        $description = sprintf('Eligius %s (%d days)', $plan->label, (int) $plan->duration_days);

        try {
            $initiated = $adapter->initiate_payment([
                'amount_irt'  => $amount_irt,
                'description' => $description,
                'user_id'     => $user_id,
                'plan_key'    => $plan_key,
                'callback_url'=> $adapter->get_callback_url(),
                'metadata'    => ['user_id' => $user_id, 'plan_key' => $plan_key],
            ]);
        } catch (\Exception $e) {
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => $user_id,
                'event_type' => 'payment.create_failed',
                'severity'   => 'WARNING',
                'details'    => ['gateway' => $gateway_key, 'reason' => 'gateway error'],
            ]);
            return new \WP_Error('GATEWAY_UNAVAILABLE', 'Gateway request failed.', ['status' => 503]);
        }

        // Insert AFTER a successful gateway response — gateway_ref = authority
        // (UNIQUE, docs 02 §2.3).
        $ok = $wpdb->insert($wpdb->prefix . 'eligius_payments', [
            'user_id'             => $user_id,
            'gateway'             => $gateway_key,
            'gateway_ref'         => (string) $initiated['authority'],
            'authority'           => (string) $initiated['authority'],
            'amount'              => $amount_irt,
            'currency'            => 'IRT',
            'plan_key'            => (string) $plan->plan_key,
            'plan_duration_days'  => (int) $plan->duration_days,
            'status'              => 'INITIATED',
            'initiated_at'        => gmdate('Y-m-d H:i:s'),
        ]);
        if (false === $ok || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_insert_failed', 'Payment insert failed.', ['status' => 500]);
        }
        $payment_id = (int) $wpdb->insert_id;

        return [
            'payment_id'   => $payment_id,
            'gateway'      => $gateway_key,
            'authority'    => (string) $initiated['authority'],
            'redirect_url' => (string) $initiated['redirect_url'],
            'amount_irt'   => $amount_irt,
            'expires_in'   => 1800,
        ];
    }

    // --------------------------------------------------------------- callback

    /**
     * Process a gateway callback end-to-end (docs 07 §P4 flow, 05 §3.1).
     * Single transaction; serialization via the payments-row FOR UPDATE.
     *
     * @return array{status:string, duplicate:bool, payment_id:int, license_id:?int}|\WP_Error
     */
    public function handle_callback(string $gateway_key, array $callback_params) {
        global $wpdb;

        $authority = (string) ($callback_params['authority'] ?? $callback_params['Authority'] ?? '');
        if ('' === $authority) {
            return new \WP_Error('eligius_callback_invalid', 'Missing authority.', ['status' => 400]);
        }

        $payments = $wpdb->prefix . 'eligius_payments';
        $idem     = $wpdb->prefix . 'eligius_payment_idempotency';

        // Safety: a PENDING idempotency row can only survive a hard process
        // crash mid-transaction; a stale one (>10 min) must never block a
        // legitimate retry — drop it before opening our transaction.
        Class_Idempotency_Repository::delete_stale_pending($authority);

        // ---- Single transaction ------------------------------------------------
        $wpdb->query('START TRANSACTION');

        // 1. THE LOCK — our authoritative payment row (exists for every callback).
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$payments}` WHERE gateway_ref = %s AND gateway = %s FOR UPDATE",
            $authority,
            $gateway_key
        ));
        if ('' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', $wpdb->last_error, ['status' => 500]);
        }
        if (!$payment) {
            $wpdb->query('ROLLBACK');
            Class_Audit_Log_Repository::insert_audit([
                'event_type' => 'payment.callback_unknown_authority',
                'severity'   => 'WARNING',
                'details'    => ['gateway' => $gateway_key],
            ]);
            return new \WP_Error('eligius_callback_unknown', 'Unknown payment authority.', ['status' => 404]);
        }

        // 2. Idempotency row + duplicate handling (docs 02 §2.7).
        $idemRow = Class_Idempotency_Repository::find_for_update($authority);
        if (is_wp_error($idemRow)) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', $idemRow->get_error_message(), ['status' => 500]);
        }
        if (!$idemRow) {
            if (!Class_Idempotency_Repository::insert_pending($authority, $gateway_key)) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('eligius_db_error', 'Idempotency insert failed.', ['status' => 500]);
            }
        } elseif ('VERIFIED' === $idemRow->status || 'VERIFIED' === $payment->status) {
            // Duplicate callback — already fully processed.
            $wpdb->query('COMMIT');
            return ['status' => 'VERIFIED', 'duplicate' => true, 'payment_id' => (int) $payment->id, 'license_id' => $payment->license_id ? (int) $payment->license_id : null];
        }

        // 3. Server-to-Server verification (network call; adapter converts
        //    IRT→IRR and compares the gateway amount — TD-2).
        $adapter = Class_Gateway_Factory::create($gateway_key);
        if (!$adapter) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('GATEWAY_UNAVAILABLE', 'Gateway unavailable for verification.', ['status' => 503]);
        }
        $verify = $adapter->verify_payment($callback_params, (int) $payment->amount);

        if (empty($verify['verified'])) {
            // Hard-fail paths: gateway cancel/reject or AMOUNT_MISMATCH.
            $updated = $wpdb->update($payments, [
                'status'           => 'FAILED',
                'gateway_response' => wp_json_encode(['error' => $verify['error'], 'ref_id' => $verify['ref_id']]),
            ], ['id' => (int) $payment->id]);
            if (false === $updated || '' !== $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('eligius_db_error', 'Payment failure update failed.', ['status' => 500]);
            }
            Class_Idempotency_Repository::mark($authority, 'FAILED', $callback_params, ['error' => $verify['error']]);
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $payment->user_id,
                'license_id' => $payment->license_id ? (int) $payment->license_id : null,
                'event_type' => 'payment.failed',
                'severity'   => 'WARNING',
                'details'    => ['reason' => $verify['error'], 'gateway' => $gateway_key],
            ]);
            $wpdb->query('COMMIT');
            return ['status' => 'FAILED', 'duplicate' => false, 'payment_id' => (int) $payment->id, 'license_id' => null];
        }

        // 4. Payment VERIFIED (still inside the same transaction).
        $updated = $wpdb->update($payments, [
            'status'           => 'VERIFIED',
            'gateway_response' => wp_json_encode(['ref_id' => $verify['ref_id'], 'amount' => $verify['amount']]),
            'verified_at'      => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int) $payment->id]);
        if (false === $updated || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', 'Payment verify update failed.', ['status' => 500]);
        }

        // 5. License activation INSIDE the same transaction — a failed
        //    activation rolls the ENTIRE callback back (payment stays
        //    INITIATED → retryable), so a paid-but-inactive state can never
        //    be committed (requirement 9).
        $manager = Class_License_Manager::instance();
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}eligius_devices WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
            (int) $payment->user_id
        ));
        $licenseId = $manager->activate_license_within_transaction(
            (int) $payment->user_id,
            (string) $payment->plan_key,
            (int) $payment->id,
            $device ? (int) $device->id : null
        );
        if (is_wp_error($licenseId)) {
            $wpdb->query('ROLLBACK');
            // Recovery: the gateway may re-send the callback (idempotency row
            // rolled back too) or an admin can re-run verification.
            Class_Audit_Log_Repository::insert_audit([
                'user_id'    => (int) $payment->user_id,
                'event_type' => 'payment.activation_failed',
                'severity'   => 'CRITICAL',
                'details'    => ['reason' => $licenseId->get_error_message(), 'payment_id' => (int) $payment->id],
            ]);
            return new \WP_Error('eligius_activation_failed', 'Payment verified but license activation failed — retryable.', ['status' => 500]);
        }

        // 5b. Link the license id on the payments row (docs 02 §2.3 FK).
        $linked = $wpdb->update($payments, [
            'license_id' => $licenseId,
        ], ['id' => (int) $payment->id]);
        if (false === $linked || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', 'License link update failed.', ['status' => 500]);
        }

        // 6. Idempotency final status.
        if (!Class_Idempotency_Repository::mark($authority, 'VERIFIED', $callback_params, ['ref_id' => $verify['ref_id']])) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('eligius_db_error', 'Idempotency mark failed.', ['status' => 500]);
        }

        $wpdb->query('COMMIT');

        // 7. Audit (after commit — informational).
        Class_Audit_Log_Repository::insert_audit([
            'user_id'    => (int) $payment->user_id,
            'license_id' => $licenseId,
            'event_type' => 'payment.verified',
            'severity'   => 'INFO',
            'details'    => [
                'payment_id' => (int) $payment->id,
                'gateway'    => $gateway_key,
                'ref_id'     => $verify['ref_id'],
                'amount_irt' => (int) $payment->amount,
            ],
        ]);

        return ['status' => 'VERIFIED', 'duplicate' => false, 'payment_id' => (int) $payment->id, 'license_id' => $licenseId];
    }

    // ---------------------------------------------------------------- refund

    /** Admin-initiated refund (docs 05 §6 — Payment Management). */
    public function refund_payment(int $payment_id, int $actor_user_id, string $reason = '') {
        global $wpdb;

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}eligius_payments WHERE id = %d",
            $payment_id
        ));
        if (!$payment) {
            return new \WP_Error('eligius_payment_not_found', 'Payment not found.', ['status' => 404]);
        }
        if ('VERIFIED' !== $payment->status) {
            return new \WP_Error('eligius_refund_not_allowed', 'Only VERIFIED payments can be refunded.', ['status' => 422]);
        }

        $adapter = Class_Gateway_Factory::create((string) $payment->gateway);

        // Owner decision (Phase-4 final audit): automatic refunds are DISABLED.
        // This guard fires BEFORE any database change — payment and license
        // stay untouched. Manual license revocation by an administrator
        // (Class_License_Manager::revoke) remains the only available path.
        if (!$adapter || !$adapter->supports_refund()) {
            return new \WP_Error('REFUND_NOT_SUPPORTED', 'Automatic refunds are disabled; gateway does not support refunds.', ['status' => 501]);
        }

        $refund = $adapter->refund((string) $payment->gateway_ref, (int) $payment->amount);
        if (empty($refund['refunded'])) {
            return new \WP_Error('REFUND_NOT_SUPPORTED', $refund['error'] ?: 'Refund failed.', ['status' => 501]);
        }

        // Payment → REFUNDED; the linked license → REVOKED (terminal, docs 04 §8.1).
        $updated = $wpdb->update($wpdb->prefix . 'eligius_payments', [
            'status' => 'REFUNDED',
            'verified_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $payment_id]);
        if (false === $updated || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_error', 'Refund update failed.', ['status' => 500]);
        }

        if ($payment->license_id) {
            $license = Class_License_Repository::find((int) $payment->license_id);
            if ($license && 'REVOKED' !== $license->status) {
                Class_License_Manager::instance()->revoke(
                    (int) $payment->license_id,
                    'payment refunded' . ($reason !== '' ? ': ' . $reason : ''),
                    $actor_user_id
                );
            }
        }

        Class_Audit_Log_Repository::insert_audit([
            'user_id'       => (int) $payment->user_id,
            'license_id'    => $payment->license_id ? (int) $payment->license_id : null,
            'actor_user_id' => $actor_user_id,
            'event_type'    => 'payment.refunded',
            'severity'      => 'CRITICAL',
            'details'       => ['reason' => (string) $reason, 'gateway' => $payment->gateway],
        ]);

        return ['refunded' => true, 'payment_id' => $payment_id];
    }
}
