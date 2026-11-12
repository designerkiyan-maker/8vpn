# Eligius Subscription System — Implementation Roadmap

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

This roadmap breaks the subscription system implementation into discrete, dependency-aware phases. Each phase delivers independently testable functionality and can be shipped independently.

### 1.1 Guiding Principles

1. **Backend-First** — All subscription logic lives in WordPress; Android app is a thin client
2. **Independent Deployability** — Backend phases deploy independently of Android releases
3. **Additive Changes Only** — No modifications to VPN core (`CoreProcess`, `ArgBuilder`, `SingBox`, `Tun2Socks`, native libs)
4. **Server-Authoritative** — All business logic enforced on server; Android is a thin client
5. **Test-First** — Each phase includes acceptance criteria and test requirements

---

## 2. Phase Overview

| Phase | Duration | Focus | Dependencies |
|-------|----------|-------|--------------|
| **P0** | 1 week | Project setup, CI/CD, dev environment | — |
| **P1** | 2 weeks | **Server Licensing Core** (tables, license CRUD, trial, status API) | P0 |
| **P2** | 1.5 weeks | **Device Binding** (Keystore keys, registration, challenge-response) | P1 |
| **P3** | 2 weeks | **App Enforcement** (LicenseStore, gate in TunnelService, UI) | P1, P2 |
| **P4** | 2 weeks | **Payments** (Gateway abstraction, ZarinPal/IDPay, S2S verification) | P1 |
| **P5** | 1.5 weeks | **Trials & Transfers** (30-day trial, device transfer workflow) | P1, P2 |
| **P6** | 2 weeks | **Hardening & Tests** (security hardening, unit/integration tests) | P1–P5 |
| **P7** | 1 week | **OTP Login (Optional)** | P1 |

**Total: ~12 weeks**

---

## 2. Phase Details

---

## P0 — Project Setup & Foundation (1 week)

### Scope
- Development environment setup
- CI/CD pipeline verification
- WordPress plugin scaffold
- Android dependency additions
- Documentation structure

### Deliverables
- [ ] WordPress plugin scaffold (`eligius-subscription/`) with autoloader
- [ ] Android dependencies added (`build.gradle.kts`: Retrofit, Moshi, OkHttp, security-crypto, play-services-auth)
- [ ] CI/CD pipeline for plugin (GitHub Actions)
- [ ] Local dev environment (WordPress + MariaDB + Android emulator)
- [ ] Documentation structure (`docs/architecture/eligius-subscription/`)

### Acceptance Criteria
- [ ] `composer install` / `npm install` equivalent works
- [ ] `./gradlew assembleDebug` succeeds
- [ ] WordPress plugin activates without fatal errors
- [ ] Android debug build installs on emulator

### Risks
- Gradle cache corruption (mitigation: `~/.gradle` cleanup script)
- WordPress plugin conflicts (mitigation: unique prefix `eligius_sub_`)

---

## P1 — Server Licensing Core (2 weeks)

### Scope
Core licensing infrastructure in WordPress: database tables, license CRUD, trial grant, license status API, signed offline entitlements, admin UI skeleton.

### Files to Create
```
server/eligius-subscription.php                    # Main plugin file
includes/
  bootstrap.php                                     # Autoloader & init
  activation.php                                    # Activation hook
  deactivation.php                                  # Deactivation hook
  uninstall.php                                     # Uninstall (guarded)
  core/
    class-database.php                              # Schema & migrations
    class-repository-base.php                       # Base repository
    class-license-repository.php                    # License data access
    class-device-repository.php                     # Device data access
    class-audit-log-repository.php                  # Audit logging
  auth/
    class-auth-integration.php                      # JWT + WP user sync
  license/
    class-license-manager.php                       # Core license logic
    class-license-issuer.php                        # License creation + signing
    class-license-validator.php                     # Online/offline validation
    class-trial-manager.php                         # Trial logic
  api/
    class-license-controller.php                    # REST endpoints
  admin/
    class-admin-dashboard.php                       # Dashboard page
    class-licenses-admin.php                        # Licenses management
    class-admin-menu.php                            # Menu registration
  security/
    class-rate-limiter.php                          # Rate limiting
    class-audit-logger.php                          # Audit logging
  cron/
    class-cron-manager.php                          # Cron job management
    class-expiry-processor.php                      # Expiry processing
```

### Database Changes
Run migration to create:
- `wp_eligius_devices`
- `wp_eligius_licenses`
- `wp_eligius_payments`
- `wp_eligius_license_events`
- `wp_eligius_device_transfers`
- `wp_eligius_payment_idempotency`
- `wp_eligius_audit_logs`
- `wp_eligius_payment_idempotency`
- `wp_eligius_plans` (default plans)

