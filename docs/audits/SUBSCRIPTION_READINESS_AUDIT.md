# Eligius — Subscription Readiness Audit

**Date:** 2026-11-09
**Scope:** Read-only inspection of the repository at commit `f5f5efe` (+ server-side PHP shipped in `server/`), performed to determine readiness for a subscription / licensing / device-binding / payment system.
**Status of this document:** Audit only. No source code, Gradle, manifest, database, or CI changes were made. The single permitted modification is this file.

---

## A. Executive Summary

Eligius is a **working, single-module Android VPN client** (Kotlin + Jetpack Compose, ~6,700 LOC of app code, one `:app` module) with a **live WordPress-based account backend**. The following already exist and work in production:

- A full **Aether-based VPN core** (Rust child process + sing-box/libbox + hev-socks5-tunnel + Psiphon/Lyrebird), built and released through GitHub Actions since v1.0.0 (11 releases published, current v1.3.11 / versionCode 20).
- A **login gate** (username/password via the WP JWT plugin, with a wp-login.php form fallback), a **3-step registration wizard with SMS OTP verification** (IPPanel gateway), and a **forgot-password flow with OTP** — all verified live end-to-end against `wp.mrkiyan.ir`.
- **Per-user lifetime usage accounting** stored server-side (WP usermeta) and surfaced in the Account tab.
- Self-hosted update distribution (`/wp-json/eligius/v1/version` + APK downloads) with a GitHub fallback.
- CI that compiles native components (Rust, Go, JNI) and produces signed APKs for three ABIs.

**What does not exist at all:** any notion of subscription, license, trial, payment, device identity, device binding, or license enforcement. There is **no device-identification mechanism anywhere in the codebase** (no `ANDROID_ID`, no UUID generation, no Keystore-based key material for app secrets), **no database** (all persistence is `SharedPreferences`), **no DI framework**, **no Navigation library**, and **zero tests** (no `test/` or `androidTest/` source sets exist).

The VPN connection path is currently gated only by *login* — and only at three UI-side entry points. `TunnelService.startSession()` itself performs **no authorization check**. Once the subscription system exists, enforcement must be added inside the service (the single funnel `startSession()`), not only in the UI.

**Bottom line:** the app-side skeleton the subscription system can hang on (auth, account model, OTP UX, server communication, enforced gate points) is real and battle-tested. Everything license/payment/device-specific must be built from zero, on both the app and the backend. The existing WordPress/PHP stack is the lowest-friction substrate for the backend, provided license data moves out of `usermeta` into proper relational tables.

**Subscription readiness (methodology in §D): ≈ 22%.**

---

## B. Current Architecture (evidence-based)

### B.1 Module & build layout

| Item | Evidence |
|---|---|
| Single module | `settings.gradle.kts` → `include(":app")` only |
| App config | `app/build.gradle.kts` — `applicationId ir.mrkiyan.eligius`, minSdk 26, target/compile 35, versionCode 20 / versionName 1.3.11 |
| Signing | `signingConfigs.release` from `KEYSTORE_FILE/PASSWORD/ALIAS/PASSWORD` env; CI refuses to build without `KEYSTORE_BASE64` (`.github/workflows/main.yml`, "Decode keystore (optional)" step errors out without it) |
| ABI splits | armeabi-v7a, arm64-v8a + universal (`splits.abi`) |
| Native deps | `app/libs/libbox.aar` (sing-box, gitignored, built by CI `libbox` job); `lib*.so` executables shipped with `useLegacyPackaging` and run from `nativeLibraryDir` |
| Obfuscation | `isMinifyEnabled = true` + `proguard-rules.pro` keeping JNI-registered classes (`hev.htproxy.TProxyService`, `ir.mrkiyan.eligius.Tun2Socks`, `go.**`, `io.nekohasekai.**`) |
| Root build | `build.gradle.kts` — AGP 8.7.3, Kotlin 2.0.21, compose plugin |

### B.2 App architecture pattern

**No formal architecture pattern is applied** (no MVVM/MVI layering, no repository layer, no use-case classes, no DI). The actual pattern is:

