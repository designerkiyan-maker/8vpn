# Eligius Subscription System — API Specification

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

**Base URL:** `https://wp.mrkiyan.ir/wp-json/eligius/v1/`  
**Content-Type:** `application/json`  
**Authentication:** JWT Bearer token (via `Authorization: Bearer <token>`)  
**Rate Limiting:** Per-IP, per-user, per-endpoint (see § Rate Limits)

### 1.1 Response Format

All responses follow this envelope:

```json
{
  "success": true,
  "data": { ... },
  "meta": { "timestamp": "2026-11-10T12:00:00Z" }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable message",
    "details": { "field": "validation error" }
  }
}
```

### 1.2 Authentication Types

| Type | Header | Use Case |
|------|--------|----------|
| **Public** | None | Rate-limited, no auth |
| **User JWT** | `Authorization: Bearer <jwt>` | User-scoped endpoints |
| **Device JWT** | `Authorization: Bearer <jwt>` + `X-Device-Hash` | Device-bound endpoints |
| **Admin** | `Authorization: Bearer <jwt>` + `current_user_can('manage_options')` | Admin endpoints |
| **Gateway Callback** | IP whitelist + HMAC signature | Payment callbacks |

### 1.3 Standard Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| `VALIDATION_ERROR` | 400 | Request body validation failed |
| `UNAUTHORIZED` | 401 | Missing/invalid JWT |
| `FORBIDDEN` | 403 | Insufficient capabilities |
| `NOT_FOUND` | 404 | Resource not found |
| `CONFLICT` | 409 | Duplicate resource (device, trial, payment) |
| `DEVICE_LIMIT_REACHED` | 409 | One-active-device policy: another device is already active for this account (transfer via OTP required) |
| `UNPROCESSABLE_ENTITY` | 422 | Business rule violation |
| `TOO_MANY_REQUESTS` | 429 | Rate limit exceeded |
| `INTERNAL_ERROR` | 500 | Server error |
| `GATEWAY_UNAVAILABLE` | 503 | Payment gateway unreachable |

---

## 2. Authentication Endpoints

### 2.1 OTP Initiation (Registration)

**Endpoint:** `POST /register/otp-start`

**Auth:** Public (rate-limited: 5/hour/IP)

**Request:**
```json
{
  "mobile": "09123456789",
  "email": "user@example.com",
  "password": "SecurePass123",
  "first_name": "Ali",
  "last_name": "Rezaei",
  "national_code": "1234567890",
  "hash": "fUIdyUNivXt"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "sent": true,
    "expires_in": 600,
    "username": "09123456789"
  }
}
```

**Errors:**
- `VALIDATION_ERROR` (400) — Invalid mobile, email, national code, weak password
- `CONFLICT` (409) — Mobile/email/national_code already registered
- `TOO_MANY_REQUESTS` (429) — Rate limit exceeded

> **Note (TD-10):** `national_code` is currently required by the **live** endpoint (`server/eligius-otp.php`). Per the owner's no-unnecessary-identity-data decision, this field becomes **optional/removed** during implementation (a code change to the live endpoint — see 09-FINAL-DESIGN-REPORT.md). The Android client must not depend on it.

---

### 2.2 OTP Verification (Registration)

**Endpoint:** `POST /register/otp-verify`

**Auth:** Public (rate-limited: 5/hour/IP)

**Request:**
```json
{
  "mobile": "09123456789",
  "code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "verified": true,
    "username": "09123456789"
  }
}
```

**Errors:**
- `VALIDATION_ERROR` (400) — Invalid code format
- `UNPROCESSABLE_ENTITY` (422) — Code expired, max tries exceeded, wrong code

---

### 2.3 OTP Initiation (Forgot Password)

**Endpoint:** `POST /register/forgot-start`

**Auth:** Public (rate-limited: 5/hour/IP)

**Request:**
```json
{
  "mobile": "09123456789",
  "hash": "fUIdyUNivXt"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "sent": true,
    "expires_in": 600,
    "username": "09123456789"
  }
}
```

**Errors:**
- `NOT_FOUND` (404) — No verified account with this mobile
- `TOO_MANY_REQUESTS` (429)

---

### 2.4 OTP Verification (Forgot Password)

**Endpoint:** `POST /register/forgot-verify`

**Auth:** Public (rate-limited: 5/hour/IP)

**Request:**
```json
{
  "mobile": "09123456789",
  "code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "verified": true,
    "reset_token": "a1b2c3d4e5f6...",
    "username": "09123456789"
  }
}
```

---

### 2.5 Password Reset

**Endpoint:** `POST /register/forgot-reset`