### API Endpoints (P1)
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/licenses/status` | GET | User JWT + X-Device-Hash | Current license status + offline entitlement |
| `/licenses/validate` | GET | User JWT + X-Device-Hash | Quick validation for VPN gate |
| `/licenses/offline-entitlement` | GET | User JWT + X-Device-Hash | Signed offline entitlement blob |
| `/licenses/trial` | POST | User JWT + X-Device-Hash | Grant 30-day trial |
| `/licenses/renew` | POST | User JWT | Renew subscription |
| `/licenses/revocation-list` | GET | User JWT + X-Device-Hash | CRL for offline validation |
| `/devices/register` | POST | User JWT + X-Device-Hash | Register device |
| `/devices/challenge` | POST | User JWT + X-Device-Hash | Get challenge for signing |
| `/devices/verify` | POST | User JWT + X-Device-Hash | Verify signed challenge |
| `/devices` | GET | User JWT | List user devices |
| `/devices/transfer/start` | POST | User JWT + X-Device-Hash | Initiate device transfer |
| `/devices/transfer/verify` | POST | User JWT | Verify transfer OTP |

### Admin UI (P1)
- Dashboard: stats (active licenses, trials, revenue)
- Licenses list: filter by status, plan, user
- Users list: subscription status, devices
- Audit logs viewer

### Acceptance Criteria
- [ ] `curl POST /licenses/trial` → grants 30-day trial, returns signed blob
- [ ] `curl GET /licenses/status` → returns license status + offline blob
- [ ] `curl GET /licenses/validate` → fast boolean for VPN gate
- [ ] Trial cannot be granted twice for the same `user_id` (account-keyed control)
- [ ] **One-active-device enforced transactionally through the SINGLE user-row lock point** (02 §2.8): two concurrent registration/transfer attempts for the same user → exactly one succeeds, the other gets `DEVICE_LIMIT_REACHED`; bind-vs-transfer races serialized by the same lock; all tables verified InnoDB; every statement's result + `$wpdb->last_error` explicitly checked with ROLLBACK on failure
- [ ] Offline blob validates on device (Ed25519, embedded public key)
- [ ] CRL endpoint returns revoked IDs, cached 5 min
- [ ] Admin can revoke/extend/revoke licenses from wp-admin
- [ ] All endpoints rate-limited (5/hour/IP for auth, 100/min/device for validation)
- [ ] Audit log captures all license events

### Tests Required
- Unit: License state machine, trial eligibility, offline blob signing/verification
- Integration: All REST endpoints (happy path + error cases)
- Security: SQL injection, auth bypass, rate limit bypass attempts

### Risks
- Ed25519 signing on PHP (mitigation: `sodium` extension or `paragonie/sodium_compat`)
- Key rotation testing (mitigation: automated test with multiple `kid` values)

---

## P2 — Device Binding (1.5 weeks)

### Scope
Device identity using Android Keystore, cryptographic challenge-response, server-side binding, one-device-per-user enforcement.

### Files to Create/Modify

**Server (WordPress):**
- `includes/auth/class-device-auth.php` — Challenge generation, verification
- `includes/core/class-device-repository.php` — Device data access
- `includes/license/class-device-transfer.php` — Transfer workflow

**Android (New Files):**
```
app/src/main/java/ir/mrkiyan/eligius/
  DeviceIdentity.kt              # Keystore key management, registration
  SplitOtpInput.kt               # (Already exists)
  SmsOtp.kt                      # (Already exists, minor updates)
```

**Android (Modify):**
- `AuthRegisterScreen.kt` — Send `device_hash` with `registerOtpStart`
- `AuthForgotScreen.kt` — Send `device_hash` with `forgotStart`
- `AuthStore.kt` — `DeviceIdentity` init, `getDeviceHash()` calls

### Device Registration Flow

```
Android                          Server
   │                                 │
   │ 1. Generate EC P-256 keypair   │
   │    in Android Keystore         │
   │                                 │
   │ 2. Compute device_hash =       │
   │    SHA-256(pubkey_der || ANDROID_ID)
   │                                 │
   │ 3. POST /devices/register ────►│
   │    { public_key, fingerprint,  │
   │      label }                   │
   │                                 │
   │                         Verify fingerprint = SHA-256(pubkey || ANDROID_ID)
   │                         Check one-device-per-user
   │                                 │
   │◄── { device_id } ──────────────│
