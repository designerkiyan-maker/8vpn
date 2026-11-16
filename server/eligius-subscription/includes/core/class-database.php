<?php
/**
 * Schema & migrations (doc 02-DATABASE-DESIGN.md §2, doc 01 §2.3, doc 02 §5).
 *
 * Creates the eight wp_eligius_* tables with explicit ENGINE=InnoDB (row
 * locking + transactions only exist in InnoDB — doc 02 §2.8.2), verifies the
 * engine of wp_users, seeds default plans, and handles DB version upgrades.
 *
 * Tables use raw CREATE TABLE IF NOT EXISTS instead of dbDelta(): foreign
 * keys, the ENUM columns, and the generated column used for the
 * one-active-license constraint (doc 02 §2.2 uniq_user_active_plan) are
 * beyond what dbDelta can express reliably.
 */

namespace Eligius_Sub\Core;

defined('ABSPATH') || exit;

class Class_Database {

    /** Tables managed by this plugin, table-suffix => FK parents needed. */
    private static $tables = [
        'eligius_plans',
        'eligius_devices',
        'eligius_licenses',
        'eligius_payments',
        'eligius_license_events',
        'eligius_device_transfers',
        'eligius_payment_idempotency',
        'eligius_audit_logs',
    ];

    /**
     * Run the full initial migration (doc 02 §5.1).
     *
     * @return true|\WP_Error True on success, typed error on refusal.
     */
    public static function install() {
        global $wpdb;

        // Doc 02 §2.8.2: wp_users MUST be InnoDB for the shared user-row lock.
        $engine_check = self::require_innodb($wpdb->users);
        if (is_wp_error($engine_check)) {
            return $engine_check;
        }

        foreach (self::$tables as $suffix) {
            $sql = self::table_sql($suffix);
            if (null === $sql) {
                continue;
            }
            $wpdb->query($sql);
            if ('' !== $wpdb->last_error) {
                return new \WP_Error(
                    'eligius_db_create_failed',
                    sprintf('Failed creating %1$s: %2$s', $suffix, $wpdb->last_error),
                    ['status' => 500]
                );
            }
        }

        // Every eligius_* table must now be InnoDB (refuse rather than run a
        // broken one-active-device control — doc 02 §2.8.5).
        foreach (self::$tables as $suffix) {
            $engine_check = self::require_innodb($wpdb->prefix . $suffix);
            if (is_wp_error($engine_check)) {
                return $engine_check;
            }
        }

        self::seed_plans();

        update_option('eligius_sub_db_version', ELIGIUS_SUB_DB_VERSION);
        add_option('eligius_sub_grace_period_hours', 72);
        add_option('eligius_sub_trial_days', 30);
        add_option('eligius_sub_max_devices', 1);
        add_option('eligius_sub_license_key_rotation_days', 90);
        add_option('eligius_sub_max_transfers_per_day', 3);

        // Phase 5 migration: add `blocked` column to wp_eligius_devices if missing.
        self::migrate_add_blocked_column();

        return true;
    }