- **Singleton state holders** — `object AuthStore` (`AuthStore.kt`), `object SettingsStore` (`Models.kt:128`), `object Engine` (`Engine.kt`), each exposing `MutableStateFlow` and initialized in `App.onCreate()` (`App.kt:5-13`: `SettingsStore.init` → `AuthStore.init` → `Engine.init`).
- **Single-Activity Compose UI with a hand-rolled router** — `MainActivity.kt` `Root()` keeps `var screen by rememberSaveable { mutableIntStateOf(...) }` over constants `HOME=0 … AUTH_FORGOT=9` (`MainActivity.kt:166-174`), switching inside one `AnimatedContent` (fade 280/200 ms). No Navigation-Compose, no fragments.
- **Direct network I/O in the store objects** — `AuthStore` uses raw `HttpURLConnection` against `WP_BASE = "https://wp.mrkiyan.ir"` (`AuthStore.kt:28`); no Retrofit/OkHttp/Ktor, no certificate pinning, no request signing.
- **Persistence** — three `SharedPreferences` files only: `"auth"` (`AuthStore.init`, `AuthStore.kt:60-76`), `"settings"` (`SettingsStore.init` + schema migrations up to 11, `Models.kt:139-190`), `"lastsession"` (`Engine`). **No Room/SQLite anywhere** (verified: no `Room|SQLite|sqlite` matches).
- **Locale** — `LocaleHelper.wrap()` applied in both `MainActivity` and `TunnelService.attachBaseContext` (`TunnelService.kt:65`); language persisted as `"language"` key in `"settings"` prefs.

### B.3 VPN core and connection lifecycle

```
MainActivity.toggleConnect()  (MainActivity.kt:104-113)
        │  VpnService.prepare() → vpnLauncher (user consent) → TunnelService.start(ctx)
        ▼
TunnelService.onStartCommand (TunnelService.kt:85-97)   [START_STICKY]
        └─ startSession() (TunnelService.kt:111-126)     ← SINGLE FUNNEL, no auth/license check
              └─ run(s) (TunnelService.kt:197+)
                    ├─ awaitNetwork() / startCore()      → CoreProcess (Aether, Rust) + ArgBuilder
                    ├─ bringUpTun() → startSingBox() (libbox) or startTun2Socks() (hev JNI)
                    ├─ monitor() / checkSpeed() / fetchExit()   (health loop, auto-retry/recycle)
                    └─ finish(error) (TunnelService.kt:179-196)
                          └─ AuthStore.reportUsage(...)  ← only when a JWT session exists (line 185)
```

Other connection entry points:

| Entry | Gate | Evidence |
|---|---|---|
| Main UI connect button | Login gate via auth LaunchedEffect (`MainActivity.kt:88-96` flips to HOME only when `AuthStore.flow != null`) | `MainActivity.kt:104` |
| Boot autostart | `if (!AuthStore.isLoggedIn) return` | `BootReceiver.kt:14` |
| Quick Settings tile | `if (!AuthStore.isLoggedIn) { open app; return }` | `QsTile.kt:35-40` |
| `TunnelService.startSession()` itself | **NONE** — any in-process caller reaching `onStartCommand` connects | `TunnelService.kt:111` |

The service is `exported="false"` + `BIND_VPN_SERVICE` permission (`AndroidManifest.xml`), so external apps cannot start it directly; the risk is *in-app* paths only. `CoreProcess.kt` (100 lines) spawns the Rust Aether binary; `ArgBuilder.kt` (121 lines) assembles its CLI arguments. **Neither contains any auth/licensing logic, and none should be added there.**

### B.4 Existing backend (server/)

All backend logic lives in **two WordPress PHP files** shipped in `server/` (deployed to the live host at `wp.mrkiyan.ir`):

| File | Type | Endpoints | Storage |
|---|---|---|---|
| `server/eligius-register.php` | mu-plugin | `POST /eligius/v1/register` (legacy direct registration), `GET /version` (auto-detects newest APK in `uploads/eligius/`), `GET+POST /usage` (JWT-gated lifetime usage), `GET /ftpinfo` (setup key) | WP **usermeta**: `eligius_total_rx/tx/duration/sessions`, `national_code`, `mobile`, `billing_phone`, `registered_via` |
| `server/eligius-otp.php` | regular plugin | `POST /register/otp-start`, `POST /register/otp-verify`, `POST /register/forgot-start`, `POST /register/forgot-verify`, `POST /register/forgot-reset` | WP **transients**: `eligius_otp_<md5(mobile)>` (10 min, sha256(otp.wp_salt()), 5 tries), `eligius_forgot_<md5>`, `eligius_reset_<40-hex-token>`; rate limit 5/h/IP via `eligius_rate_limited()` (mu-plugin) |