**Auth:** Public (rate-limited: 5/hour/IP)

**Request:**
```json
{
  "reset_token": "a1b2c3d4e5f6...",
  "password": "NewSecurePass123"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "success": true,
    "username": "09123456789"
  }
}
```

**Errors:**
- `UNPROCESSABLE_ENTITY` (422) — Token expired, invalid, weak password

---

### 2.6 JWT Login

**Endpoint:** `POST /auth/login`

**Auth:** Public

**Request:**
```json
{
  "username": "09123456789",
  "password": "SecurePass123"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "id": 123,
      "username": "09123456789",
      "email": "user@example.com",
      "name": "Ali Rezaei",
      "role": "subscriber"
    },
    "expires_in": 1209600
  }
}
```

---

### 2.7 Session Refresh

**Endpoint:** `POST /auth/refresh`

**Auth:** User JWT

**Request:** `{}`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 1209600
  }
}
```

---

### 2.7 OTP Login (Optional)

**Endpoint:** `POST /auth/otp-login-start`

**Auth:** Public

**Request:**
```json
{
  "mobile": "09123456789",
  "hash": "fUIdyUNivXt"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": { "sent": true, "expires_in": 600 }
}
```

**Endpoint:** `POST /auth/otp-login-verify`

**Auth:** Public

**Request:**
```json
{
  "mobile": "09123456789",
  "code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": { ... }
  }
}
```

---

## 2. Device Endpoints

### 3.1 Register Device

**Endpoint:** `POST /devices/register`

**Auth:** User JWT + `X-Device-Hash` header

**Headers:**
```
Authorization: Bearer <jwt>
X-Device-Hash: a1b2c3d4e5f6... (64-char SHA-256)
Content-Type: application/json
```

**Request:**
```json
{
  "public_key": "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyE...\n-----END PUBLIC KEY-----",
  "label": "My Phone",
  "fingerprint": "a1b2c3d4e5f6... (SHA-256 of pubkey || ANDROID_ID)"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "device_id": 42,
    "device_hash": "a1b2c3d4e5f6...",
    "label": "My Phone",
    "created_at": "2026-11-10T12:00:00Z"
  }
}
```

**Errors:**
- `DEVICE_LIMIT_REACHED` (409) — Another device is already **active** for this account (one-active-device policy, CD-9). The response includes `active_device_id`; the user must either deactivate it or run the **OTP transfer workflow** (`/devices/transfer/start` → `/verify`). Server-side concurrency control (`SELECT … FOR UPDATE`, 02 §2.8) guarantees two devices can never bind simultaneously, even with parallel requests.
- `CONFLICT` (409) — This exact device is already registered
- `UNAUTHORIZED` (401) — Invalid JWT

---

### 3.2 Device Challenge

**Endpoint:** `POST /devices/challenge`

**Auth:** User JWT + `X-Device-Hash`

**Request:**
```json
{
  "device_hash": "a1b2c3d4e5f6..."
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "challenge": "dGhpcyBpcyBhIHJhbmRvbSBjaGFsbGVuZ2U=",
    "expires_in": 60
  }
}
```

**Note:** Client must sign challenge with device private key and call `/devices/verify`.

---

### 3.3 Device Verification

**Endpoint:** `POST /devices/verify`

**Auth:** User JWT + `X-Device-Hash`

**Request:**
```json
{
  "device_hash": "a1b2c3d4e5f6...",
  "challenge": "dGhpcyBpcyBhIHJhbmRvbSBjaGFsbGVuZ2U=",
  "signature": "MEUCIQD..."
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "verified": true,
    "device_id": 42,
    "is_active": true
  }
}
```

**Errors:**
- `UNPROCESSABLE_ENTITY` (422) — Invalid signature, challenge expired

---

### 3.4 Device Status

**Endpoint:** `GET /devices`

**Auth:** User JWT

**Response (200):**
```json
{
  "success": true,
  "data": {
    "devices": [
      {
        "device_id": 42,
        "device_hash": "a1b2c3d4e5f6...",
        "label": "My Phone",
        "is_active": true,
        "created_at": "2026-11-10T12:00:00Z",
        "last_seen_at": "2026-11-10T11:30:00Z"
      }
    ],
    "active_device_id": 42
  }
}
```

---

### 3.5 Device Transfer

**Endpoint:** `POST /devices/transfer/start`

**Auth:** User JWT + `X-Device-Hash`

**Request:**
```json
{
  "to_device_hash": "b2c3d4e5f6a7..."
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "transfer_id": 15,
    "otp_sent": true,
    "expires_in": 600
  }
}
```

**Errors:**
- `CONFLICT` (409) — Target device already bound, or transfer pending

---

**Endpoint:** `POST /devices/transfer/verify`

**Auth:** User JWT

**Request:**
```json
{
  "transfer_id": 15,
  "otp_code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "verified": true,
    "new_active_device_id": 55
  }
}
```

---

### 3.6 Deactivate Device

**Endpoint:** `POST /devices/deactivate`

**Auth:** User JWT + `X-Device-Hash`

**Request:**
```json
{
  "device_hash": "a1b2c3d4e5f6..."
}
```

**Response (200):**
```json
{
  "success": true,
  "data": { "deactivated": true }
}
```

---

## 4. Plan Endpoints

### 4.1 List Plans

**Endpoint:** `GET /plans`

**Auth:** Public

**Response (200):**
```json
{
  "success": true,
  "data": {
    "plans": [
      {
        "key": "trial",
        "label": "Free Trial",
        "duration_days": 30,
        "price_irt": 0,
        "features": ["VPN Access", "All Servers", "Split Tunneling"],
        "is_trial": true
      },
      {
        "key": "monthly",
        "label": "Monthly",
        "duration_days": 30,
        "price_irt": 150000,
        "features": ["VPN Access", "All Servers", "Split Tunneling", "Priority Support"],
        "is_trial": false
      },
      {
        "key": "quarterly",
        "label": "Quarterly",
        "duration_days": 90,
        "price_irt": 400000,
        "discount_percent": 10,
        "features": ["VPN Access", "All Servers", "Split Tunneling", "Priority Support"],
        "is_trial": false
      },
      {
        "key": "semiannual",
        "label": "Semi-annual",
        "duration_days": 180,
        "price_irt": 750000,
        "discount_percent": 15,
        "features": ["VPN Access", "All Servers", "Split Tunneling", "Priority Support"],
        "is_trial": false
      },
      {
        "key": "annual",
        "label": "Annual",
        "duration_days": 365,
        "price_irt": 1400000,
        "discount_percent": 20,
        "features": ["VPN Access", "All Servers", "Split Tunneling", "Priority Support"],
        "is_trial": false
      }
    ]
  }
}
```

---

### 4.2 Plan Details

**Endpoint:** `GET /plans/{plan_key}`

**Auth:** Public

**Response (200):**
```json
{
  "success": true,
  "data": {
    "key": "annual",
    "label": "Annual",
    "duration_days": 365,
    "price_irt": 1400000,
    "discount_percent": 20,
    "features": [...],
    "is_trial": false
  }
}
```

---

## 5. Subscription Endpoints

### 5.1 Current Subscription

**Endpoint:** `GET /subscriptions/current`

**Auth:** User JWT

**Response (200):**
```json
{
  "success": true,
  "data": {
    "subscription": {
      "license_id": 123,
      "plan_key": "annual",
      "plan_label": "Annual",
      "status": "ACTIVE",
      "started_at": "2026-11-10T12:00:00Z",
      "expires_at": "2027-11-10T12:00:00Z",
      "days_remaining": 365,
      "auto_renew": false,
      "device_bound": true,
      "device_id": 42,
      "offline_entitlement": {
        "blob": "eyJhbGciOiJFZDI1NTE5I...",
        "expires_at": "2026-11-13T12:00:00Z"
      }
    }
  }
}
```

**Response when no active subscription (404):**
```json
{
  "success": false,
  "error": {
    "code": "NOT_FOUND",
    "message": "No active subscription"
  }
}
```

---

### 5.2 Subscription History

**Endpoint:** `GET /subscriptions/history`

**Auth:** User JWT

**Query Params:** `page=1&per_page=20`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "subscriptions": [
      {
        "license_id": 120,
        "plan_key": "monthly",
        "plan_label": "Monthly",
        "status": "EXPIRED",
        "started_at": "2026-10-01T12:00:00Z",
        "expired_at": "2026-11-01T12:00:00Z",
        "source": "payment",
        "payment_id": 45
      },
      {
        "license_id": 115,
        "plan_key": "trial",
        "status": "EXPIRED",
        "started_at": "2026-09-15T12:00:00Z",
        "expired_at": "2026-10-15T12:00:00Z",
        "source": "trial"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 2
    }
  }
}
```