    /**
     * Phase 5 migration: add `blocked` column to wp_eligius_devices.
     * Versioned — idempotent via column_exists check (doc 02 §5.2).
     */
    private static function migrate_add_blocked_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'eligius_devices';
        $exists = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$table}` LIKE 'blocked'",
            ARRAY_A
        );
        if ('' !== $wpdb->last_error || !empty($exists)) {
            return; // already exists or table missing
        }
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `blocked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`");
        if ('' !== $wpdb->last_error) {
            error_log('eligius-subscription: failed to add blocked column to ' . $table . ': ' . $wpdb->last_error);
        }
    }

    /** Schema upgrades between stored and current DB versions (doc 02 §5.2). */
    public static function maybe_upgrade() {
        $current = get_option('eligius_sub_db_version', '0');
        if (version_compare((string) $current, ELIGIUS_SUB_DB_VERSION, '<')) {
            // v1.0.0 → same full install; idempotent via IF NOT EXISTS + plans upsert.
            $result = self::install();
            if (!is_wp_error($result)) {
                update_option('eligius_sub_db_version', ELIGIUS_SUB_DB_VERSION);
            }
        }
    }

    /**
     * Ensure a table is InnoDB; attempt conversion, refuse on failure.
     */
    private static function require_innodb($table) {
        global $wpdb;

        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if ('' !== $wpdb->last_error) {
            return new \WP_Error('eligius_db_engine_check_failed', $wpdb->last_error, ['status' => 500]);
        }
        if (null === $engine) {
            // wp_users missing is impossible; an eligius_* table here means CREATE failed above.
            return new \WP_Error('eligius_db_table_missing', "Table {$table} does not exist.", ['status' => 500]);
        }
        if ('InnoDB' === $engine) {
            return true;
        }

        // Conversion attempt (doc 02 §2.8.2: ALTER TABLE … ENGINE=InnoDB).
        $wpdb->query("ALTER TABLE `{$table}` ENGINE=InnoDB");
        if ('' !== $wpdb->last_error) {
            return new \WP_Error(
                'eligius_db_not_innodb',
                sprintf(
                    'Table %1$s uses %2$s and could not be converted to InnoDB. Row locking requires InnoDB (doc 02 §2.8.2).',
                    $table,
                    $engine
                ),
                ['status' => 500]
            );
        }
        return true;
    }

    /** CREATE TABLE statement per suffix (doc 02 §2.1–2.7 + §2.2 plans). */
    private static function table_sql($suffix) {
        global $wpdb;

        $p      = $wpdb->prefix;
        $users  = $wpdb->users;
        $tail   = ' ENGINE=InnoDB ' . $wpdb->get_charset_collate();

        switch ($suffix) {
            case 'eligius_plans':
                // Columns per doc 01 §2.3 create_default_plans() + doc 03 §4.1 response fields.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_plans` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plan_key` VARCHAR(32) NOT NULL,
                    `label` VARCHAR(64) NOT NULL,
                    `duration_days` SMALLINT UNSIGNED NOT NULL,
                    `price_irt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `discount_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `features` JSON NULL,
                    `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
                    `sort` SMALLINT NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_plan_key` (`plan_key`)
                ){$tail}";

            case 'eligius_devices':
                // Doc 02 §2.1. device_hash is NOT globally unique: the same
                // install can register against several accounts (doc 05 §3.2
                // step 3 anticipates cross-account device_hash correlations),
                // so uniqueness is (user_id, device_hash).
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_devices` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `device_hash` CHAR(64) NOT NULL,
                    `public_key` TEXT NOT NULL,
                    `label` VARCHAR(64) NOT NULL DEFAULT '',
                    `registered_via` VARCHAR(32) NOT NULL DEFAULT '',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_seen_at` DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_user_device` (`user_id`, `device_hash`),
                    KEY `idx_user_active` (`user_id`, `is_active`),
                    KEY `idx_device_hash` (`device_hash`),
                    CONSTRAINT `fk_edev_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE CASCADE
                ){$tail}";

            case 'eligius_licenses':
                // Doc 02 §2.2 + doc 04 §3.1 (signed_blob, blob_expires_at,
                // public_key_id). MariaDB has no partial unique indexes, so
                // "one active license per user per plan" is expressed with a
                // STORED generated column: non-terminal statuses yield the
                // plan_key, terminal ones yield NULL (NULLs are exempt from
                // unique keys) — unlimited history rows, single ACTIVE /
                // TRIAL / PENDING_PAYMENT row per plan.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_licenses` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `plan_key` VARCHAR(32) NOT NULL,
                    `plan_label` VARCHAR(64) NOT NULL,
                    `duration_days` SMALLINT UNSIGNED NOT NULL,
                    `started_at` DATETIME NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `status` ENUM('ACTIVE','EXPIRED','REVOKED','PENDING_PAYMENT','TRIAL') NOT NULL DEFAULT 'PENDING_PAYMENT',
                    `source` VARCHAR(32) NOT NULL DEFAULT 'payment',
                    `payment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `previous_license_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `signed_blob` TEXT NULL,
                    `blob_expires_at` DATETIME NULL DEFAULT NULL,
                    `public_key_id` VARCHAR(32) NULL DEFAULT NULL,
                    `active_plan_slot` VARCHAR(32) GENERATED ALWAYS AS
                        (CASE WHEN `status` IN ('ACTIVE','PENDING_PAYMENT','TRIAL') THEN `plan_key` ELSE NULL END) STORED,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_user_active_plan` (`user_id`, `active_plan_slot`),
                    KEY `idx_user_status` (`user_id`, `status`),
                    KEY `idx_device_active` (`device_id`, `status`),
                    KEY `idx_expires` (`expires_at`),
                    KEY `idx_status_expires` (`status`, `expires_at`),
                    KEY `idx_user_source` (`user_id`, `source`),
                    CONSTRAINT `fk_lic_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE CASCADE,
                    CONSTRAINT `fk_lic_device` FOREIGN KEY (`device_id`) REFERENCES `{$p}eligius_devices`(`id`) ON DELETE SET NULL
                ){$tail}";

            case 'eligius_payments':
                // Doc 02 §2.3. Payment processing itself is Phase 4; the table
                // exists from P1 per the Roadmap "Database Changes" list.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_payments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `license_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `gateway` VARCHAR(32) NOT NULL,
                    `gateway_ref` VARCHAR(128) NOT NULL,
                    `authority` VARCHAR(128) NULL DEFAULT NULL,
                    `amount` BIGINT UNSIGNED NOT NULL,
                    `currency` CHAR(3) NOT NULL DEFAULT 'IRT',
                    `plan_key` VARCHAR(32) NOT NULL,
                    `plan_duration_days` SMALLINT UNSIGNED NOT NULL,
                    `status` ENUM('INITIATED','VERIFIED','FAILED','REFUNDED','EXPIRED') NOT NULL DEFAULT 'INITIATED',
                    `gateway_response` JSON NULL,
                    `initiated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `verified_at` DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_gateway_ref` (`gateway_ref`),
                    KEY `idx_pay_user_status` (`user_id`, `status`),
                    KEY `idx_pay_license` (`license_id`),
                    KEY `idx_pay_initiated` (`initiated_at`),
                    CONSTRAINT `fk_pay_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE CASCADE,
                    CONSTRAINT `fk_pay_license` FOREIGN KEY (`license_id`) REFERENCES `{$p}eligius_licenses`(`id`) ON DELETE SET NULL
                ){$tail}";

            case 'eligius_license_events':
                // Doc 02 §2.4. Event types use the dotted notation used by all
                // code examples in the docs (payment.verified, license.expired,
                // device.bound, trial.granted, …).
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_license_events` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `license_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `event_type` VARCHAR(64) NOT NULL,
                    `old_status` VARCHAR(32) NULL DEFAULT NULL,
                    `new_status` VARCHAR(32) NULL DEFAULT NULL,
                    `details` JSON NULL,
                    `ip` VARBINARY(16) NULL DEFAULT NULL,
                    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_lev_license_time` (`license_id`, `created_at`),
                    KEY `idx_lev_user_time` (`user_id`, `created_at`),
                    KEY `idx_lev_event_type` (`event_type`),
                    KEY `idx_lev_created` (`created_at`),
                    CONSTRAINT `fk_lev_license` FOREIGN KEY (`license_id`) REFERENCES `{$p}eligius_licenses`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_lev_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE CASCADE,
                    CONSTRAINT `fk_lev_device` FOREIGN KEY (`device_id`) REFERENCES `{$p}eligius_devices`(`id`) ON DELETE SET NULL
                ){$tail}";

            case 'eligius_device_transfers':
                // Doc 02 §2.5.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_device_transfers` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `from_device_id` BIGINT UNSIGNED NOT NULL,
                    `to_device_id` BIGINT UNSIGNED NOT NULL,
                    `otp_code` CHAR(6) NULL DEFAULT NULL,
                    `status` ENUM('PENDING','VERIFIED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'PENDING',
                    `initiated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `completed_at` DATETIME NULL DEFAULT NULL,
                    `expires_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_tr_user_status` (`user_id`, `status`),
                    KEY `idx_tr_from` (`from_device_id`),
                    KEY `idx_tr_to` (`to_device_id`),
                    KEY `idx_tr_expires` (`expires_at`),
                    CONSTRAINT `fk_tr_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE CASCADE,
                    CONSTRAINT `fk_tr_from` FOREIGN KEY (`from_device_id`) REFERENCES `{$p}eligius_devices`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_tr_to` FOREIGN KEY (`to_device_id`) REFERENCES `{$p}eligius_devices`(`id`) ON DELETE CASCADE
                ){$tail}";

            case 'eligius_payment_idempotency':
                // Doc 02 §2.7.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_payment_idempotency` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `gateway_ref` VARCHAR(128) NOT NULL,
                    `gateway` VARCHAR(32) NOT NULL,
                    `status` VARCHAR(32) NOT NULL,
                    `request_payload` JSON NULL,
                    `response_payload` JSON NULL,
                    `processed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_idem_gateway_ref` (`gateway_ref`)
                ){$tail}";

            case 'eligius_audit_logs':
                // Doc 02 §2.6.
                return "CREATE TABLE IF NOT EXISTS `{$p}eligius_audit_logs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `license_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `actor_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `event_type` VARCHAR(64) NOT NULL,
                    `severity` ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
                    `details` JSON NULL,
                    `ip` VARBINARY(16) NULL DEFAULT NULL,
                    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_au_user_time` (`user_id`, `created_at`),
                    KEY `idx_au_license_time` (`license_id`, `created_at`),
                    KEY `idx_au_type_time` (`event_type`, `created_at`),
                    KEY `idx_au_severity_time` (`severity`, `created_at`),
                    CONSTRAINT `fk_au_user` FOREIGN KEY (`user_id`) REFERENCES `{$users}`(`ID`) ON DELETE SET NULL,
                    CONSTRAINT `fk_au_device` FOREIGN KEY (`device_id`) REFERENCES `{$p}eligius_devices`(`id`) ON DELETE SET NULL,
                    CONSTRAINT `fk_au_license` FOREIGN KEY (`license_id`) REFERENCES `{$p}eligius_licenses`(`id`) ON DELETE SET NULL
                ){$tail}";
        }

        return null;
    }

    /** Default plans (doc 01 §2.3 + doc 03 §4.1 catalog). */
    public static function seed_plans() {
        global $wpdb;

        $plans = [
            ['key' => 'trial',      'label' => 'Free Trial',  'days' => 30,  'price' => 0,        'discount' => 0,  'sort' => 0, 'trial' => 1],
            ['key' => 'monthly',    'label' => 'Monthly',     'days' => 30,  'price' => 150000,   'discount' => 0,  'sort' => 1, 'trial' => 0],
            ['key' => 'quarterly',  'label' => 'Quarterly',   'days' => 90,  'price' => 400000,   'discount' => 10, 'sort' => 2, 'trial' => 0],
            ['key' => 'semiannual', 'label' => 'Semi-annual', 'days' => 180, 'price' => 750000,   'discount' => 15, 'sort' => 3, 'trial' => 0],
            ['key' => 'annual',     'label' => 'Annual',      'days' => 365, 'price' => 1400000,  'discount' => 20, 'sort' => 4, 'trial' => 0],
        ];

        $table = $wpdb->prefix . 'eligius_plans';
        foreach ($plans as $plan) {
            // Upsert keeps plan definitions authoritative across upgrades.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE plan_key = %s",
                $plan['key']
            ));
            if ('' !== $wpdb->last_error) {
                continue;
            }
            if ($existing) {
                $wpdb->update($table, [
                    'label'            => $plan['label'],
                    'duration_days'    => $plan['days'],
                    'price_irt'        => $plan['price'],
                    'discount_percent' => $plan['discount'],
                    'is_trial'         => $plan['trial'],
                    'sort'             => $plan['sort'],
                    'is_active'        => 1,
                ], ['id' => (int) $existing]);
            } else {
                $wpdb->insert($table, [
                    'plan_key'         => $plan['key'],
                    'label'            => $plan['label'],
                    'duration_days'    => $plan['days'],
                    'price_irt'        => $plan['price'],
                    'discount_percent' => $plan['discount'],
                    'is_trial'         => $plan['trial'],
                    'sort'             => $plan['sort'],
                    'is_active'        => 1,
                ]);
            }
        }
    }

    /** Drop all plugin tables — uninstall path ONLY (doc 02 §1.1 no auto-delete). */
    public static function drop_tables() {
        global $wpdb;
        foreach (array_reverse(self::$tables) as $suffix) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $suffix . '`');
        }
    }
}