External services: **JWT Authentication for WP REST API** plugin (login), **IPPanel Edge SMS** (`edge.ippanel.com/v1/api/send`, pattern lane `ogz3xw5vr0celc4` approved; `ELIGIUS_IPPANEL_*` constants defined in `wp-config.php`), MySQL (via WP). No custom DB tables exist; no payment integration exists; no admin UI beyond stock wp-admin exists.

### B.5 Authentication state machine (current)

```
register: step1(names+national code) → step2(email+mobile+password) → otp-start → step3 OTP entry
          → otp-verify → eligius_mobile_verified=1 → AuthStore.login(mobile, password) → session
login:    AuthStore.login → jwtLogin() (wp-json/jwt-auth/v1/token) → fallback formLogin() (wp-login.php POST)
          → Session(user, username, email, token, exp) → prefs "auth" → AuthStore.flow → router HOME
gate:     MainActivity LaunchedEffect(auth) flips AUTH↔HOME on session change (MainActivity.kt:88-96)
forgot:   forgot-start → split OTP → forgot-verify → reset_token → forgot-reset → auto login
logout:   AccountScreen → TunnelService.stop() BEFORE AuthStore.logout() (usage attribution order)
```

Token handling: JWT stored **plaintext** in `"auth"` prefs; expiry parsed from the JWT payload (`jwtExp`, `AuthStore.kt:340-347`); validated only at app start (`init` clears an expired session). No refresh token, no rotation, no revocation call, no pinning.

### B.6 CI/CD (`.github/workflows/main.yml`)

Jobs: `native` (matrix over 3 ABIs: Rust Aether+Tor, Go Psiphon/Lyrebird, hev JNI) → `libbox` (gomobile sing-box) → `apk` (assembleRelease, keystore secrets enforced, artifact upload, tag-triggered FTP deploy to the update server + `softprops/action-gh-release`). Trigger: `push` (branches + tags) + `workflow_dispatch`. 11 releases shipped this way (v1.0.0 → v1.3.11), all aapt-verified since the version-freeze fix (2026-11-08).

---

## C. Feature Inventory

**IMPLEMENTED (working in production):**
- OTP-verified registration with 3-step wizard, split OTP boxes, resend countdown (`AuthRegisterScreen.kt`, `SplitOtpInput.kt`)
- SMS auto-fill scaffolding: SMS Retriever + User Consent arms (`SmsOtp.kt`), hash sent to server (`AuthStore.registerOtpStart(..., hash)`, `SmsOtp.appHash`)
- Forgot-password with OTP + single-use 10-min reset token (`AuthForgotScreen.kt` + `eligius-otp.php`)
- Username/password login with JWT + form fallback (`AuthScreen.kt`, `AuthStore.login`)
- Login gate at UI/boot/tile entry points
- WP account creation (username = mobile, national-code mod-11 validation, uniqueness checks, WooCommerce-compatible `billing_phone` meta)
- Lifetime usage: server accumulation + Account tab display (`eligius-register.php` usage endpoints, `OtherScreens.kt:136 AccountScreen`)
- VPN core, traffic graph, last-session card, bottom nav, split tunneling, LAN share, QS tile, boot autostart
- Self-hosted updater with dual source (`Updater.kt`), device-aware download page (`server/eligius-download-page.html`)
- CI/CD with signing, 3-ABI splits, release publishing, optional FTP deploy
- Persian/English with Iran Sans, language toggle pill, Solar icon set

**PARTIALLY IMPLEMENTED:**
- OTP *authentication* (OTP exists for registration + password reset; **login itself is password-based, not OTP-based** — the subscription plan spec says "Phone number authentication using OTP", which implies OTP login needs adding)
- VPN access control (login-only gates at UI level; nothing inside the service; nothing license-aware)
- Token security (JWT exists but plaintext in prefs, no refresh/rotation/revocation/pinning)
- Offline behavior (cached session survives offline — incidental, not a designed grace policy)