---

### 5.3 Entitlement Validation

**Endpoint:** `GET /subscriptions/validate`

**Auth:** User JWT + `X-Device-Hash`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "license_id": 123,
    "plan_key": "annual",
    "expires_at": "2027-11-10T12:00:00Z",
    "offline_entitlement": {
      "blob": "eyJhbGciOiJFZDI1NTE5I...",
      "expires_at": "2026-11-13T12:00:00Z"
    }
  }
}
```

**Response when invalid (403):**
```json
{
  "success": false,
  "error": {
    "code": "SUBSCRIPTION_EXPIRED",
    "message": "Subscription expired on 2026-11-01",
    "details": { "expired_at": "2026-11-01T12:00:00Z" }
  }
}
```

---

### 5.4 Offline Entitlement

**Endpoint:** `GET /subscriptions/offline-entitlement`

**Auth:** User JWT + `X-Device-Hash`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "blob": "eyJhbGciOiJFZDI1NTE5IiwidHlwIjoiSldUIn0...",
    "expires_at": "2026-11-13T12:00:00Z",
    "public_key_id": "v2"
  }
}
```

**Note:** Blob is a signed JWT containing `{ license_id, user_id, device_hash, expires_at, permissions }` signed with server's Ed25519 private key. Max validity 72 hours.

