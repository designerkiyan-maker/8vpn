# Eligius Subscription System — Database Design

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

This document describes the complete relational database design for the Eligius subscription system. All tables use the WordPress database prefix (`$wpdb->prefix`, typically `wp_`) and follow WordPress conventions.

### 1.1 Design Principles

1. **WordPress Integration** — Use `$wpdb->prefix`, leverage `wp_users` for accounts
2. **Relational Integrity** — Foreign keys with appropriate cascades
2. **Audit Trail** — Every financial/license event logged immutably
3. **Idempotency** — Payment deduplication via unique gateway references
4. **Performance** — Strategic indexes on high-cardinality query paths
5. **No Auto-Deletion** — Data never deleted automatically on deactivation/upgrade

### 1.2 Entity Relationship Diagram

```mermaid
erDiagram
    WP_USERS ||--o{ ELIGIUS_DEVICES : owns
    WP_USERS ||--o{ ELIGIUS_LICENSES : holds
    WP_USERS ||--o{ ELIGIUS_PAYMENTS : makes
    WP_USERS ||--o{ ELIGIUS_DEVICE_TRANSFERS : initiates
    WP_USERS ||--o{ ELIGIUS_AUDIT_LOGS : generates
    
    ELIGIUS_DEVICES ||--o{ ELIGIUS_LICENSES : binds
    ELIGIUS_DEVICES ||--o{ ELIGIUS_DEVICE_TRANSFERS : "from"
    ELIGIUS_DEVICES ||--o{ ELIGIUS_DEVICE_TRANSFERS : "to"
    
    ELIGIUS_LICENSES ||--o{ ELIGIUS_PAYMENTS : activated_by
    ELIGIUS_LICENSES ||--o{ ELIGIUS_LICENSE_EVENTS : logs
    ELIGIUS_LICENSES ||--o{ ELIGIUS_DEVICE_TRANSFERS : transfers
    
    ELIGIUS_PAYMENTS ||--o{ ELIGIUS_PAYMENT_IDEMPOTENCY : deduplicates
    
    ELIGIUS_DEVICE_TRANSFERS }|--|| ELIGIUS_DEVICES : "from_device"
    ELIGIUS_DEVICE_TRANSFERS }|--|| ELIGIUS_DEVICES : "to_device"
    
    WP_USERS {
        bigint ID PK
        varchar user_login
        varchar user_email
        varchar user_pass
        varchar display_name
        datetime user_registered
    }
    
    ELIGIUS_DEVICES {
        bigint id PK
        bigint user_id FK
        char device_hash UK
        text public_key
        varchar label
        boolean is_active
        datetime created_at
        datetime last_seen_at
    }
    
    ELIGIUS_LICENSES {
        bigint id PK
        bigint user_id FK
        bigint device_id FK NULL
        varchar plan_key
        varchar plan_label
        smallint duration_days
        datetime started_at
        datetime expires_at
        enum status
        varchar source
        bigint payment_id FK NULL
        bigint previous_license_id FK NULL
        datetime started_at
        datetime expires_at
        datetime created_at
        datetime updated_at
    }
    
    ELIGIUS_PAYMENTS {
        bigint id PK
        bigint user_id FK
        bigint license_id FK NULL
        varchar gateway
        varchar gateway_ref UK
        varchar authority
        bigint amount
        char currency
        varchar plan_key
        smallint plan_duration_days
        enum status
        json gateway_response
        datetime initiated_at
        datetime verified_at
    }
    
    ELIGIUS_LICENSE_EVENTS {
        bigint id PK
        bigint license_id FK
        bigint user_id FK
        bigint device_id FK NULL
        varchar event_type
        varchar old_status
        varchar new_status
        json details
        varbinary ip
        varchar user_agent
        datetime created_at
    }
    
    ELIGIUS_DEVICE_TRANSFERS {
        bigint id PK
        bigint user_id FK
        bigint from_device_id FK
        bigint to_device_id FK
        char otp_code
        enum status
        datetime initiated_at
        datetime completed_at
        datetime expires_at
    }
    
    ELIGIUS_PAYMENT_IDEMPOTENCY {
        bigint id PK
        varchar gateway_ref UK
        varchar gateway
        varchar status
        json request_payload
        json response_payload
        datetime processed_at
    }
    
    ELIGIUS_AUDIT_LOGS {
        bigint id PK
        bigint user_id FK NULL
        bigint device_id FK NULL
        bigint license_id FK NULL
        bigint actor_user_id FK NULL
        varchar event_type
        enum severity
        json details
        varbinary ip
        varchar user_agent
        datetime created_at
    }
    
    ELIGIUS_PAYMENT_IDEMPOTENCY {
        bigint id PK
        varchar gateway_ref UK
        varchar gateway
        varchar status
        json request_payload
        json response_payload
        datetime processed_at
    }
    
    ELIGIUS_DEVICE_TRANSFERS {
        bigint id PK
        bigint user_id FK
        bigint from_device_id FK
        bigint to_device_id FK
        char otp_code
        enum status
        datetime initiated_at
        datetime completed_at
        datetime expires_at
    }
```

