<?php
/**
 * Core license logic (doc 01 §2.1 license/class-license-manager.php,
 * doc 04 §5.1 online validation, §3.3 status transitions, §8.1 revocation,
 * doc 05 §4.1 activation, §6 administrative operations).
 */

namespace Eligius_Sub\License;

defined('ABSPATH') || exit;

use Eligius_Sub\Core\Class_Audit_Log_Repository;
use Eligius_Sub\Core\Class_Device_Repository;
use Eligius_Sub\Core\Class_License_Repository;
use Eligius_Sub\Core\Class_UTC_Time;

class Class_License_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Full license status for the active device (doc 04 §5.1 — includes the
     * offline entitlement when more than one hour of validity remains).
     *
     * @return array{valid:bool, ...} Always returns an array; use 'valid' as the gate.
     */
    public function get_status($user_id, $device) {
        // 1. Verify device belongs to the user and is active (already resolved).
        $device_id = (int) $device->id;

        // Phase 5b (owner decision): a PENDING device (registered, inactive)
        // NEVER serves a license and NEVER enters the trial path — it is
        // directed to the OTP transfer flow. trial_eligible is explicitly
        // false: an account whose subscription lives on another device must
        // not be offered a trial (route_trial also requires an ACTIVE device,
        // so this cannot be bypassed with a direct call).
        if (!(int) $device->is_active) {
            $active_id = (int) Class_Device_Repository::find_active_id($user_id);
            return [
                'valid'            => false,
                'error'            => $active_id > 0 ? 'DEVICE_LIMIT_REACHED' : 'DEVICE_NOT_BOUND',
                'active_device_id' => $active_id,
                'trial_eligible'   => false,
            ];
        }

        // 2. License bound to this device.
        $license = Class_License_Repository::get_current_for_device($user_id, $device_id);

        if (!$license) {
            // 3. Trial fallback: trial is account-keyed (doc 05 §3.1) but the
            //    grant stores device_id informationally, so accept the trial
            //    for the currently-active device even if device_id moved.
            $trial = Class_License_Repository::get_active_trial($user_id);
            if ($trial) {
                return $this->build_response($trial, $device_id);
            }

            // Phase 5b: surface REAL trial eligibility (server-side gate =
            // has_trial_ever, doc 05 §3.1/CD-6) so the client only shows the
            // trial banner when the backend says the account qualifies.
            return [
                'valid'          => false,
                'error'          => 'NO_ACTIVE_LICENSE',
                'trial_eligible' => !Class_License_Repository::has_trial_ever($user_id),
            ];
        }

        return $this->build_response($license, $device_id);
    }

    /** Response builder (doc 04 §5.1 build_license_response). */
    private function build_response($license, $device_id) {
        $response = [
            'valid'        => true,
            'license_id'   => (int) $license->id,
            'plan_key'     => (string) $license->plan_key,
            'plan_label'   => (string) $license->plan_label,
            'status'       => (string) $license->status,
            'started_at'   => (string) $license->started_at,
            'expires_at'   => (string) $license->expires_at,
            'device_id'    => (int) $device_id,
            'device_bound' => true,
        ];

        // Issue offline entitlement when >1h validity remains (doc 04 §5.1).
        if (Class_UTC_Time::is_future($license->expires_at, 3600)) {
            $entitlement = Class_License_Issuer::instance()
                ->issue_offline_entitlement((int) $license->id, (int) $device_id);
            if (!is_wp_error($entitlement)) {
                $response['offline_entitlement'] = $entitlement;
            }
        }

        return $response;
    }

    /**
     * Fast boolean validation for the VPN gate (doc 03 §9 rate window
     * 100/min/device; Roadmap P1 — /licenses/validate).
     *
     * @return array{valid:bool,error?:string,license_id?,plan_key?,expires_at?}
     */
    public function validate($user_id, $device) {
        $device_id = (int) $device->id;

        $license = Class_License_Repository::get_current_for_device($user_id, $device_id);
        if (!$license) {
            $trial = Class_License_Repository::get_active_trial($user_id);
            if (!$trial) {
                return ['valid' => false, 'error' => 'NO_ACTIVE_LICENSE'];
            }
            return [
                'valid'      => true,
                'license_id' => (int) $trial->id,
                'plan_key'   => (string) $trial->plan_key,
                'expires_at' => (string) $trial->expires_at,
            ];
        }

        return [
            'valid'      => true,
            'license_id' => (int) $license->id,
            'plan_key'   => (string) $license->plan_key,
            'expires_at' => (string) $license->expires_at,
        ];
    }

    /** Latest ACTIVE/TRIAL license row or null. */
    public function current_license($user_id) {
        return Class_License_Repository::get_current($user_id);
    }

    /** Payment-driven activation (doc 05 §4.1) — standalone transaction. */
    public function activate_license($user_id, $plan_key, $payment_id = null, $device_id = null) {
        global $wpdb;

        $wpdb->query('START TRANSACTION');
        $result = $this->activate_license_within_transaction($user_id, $plan_key, $payment_id, $device_id);
        if (is_wp_error($result)) {
            $wpdb->query('ROLLBACK');
            return $result;
        }
        $wpdb->query('COMMIT');
        return $result;
    }

    /**
     * Activation core WITHOUT its own transaction — used by the payment
     * callback so license activation joins the callback's single transaction
     * (all-or-nothing: a failed activation rolls the whole callback back,
     * docs 05 §3.1 + Phase-4 requirement 9). Assumes the caller has opened a
     * transaction and holds the user-row lock (CD-9 single lock point).
     *
     * @return int|\WP_Error License id or typed error (caller ROLLBACKs).
     */
    public function activate_license_within_transaction($user_id, $plan_key, $payment_id = null, $device_id = null) {
        global $wpdb;

        $plan = $this->get_plan($plan_key);
        if (!$plan) {
            return new \WP_Error('eligius_plan_not_found', 'Unknown plan.', ['status' => 422]);
        }

        // Shared lock point (doc 02 §2.8.3) serializes license creation.
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            (int) $user_id
        ));
        if (null === $locked || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_lock_failed', 'Could not lock the account row.', ['status' => 500]);
        }

        $existing = Class_License_Repository::get_latest($user_id);

        // TRIAL → PAID conversion (doc 05 §3.1: "Payment during trial →
        // upgrades to paid, extend from trial expiry"): the paid license
        // starts when the trial would have ended; the trial row stays TRIAL
        // and is expired later by the daily cron.
        $started_at = Class_UTC_Time::mysql_utc();
        if ($existing
            && 'TRIAL' === $existing->status
            && !$plan->is_trial
            && $existing->expires_at > Class_UTC_Time::mysql_utc()
        ) {
            $started_at = (string) $existing->expires_at;
        }

        // RENEWAL (doc 05 §4.1): same-plan payment while that plan is ACTIVE
        // must EXTEND the existing row — inserting a second ACTIVE row would
        // violate uniq_user_active_plan and make the callback retry loop
        // permanently fail (Phase-4 requirement 9). Renewal extends
        // expires_at from the current expiry and reuses the row id.
        if ($existing
            && 'ACTIVE' === $existing->status
            && $existing->plan_key === (string) $plan->plan_key
            && $existing->expires_at > Class_UTC_Time::mysql_utc()
        ) {
            $new_expires = gmdate('Y-m-d H:i:s', strtotime("+{$plan->duration_days} days", strtotime($existing->expires_at)));
            $extended = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}eligius_licenses
                  SET expires_at = %s, updated_at = CURRENT_TIMESTAMP
                  WHERE id = %d",
                $new_expires,
                (int) $existing->id
            ));
            if (false === $extended || '' !== $wpdb->last_error) {
                return new \WP_Error('eligius_db_update_failed', $wpdb->last_error ?: 'License renewal failed.', ['status' => 500]);
            }

            $event = Class_Audit_Log_Repository::insert_license_event([
                'license_id' => (int) $existing->id,
                'user_id'    => (int) $user_id,
                'device_id'  => $device_id,
                'event_type' => 'license.renewed',
                'details'    => [
                    'plan_key'      => $plan_key,
                    'extends_to'    => $new_expires,
                    'payment_id'    => $payment_id ? (int) $payment_id : null,
                ],
            ]);
            if (0 === $event) {
                return new \WP_Error('eligius_db_audit_failed', 'License renewal event insert failed.', ['status' => 500]);
            }

            return (int) $existing->id;
        }

        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$plan->duration_days} days", strtotime($started_at)));

        $data = [
            'user_id'         => (int) $user_id,
            'device_id'       => $device_id ? (int) $device_id : null,
            'plan_key'        => (string) $plan->plan_key,
            'plan_label'      => (string) $plan->label,
            'duration_days'   => (int) $plan->duration_days,
            'started_at'      => $started_at,
            'expires_at'      => $expires_at,
            'status'          => 'ACTIVE',
            'source'          => $payment_id ? 'payment' : 'admin',
            'payment_id'      => $payment_id ? (int) $payment_id : null,
            'previous_license_id' => $existing ? (int) $existing->id : null,
        ];

        $license_id = Class_License_Repository::create($data);
        if (0 === $license_id || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_insert_failed', $wpdb->last_error ?: 'License insert failed.', ['status' => 500]);
        }

        // Terminal non-active statuses of the previous license are kept for
        // history; a still-ACTIVE previous row would violate
        // uniq_user_active_plan, which is the one-active-license guarantee.
        $event = Class_Audit_Log_Repository::insert_license_event([
            'license_id' => $license_id,
            'user_id'    => (int) $user_id,
            'device_id'  => $device_id,
            'event_type' => 'license.activated',
            'new_status' => 'ACTIVE',
            'details'    => ['plan_key' => $plan_key, 'source' => $data['source']],
        ]);
        if (0 === $event) {
            return new \WP_Error('eligius_db_audit_failed', 'License event insert failed.', ['status' => 500]);
        }

        return (int) $license_id;
    }

    /** Admin revocation (doc 04 §8.1 immediate, terminal — doc 04 §3.3). */
    public function revoke($license_id, $reason = '', $actor_user_id = 0) {
        $license = Class_License_Repository::find($license_id);
        if (!$license) {
            return new \WP_Error('eligius_license_not_found', 'License not found.', ['status' => 404]);
        }
        if ('REVOKED' === $license->status) {
            return new \WP_Error('eligius_license_already_revoked', 'License already revoked.', ['status' => 409]);
        }

        $updated = Class_License_Repository::update($license_id, ['status' => 'REVOKED']);
        if (false === $updated && '' !== $GLOBALS['wpdb']->last_error) {
            return new \WP_Error('eligius_db_update_failed', $GLOBALS['wpdb']->last_error, ['status' => 500]);
        }

        Class_Audit_Log_Repository::insert_license_event([
            'license_id' => (int) $license_id,
            'user_id'    => (int) $license->user_id,
            'device_id'  => $license->device_id,
            'event_type' => 'license.revoked',
            'old_status' => (string) $license->status,
            'new_status' => 'REVOKED',
            'details'    => ['reason' => (string) $reason],
        ]);
        Class_Audit_Log_Repository::insert_audit([
            'user_id'       => (int) $license->user_id,
            'license_id'    => (int) $license_id,
            'actor_user_id' => (int) $actor_user_id,
            'event_type'    => 'admin.license.revoked',
            'severity'      => 'CRITICAL',
            'details'       => ['reason' => (string) $reason],
        ]);

        return ['revoked' => true, 'license_id' => (int) $license_id];
    }

    /** Admin extension (doc 05 §6 manual extension). */
    public function extend($license_id, $days, $reason = '', $actor_user_id = 0) {
        global $wpdb;

        $days = (int) $days;
        if ($days < 1 || $days > 3650) {
            return new \WP_Error('eligius_bad_days', 'Extension must be 1–3650 days.', ['status' => 400]);
        }

        $license = Class_License_Repository::find($license_id);
        if (!$license) {
            return new \WP_Error('eligius_license_not_found', 'License not found.', ['status' => 404]);
        }

        $base = max($license->expires_at, Class_UTC_Time::mysql_utc());
        $new_expires = gmdate('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($base)));

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_licenses SET expires_at = %s WHERE id = %d",
            $new_expires,
            (int) $license_id
        ));
        if (false === $updated || '' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_update_failed', $wpdb->last_error ?: 'Extend failed.', ['status' => 500]);
        }

        Class_Audit_Log_Repository::insert_license_event([
            'license_id' => (int) $license_id,
            'user_id'    => (int) $license->user_id,
            'event_type' => 'license.extended',
            'details'    => ['days' => $days, 'new_expires_at' => $new_expires, 'reason' => (string) $reason],
        ]);

        return ['extended' => true, 'license_id' => (int) $license_id, 'expires_at' => $new_expires];
    }

    /** Plan lookup (doc 05 §4.1). */
    public function get_plan($plan_key) {
        global $wpdb;
        $table = $GLOBALS['wpdb']->prefix . 'eligius_plans';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE plan_key = %s AND is_active = 1",
            (string) $plan_key
        ));
    }

    /** Active plans for the admin dashboard. */
    public function list_plans() {
        global $wpdb;
        $table = $GLOBALS['wpdb']->prefix . 'eligius_plans';
        return $wpdb->get_results("SELECT * FROM `{$table}` WHERE is_active = 1 ORDER BY sort ASC");
    }
}