```

### Challenge-Response Protocol

```
Android                          Server
   │                                 │
   │── POST /devices/challenge ────►│
   │  { device_hash }               │
   │                                 │
   │◄── { challenge: "base64",      │
   │      expires_in: 60 } ─────────│
   │                                 │
   │  sign(challenge) with          │
   │  device private key (Keystore) │
   │                                 │
   │── POST /devices/verify ────────►│
   │  { device_hash, challenge,    │
   │    signature }                 │
   │                                 │
   │         Verify signature       │
   │         with stored pubkey     │
   │                                 │
   │◄── { verified: true } ─────────│
```

### Acceptance Criteria
- [ ] Device registration stores `device_hash`, `public_key`, `label`
- [ ] One active device per user (enforced at registration + license binding)
- [ ] Challenge-response uses Android Keystore (non-exportable key)
- [ ] Server verifies Ed25519/ECDSA P-256 signature
- [ ] Challenge expires in 60 seconds
- [ ] Device transfer: OTP-verified, rate-limited (3/day)
- [ ] Device transfer swaps `device_id` on license, deactivates old device
- [ ] Rooted device detection logs warning (not blocking)

### Tests Required
- Unit: Key generation, fingerprint calculation, signature verification
- Integration: Registration → Challenge → Verify flow
- Security: Replay attack, signature forgery, key extraction attempts

### Risks
- Android Keystore availability (API 23+ for EC, API 28+ for Ed25519) — fallback to ECDSA P-256
- Emulator testing (no hardware keystore) — software fallback works

---

## P3 — App Enforcement (2 weeks)

### Scope
Android-side license enforcement: `LicenseStore`, `LicenseStore.ensureValid()` gate in `TunnelService.startSession()`, UI for subscription status, expiry dialogs, offline entitlement caching.

### Android Files to Create
```
app/src/main/java/ir/mrkiyan/eligius/
  LicenseStore.kt                    # License state, validation, offline cache
  OfflineEntitlementStore.kt         # EncryptedSharedPreferences for blob
  RevocationListManager.kt           # CRL caching (5 min TTL)
  OfflineEntitlementValidator.kt     # JWT validation with embedded keys
  SecureTokenStore.kt                # EncryptedSharedPreferences for JWT
  RevocationListManager.kt           # CRL cache (5 min TTL)
  api/
    LicenseApiService.kt             # Retrofit interface
    DeviceApiService.kt
    PaymentApiService.kt
    AuthApiService.kt
    model/...                        # Data classes
```

### Android Files to Modify
| File | Change |
|------|--------|
| `TunnelService.kt` | Add `LicenseStore.ensureValid()` gate in `startSession()` |
| `MainActivity.kt` | `toggleConnect()` pre-check, `AUTH_FORGOT` route |
| `AuthStore.kt` | `hash` param in `registerOtpStart`, `forgotStart`, `forgotVerify`, `forgotReset`; extend `login()` to call `LicenseStore.ensureValid()` |
| `AuthScreen.kt` | "Forgot Password?" link → `AUTH_FORGOT` route |
| `AuthForgotScreen.kt` | Use `AuthStore.forgotStart/Verify/Reset` |
| `AuthRegisterScreen.kt` | Pass `smsHash` to `registerOtpStart` |
| `AuthStore.kt` | `login()` calls `LicenseStore.ensureValid()` after auth |
| `MainActivity.kt` | Add `AUTH_FORGOT` route, `toggleConnect()` license pre-check |
| `BootReceiver.kt` | Cached license check before autostart |
| `QsTile.kt` | Cached license check in `onClick()` |
| `App.kt` | Initialize `LicenseStore`, `DeviceIdentity`, `OfflineEntitlementStore`, `RevocationListManager`, `SecureTokenStore` |
| `Models.kt` (`SettingsStore`) | Schema v12: add `lastLicenseCheckAt`, `cachedLicenseBlob` |
| `app/build.gradle.kts` | Add dependencies |

### LicenseStore Core Logic

```kotlin
object LicenseStore {
    // State
    val licenseState = MutableStateFlow<LicenseState?>(null)
    val isChecking = MutableStateFlow<Boolean>(false)
    