---

## 2. Table Definitions

### 2.1 `wp_eligius_devices`

**Purpose:** Registered devices per user. One active device per user account.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | Primary key |
| `user_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `wp_users.ID` | WordPress user |
| `device_hash` | `CHAR(64)` | NOT NULL, UNIQUE | SHA-256(device_pubkey ‖ ANDROID_ID) |
| `public_key` | `TEXT` | NOT NULL | ECDSA P-256 public key (PEM) — device binding per CD-10 |
| `label` | `VARCHAR(64)` | DEFAULT '' | User-visible name |
| `is_active` | `TINYINT(1)` | NOT NULL DEFAULT 1 | Active status |
| `created_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | Registration time |
| `last_seen_at` | `DATETIME` | NULL | Last successful auth |

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY uniq_user_device (user_id, device_hash)` — One device per user
- `KEY idx_user_active (user_id, is_active)` — Active devices query
- `KEY idx_device_hash (device_hash)` — Fast lookup by hash

**Constraints:**
- `FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE`
- `device_hash` = SHA-256(Ed25519_pubkey ‖ ANDROID_ID) — Not reversible

---

### 2.2 `wp_eligius_licenses`

**Purpose:** Subscription licenses. One active license per user per plan type.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | Primary key |
| `user_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `wp_users.ID` | License holder |
| `device_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_devices.id` | Bound device (NULL = trial/unbound) |
| `plan_key` | `VARCHAR(32)` | NOT NULL | `monthly`, `quarterly`, `semiannual`, `annual`, `trial` |
| `plan_label` | `VARCHAR(64)` | NOT NULL | Display name |
| `duration_days` | `SMALLINT UNSIGNED` | NOT NULL | 30, 90, 180, 365, 30 |
| `started_at` | `DATETIME` | NOT NULL | Server timestamp |
| `expires_at` | `DATETIME` | NOT NULL | Server timestamp |
| `status` | `ENUM` | NOT NULL DEFAULT 'PENDING_PAYMENT' | `ACTIVE`, `EXPIRED`, `REVOKED`, `PENDING_PAYMENT`, `TRIAL` |
| `source` | `VARCHAR(32)` | NOT NULL DEFAULT 'payment' | `trial`, `payment`, `admin`, `renewal` |
| `payment_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_payments.id` | Activating payment |
| `previous_license_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_licenses.id` | Renewal chain |
| `created_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

**Indexes:**
- `PRIMARY KEY (id)`
- `KEY idx_user_status (user_id, status)` — Active license lookup
- `KEY idx_device_active (device_id, status)` — Device binding check
- `KEY idx_expires (expires_at)` — Expiry sweeps
- `KEY idx_status_expires (status, expires_at)` — Batch expiry processing
- `UNIQUE KEY uniq_user_active_plan (user_id, status, plan_key)` — One active per plan type

**Foreign Keys:**
- `FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE`
- `FOREIGN KEY (device_id) REFERENCES eligius_devices(id) ON DELETE SET NULL`
- `FOREIGN KEY (payment_id) REFERENCES eligius_payments(id) ON DELETE SET NULL`
- `FOREIGN KEY (previous_license_id) REFERENCES eligius_licenses(id) ON DELETE SET NULL`

---

### 2.3 `wp_eligius_payments`

**Purpose:** Payment records with idempotency guarantees.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | Primary key |
| `user_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `wp_users.ID` | Payer |
| `license_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_licenses.id` | Created license |
| `gateway` | `VARCHAR(32)` | NOT NULL | `zarinpal`, `idpay`, `zibal` |
| `gateway_ref` | `VARCHAR(128)` | NOT NULL, UNIQUE | Gateway authority/token |
| `authority` | `VARCHAR(128)` | NULL | Gateway transaction ID |
| `amount` | `BIGINT UNSIGNED` | NOT NULL | Amount in IRR |
| `currency` | `CHAR(3)` | NOT NULL DEFAULT 'IRT' | Currency code |
| `plan_key` | `VARCHAR(32)` | NOT NULL | Plan identifier |
| `plan_duration_days` | `SMALLINT UNSIGNED` | NOT NULL | 30/90/180/365 |
| `status` | `ENUM` | NOT NULL DEFAULT 'INITIATED' | `INITIATED`, `VERIFIED`, `FAILED`, `REFUNDED`, `EXPIRED` |
| `gateway_response` | `JSON` | NULL | Raw gateway response |
| `initiated_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `verified_at` | `DATETIME` | NULL | Verification time |

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY uniq_gateway_ref (gateway_ref)` — Idempotency key
- `KEY idx_user_status (user_id, status)` — User payment history
- `KEY idx_license (license_id)` — License payments
- `KEY idx_gateway_ref (gateway_ref)` — Callback lookup
- `KEY idx_initiated (initiated_at)` — Cleanup old pending