---

## 6. Payment Endpoints

### 6.1 Create Payment

**Endpoint:** `POST /payments/create`

**Auth:** User JWT

**Request:**
```json
{
  "plan_key": "annual",
  "gateway": "zarinpal"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "payment_id": 789,
    "gateway": "zarinpal",
    "authority": "A00000000000000000000000000123456",
    "redirect_url": "https://www.zarinpal.com/pg/StartPay/A00000000000000000000000000123456",
    "amount_irt": 1400000,
    "expires_in": 1800
  }
}
```

**Errors:**
- `CONFLICT` (409) — Active subscription exists
- `UNPROCESSABLE_ENTITY` (422) — Invalid plan, gateway unavailable

---

### 6.2 Payment Status

**Endpoint:** `GET /payments/{payment_id}`

**Auth:** User JWT

**Response (200):**
```json
{
  "success": true,
  "data": {
    "payment_id": 789,
    "status": "VERIFIED",
    "gateway": "zarinpal",
    "amount_irt": 1400000,
    "plan_key": "annual",
    "initiated_at": "2026-11-10T12:00:00Z",
    "verified_at": "2026-11-10T12:05:00Z"
  }
}
```

---

### 6.3 Payment History

**Endpoint:** `GET /payments/history`

**Auth:** User JWT

**Query:** `page=1&per_page=20`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "payments": [
      {
        "payment_id": 789,
        "gateway": "zarinpal",
        "amount_irt": 1400000,
        "plan_key": "annual",
        "status": "VERIFIED",
        "initiated_at": "2026-11-10T12:00:00Z",
        "verified_at": "2026-11-10T12:05:00Z"
      }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 1 }
  }
}
```

---

### 6.4 Gateway Callback (Internal)

**Endpoint:** `GET /payments/callback/{gateway}`

**Auth:** None (Gateway callback)

**Example (ZarinPal):** `GET /payments/callback/zarinpal?Authority=A000...&Status=OK`

**Response:** Redirect to app deep link or web page

**Server Logic:**
1. Verify `Authority` exists in `eligius_payments`
2. Call gateway verification API (S2S)
3. If verified → update payment status, activate license
4. Record in `eligius_payment_idempotency`
5. Log audit event
5. Redirect to `eligius://payment/result?status=success&payment_id=789`

---

## 7. License Endpoints

### 7.1 License Status

**Endpoint:** `GET /licenses/status`

**Auth:** User JWT + `X-Device-Hash`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "license_id": 123,
    "plan_key": "annual",
    "status": "ACTIVE",
    "started_at": "2026-11-10T12:00:00Z",
    "expires_at": "2027-11-10T12:00:00Z",
    "device_id": 42,
    "device_bound": true,
    "offline_entitlement": {
      "blob": "eyJhbGciOiJFZDI1NTE5I...",
      "expires_at": "2026-11-13T12:00:00Z"
    }
  }
}
```

---

### 7.2 License Renewal

**Endpoint:** `POST /licenses/renew`

**Auth:** User JWT

**Request:**
```json
{
  "plan_key": "annual",
  "gateway": "zarinpal"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "payment_id": 790,
    "redirect_url": "https://www.zarinpal.com/pg/StartPay/...",
    "extends_from": "2027-11-10T12:00:00Z",
    "new_expires_at": "2028-11-10T12:00:00Z"
  }
}
```

**Note:** Renewal extends from current `expires_at` if active, from `now()` if expired.

---

### 7.3 License Revocation (Admin)

**Endpoint:** `POST /admin/licenses/{license_id}/revoke`

**Auth:** Admin JWT (`manage_options` capability)

**Request:**
```json
{
  "reason": "Terms violation"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "revoked": true,
    "license_id": 123,
    "revoked_at": "2026-11-10T12:00:00Z"
  }
}
```

---

## 8. Admin Endpoints

### 8.1 User Management

**Endpoint:** `GET /admin/users`

**Auth:** Admin JWT

**Query:** `page=1&per_page=50&search=ali&status=active`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "id": 123,
        "username": "09123456789",
        "email": "user@example.com",
        "name": "Ali Rezaei",
        "registered_at": "2026-11-10T12:00:00Z",
        "subscription_status": "ACTIVE",
        "plan_key": "annual",
        "devices_count": 1,
        "last_login": "2026-11-10T11:30:00Z"
      }
    ],
    "pagination": { "page": 1, "per_page": 50, "total": 1250 }
  }
}
```