    // Main gate — called before VPN connection
    suspend fun ensureValid(): ValidationResult = withContext(Dispatchers.IO) {
        // 1. Check auth
        if (!AuthStore.isLoggedIn) return ValidationResult(false, "NOT_LOGGED_IN")
        
        // 2. Cached license (5 min TTL)
        val cached = licenseState.value
        if (cached?.valid == true && !isExpired(cached)) {
            if (cached.licenseId != null && RevocationManager.isRevoked(cached.licenseId!!)) {
                return ValidationResult(false, "LICENSE_REVOKED")
            }
            return ValidationResult(true, licenseState = cached)
        }
        
        // 3. Offline entitlement (72h max)
        val offline = offlineStore!!.get()
        if (offline != null && OfflineEntitlementValidator.validate(offline.blob).isValid) {
            if (revocationManager.isRevoked(entitlement.licenseId)) {
                return ValidationResult(false, "LICENSE_REVOKED")
            }
            return ValidationResult(true, offlineLicenseState)
        }
        
        // 4. Online validation
        return onlineValidation()
    }
    
    private suspend fun onlineValidation(): ValidationResult = withContext(Dispatchers.IO) {
        val deviceHash = DeviceIdentity.getDeviceHash() ?: return ValidationResult(false, "DEVICE_NOT_REGISTERED")
        val response = apiService!!.getLicenseStatus(deviceHash)
        
        if (response.isSuccessful) {
            val data = response.body()?.data
            val state = LicenseState(
                valid = data?.valid == true,
                licenseId = data?.licenseId,
                planKey = data?.planKey,
                planLabel = data?.planLabel,
                status = data?.status,
                startedAt = data?.startedAt,
                expiresAt = data?.expiresAt,
                daysRemaining = data?.daysRemaining,
                deviceBound = data?.deviceBound == true,
                deviceId = data?.deviceId,
                offlineEntitlement = data?.offlineEntitlement?.let { OfflineEntitlement(
                    blob = it.blob,
                    expiresAt = it.expiresAt,
                    kid = it.kid
                )}
            )
            
            if (state.valid) {
                // Cache successful result
                licenseState.value = state
                lastCheckedAt.value = System.currentTimeMillis()
                
                // Store offline entitlement if provided
                response.body()?.data?.offlineEntitlement?.let { offlineStore!!.save(OfflineEntitlement(
                    blob = it.blob,
                    expiresAt = it.expiresAt,
                    kid = it.kid
                ))}
                return ValidationResult(state.valid, licenseState = state)
            }
            return ValidationResult(false, parseError(response.errorBody()))
        }
        return ValidationResult(false, parseError(response.errorBody()))
    }
}
```

### UI Components

| Component | Location | Trigger |
|-----------|----------|---------|
| `LicenseStatusDialog` | `LicenseStatusDialog.kt` | `toggleConnect()` when invalid, Home screen banner |
| `PlanSelectionScreen` | `PlanSelectionScreen.kt` | "Choose Plan" from expiry dialog |
| `PaymentActivity` | `PaymentActivity.kt` | Deep link `eligius://payment/result` |
| `LicenseStatusBanner` | `HomeScreen.kt` | Top of Home when ≤7 days remain |

### Acceptance Criteria
- [ ] `LicenseStore.ensureValid()` returns valid for active license
- [ ] Returns `NO_ACTIVE_LICENSE` for expired/no license
- [ ] Returns `DEVICE_NOT_REGISTERED` if device not registered
- [ ] Offline entitlement works for 72h without network
- [ ] CRL checked every 5 min, cached
- [ ] `TunnelService.startSession()` blocks on invalid license
- [ ] `MainActivity.toggleConnect()` shows plan selection on invalid
- [ ] Home screen shows expiry banner ≤7 days
- [ ] Offline entitlement validated with embedded public key (kid rotation)
- [ ] Revocation list cached 5 min, checked on validation

### Tests Required
- Unit: `LicenseStore.ensureValid()` all states, offline validator, revocation cache
- Instrumented: `startSession()` gate, `toggleConnect()` guard, offline flow
- Security: Offline blob tampering, revocation bypass, key rotation

### Risks
- Network latency on `ensureValid()` (mitigation: 5-min cache, async pre-fetch)
- Offline entitlement expiry edge cases (mitigation: generous 72h buffer)

---

## P4 — Payments (2 weeks)

### Scope
Payment gateway abstraction, ZarinPal/IDPay integration, server-to-server verification, idempotent callbacks, subscription activation.

### Files to Create (Server)
```
includes/
  payment/
    interface-gateway-adapter.php
    class-payment-processor.php
    class-gateway-factory.php
    gateways/
      class-zarinpal-adapter.php
      class-idpay-adapter.php
      class-zibal-adapter.php
    class-payment-processor.php
    class-gateway-factory.php
    class-idempotency-repository.php
```

### Payment Flow