**Constraints:**
- `FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE`
- `FOREIGN KEY (license_id) REFERENCES eligius_licenses(id) ON DELETE SET NULL`
- `UNIQUE KEY uniq_gateway_ref (gateway_ref)` — Idempotency

---

### 2.4 `wp_eligius_license_events`

**Purpose:** Immutable audit trail for license lifecycle.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | Primary key |
| `license_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `eligius_licenses.id` | |
| `user_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `wp_users.ID` | |
| `device_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_devices.id` | |
| `event_type` | `VARCHAR(64)` | NOT NULL | `created`, `activated`, `renewed`, `expired`, `revoked`, `transferred`, `trial_granted`, `payment_verified`, `device_bound`, `device_unbound` |
| `old_status` | `VARCHAR(32)` | NULL | Previous status |
| `new_status` | `VARCHAR(32)` | NULL | New status |
| `details` | `JSON` | NULL | Extended context |
| `ip` | `VARBINARY(16)` | NULL | IPv4/IPv6 binary |
| `user_agent` | `VARCHAR(255)` | NULL | Client UA |
| `created_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |

**Indexes:**
- `PRIMARY KEY (id)`
- `KEY idx_license_time (license_id, created_at)`
- `KEY idx_user_time (user_id, created_at)`
- `KEY idx_event_type (event_type)`
- `KEY idx_created (created_at)`

---

### 2.5 `wp_eligius_device_transfers`

**Purpose:** Device transfer workflow with OTP verification.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | |
| `user_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `wp_users.ID` | |
| `from_device_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `eligius_devices.id` | Old device |
| `to_device_id` | `BIGINT UNSIGNED` | NOT NULL, FK → `eligius_devices.id` | New device |
| `otp_code` | `CHAR(6)` | NULL | Sent to user's mobile |
| `status` | `ENUM` | NOT NULL DEFAULT 'PENDING' | `PENDING`, `VERIFIED`, `EXPIRED`, `CANCELLED` |
| `initiated_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `completed_at` | `DATETIME` | NULL | |
| `expires_at` | `DATETIME` | NOT NULL | 10 min TTL |