---

### 8.2 Device Management (Admin)

**Endpoint:** `GET /admin/devices`

**Auth:** Admin JWT

**Query:** `user_id=123&status=active&page=1`

---

### 8.3 License Management (Admin)

**Endpoint:** `GET /admin/licenses`

**Auth:** Admin JWT

**Query:** `user_id=123&status=active&plan_key=annual&page=1`

**Endpoint:** `POST /admin/licenses/{license_id}/extend`

```json
{
  "days": 30,
  "reason": "Customer support gesture"
}
```

---

### 8.3 Payment Management (Admin)

**Endpoint:** `GET /admin/payments`

**Auth:** Admin JWT

**Query:** `status=verified&gateway=zarinpal&date_from=2026-11-01&page=1`

---

### 8.4 Audit Logs (Admin)

**Endpoint:** `GET /admin/audit-logs`

**Auth:** Admin JWT

**Query:** `event_type=payment.verified&severity=CRITICAL&page=1`

---

### 8.4 Gateway Settings (Admin)

**Endpoint:** `GET /admin/gateway-settings`

**Auth:** Admin JWT

**Endpoint:** `PUT /admin/gateway-settings`

```json
{
  "zarinpal": {
    "enabled": true,
    "merchant_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "callback_url": "https://wp.mrkiyan.ir/wp-json/eligius/v1/payments/callback/zarinpal",
    "sandbox": false
  },
  "idpay": {
    "enabled": true,
    "api_key": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "callback_url": "https://wp.mrkiyan.ir/wp-json/eligius/v1/payments/callback/idpay",
    "sandbox": false
  }
}
```

---

### 8.4 General Settings (Admin)

**Endpoint:** `GET /admin/general-settings`

**Auth:** Admin JWT

**Endpoint:** `PUT /admin/general-settings`

```json
{
  "trial_duration_days": 30,
  "max_devices_per_user": 1,
  "offline_grace_period_hours": 72,
  "license_key_rotation_days": 90,
  "auto_renewal_default": false
}
```

---

## 9. Rate Limits

| Endpoint Category | Limit | Window |
|-------------------|-------|--------|
| Auth (OTP start/verify) | 5 req | 1 hour / IP |
| Device registration | 10 req | 1 hour / user |
| Device challenge/verify | 20 req | 1 minute / user |
| Payment create | 5 req | 1 hour / user |
| Payment callback | 100 req | 1 minute / IP |
| License validation | 100 req | 1 minute / device |
| Admin endpoints | 200 req | 1 minute / user |
| Global API | 1000 req | 1 minute / IP |

**Headers Returned:**
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1699632000
```

---

## 10. Idempotency

| Endpoint | Idempotency Key |
|----------|-----------------|
| `POST /payments/create` | Client-generated `Idempotency-Key` header (UUID) |
| `POST /payments/callback/{gateway}` | Gateway `Authority` / reference |
| `POST /devices/transfer/verify` | `transfer_id` + `otp_code` |
| `POST /licenses/renew` | Client `Idempotency-Key` header |

**Implementation:** Client generates UUID v4, sends `Idempotency-Key` header. Server stores result keyed by key+user for 24h.

---

## 11. Webhook Events (Future)

| Event | Payload |
|-------|---------|
| `license.created` | `{ license_id, user_id, plan_key, expires_at }` |
| `license.activated` | `{ license_id, user_id, device_id }` |
| `license.expired` | `{ license_id, user_id }` |
| `license.revoked` | `{ license_id, user_id, reason }` |
| `payment.verified` | `{ payment_id, user_id, license_id, amount }` |
| `payment.failed` | `{ payment_id, user_id, reason }` |
| `device.registered` | `{ device_id, user_id }` |
| `device.transferred` | `{ from_device_id, to_device_id, user_id }` |

**Delivery:** HTTP POST to configured webhook URL with HMAC-SHA256 signature header.

---

*End of API Specification*