```
Android                          Server                          Gateway
   │                                 │                              │
   │── POST /payments/create ───────►│                              │
   │  { plan_key, gateway }          │                              │
   │                                 │                              │
   │    Create payment record         │                              │
   │    status = INITIATED            │                              │
   │                                 │                              │
   │◄── { payment_id, authority,     │                              │
   │      redirect_url, amount } ────│                              │
   │                                 │                              │
   │    Redirect to gateway URL       │                              │
   │                              User pays                         │
   │                              Gateway callback                  │
   │                         GET /payments/callback/zarinpal       │
   │                    ?Authority=X&Status=OK                     │
   │                              S2S Verification                 │
   │    POST https://api.zarinpal.com/pg/v4/payment/verify.json   │
   │                              │                                 │
   │                         { code: 100, ref_id }                │
   │                              │                                 │
   │              Verify: code=100, amount matches, ref_id unique │
   │                              │                                 │
   │    Update payment: VERIFIED                                 │
   │    Create license: ACTIVE                                   │
   │                              │                                 │
   │                    Redirect to app                            │
   │                    eligius://payment/result?status=success    │
   │                              │                                 │
   │              App polls /licenses/status                    │
   │                              │                                 │
```

### Gateway Adapter Interface

```php
interface Eligius_Sub_Gateway_Adapter {
    public function get_key(): string; // 'zarinpal', 'idpay', 'zibal'
    public function get_name(): string;
    
    public function initiate_payment(array $params): array;
    // Returns ['authority' => string, 'redirect_url' => string]
    
    public function verify_payment(array $callback): array;
    // Returns ['verified' => bool, 'amount' => int, 'ref_id' => string]
    
    public function get_callback_url(): string;
    public function supports_refund(): bool;
    public function refund(string $gateway_ref, int $amount): array;
}
```

### ZarinPal Adapter (Reference Implementation)

```php
class Eligius_Sub_Zarinpal_Adapter implements Eligius_Sub_Gateway_Adapter {
    private $merchant_id;
    private $callback_url;
    private $sandbox;
    private $base_url;
    
    public function __construct($config) {
        $this->merchant_id = $config['merchant_id'];
        $this->callback_url = $config['callback_url'];
        $this->sandbox = $config['sandbox'] ?? false;
        $this->base_url = $this->sandbox 
            ? 'https://sandbox.zarinpal.com/pg/v4' 
            : 'https://api.zarinpal.com/pg/v4';
    }
    
    public function get_key(): string { return 'zarinpal'; }
    public function get_name(): string { return 'ZarinPal'; }
    public function get_callback_url(): string { return $this->callback_url; }
    public function supports_refund(): bool { return true; }
    
    public function initiate_payment(array $params): array {
        $response = wp_remote_post("{$this->base_url}/payment/request.json", [
            'body' => json_encode([
                'merchant_id' => $this->merchant_id,
                'amount' => $params['amount'],
                'callback_url' => $this->callback_url,
                'description' => $params['description'],
                'metadata' => $params['metadata'] ?? []
            ]),
            'headers' => ['Content-Type' => 'application/json']
        );
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (($body['data']['code'] ?? 0) !== 100) {
            throw new Exception('ZarinPal request failed: ' . ($body['data']['message'] ?? 'Unknown'));
        }
        
        $authority = $body['data']['authority'];
        $redirect = $this->sandbox 
            ? "https://sandbox.zarinpal.com/pg/StartPay/{$authority}"
            : "https://www.zarinpal.com/pg/StartPay/{$authority}";
        
        return ['authority' => $authority, 'redirect_url' => $redirect];
    }
    
    public function verify_payment(array $callback): array {
        $authority = $callback['Authority'] ?? '';
        $status = $callback['Status'] ?? '';
        
        if ($status !== 'OK') {
            return ['verified' => false, 'reason' => 'User cancelled or failed'];
        }
        
        $response = wp_remote_post("{$this->base_url}/payment/verify.json", [
            'body' => json_encode([
                'merchant_id' => $this->merchant_id,
                'authority' => $authority,
                'amount' => $amount // from stored payment
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (($body['data']['code'] ?? 0) === 100) {
            return [
                'verified' => true,
                'amount' => $body['data']['amount'] ?? 0,
                'ref_id' => $body['data']['ref_id'] ?? ''
            ];
        }
        
        return ['verified' => false, 'reason' => $body['data']['message'] ?? 'Verification failed'];
    }
    
    public function refund(string $gateway_ref, int $amount): array {
        $response = wp_remote_post("{$this->base_url}/payment/refund.json", [
            'body' => json_encode([
                'merchant_id' => $this->merchant_id,
                'authority' => $gateway_ref,
                'amount' => $amount
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return [
            'success' => ($body['data']['code'] ?? 0) === 100,
            'ref_id' => $body['data']['ref_id'] ?? ''
        ];
    }
}
```