**MISSING (does not exist anywhere in the repo):**
- Device identity / registration / binding / transfer
- Android Keystore key material for app secrets
- Free trial, subscription plans, license issuance/validation/revocation, expiration handling, grace period
- Payment (no gateway SDK, no WebView flow, no server verification)
- Admin panel (beyond stock wp-admin user list), audit logging
- Tests (entire `test/` and `androidTest/` source sets absent)
- Database (no local DB; server has no custom tables)
- DI, Navigation library, network layer abstraction (Retrofit/OkHttp), repository layer

---

## D. Subscription Readiness Score

**Overall: 22%**

Methodology: the 18 requirements in §4 were scored 0 (missing) / 0.5 (partial) / 1 (implemented), then weighted by how much each reduces *new build effort* (weights in parentheses). Backend items are weighted ×2 because the backend is the larger, riskier build.

| Requirement | Weight | Score | Weighted |
|---|---|---|---|
| OTP Authentication | 2 | 0.5 | 1.0 |
| User Accounts | 2 | 1.0 | 2.0 |
| Device Registration | 3 | 0 | 0 |
| Device Binding | 3 | 0 | 0 |
| Device Security (Keystore) | 2 | 0 | 0 |
| Free Trial | 2 | 0 | 0 |
| Subscription Plans | 2 | 0 | 0 |
| Payment | 3 | 0 | 0 |
| Payment Verification | 3 | 0 | 0 |
| License Management | 3 | 0 | 0 |
| Token Security | 2 | 0.5 | 1.0 |
| Subscription Validation | 3 | 0 | 0 |
| VPN Access Control | 3 | 0.5 | 1.5 |
| Active Session Control | 2 | 0 | 0 |
| Offline Grace Period | 1 | 0 | 0 |
| Device Transfer | 2 | 0 | 0 |
| Admin Panel | 2 | 0 | 0 |
| Audit Logging | 1 | 0 | 0 |
| **Backend multiplier ×2** | | | **(total 9.5 / 43 = 22%)** |

**Why not higher:** 13 of 18 requirements are at zero; the entire license/payment/device subsystem — app *and* backend — is unbuilt. **Why not lower:** the substrate is genuinely reusable: WP user model, JWT login, OTP/SMS plumbing, REST endpoints pattern, a hardened CI, and a working login-gate architecture with three clearly identifiable enforcement points.

---

## E. Critical Gaps (priority-ranked)