**Indexes:**
- `PRIMARY KEY (id)`
- `KEY idx_user_status (user_id, status)`
- `KEY idx_from_device (from_device_id)`
- `KEY idx_to_device (to_device_id)`
- `KEY idx_expires (expires_at)`

**Constraints:**
- `FOREIGN KEY (from_device_id) REFERENCES eligius_devices(id) ON DELETE CASCADE`
- `FOREIGN KEY (to_device_id) REFERENCES eligius_devices(id) ON DELETE CASCADE`
- `FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE`

---

### 2.6 `wp_eligius_audit_logs`

**Purpose:** Comprehensive security/financial audit trail.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | |
| `user_id` | `BIGINT UNSIGNED` | NULL, FK → `wp_users.ID` | |
| `device_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_devices.id` | |
| `license_id` | `BIGINT UNSIGNED` | NULL, FK → `eligius_licenses.id` | |
| `actor_user_id` | `BIGINT UNSIGNED` | NULL, FK → `wp_users.ID` | Admin who acted |
| `event_type` | `VARCHAR(64)` | NOT NULL | `payment.verified`, `license.revoked`, `device.bound`, `auth.failed`, `admin.license.revoked`, `admin.user.suspend` |
| `severity` | `ENUM` | NOT NULL DEFAULT 'INFO' | `INFO`, `WARNING`, `CRITICAL` |
| `details` | `JSON` | NULL | Extended context |
| `ip` | `VARBINARY(16)` | NULL | IPv4/IPv6 |
| `user_agent` | `VARCHAR(255)` | NULL | |
| `created_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |

**Indexes:**
- `KEY idx_user_time (user_id, created_at)`
- `KEY idx_license_time (license_id, created_at)`
- `KEY idx_type_time (event_type, created_at)`
- `KEY idx_severity_time (severity, created_at)`

---

### 2.7 `wp_eligius_payment_idempotency`

**Purpose:** Gateway callback deduplication (separate from payments table).

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `BIGINT UNSIGNED` | PK, AI | |
| `gateway_ref` | `VARCHAR(128)` | NOT NULL, UNIQUE | Gateway authority/token |
| `gateway` | `VARCHAR(32)` | NOT NULL | `zarinpal`, `idpay`, `zibal` |
| `status` | `VARCHAR(32)` | NOT NULL | `VERIFIED`, `FAILED`, `DUPLICATE` |
| `request_payload` | `JSON` | NULL | Raw callback |
| `response_payload` | `JSON` | NULL | Verification response |
| `processed_at` | `DATETIME` | NOT NULL DEFAULT CURRENT_TIMESTAMP | |

---

### 2.8 One Active Device — Concurrency Control (CD-9)

**Confirmed policy:** each account can use the VPN on **exactly one active device at any time** — for the trial AND all paid plans. MariaDB has no partial unique indexes, so the "one active device per user" rule cannot be expressed as a plain UNIQUE constraint on `is_active`. It is therefore enforced **transactionally** with **ONE fixed lock point shared by ALL device-changing operations** of an account.

#### 2.8.1 The Single Lock Point

**THE lock = `SELECT ID FROM wp_users WHERE ID = %d FOR UPDATE` — the user's own `wp_users` row.**

Every device-changing operation of an account acquires this lock **first**, before touching any `eligius_*` row:

| Operation | Lock acquisition | What it serializes against |
|-----------|------------------|----------------------------|
| First device registration | user-row lock | Another concurrent first registration |
| New device registration (after unbind) | user-row lock | Another registration or a transfer |
| Device transfer (OTP-verified) | user-row lock | Another transfer or a bind |
| Binding change (admin release/re-bind) | user-row lock | Any other device op |

**Why the `wp_users` row (not a device row):**

1. A device row to lock does not always exist (first registration) — the user row **always exists** and is the stable per-account anchor.
2. A transfer touches **two** device rows — a device-row lock would not serialize two transfers against each other; the user-row lock serializes everything of the account.
3. `bind` vs `transfer` races are eliminated by construction: both acquire the **same** lock before any eligibility check, so their check-then-act sequences cannot interleave.
4. The `wp_users` row is short, indexed by PK, and never deleted while the account lives — minimal deadlock surface (single-row, fixed acquisition order).

#### 2.8.2 InnoDB & Single-Connection Requirements

| Requirement | Detail | Enforcement |
|-------------|--------|-------------|
| **InnoDB everywhere** | Row locking (`FOR UPDATE`) and transactions exist **only in InnoDB**. MyISAM silently ignores both → the whole control collapses | On plugin activation: check `ENGINE` of `wp_users` and every `eligius_*` table; if any table is not InnoDB → `ALTER TABLE … ENGINE=InnoDB` (or refuse activation with an explicit admin error). Every `eligius_*` CREATE TABLE includes `ENGINE=InnoDB` explicitly |
| **One DB connection** | All statements of a transaction must run on the **same connection**, otherwise `FOR UPDATE` locks and uncommitted writes are invisible to each other | WordPress `$wpdb` uses a single connection per request — all statements go through the same global `$wpdb`; **no** `new mysqli/PDO`, no persistent connections (`wp_using_ext_object_cache` is irrelevant, but `mysqli_poll`-style parallel clients are forbidden inside the transaction) |
| **No implicit commits** | DDL, and any `wp_ajax_` handler that itself calls `START TRANSACTION`, must not run inside the open transaction | Transaction scope limited to the data operations listed below |

#### 2.8.3 Explicit Database Error Checking

WordPress `$wpdb` **does not throw exceptions** — a failed `INSERT`/`UPDATE`/`query()` silently returns `false`/`0` and records the message in `$wpdb->last_error`. Relying on the surrounding `catch (Exception …)` is therefore **insufficient**: a silent failure (e.g., lock-wait timeout, deadlock victim, duplicate key) would COMMIT a half-applied binding. Every statement must be checked explicitly, and any failure → immediate `ROLLBACK` + typed error:

```php
function eligius_sub_bind_device( $user_id, $device_hash, $public_key_pem, $label ) {
    global $wpdb;

    // ---- Preconditions (no transaction needed) -----------------------------
    $device_hash = strtolower( preg_replace( '/[^0-9a-f]/', '', (string) $device_hash ) );
    if ( strlen( $device_hash ) !== 64 ) {
        return new WP_Error( 'eligius_bad_fingerprint', 'Invalid device fingerprint.', [ 'status' => 400 ] );
    }

    // ---- Single connection, single transaction -----------------------------
    $wpdb->query( 'START TRANSACTION' );

    // Step 1: THE LOCK — the account's own wp_users row (same point for
    // register / transfer / bind-change; serializes bind-vs-transfer too).
    $locked = $wpdb->get_row( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $user_id
    ) );
    if ( null === $locked || '' !== $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'eligius_db_lock_failed', 'Could not lock the account row.', [ 'status' => 500 ] );
    }

    // Step 2: enforce one-active-device under the lock.
    $active_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}eligius_devices
          WHERE user_id = %d AND is_active = 1
          LIMIT 1",
        $user_id
    ) );
    if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'eligius_db_error', $wpdb->last_error, [ 'status' => 500 ] ); }

    if ( $active_id > 0 ) {
        // Never auto-replace: the OTP transfer workflow is the only path.
        $wpdb->query( 'COMMIT' );  // COMMIT of a read-only tx = lock release
        return new WP_Error( 'eligius_device_limit_reached',
            'This account already has an active device. Transfer it first (OTP).',
            [ 'status' => 409, 'active_device_id' => $active_id ] );
    }

    // Step 3: insert the new device (explicit result check).
    $ok = $wpdb->insert( "{$wpdb->prefix}eligius_devices", [
        'user_id'    => $user_id,
        'device_hash'=> $device_hash,
        'public_key' => $public_key_pem,
        'label'      => sanitize_text_field( $label ),
        'is_active'  => 1,
    ] );
    if ( false === $ok || '' !== $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'eligius_db_insert_failed', $wpdb->last_error ?: 'Device insert failed.', [ 'status' => 500 ] );
    }
    $device_id = (int) $wpdb->insert_id;

    // Step 4: bind the account's ACTIVE/TRIAL license to this device.
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}eligius_licenses
          SET device_id = %d, updated_at = CURRENT_TIMESTAMP
          WHERE user_id = %d AND status IN ('ACTIVE','TRIAL')",
        $device_id, $user_id
    ) );
    if ( false === $updated || '' !== $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'eligius_db_bind_failed', $wpdb->last_error ?: 'License bind failed.', [ 'status' => 500 ] );
    }

    // Step 5: audit row (also checked — an unaudited bind must not commit).
    $ok = $wpdb->insert( "{$wpdb->prefix}eligius_license_events", [
        'license_id' => 0, 'user_id' => $user_id, 'device_id' => $device_id,
        'event_type' => 'device.bound', 'severity' => 'INFO',
        'ip' => inet_pton( $_SERVER['REMOTE_ADDR'] ?? '' ) ?: null,
    ] );
    if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'eligius_db_audit_failed', '', [ 'status' => 500 ] ); }

    $wpdb->query( 'COMMIT' );
    return [ 'device_id' => $device_id ];
}
```

> **Note (license_id = 0 in the audit row):** the audit insert runs *before* the license row is re-read for accuracy of the example; the repository implementation MUST re-read the bound license inside the transaction and store its real ID (see 05 §3.1 activation flow — same transaction discipline).

The **transfer** transaction (§3.2) acquires the **same user-row lock as its first statement** (replacing its current `FOR UPDATE` on the transfer row, which must become `FOR UPDATE` **after** the user lock) and applies the identical explicit-check discipline to its four statements.

#### 2.8.4 Rules Enforced by This Pattern

| Rule | Mechanism |
|------|-----------|
| One active device per user | User-row `FOR UPDATE` serializes **every** device-changing operation of the account |
| Applies to trial AND paid | License update targets `status IN ('ACTIVE','TRIAL')` |
| Old device deauthorized after transfer | Transfer transaction sets old `is_active=0` and moves `license.device_id` in the same commit |
| Two devices never share a license | `license.device_id` is a single column — binding moves atomically, never duplicates |
| bind vs transfer races | Both acquire the same user-row lock before any eligibility read — check-then-act sequences cannot interleave |
| No half-applied state | Every statement's result + `$wpdb->last_error` checked; any failure → ROLLBACK → typed `WP_Error` |

#### 2.8.5 Acceptance Criteria

- [ ] All `eligius_*` tables and `wp_users` verified InnoDB on activation (conversion or refusal)
- [ ] Concurrent **bind + bind** for one account → exactly one active device, loser gets `eligius_device_limit_reached` (409)
- [ ] Concurrent **bind + transfer** for one account → fully serialized by the user-row lock; never two active devices; transfer never lands on a stale binding
- [ ] Concurrent **transfer + transfer** → one commits, the other either sees `PENDING`→blocked or rejects on OTP/state
- [ ] A forced DB failure mid-transaction (e.g., dropped column in a test) → full ROLLBACK, no partial rows, typed error surfaced to the API
- [ ] `$wpdb->last_error` checked after **every** statement inside the transaction

## 3. Transaction Consistency

### 3.1 Payment Activation Flow (ACID)

```php
function process_payment_verification($gateway_ref, $gateway, $user_id, $plan_key) {
    global $wpdb;
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // 1. Check idempotency (SELECT FOR UPDATE)
        $idempotency = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}eligius_payment_idempotency 
             WHERE gateway_ref = %s FOR UPDATE",
            $gateway_ref
        ));
        
        if ($idempotency) {
            if ($idempotency->status === 'VERIFIED') {
                $wpdb->query('COMMIT');
                return ['duplicate' => true];
            }
            // Update existing record
            $wpdb->update(
                "{$wpdb->prefix}eligius_payment_idempotency",
                ['status' => 'VERIFIED', 'response_payload' => $response],
                ['gateway_ref' => $gateway_ref]
            );
        } else {
            // Insert new idempotency record
            $wpdb->insert(
                "{$wpdb->prefix}eligius_payment_idempotency",
                ['gateway_ref' => $gateway_ref, 'gateway' => $gateway, 'status' => 'VERIFIED']
            );
        }
        
        // 2. Insert payment record
        $wpdb->insert(
            "{$wpdb->prefix}eligius_payments",
            [
                'user_id' => $user_id,
                'gateway' => $gateway,
                'gateway_ref' => $gateway_ref,
                'amount' => $amount,
                'currency' => 'IRT',
                'plan_key' => $plan_key,
                'plan_duration_days' => $duration,
                'status' => 'VERIFIED',
                'gateway_response' => $response,
                'verified_at' => current_time('mysql')
            ]
        );
        $payment_id = $wpdb->insert_id;
        
        // 3. Create/renew license
        $license = create_or_renew_license($user_id, $plan_key, $duration, $payment_id);
        
        // 4. Audit log
        log_audit_event([
            'user_id' => $user_id,
            'license_id' => $license->id,
            'event_type' => 'payment.verified',
            'severity' => 'INFO',
            'details' => ['payment_id' => $payment_id, 'gateway' => $gateway],
            'ip' => inet_pton($_SERVER['REMOTE_ADDR']),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $wpdb->query('COMMIT');
        return ['success' => true, 'license' => $license];
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}
```

### 3.2 Device Transfer Transaction

```php
function process_device_transfer($user_id, $from_device_id, $to_device_id, $otp_code) {
    global $wpdb;
    
    // STEP 0: THE SHARED LOCK — same wp_users row used by bind_device (§2.8.3).
    // Acquiring this FIRST serializes transfer-vs-bind and transfer-vs-transfer.
    $wpdb->query('START TRANSACTION');
    
    try {
        $locked = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
            $user_id
        ));
        if (null === $locked || '' !== $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            throw new Exception('Could not lock the account row.');
        }
        
        // STEP 1: Verify OTP (now protected by the user-row lock above)
        $transfer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}eligius_device_transfers 
             WHERE user_id = %d AND from_device_id = %d AND to_device_id = %d 
             AND status = 'PENDING' AND expires_at > NOW()",
            $user_id, $from_device_id, $to_device_id
        ));
        
        if (!$transfer || !hash_equals($transfer->otp_code, $otp_code)) {
            throw new Exception('Invalid OTP');
        }
        
        // Update transfer
        $wpdb->update(
            "{$wpdb->prefix}eligius_device_transfers",
            ['status' => 'VERIFIED', 'completed_at' => current_time('mysql')],
            ['id' => $transfer->id]
        );
        
        // Swap device bindings (old device is deauthorized immediately)
        $wpdb->update(
            "{$wpdb->prefix}eligius_devices",
            ['is_active' => 0],
            ['id' => $from_device_id]
        );
        $wpdb->update(
            "{$wpdb->prefix}eligius_devices",
            ['is_active' => 1],
            ['id' => $to_device_id]
        );
        
        // Update license binding
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}eligius_licenses 
             SET device_id = %d, updated_at = CURRENT_TIMESTAMP 
             WHERE device_id = %d AND user_id = %d",
            $to_device_id, $from_device_id, $user_id
        ));
        
        // Audit
        log_audit_event([
            'user_id' => $user_id,
            'device_id' => $to_device_id,
            'event_type' => 'device.transferred',
            'severity' => 'INFO',
            'details' => ['from' => $from_device_id, 'to' => $to_device_id]
        ]);
        
        $wpdb->query('COMMIT');
        return ['success' => true];
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}
```

**Concurrency note:** the user-row lock (STEP 0) is the same lock used by `bind_device` (§2.8.3). This guarantees `bind` vs `transfer` and `transfer` vs `transfer` are fully serialized — two parallel device-changing operations for the same account can never interleave their check-then-act sequences. The transfer-row `FOR UPDATE` was removed because the user-row lock already provides the serialization; locking the transfer row separately would create an unnecessary second lock and a potential deadlock surface.

---

## 4. Data Retention & Cleanup

| Table | Retention Policy | Cleanup Job |
|-------|------------------|-------------|
| `eligius_devices` | Indefinite (user data) | Manual only |
| `eligius_licenses` | Indefinite + 1 year after expiry | Annual archive |
| `eligius_payments` | 7 years (tax compliance) | Never auto-delete |
| `eligius_license_events` | 3 years | Monthly partition/archive |
| `eligius_device_transfers` | 1 year after completion | Monthly cleanup |
| `eligius_payment_idempotency` | 90 days | Daily cleanup |
| `eligius_audit_logs` | 5 years | Quarterly archive |
| `eligius_payment_idempotency` | 90 days | Daily cleanup |

**Cleanup Cron (weekly):**
```php
function eligius_sub_cleanup_old_records() {
    global $wpdb;
    $wpdb->query("
        DELETE FROM {$wpdb->prefix}eligius_payment_idempotency 
        WHERE processed_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $wpdb->query("
        DELETE FROM {$wpdb->prefix}eligius_device_transfers 
        WHERE status IN ('EXPIRED','CANCELLED') 
        AND completed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
    ");
    // Archive old events to compressed table if needed
}
add_action('eligius_sub_weekly_audit_cleanup', 'eligius_sub_cleanup_old_records');
```

---

## 5. Migration Strategy

### 5.1 Initial Migration (v1.0.0)
```php
// Create all tables with proper indexes
// Insert default plans
// Set default options
// Register cron jobs
// Flush rewrite rules
```

### 5.2 Future Upgrades
```php
function eligius_sub_maybe_upgrade() {
    $current = get_option('eligius_sub_db_version', '0');
    if (version_compare($current, ELIGIUS_SUB_DB_VERSION, '<')) {
        $db = new Eligius_Sub_Database();
        $db->upgrade($current);
    }
}
add_action('admin_init', 'eligius_sub_maybe_upgrade');
```

---

## 6. Cross-Reference: Existing vs New Tables

| Existing (usermeta) | New Table | Migration |
|---------------------|-----------|-----------|
| `eligius_total_rx` | — | Deprecated (usage stays in usermeta) |
| `eligius_total_tx` | — | Deprecated |
| `eligius_total_duration` | — | Deprecated |
| `eligius_sessions` | — | Deprecated |
| `eligius_mobile_verified` | `eligius_devices.is_active` + `eligius_licenses` | New device binding replaces |
| `national_code` | — | Stays in usermeta |
| `mobile` / `billing_phone` | `eligius_devices.device_hash` | New canonical device identity |
| `registered_via` | `eligius_devices.registered_via` | Migrated |

---

## 7. Verification Checklist

- [ ] All tables use `$wpdb->prefix`
- [ ] All foreign keys reference existing tables
- [ ] Unique constraints prevent duplicate active devices
- [ ] One-active-device rule enforced transactionally (`SELECT … FOR UPDATE` — §2.8) so concurrent registrations/ transfers cannot produce two active devices
- [ ] Device transfer deactivates the old device and moves `license.device_id` in a single commit
- [ ] Unique constraints prevent multiple free trials per account (`user_id`)
- [ ] Payment idempotency via unique `gateway_ref`
- [ ] License status transitions audited
- [ ] Device transfer requires OTP verification
- [ ] Trial limited to one per account (`user_id` — account-keyed per CD-6); `device_id` stored as informational binding, transferable via OTP
- [ ] MariaDB transactions for payment activation
- [ ] Idempotency table prevents duplicate payment processing
- [ ] Audit logs cover all financial/license events
- [ ] Cleanup jobs don't delete user data
- [ ] Migration path from existing usermeta data

---

*End of Database Design Document*