### Idempotency & Anti-Fraud

```php
class Eligius_Sub_Payment_Processor {
    private $idempotency_repo;
    
    public function process_gateway_callback($gateway_key, $request) {
        // 1. Idempotency check (SELECT FOR UPDATE)
        $existing = $this->idempotency_repo->find_by_gateway_ref($gateway, $authority);
        
        if ($existing) {
            if ($existing->status === 'VERIFIED') {
                return ['duplicate' => true];
            }
            // Update existing
            $this->idempotency_repo->update($existing->id, [
                'status' => 'VERIFIED',
                'response_payload' => $request
            ]);
        } else {
            // Create new
            $this->idempotency_repo->create([
                'gateway' => $gateway,
                'gateway_ref' => $authority,
                'status' => 'VERIFIED',
                'request_payload' => $request,
            ]);
        }
        
        // 2. Server-to-Server verification
        $verification = $this->gateways[$gateway_key]->verify($request);
        
        if (!$verification['verified']) {
            $this->mark_payment_failed($payment_id, $verification['reason']);
            return ['verified' => false];
        }
        
        // Verify amount matches
        if ($verification['amount'] != $payment->amount) {
            throw new Exception('Amount mismatch');
        }
        
        // Check idempotency
        if ($idempotency && $idempotency->status === 'VERIFIED') {
            return ['duplicate' => true];
        }
        
        // Activate license
        $this->activate_license($payment->user_id, $payment->plan_key, $payment->id);
        
        // Mark idempotency verified
        $this->idempotency_repo->upsert($gateway, $authority, 'VERIFIED', $request);
        
        return ['success' => true];
    }
}
```

### Acceptance Criteria
- [ ] `POST /payments/create` returns redirect URL for ZarinPal/IDPay
- [ ] Gateway callback verifies S2S, updates payment, activates license
- [ ] Idempotency: duplicate callback returns `duplicate: true`
- [ ] Amount mismatch → payment failed, license not activated
- [ ] Forged callback rejected (S2S verification fails)
- [ ] Duplicate `gateway_ref` → idempotency returns `duplicate: true`
- [ ] Refund flow works (admin-initiated)
- [ ] Audit log: `payment.verified`, `payment.failed`, `payment.refunded`

### Tests Required
- Unit: Gateway adapters (mock HTTP), idempotency logic, amount verification
- Integration: Full payment flow (sandbox), callback handling
- Security: Forged callback, amount mismatch, replay attack, duplicate callback

### Risks
- Gateway API changes (mitigation: adapter pattern, versioned API)
- Sandbox vs production URL differences (config-driven)
- Callback timeout (mitigation: async processing, idempotency)

---

## P5 — Trials & Transfers (1.5 weeks)

### Scope
30-day free trial (account-keyed per CD-6), device transfer workflow with OTP verification.

### Trial Business Logic (Recap)

| Rule | Implementation |
|------|----------------|
| 30 days | `expires_at = started_at + 30 days` (server clock) |
| One per device | `device_hash` uniqueness in `eligius_licenses` + `eligius_devices` |
| Reinstall protection | `device_hash = SHA-256(pubkey || ANDROID_ID)` survives reinstall if Keystore intact |
| No account sharing | Trial bound to `device_hash`, not account |
| Conversion | Payment during trial → upgrade to paid, extend from trial expiry |

### Device Transfer Workflow

```
User (old device)          Server          User (new device)
   │                         │                   │
   │── POST /transfer/start ─►│                   │
   │  { to_device_hash }      │                   │
   │                         │                   │
   │◄── { transfer_id,       │                   │
   │      otp_sent: true }   │                   │
   │                         │                   │
   │                         │   SMS OTP         │
   │                         │─────────────────►│
   │                         │                   │
   │                         │◄── User enters    │
   │                         │      OTP          │
   │                         │                   │
   │                         │  POST /transfer/verify        │
   │                         │  { transfer_id, otp }         │
   │                         │                   │            │
   │                         │  Verify OTP                 │
   │                         │  Swap device bindings       │
   │                         │  Update license device_id   │
   │                         │                   │            │
   │◄── { verified: true } ──│                   │            │
   │                         │                   │            │
```