1. **CRITICAL — License backend does not exist.** No tables, no issuance, no validation, no revocation. Everything else depends on it.
2. **CRITICAL — No payment pipeline.** No gateway integration (e.g. ZarinPal/IDPay next-open-gateway style), no server-to-server verification, no idempotent activation. Forged-callback risk is currently *unprotected by absence* — nothing to forge yet, which is the time to design it correctly.
3. **CRITICAL — No device identity or binding.** `grep ANDROID_ID|getDeviceId|UUID` → zero matches. One-license-many-devices is currently trivially possible *by design* (accounts aren't even tied to a device).
4. **CRITICAL — Enforcement gap inside the VPN service.** `TunnelService.startSession()` (`TunnelService.kt:111`) runs without any authorization check; gates exist only at `MainActivity.kt:104`, `BootReceiver.kt:14`, `QsTile.kt:35`. An in-service check must be the real gate.
5. **HIGH — Token/secret storage is plaintext.** JWT in `"auth"` SharedPreferences; no Keystore-held key, no EncryptedSharedPreferences. On rooted devices this is trivially readable.
6. **HIGH — Trial-reset vector is wide open.** No device identity + `allowBackup` already false (good) but a fresh install gets a fresh state; without server-side device fingerprinting the 30-day trial resets on reinstall.
7. **HIGH — Zero tests.** No `test/`/`androidTest/` source sets. License logic without tests will regress silently.
8. **HIGH — No admin tooling for licenses.** wp-admin lists users but has no license views (status, expiry, revoke, transfer, payments).
9. **MEDIUM — No audit logging** of security/financial events anywhere (app logcat is debug-grade; server writes nothing).
10. **MEDIUM — Time tampering.** Expiry must be judged server-side; client clock is user-controllable. Current JWT `exp` check happens client-side at init only.
11. **MEDIUM — OTP login gap.** Spec says phone-number authentication via OTP; current login is username+password (OTP is only used at registration/reset). Decide whether login becomes OTP-primary.
12. **LOW — No offline grace design** (build once licenses exist).

---

## F. Recommended Architecture

### F.1 Backend (recommended: Option A — extend WordPress)

| Option | Fit | Security | Complexity | Deployment |
|---|---|---|---|---|
| **A. Custom WP plugin + custom tables** (recommended) | High — same host, same PHP/MySQL, same REST namespace (`eligius/v1`), JWT already integrated, wp-admin = ready-made admin shell | Good if implemented carefully (prepared SQL, server-side verification, capability checks) | **Low** — team already ships working WP plugins (`eligius-otp.php` is production-proven) | None — same VPS (`95.38.209.36`, nginx + PHP + MySQL) |
| B. Standalone PHP/MySQL microservice | Medium — same language, separate deploy/DB | Same effort as A but duplicated auth (JWT validation shared with WP is harder) | Medium | New vhost + deploy pipeline |
| C. Separate stack (Node/Go + Postgres + own admin SPA) | Low — new language, new ops, new admin UI | Potentially strongest long-term | High | New runtime on the VPS or a second host |

Chosen **A** because: single VPS already runs WP+MySQL; the team has two working WP plugins in production; wp-admin provides the admin-panel skeleton (custom license pages plug into it); JWT issuance/validation already exists; REST namespace and rate-limiting helpers (`eligius_rate_limited`) are reusable. Custom **tables** (not usermeta) for licenses/payments/devices — see §H.

### F.2 App-side additions (no VPN-core changes)

```
app/
  AuthStore.kt            ← extend: token refresh, LicenseStore calls (or new LicenseStore)
  LicenseStore.kt  (new)  ← object; license StateFlow; server validation; cached-signed state
  DeviceIdentity.kt (new) ← Keystore-backed keypair + ANDROID_ID-ish fingerprint + registration
  PaymentActivity (new)   ← gateway flow (WebView/deep-link return)
  TunnelService.kt        ← ONE insertion: license gate at startSession() (§G)
  MainActivity.kt         ← purchase navigation from the gate dialog
  OtherScreens.kt         ← Account tab: subscription card, plans, device management
```

License model (server is source of truth): `License { plan, startedAt, expiresAt, status: ACTIVE|EXPIRED|REVOKED, deviceIdHash }`. App validates on: connect attempt, app start (background refresh), and every 24 h while running. Offline grace: trust a **signed** license blob cached locally for ≤ 72 h (signature verified with a Keystore-embedded public key; blob carries `expiresAt`), never trust the wall clock beyond the signed blob.

---

## G. Integration Plan (exact files)

| # | File | Change | Risk |
|---|---|---|---|
| 1 | `TunnelService.kt` (line ~111, `startSession()`) | Insert license check **before** `job = scope.launch { run(s) }`: call `LicenseStore.ensureValid()` (suspend, server round-trip w/ cached fallback); on failure → `Engine.lastReason` = expiry message, `Engine.status = IDLE`, stop. **This is the enforcement point.** | Low — one guard at the funnel; `run()`/`monitor()` untouched |
| 2 | `MainActivity.kt` (`toggleConnect`, ~line 104) | Pre-check license before `TunnelService.start()`; on invalid → show purchase/expiry dialog instead of connecting | Low |
| 3 | `BootReceiver.kt` (line 14 area) | Extend the existing `isLoggedIn` gate with a cheap cached-license check (no network on boot; use cached signed state) | Low |
| 4 | `QsTile.kt` (`onClick`) | Same cached check as BootReceiver | Low |
| 5 | `AuthStore.kt` | Token storage via Keystore-wrapped encryption; `registerOtpStart` flow already sends device hash — extend with device registration; add `verifyToken()`/revocation handling | Medium — touches every auth call site |
| 6 | `OtherScreens.kt` (`AccountScreen`, line 136) | Subscription status card, plan list, device management entry | Low — additive UI |
| 7 | `ConnectionScreen.kt` / `HomeScreen.kt` | Expiry banner (existing `Engine.lastReason` channel already renders reasons) | Low |
| 8 | `Models.kt` (`SettingsStore`, schema) | Add `lastLicenseCheckAt`, cached license blob — via the existing schema-migration pattern (`if (prefs.getInt("schema",0) < 12)`) | Low — pattern already established |
| 9 | `App.kt` | `LicenseStore.init(this)` | Trivial |
| 10 | `AndroidManifest.xml` | Payment gateway activity/intent-filter entries (deep-link return) | Low |
| 11 | `app/build.gradle.kts` | Payment SDK dependency (if SDK-based) | Low |
| **Not touched** | `CoreProcess.kt`, `ArgBuilder.kt`, `SingBox.kt`, `Tun2Socks.kt`, native libs, `Engine.kt` traffic internals | — | — |

---

## H. Database Design (server, MySQL via WP dbo)

```
eligius_devices
  id BIGINT PK AI
  user_id BIGINT FK → wp_users.ID
  device_hash CHAR(64) UNIQUE          -- sha256(device secret pubkey ‖ ANDROID_ID) — NOT raw identifiers
  public_key TEXT                      -- Keystore-generated Ed25519/EC P-256 public key
  label VARCHAR(64)                    -- user-visible device name
  created_at DATETIME, last_seen_at DATETIME
  UNIQUE KEY (user_id, device_hash)    -- one row per user+device

eligius_licenses
  id BIGINT PK AI
  user_id BIGINT FK
  device_id BIGINT NULL FK → eligius_devices.id   -- NULL = unbound (trial not yet bound)
  plan ENUM('trial','monthly','quarterly','semiannual','annual')
  duration_days SMALLINT
  started_at DATETIME, expires_at DATETIME
  status ENUM('ACTIVE','EXPIRED','REVOKED','PENDING_PAYMENT')
  source VARCHAR(32)                   -- 'trial' | payment id
  UNIQUE KEY (user_id, status, plan)   -- one ACTIVE license per plan kind per user

eligius_payments
  id BIGINT PK AI
  user_id BIGINT FK, license_id BIGINT FK
  gateway VARCHAR(24)                  -- e.g. zarinpal / idpay
  gateway_ref VARCHAR(128) UNIQUE      -- authority/track id — idempotency key
  amount INT, currency CHAR(3)='IRT'
  status ENUM('INITIATED','VERIFIED','FAILED','REFUNDED')
  created_at, verified_at DATETIME

eligius_events  (audit)
  id BIGINT PK AI, user_id BIGINT NULL, device_id BIGINT NULL
  event VARCHAR(48)                    -- 'payment.verified', 'license.revoked', 'device.bound', 'auth.failed'…
  details JSON, ip VARBINARY(16), created_at DATETIME, INDEX(event, created_at)
```

Rules: one **ACTIVE** license per user at a time for the same plan kind; binding a license to a second device requires the transfer workflow (unbind old, rate-limited, audit-logged); trial is a license row with `plan='trial'` keyed by `device_hash` (not user) so reinstalling cannot reset it — trial uniqueness enforced on `device_hash`.

---

## I. API Specification (extends `eligius/v1`)

| Endpoint | Method | Auth | Responsibility |
|---|---|---|---|
| `/eligius/v1/device/register` | POST | JWT | Register/re-attach device: receives Keystore public key + fingerprint → returns device id + binds (respecting one-active-device rule; 409 with existing device info if occupied) |
| `/eligius/v1/device/list` | GET | JWT | List bound devices (for Account screen) |
| `/eligius/v1/device/transfer` | POST | JWT + OTP | Rate-limited device replacement (revoke old binding, bind new) |
| `/eligius/v1/license/status` | GET | JWT (+ device header) | Current license: plan, expiresAt (server clock), status, signature blob for offline caching |
| `/eligius/v1/license/trial` | POST | JWT + device | One-time 30-day trial grant — idempotent per `device_hash`, server-clock `started_at` |
| `/eligius/v1/payment/init` | POST | JWT | Create gateway payment: plan → returns gateway URL/authority + `payment_id` |
| `/eligius/v1/payment/verify` | GET/POST | gateway callback (server-to-server) + JWT confirm | Gateway redirect hits server with authority → server verifies **server-to-server** with gateway API → activates license, writes `eligius_payments`+`eligius_events`; client polls `license/status` |
| `/eligius/v1/license/revoke` | POST | admin (wp-admin only) | Remote revocation — license status flip + audit event; app sees it on next validation |
| `/eligius/v1/otp/login-start` / `login-verify` | POST | none (rate-limited) | If OTP-primary login is chosen (§L decision) |

All endpoints: `eligius_rate_limited()`-style throttling, JSON body, bilingual error messages (established convention), JWT `permission_callback` for user-scoped routes, capability check for admin routes.

---

## J. Security Recommendations (prioritized)

| # | Risk | Severity | Attack scenario | Current protection | Mitigation |
|---|---|---|---|---|---|
| 1 | License reuse on multiple devices | CRITICAL | One account, install on N phones | None (no device concept) | Device registration + one-active-device rule + binding check inside `startSession()`; server refuses validation from unbound `device_hash` |
| 2 | Forged payment callbacks | CRITICAL | Attacker POSTs fake "paid" to verify endpoint | N/A (endpoint doesn't exist) | Server-to-server verification with gateway API using secret; client never "self-verifies"; idempotency on `gateway_ref` |
| 3 | Device-ID spoofing | HIGH | Copy another install's fingerprint to steal binding | None | Keystore-generated keypair (non-exportable, hardware-backed where available); server binds the **public key**, challenges signed on each validation — spoofing the ID without the key fails |
| 4 | Trial reset via reinstall | HIGH | Uninstall+reinstall → new trial | None (no identity) | Trial keyed to `device_hash` (keyed by Keystore key that survives reinstall if backed up properly / or ANDROID_ID as secondary signal); server-side uniqueness |
| 5 | Token theft (rooted device / backup) | HIGH | Read prefs → replay JWT | Plaintext prefs; `allowBackup=false` (good) | Keystore-encrypted token storage; short-lived JWTs + refresh; server-side revocation list |
| 6 | Clock tampering | HIGH | Roll device clock → extend trial/expiry | Client-side `exp` check at init only | All expiry judged **server-side**; offline grace uses signed blob (server clock baked in) with bounded window |
| 7 | Modified app / rooted bypass | HIGH | Strip the license check from the APK | Minify on; no integrity check | Server-side enforcement is authoritative (connection refused without valid license); consider Play Integrity-equivalent unavailable → keep server authoritative; detect obvious tampering as UX-only signal |
| 8 | Replayed API requests | MEDIUM | Replay `payment/verify` | N/A | Idempotency keys, nonce+timestamp, `gateway_ref` uniqueness |
| 9 | Unauthorized subscription extension | MEDIUM | Manipulate cached license blob | N/A | Blob signed by server key (public key embedded via `buildConfigField`); client cannot forge |
| 10 | Backend compromise (WP) | MEDIUM | Deface endpoints, dump users | Stock WP hardening | Least-privilege DB user, wp-admin 2FA, WAF/rate-limit, keep plugins minimal, audit `eligius_events` |
| 11 | Distinguish identification vs authentication | — | — | — | **Do not use IMEI/serial** (unavailable to normal apps since API 26+ and restricted by policy). Identification = fingerprint hints (ANDROID_ID, model); **authentication = Keystore keypair challenge-response**. The design above uses both, with authentication doing the security work. |

---

## K. Testing & Quality

**Current coverage: 0%.** `find app/src -type d -name test -o -name androidTest` → empty. No unit, no integration, no UI tests. CI has no test step.

**Minimum required before production (in build order):**
1. **Unit** — license state machine (ACTIVE/EXPIRED/REVOKED transitions, grace window math with signed blobs), national-code validator parity with server, OTP input sanitization, JWT exp parsing.
2. **Unit** — payment activation idempotency logic (mirror of server rules).
3. **Integration (API)** — every §I endpoint: happy path + wrong-code + expired + revoked + throttled + unbound-device (curl scripts already exist as precedent from the otp e2e testing).
4. **Instrumented** — `startSession()` refuses with invalid/absent license (the enforcement point); BootReceiver/QsTile gates.
5. **Security scenarios** — expired license mid-session (server revokes while tunnel is UP: monitor loop or periodic revalidation must disconnect — decision §L), cloned prefs onto second device (binding must reject), clock rolled back (server clock wins).
6. **Manual regression** — the existing 12-step phone checklist (login, register OTP, forgot, connect, usage) after each phase.

---

## L. UI/UX Placement (no redesign — audit only)

- **Account tab** (`OtherScreens.kt:136 AccountScreen`): subscription status card directly under the profile header (plan, days remaining, progress ring in the existing purple gradient language), tap → **Plans sheet** (four plans as cards using existing `AppCard` + `RoundedCornerShape(16.dp)` button style), device management list (bound device label + "transfer" action), payment history.
- **Home screen** (`HomeScreen.kt`): expiry banner above the connection card when ≤ 7 days remain (reuses `Engine.lastReason` display channel).
- **Connection gate**: expired → existing locked-dialog pattern (`ConnectionScreen.kt` locked tap → AlertDialog) extended with "Renew" button.
- **Navigation**: no new bottom-bar tabs; plans/purchase live inside Account (matches existing 5-slot bottom bar with floating Connect).
- Persian-first copy in the established bilingual string convention (`values/` + `values-fa/`).

---

## L. Decisions Required (owner approval)

1. **Backend substrate**: confirm Option A (WordPress custom plugin + custom tables) vs. a separate service.
2. **Payment gateway**: which Iranian gateway(s) — ZarinPal / IDPay / Zibal / direct PSP — and whether wallet/coin options are in scope.
3. **Login model**: keep password login (current) or switch to OTP-primary login (spec wording suggests OTP auth; affects `AuthScreen` rework).
4. **Offline grace window**: 72 h recommended — confirm or adjust.
5. **Mid-session revocation**: disconnect an active tunnel immediately on revocation (recommended) or at next connect?
6. **Trial binding**: bind trial to device only (reinstall-safe but machine-bound) vs. account-only (portable but resettable) — device-keyed recommended.
7. **Admin panel**: stock wp-admin custom pages sufficient, or a separate standalone web panel (independent of WP login)?

## M. Implementation Roadmap (phases, dependencies, acceptance)

| Phase | Contents | Depends on | Acceptance criteria |
|---|---|---|---|
| **P1 — Licensing core (server)** | 4 tables, license CRUD, trial grant, `license/status` + signature blob, admin pages in wp-admin, audit events | §L-1, §L-6 | curl suite: grant/expire/revoke/unbound-device all correct; signed blob verifies |
| **P2 — Device binding** | `DeviceIdentity` (Keystore keypair), `/device/*` endpoints, registration on login, one-device enforcement | P1 | Second device login → 409 + transfer path; validation challenge signs correctly |
| **P3 — App enforcement** | `LicenseStore`, `startSession()` gate, BootReceiver/QsTile/toggleConnect gates, expiry UI, offline grace | P1, P2 | Instrumented test: connect refused without license; connects with valid; expires mid-session per §L-5 decision |
| **P4 — Payments** | Gateway integration, `payment/init`+`verify` (S2S), activation, payment history UI | P1 | Real (sandbox) payment: INITIATED→VERIFIED→license ACTIVE; forged callback rejected; duplicate `gateway_ref` no-op |
| **P5 — Trials** | 30-day device-keyed trial, trial UX, anti-reset | P2 | Reinstall does not re-grant; second device does not re-grant |
| **P6 — Hardening + tests** | §J mitigations 1-6, unit+instrumented suites, audit log viewer, rate limits | P1-P5 | All critical tests green; security checklist signed off |
| **P7 — OTP login (optional)** | If §L-3 = OTP-primary: login-start/verify + UI | P1 | OTP login replaces password path (or coexists) |

**Do first:** P1 (server licensing core) — it unblocks everything and requires zero app changes.
**Do not touch:** `CoreProcess.kt`, `ArgBuilder.kt`, `SingBox.kt`, `Tun2Socks.kt`, native build jobs, the Aether checkout/pinning in CI, and the existing registration/OTP/forgot flows beyond the additive device-registration hook.

---

## M. Final Recommendation

Eligius today is a **solid, production-proven VPN client with real accounts** — roughly a fifth of the way to a subscription product. The single highest-leverage first step is the **server-side licensing core (P1)** inside the existing WordPress installation, because: (a) the deployment, DB, auth, rate-limiting and admin shell already exist there; (b) it requires no app release; (c) every later phase consumes it. In parallel, only two app-side changes are needed before any of it matters: a **license gate inside `TunnelService.startSession()`** (the one true funnel) and **Keystore-backed device identity** — both additive, neither touching the VPN core. Everything payment-related must be designed **server-authoritative from day one**: no client-side expiry math, no client-trusted callbacks, no plaintext license blobs without signature. The existing OTP/SMS/registration machinery, the usage accounting, and the gate architecture are the assets to reuse; the VPN core, the native build pipeline, and the current registration flows are the things to leave untouched.