### Acceptance Criteria
- [ ] Trial: 30 days, account-keyed (one per `user_id`), device-bound informationally at grant
- [ ] Reinstall same device → same trial (if Keystore key survives)
- [ ] Transfer: OTP-verified, 10-min TTL, rate-limited (3/day)
- [ ] Transfer swaps `device_id` on license, deactivates old device
- [ ] Transfer audit logged
- [ ] Admin can block specific devices

### Tests Required
- Unit: Trial eligibility logic, transfer OTP verification
- Integration: Full transfer flow (old device → new device)
- Security: Transfer rate limit, OTP brute force, device enumeration

---

## P6 — Hardening & Tests (2 weeks)

### Scope
Security hardening, comprehensive test suite, audit logging, rate limiting, key rotation.

### Security Hardening Checklist

| Area | Action |
|------|--------|
| **Token Storage** | Migrate JWT to `EncryptedSharedPreferences` (AES256-GCM) |
| **Offline Entitlement** | Verify Ed25519 signature with embedded keys (kid rotation) |
| **Time Integrity** | elapsedRealtime monotonic anchor + **BOOT_COUNT hard prerequisite** (unavailable at anchor or check → offline DENIED, no fallback — 04 §4.4 pass-5 policy) + wall-rollback check + AUTO_TIME signal (**weak positive** — toggle ON ≠ service working, no API to verify time_detector for normal apps) + retroactive `entitlement.time_anomaly` logging |
| **Revocation** | CRL checked every 5 min, cached; offline blob checked against CRL |
| **Key Rotation** | 90-day license signing key rotation; 3 keys embedded (v1, v2, v3) |
| **Rate Limiting** | Per-IP, per-user, per-endpoint (WP transient + DB) |
| **Audit Logging** | All financial/license events in `eligius_audit_logs` |
| **SQL Injection** | All queries use `$wpdb->prepare()` |
| **XSS/CSRF** | WP nonces on admin forms, REST API nonce validation |
| **Payment** | S2S verification, idempotency keys, amount verification |
| **Device Auth** | Challenge-response with Keystore key; no READ_SMS needed |

### Test Suite

| Test Type | Target | Target Coverage |
|-----------|--------|-----------------|
| Unit | License state machine, trial logic, payment activation | >90% |
| Unit | Offline validator, revocation cache, signature verification | >90% |
| Unit | Payment idempotency, amount verification, gateway adapters | >90% |
| Integration (API) | Every REST endpoint (happy path + error paths) | 100% endpoints |
| Instrumented | `startSession()` gate, `toggleConnect()` guard | Critical paths |
| Security | Expired license mid-session, cloned prefs, clock rollback (while running AND across reboot), AUTO_TIME disabled, AUTO_TIME ON but time_detector silently failing (weak signal — documented), BOOT_COUNT unavailable → offline DENIED, trial multi-account correlation flags | Critical scenarios |
| Manual Regression | 12-step phone checklist (login, register, forgot, connect, usage) | Per release |

### Test Infrastructure
```kotlin
// build.gradle.kts additions
dependencies {
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.mockito:mockito-core:5.11.0")
    testImplementation("org.mockito.kotlin:mockito-kotlin:5.1.0")
    androidTestImplementation("androidx.test:runner:1.5.2")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
    androidTestImplementation("org.mockito:mockito-android:5.11.0")
}

// Test runner
testOptions {
    animationsDisabled = true
    unitTests.returnDefaultValues = true
}
```

### Acceptance Criteria
- [ ] Unit test coverage >90% for business logic
- [ ] All REST endpoints have integration tests
- [ ] Instrumented tests for VPN gate pass
- [ ] Security scenarios pass (expired mid-session, cloned prefs, clock rollback)
- [ ] All critical tests green; security checklist signed off
- [ ] CI pipeline runs tests on every PR

---

## P7 — OTP Login (Optional, 1 week)

### Scope
Optional: Replace password login with OTP-primary authentication.

### Scope
- New endpoints: `/auth/otp-login-start`, `/auth/otp-login-verify`
- `AuthScreen.kt`: Toggle between password/OTP login
- `AuthStore.kt`: `otpLoginStart`, `otpLoginVerify` methods
- `SmsOtp`: Reuse existing OTP infrastructure

### Acceptance Criteria
- [ ] User can choose "Login with OTP" on login screen
- [ ] OTP sent via existing IPPanel infrastructure
- [ ] OTP verification → JWT token → auto-login
- [ ] Falls back to password if OTP fails

### Risks
- User confusion (mitigation: clear UI, default to password)
- SMS delivery delays (mitigation: Consent dialog fallback)

---

## 4. Cross-Phase Dependencies

```
P0 ──► P1 ──► P2 ──► P3 ──► P6
       │      │      │
       ▼      ▼      ▼
       P4 ◄─── P5 ◄──┘
       │
       ▼
       P7 (optional)
```

- **P1** unblocks **P2**, **P3**, **P4**, **P5**
- **P2** unblocks **P3** (device binding needed for license gate)
- **P1** unblocks **P4** (payment needs license activation)
- **P1, P2** unblock **P5** (trials need device binding)
- **P1–P5** unblock **P6** (tests need full system)
- **P1** unblocks **P7** (optional)

---

## 5. Release Strategy

| Release | Contents | Target |
|---------|----------|--------|
| **v1.4.0** | P0 + P1 (Licensing core, device binding, trial) | Week 4 |
| **v1.5.0** | P2 + P3 (App enforcement, device transfer) | Week 7 |
| **v1.6.0** | P4 (Payments) | Week 9 |
| **v1.7.0** | P5 + P6 (Trials, transfers, hardening, tests) | Week 11 |
| **v1.8.0** | P7 (OTP Login, optional) | Week 12 |

**Branch Strategy:**
- `main` → Production (tagged releases)
- `develop` → Integration branch
- `feature/*` → Feature branches per phase

---

## 6. Resource Estimation

| Role | P0 | P1 | P2 | P3 | P4 | P5 | P6 | P7 | Total |
|------|----|----|----|----|----|----|----|----|-------|
| Backend (PHP/WordPress) | 1 | 2 | 0.5 | 0 | 2 | 1 | 0.5 | 0 | 7 |
| Android (Kotlin) | 0.5 | 0.5 | 1.5 | 2 | 0.5 | 0.5 | 1 | 0.5 | 8.5 |
| DevOps/CI | 1 | 0.5 | 0 | 0 | 0 | 0 | 0.5 | 0 | 2 |
| QA/Testing | 0.5 | 1 | 0.5 | 1 | 0.5 | 0.5 | 2 | 0.5 | 6.5 |
| **Total (person-weeks)** | **3** | **4** | **2** | **2** | **2.5** | **2** | **3.5** | **0.5** | **19.5** |

---

## 6. Risk Register

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Gateway API changes | Medium | High | Adapter pattern, versioned API, monitoring |
| Android Keystore unavailable | Low | High | Fallback to ECDSA P-256, software keystore |
| Payment gateway downtime | Low | High | Multiple gateway support, graceful degradation |
| Key rotation failure | Low | Critical | Pre-generated keys, automated APK release |
| Trial abuse (factory reset / multi-account) | Medium | Medium | Account-keyed trial (CD-6) + OTP-verified mobile + layered rate limits + anomaly flagging; **complete prevention not claimed** — documented residual risk |
| Offline clock rollback (post-reboot gap) | Medium | Medium | Layered time integrity (elapsedRealtime anchor + wall comparison + AUTO_TIME signal, 04 §4.4); server-side retroactive anomaly detection; **honest documented limitation** |
| Regulatory changes (Iran) | Medium | High | Modular gateway abstraction, local compliance review |

---

## 7. Go/No-Go Criteria per Phase

| Phase | Go Criteria |
|-------|-------------|
| P1 | All license CRUD endpoints pass integration tests; trial grant/verify works; offline blob validates |
| P2 | Device registration + challenge-response works; one-device-per-user enforced |
| P3 | `TunnelService.startSession()` blocks invalid licenses; offline entitlement works 72h |
| P4 | End-to-end payment flow (sandbox) works; S2S verification rejects forged callbacks |
| P5 | Trial grant/verify works; device transfer OTP flow completes |
| P6 | All tests pass; security checklist signed off; CI green |
| P7 | OTP login works alongside password login |

**Do first:** P1 (server licensing core) — it unblocks everything and requires zero app changes.
**Do not touch:** `CoreProcess.kt`, `ArgBuilder.kt`, `SingBox.kt`, `Tun2Socks.kt`, native build jobs, the Aether checkout/pinning in CI, and the existing registration/OTP/forgot flows beyond the additive device-registration hook.

---

## 8. Post-Launch Monitoring

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| License validation latency (p95) | < 200ms | > 500ms |
| Payment success rate | > 98% | < 95% |
| SMS delivery rate | > 95% | < 90% |
| API error rate (5xx) | < 0.1% | > 1% |
| CRL cache hit rate | > 95% | < 90% |
| Offline entitlement validation success | > 99% | < 95% |

---

*End of Implementation Roadmap*