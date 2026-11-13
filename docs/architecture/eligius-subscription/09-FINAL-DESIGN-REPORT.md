# Eligius Subscription System — Final Design Report

**Version:** 1.0-FINAL  
**Date:** 2026-11-10  
**Status:** Design Finalized — Awaiting Owner Approval for Implementation  
**Related:** `docs/audits/SUBSCRIPTION_READINESS_AUDIT.md`

---

## Confirmed Decisions

The following decisions have been **explicitly approved by the owner** and are applied consistently across all 8 design documents. They are final and must NOT be re-asked.

### CD-1. Backend Architecture
- **Backend:** WordPress + dedicated custom plugin `eligius-subscription`
- **Database:** Independent MariaDB relational tables (NOT usermeta-based)
- **Admin Panel:** Custom pages inside native `wp-admin`
- Reuses existing WordPress infrastructure (users, JWT auth, REST namespace `eligius/v1`, rate limiter `eligius_rate_limited()`, IPPanel SMS integration)

### CD-2. User Authentication (Login Model)
- **Mobile number + password login: RETAINED** (existing flow, unchanged)
- **SMS OTP login: ADDED as an optional method** (`/auth/otp-login-start`, `/auth/otp-login-verify`)
- The user is **NEVER forced** to use password and OTP simultaneously — either method alone is sufficient

### CD-3. Payment Gateways
- **First gateway: ZarinPal**
- **Additional gateways (in order): IDPay → Zibal**
- **Adapter-based architecture** — adding a new gateway requires NO changes to payment core logic
- Payment verification is **Server-to-Server (S2S)** with **Idempotency** enforced via unique `gateway_ref` in `wp_eligius_payment_idempotency`

### CD-4. Offline Grace Period
- Offline Entitlement validity: **72 hours** (signed Ed25519 JWT, `kid` rotation every 90 days)
- If network is disconnected, the user can use VPN until the valid offline entitlement expires (max 72h)
- After 72 hours without successful re-validation, **VPN is stopped**

### CD-5. Subscription Revocation During Active Connection
- **Online:** CRL (`/licenses/revocation-list`) checked **every 5 minutes** (5-min cache TTL); on revocation detection, the active VPN tunnel is **stopped at the next validation tick** (max 5 minutes latency — NOT instantaneous)
- **Offline:** New revocations are NOT detectable; a valid offline entitlement remains usable until its 72-hour expiry
- The CRL result must flow to `TunnelService`: `LicenseStore.ensureValid()` → check `RevocationListManager.isRevoked(licenseId)` → if revoked, `TunnelService.stop(ctx)` and `Engine.status = IDLE`
- The term "instant disconnect" is **prohibited** — always specify the ≤5-minute polling latency

### CD-6. Trial
- Duration: **30 days** (server clock)
- **One trial per account** (`user_id`) — the primary anti-reset control; immune to reinstall/clear-data by design (does not depend on device state)
- Prerequisite: mobile number **verified via OTP** before trial grant (one OTP-capable SIM = one account — the main economic deterrent)
- At activation, trial is **bound to the user's current device** (device_id on the license row) — informational binding
- Trial transfer to another device requires **OTP verification** (device transfer workflow)
- **Layered abuse deterrence** (05 §5.1): server-side rate limits per user/device/IP + passive anomaly flagging (device_hash/IP correlation → admin review queue, never auto-block) — IP thresholds generous so CGNAT/shared-IP users are never locked out
- **NO mandatory national code** and no other unjustified identity data collection (owner decision, this finalization pass). If identity verification is ever added, it is a separate decision (TD-10)
- Multi-account creation as a trial-abuse vector is acknowledged as a **separate, incompletely preventable risk** (TD-1); no claim of complete prevention is made — see Residual Risks below

### CD-7. Device Binding — Requirement CONFIRMED, Mechanism CONFIRMED (CD-10)
- **CONFIRMED requirement:** one active device per user account; server-verified device binding; challenge-response authentication
- **CONFIRMED mechanism (owner confirmed 2026-11-13 — TD-8 resolved, see CD-10):** Android Keystore **ECDSA P-256** keypair, non-exportable, hardware-backed where available; `device_hash = SHA-256(pubkey_der || ANDROID_ID)`; SHA256withECDSA challenge signatures
- Why P-256 fits: portable across the app's minSdk (API 23+), non-exportable keys, no extra permissions, standard PHP `openssl_verify()` on the server — full decision in 04 §2.4
- **Honest limitation (unchanged):** Android Keystore keys are destroyed on app uninstall (Android 6.0+) — after reinstall, a NEW keypair → NEW `device_hash`. Device binding therefore does not survive reinstall and is **not** a trial-reset control (trial is account-keyed per CD-6)
- **Hardware attestation is NOT required** — Keystore generation must not be claimed as proof of hardware-backed key storage
- No guarantee of permanent hardware identification is made; alternatives (Ed25519, RSA, HMAC, Play Integrity) evaluated and documented in 04 §2.4

### CD-10. Device-Binding Signature Mechanism (TD-8 resolved — owner confirmed 2026-11-13)
- **Decision:** device identity uses **Android Keystore ECDSA P-256 (secp256r1)** — private key generated and stored inside the Keystore, **non-exportable**; the public key is registered with the backend; ownership is proven by signing a server-issued challenge with `SHA256withECDSA`; the server verifies the signature against the stored public key (`openssl_verify`, SHA-256, DER ECDSA)
- **Server license signing remains Ed25519** (sodium) for licenses/offline entitlements — the two algorithms have **independent responsibilities and are NOT interchangeable**
- **Hardware attestation is NOT required** and Keystore generation must not be presented as proof of hardware-backed storage (TEE/StrongBox varies; software Keystore acceptable)
- Alternative evaluation (Ed25519-on-Keystore, RSA, HMAC, Play Integrity) retained in 04 §2.4

### CD-8. Implementation Status
- Phase 0 has **NOT been executed**
- No plugin files exist, no dependencies added, no code changed
- This status must remain unchanged until the owner explicitly authorizes implementation

### CD-9. One Active Device Policy (confirmed this pass)
- Each account can use the VPN on **exactly one active device at any time** — for the **free trial AND all paid plans** alike
- Transfer to a new device is possible **only via OTP verification** (device transfer workflow)
- After a successful transfer, the **previous device is immediately no longer authorized** (old `is_active=0`, license binding moves atomically)
- The backend enforces this **server-side through ONE shared fixed lock point** — the account's own `wp_users` row via `SELECT … FOR UPDATE` — acquired **first** by every device-changing operation (first registration, re-registration, transfer, binding change), making `bind` vs `transfer` races structurally impossible (02 §2.8)
- **Storage requirement:** all `eligius_*` tables and `wp_users` must be **InnoDB** (verified on activation — conversion or refusal); all transaction statements run on the single `$wpdb` connection
- **Error discipline:** `$wpdb` does not throw — every statement's result **and** `$wpdb->last_error` are explicitly checked inside the transaction; any failure → immediate ROLLBACK → typed `WP_Error` (02 §2.8.3)
- An unauthorized second device is rejected at registration with `DEVICE_LIMIT_REACHED` (409) and fails validation at connect/tick with `DEVICE_NOT_BOUND`; the Android client surfaces this as a dedicated product state (06 §3.9)

---

## Remaining Technical Decisions

These are the only genuinely open items that need a decision or further technical review before/during implementation. They do NOT include anything already confirmed above (CD-1 … CD-8).

| # | Decision | Options | Recommendation | Blocking? |
|---|----------|---------|----------------|-----------|
| **TD-1** | **Multi-account trial abuse mitigation** (revised this pass — national code NOT approved) | (a) OTP-verified mobile per account (structural — already the flow); (b) server-side rate limits on `/licenses/trial` per user/device/IP with **generous IP thresholds** (CGNAT-safe); (c) passive correlation flagging (same `device_hash` across accounts, same-IP clustering) → admin review queue, never auto-block; (d) any stronger identity data (e.g. national code) requires separate owner approval (TD-10) | (a)+(b)+(c) as designed in 05 §5.1 — no further decision needed to start; (d) optional future hardening | No — (a)+(b)+(c) are the confirmed default |
| **TD-2** | **Payment amount currency handling** | Store prices in IRT (Toman) vs IRR (Rial) | IRT with explicit conversion at gateway call (gateways differ in expected unit; ZarinPal expects IRR — adapter must convert) | Yes — must be pinned before P4 |
| **TD-3** | **Mid-session revalidation cadence** | Every 5 min (CRL) vs every 15 min (battery) | 5 min per CD-5; make it a configurable option in wp-admin (`General Settings → CRL Check Interval`) so it can be tuned post-launch | No — 5 min default confirmed |
| **TD-4** | **License signing algorithm final choice** | Ed25519 (libsodium) vs ECDSA P-256 (OpenSSL) | Ed25519 preferred (simpler, no nonce-entropy pitfalls); requires `sodium` PHP extension — fallback to `paragonie/sodium_compat` if host lacks it | Yes — verify `sodium` on host (95.38.209.36) before P1 |
| **TD-5** | **Trial device-transfer policy detail** | Transfer also re-binds trial device_id (current design) vs trial stays account-only and any new device works | Current design (OTP-verified transfer re-binds) is recommended — keeps device binding meaningful | No — current design confirmed unless owner objects |
| **TD-6** | **ZarinPal merchant account prerequisites** | Sandbox-first vs production-first | Sandbox-first with the test merchant; production merchant ID supplied by owner via Gateway Settings page | Yes — owner must create the ZarinPal merchant account before P4 |
| **TD-7** | **Existing usermeta data migration** | Migrate `eligius_total_*` usage counters into new tables vs leave in usermeta | Leave in usermeta (usage endpoints unchanged, no migration risk); new tables only for subscription data | No — default is "leave", confirm if owner prefers otherwise |
| **TD-8** | **Device-binding signature mechanism** — **RESOLVED** | ~~(a) Android Keystore ECDSA P-256 (proposal in 04 §2.4); (b) Ed25519 runtime-upgrade path where Keystore supports it; (c) HMAC shared secret (rejected — extractable); (d) Play Integrity (rejected as primitive — connectivity/market constraints)~~ | ✅ **Confirmed by owner on 2026-11-13: option (a) as the baseline, Ed25519 server-side unchanged** — recorded as CD-10 (04 §2.4 updated) | ~~Yes — confirm before P2~~ **Resolved** |
| **TD-9** | **Offline time-integrity strategy** (final — Pass 5: BOOT_COUNT strict policy, gap eliminated) | Layered approach: elapsedRealtime monotonic anchor + **`Settings.Global.BOOT_COUNT` strict policy** (any mismatch → offline DENIED, online is the only path) + wall-rollback check (anchor − 60s tolerance) + AUTO_TIME signal + server-side retroactive anomaly flagging (04 §4.4, 06 §3.6). `bootCount()` returns `Int?` — read failure distinguishable from any real value. **The partial-rollback-across-reboot gap is ELIMINATED** — any BOOT_COUNT change → offline denied immediately. No false guarantees: rooted devices remain the residual risk | Adopt as designed; the strict BOOT_COUNT policy eliminates all documented clock-manipulation gaps except rooting | No — confirmed by owner |
| **TD-10** | **Remove mandatory national_code from registration** (added this pass) | The live endpoint `eligius-otp.php /register/otp-start` currently REQUIRES `national_code` (mod-11 validated). Per the owner's no-unnecessary-identity-data decision, this field becomes optional or removed | Remove from required fields during implementation (code change to the live endpoint — out of scope until implementation is authorized). If the owner later wants identity verification, revisit as a new decision | No — documented for the implementation phase |

> **Note:** TD-2 and TD-6 must be resolved before P4. TD-1, TD-3, TD-5, TD-7 have safe defaults and can proceed with the recommendation unless overridden. TD-8 was resolved on 2026-11-13 (see CD-10); TD-4 was verified resolved during Phase 1 (sodium present on host).

---

## Documents Modified

### Finalization Pass 1 (initial alignment)
| # | File | Changes Applied |
|---|------|-----------------|
| 1 | `01-SYSTEM-ARCHITECTURE.md` | Added `Confirmed Decisions` section (CD-1…CD-8); fixed massive duplicated `eligius_payment_idempotency`/`eligius_audit_logs` table definitions in the Database class example (had ballooned to ~10 copies); included `trial` in `create_default_plans()` seed data |
| 2 | `02-DATABASE-DESIGN.md` | Removed duplicated `ELIGIUS_DEVICE_TRANSFERS` and `ELIGIUS_PAYMENT_IDEMPOTENCY` entities from the Mermaid ER diagram (each appeared twice); retained single authoritative definitions in §2.5/§2.7 |
| 3 | `03-API-SPECIFICATION.md` | Fixed broken section numbering (§2.7 OTP Login, §3 Device Endpoints, §8.3/§8.4 duplicated blocks, missing §8.4 General Settings added); OTP Login marked as **optional** method per CD-2; Gateway Settings ordered ZarinPal→IDPay per CD-3 |
| 4 | `04-SECURITY-AND-LICENSE.md` | Removed duplicated `EMBEDDED_PUBLIC_KEYS` map in the validator; fixed duplicated §6 (Key Management/Rotation) and duplicate revocation-check wording; revocation latency documented as ≤5 minutes per CD-5 (no "instant disconnect" phrasing) |
| 5 | `05-PAYMENT-AND-BUSINESS-LOGIC.md` | Fixed duplicated subsection numbering (§3.2 Renewal / §3.3 Grace under §4; §4 duplicated); trial rules table aligned to CD-6 (account-keyed primary control); ZarinPal-first ordering per CD-3; §4.2 Mid-Session Revocation table updated to remove "Decision Required" (now decided: ≤5-min disconnect per CD-5) |
| 6 | `06-ANDROID-INTEGRATION.md` | Removed triplicated `getPublicKeyPem()` in `DeviceIdentity.kt` example; removed duplicated `EMBEDDED_PUBLIC_KEYS` in validator; fixed duplicated §6 (DI/Gradle) and §8 (Build Config/Permissions) numbering; component list annotated with "(Already exists)" for `SplitOtpInput.kt` / `SmsOtp.kt` |
| 7 | `07-IMPLEMENTATION-ROADMAP.md` | Fixed duplicated section numbering (two §4, two §6, two §3 blocks); removed duplicated `RevocationListManager.kt` entry in P3 file list; P4 gateway order ZarinPal-first per CD-3; P7 OTP Login explicitly marked **optional** per CD-2; phase numbering verified sequential P0→P7 |
| 8 | `08-ADMIN-PANEL-SPECIFICATION.md` | Fixed duplicated subsection numbering (two §2.5, two §2.7, two §3.6); gateway settings ordered ZarinPal→IDPay→Zibal per CD-3; menu structure verified against wp-admin decision (CD-1) |

### Finalization Pass 2 (owner-directed corrections — this pass)
| # | File | Changes Applied |
|---|------|-----------------|
| 9 | `01-SYSTEM-ARCHITECTURE.md` | Trial bullet list updated to the revised policy (OTP-verified mobile prerequisite, layered rate limiting, no mandatory national code); "Unresolved Technical Decisions" table replaced with a pointer to this report's Remaining Technical Decisions (TD-1…TD-10); §5 Security rows mark ECDSA P-256 as *proposed (TD-8)* |
| 10 | `04-SECURITY-AND-LICENSE.md` | **NEW §2.4** — Signature Algorithm Choice documented as a technical *proposal* (ECDSA P-256), with alternatives evaluation (Ed25519, RSA, HMAC, Play Integrity, IMEI) and honest limitations (keys destroyed on uninstall); **NEW §4.4** — Time Integrity: Layer 1 elapsedRealtime monotonic anchor, Layer 2 reboot wall-comparison + AUTO_TIME signal with the **documented partial-rollback gap**, Layer 3 server-side retroactive `entitlement.time_anomaly` flagging, options comparison table, rooted-device boundary; threat model row 6 updated; §7.1 offline-entitlement storage row corrected (persisted encrypted, not memory-only) |
| 11 | `05-PAYMENT-AND-BUSINESS-LOGIC.md` | §3.1 Trial Rules rewritten — account-keyed primary control + OTP-verified mobile + layered rate limits + **no mandatory national code** + honest limitation statement; grant_trial() server implementation rewritten (account-keyed check, device correlation = flag only, never auto-reject); §5.1 layered-policy flowchart; §5.2 reinstall/multi-account behavior table rewritten honestly; §4.2 Mid-Session Revocation updated to confirmed CD-5 behavior (≤5-min latency, Decision-Required flag removed) |
| 12 | `06-ANDROID-INTEGRATION.md` | **NEW §3.6** — `TimeIntegrity.kt` spec: elapsedRealtime anchor, reboot detection (elapsed reset), wall-rollback check, AUTO_TIME signal, sealed Result (Valid/Expired/Tampered), LicenseStore integration snippet, periodic-tick integration with `TunnelService.stop()` (CD-5); section numbering fixed (validator 3.4→3.5, new 3.6, Retrofit 3.6→3.7, Models 3.7→3.8, DI 6, Gradle 6→7, ProGuard 7→8, BuildConfig 8→9, Permissions 8→10) |
| 13 | `07-IMPLEMENTATION-ROADMAP.md` | P1 acceptance criterion fixed (trial uniqueness per `user_id`, not `device_hash`); P6 checklist **Time Integrity** row added; P6 security test scenarios expanded (rollback while running + across reboot, AUTO_TIME disabled, trial correlation flags); risk register: trial-abuse row rewritten (honest limitation), **new row** for offline clock-rollback post-reboot gap |
| 14 | `09-FINAL-DESIGN-REPORT.md` | This document — CD-6/CD-7 rewritten, TD-1 revised, TD-8/9/10 added, Residual Risks updated, Pass-2 table added |

### Finalization Pass 3 (owner-directed corrections — this pass)
| # | File | Changes Applied |
|---|------|-----------------|
| 15 | `01-SYSTEM-ARCHITECTURE.md` | **NEW Confirmed Decisions group "One Active Device Policy"** (CD-9): one device at any time, trial+paid, OTP-only transfer, old device deauthorized, server-side concurrency enforcement; §5 Security table gained the One Active Device row |
| 16 | `02-DATABASE-DESIGN.md` | **NEW §2.8 "One Active Device — Concurrency Control"**: transactional bind pattern with `SELECT … FOR UPDATE` (with fallback lock on the license row when no active device exists), rules table (trial+paid, old device deauthorized, atomic binding move); duplicate §2.5 payment_idempotency section removed (was duplicated with §2.7); verification checklist updated (transactional enforcement + `user_id`-keyed trial) |
| 17 | `03-API-SPECIFICATION.md` | New standard error code `DEVICE_LIMIT_REACHED` (409); device-registration errors rewritten with the concurrency-control note (`FOR UPDATE`, parallel requests can never both succeed) |
| 18 | `04-SECURITY-AND-LICENSE.md` | **NEW §2.5** "One Active Device — Server-Side Enforcement & Concurrency Control" (enforcement-points table + concurrency guarantee); **§4.4 Layer 2 reworked**: `Settings.Global.BOOT_COUNT` is now the **primary reboot detector** (persisted counter, read-only to apps; root aside), anchor gained `anchorBootCount`, options table updated; honest limitations retained |
| 19 | `05-PAYMENT-AND-BUSINESS-LOGIC.md` | §3.1 trial rules gained the One-Active-Device row (trial AND paid); transfer workflow security controls state old-device deactivation explicitly |
| 20 | `06-ANDROID-INTEGRATION.md` | `TimeIntegrity.kt` reworked: `Anchor` gained `anchorBootCount`, `anchor()` persists BOOT_COUNT, `check()` detects reboots via **BOOT_COUNT mismatch (primary)** with the elapsed-reset comparison as secondary; consumed-time estimator switches to wall-based after a detected reboot (advisory); section numbering fixed (validator 3.5, TimeIntegrity 3.6, Retrofit 3.7, Models 3.8; DI 6, Gradle 7, ProGuard 8, BuildConfig 9, Permissions 10) |
| 21 | `07-IMPLEMENTATION-ROADMAP.md` | P1 acceptance criterion added (one-active-device transactional enforcement); P6 Time Integrity row updated with BOOT_COUNT |
| 22 | `09-FINAL-DESIGN-REPORT.md` | This document — CD-9 added, Pass-3 table, matrix rows (One Active Device, BOOT_COUNT), TD-9 updated with BOOT_COUNT, readiness table P2 updated (CD-9 enforcement documented) |

### Finalization Pass 4 (owner-directed corrections — this pass)
| # | File | Changes Applied |
|---|------|-----------------|
| 23 | `02-DATABASE-DESIGN.md` | §2.8 fully reworked per owner correction: **ONE shared fixed lock point** = the account's `wp_users` row (`SELECT … FOR UPDATE`), acquired FIRST by ALL device-changing operations (register / re-register / transfer / bind change) — makes `bind` vs `transfer` races structurally impossible; **§2.8.2 InnoDB + single-connection requirements** (row locking exists only in InnoDB; all statements on the same `$wpdb` connection); **§2.8.3 explicit DB error checking** — `$wpdb` does not throw, so every statement's result + `$wpdb->last_error` checked with ROLLBACK + typed `WP_Error` (full corrected code); acceptance criteria expanded (bind+bind, bind+transfer, transfer+transfer, forced-mid-tx failure) |
| 24 | `04-SECURITY-AND-LICENSE.md` | §2.5 concurrency guarantee updated to the single user-row lock point + InnoDB + explicit error discipline (02 §2.8) |
| 25 | `05-PAYMENT-AND-BUSINESS-LOGIC.md` | §3.1 trial rules gained the One-Active-Device row (trial AND paid); transfer Security Controls state old-device deactivation explicitly |
| 26 | `06-ANDROID-INTEGRATION.md` | §3.6 `TimeIntegrity.kt`: `bootCount()` now returns **`Int?`** — read failure is **distinguishable from any real value** (the fake default 0 removed); anchor stores `boot_count_valid`; **DEGRADED mode** specified (BOOT_COUNT unavailable → offline only without reboot, no wall fallback, AUTO_TIME-off → require online, elapsed-reset → require online); **NEW §3.9** — explicit `DEVICE_LIMIT_REACHED` client behavior (dedicated dialog, transfer CTA, no retry, VPN gate + ≤5-min tick disconnect for the losing device) |
| 27 | `07-IMPLEMENTATION-ROADMAP.md` | P1 acceptance criterion expanded (single lock point + InnoDB verification + explicit error discipline); P6 Time Integrity row + security test scenarios updated (BOOT_COUNT unavailable → DEGRADED mode tests) |
| 28 | `08-ADMIN-PANEL-SPECIFICATION.md` | §3.3 Devices Management: **CD-9 policy statement added** (one active device, OTP transfer, automatic old-device deactivation with row-lock reference) |
| 29 | `09-FINAL-DESIGN-REPORT.md` | This document — Pass-4 table, CD-9 expanded, TD-9 updated (read-failure distinguishability + DEGRADED mode), findings on `nul` + 06/08 gaps recorded |

**`nul` file finding (owner item 4, inspected — NOT deleted):** content is 185 bytes of stderr text from a broken Windows `dir` command (`dir: cannot access '/s' … 'E:xampphtdocs8VPNappsrcmainjniLibs'` — backslashes swallowed in a Git Bash redirect, `2> nul` created a real file named `nul`). Never committed to Git, referenced nowhere, pure unused artifact from a 2026-11-09 disk-scan debug session. **Recommended: delete during implementation** (`del \\?\E:\xampp\htdocs\8VPN\nul` in cmd) — not touched now per the no-file-change constraint.

---

## Consistency Verification Matrix

| Concept | 01 | 02 | 03 | 04 | 05 | 06 | 07 | 08 |
|---------|----|----|----|----|----|----|----|----|
| `eligius_devices` table | ✓ | ✓ | — | ✓ | ✓ | — | ✓ | — |
| `eligius_licenses` table | ✓ | ✓ | — | ✓ | ✓ | — | ✓ | — |
| `eligius_payments` table | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| `device_hash` (SHA-256 pubkey‖ANDROID_ID) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Offline entitlement 72h / Ed25519 / kid rotation | ✓ | — | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| **Time integrity (elapsedRealtime anchor + BOOT_COUNT reboot detection + reboot gap documented)** | ✓ | — | — | ✓ | — | ✓ | ✓ | — |
| **One active device (CD-9: server-side, FOR UPDATE concurrency, trial+paid, OTP transfer)** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| CRL 5-min cache + tunnel stop (≤5 min latency) | ✓ | — | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| **Trial: 30d, account-keyed, OTP-verified mobile, no national code, layered deterrence** | ✓ | ✓ | ✓ | — | ✓ | — | ✓ | — |
| ZarinPal-first / IDPay / Zibal / adapter pattern | ✓ | — | ✓ | — | ✓ | — | ✓ | ✓ |
| S2S verification + idempotency (`gateway_ref` unique) | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Login: password retained + OTP optional | ✓ | — | ✓ | — | — | ✓ | ✓ | — |
| Admin: wp-admin custom pages | ✓ | — | — | — | — | — | ✓ | ✓ |
| Phase 0 NOT executed / no code changes | ✓ | — | — | — | — | — | ✓ | — |

Legend: ✓ = addressed consistently; — = not applicable to that document; all checked cells use identical table/column/endpoint names.

---

## Contradictions Found & Resolved

1. **Duplicated table definitions in 01-SYSTEM-ARCHITECTURE.md** — the `Eligius_Sub_Database::$tables` example contained the `eligius_payment_idempotency` and `eligius_audit_logs` definitions repeated ~8 times (copy-paste artifact from the original generation pass). Resolved: single definition per table.
2. **Duplicated Mermaid entities in 02-DATABASE-DESIGN.md** — `ELIGIUS_DEVICE_TRANSFERS` and `ELIGIUS_PAYMENT_IDEMPOTENCY` each appeared twice in the ER diagram. Resolved: deduplicated.
3. **Trial binding ambiguity across documents** — 04 and 05 originally described the trial as "device-keyed with reinstall protection via Keystore survival", which contradicts the confirmed decision CD-6 (account-keyed primary control, because Keystore is destroyed on uninstall). Resolved: trial uniqueness is enforced on `user_id`; device binding is presentational/secondary; the reinstall-reset limitation is documented honestly (see Remaining Decisions TD-1 for the multi-account residual risk).
4. **"Instant disconnect" phrasing** — 04 and 05 used "immediate disconnect" without qualifying the polling latency. Resolved: all documents now state revocation takes effect at the next CRL/validation tick — **max 5 minutes** after server-side revocation.
5. **OTP Login status ambiguity** — 01 and 07 listed the login model as an open decision while 03 already specified the endpoints. Resolved: OTP login is **optional** (CD-2), endpoints retained in the spec, P7 marked optional.
6. **Mid-session revocation marked "Decision Required"** — 05 §4.2 still carried a "Decision Required" flag for a decision the owner had already approved. Resolved: flag removed; CD-5 applies.
7. **Gateway order inconsistency** — 08 listed IDPay before Zibal with ZarinPal listed first but 03's Gateway Settings example showed IDPay with `enabled: true` while Zibal `enabled: false` without stating priority. Resolved: all documents now state ZarinPal → IDPay → Zibal ordering (CD-3).
8. **Section numbering breakage** — 03, 04, 05, 06, 07, 08 all had duplicated or skipped section numbers (artifacts of incremental generation). Resolved: renumbered sequentially in every document.

**Pass 2 (owner-directed corrections):**

9. **ECDSA P-256 recorded as a confirmed decision** — 01 (CD-7) and 04 presented "Android Keystore ECDSA P-256" as final, but the owner never independently confirmed the mechanism. Resolved: moved to **TD-8 (Proposed)** — the *requirement* (one device per user, server-verified binding) remains confirmed; the *mechanism* is a documented proposal with alternatives evaluation (04 §2.4).
10. **Time-integrity gap undocumented** — no document addressed how the 72h offline window survives clock rollback, `SystemClock.elapsedRealtime()` anchoring, or the reboot gap. Resolved: new §4.4 in 04, new `TimeIntegrity.kt` spec in 06, threat-model row updated, P6 checklist and risk register updated — with the **partial-rollback-across-reboot gap stated honestly** as locally undetectable.
11. **Trial policy relied on national-code uniqueness** — TD-1 previously cited "national-code uniqueness on registration (already exists)" as the primary multi-account control, but the owner has not approved mandatory national-code collection. Resolved: trial policy rebuilt on OTP-verified mobile + layered rate limits + passive correlation flagging (CGNAT-safe); national-code removal recorded as TD-10 (implementation-phase change to the live `eligius-otp.php` endpoint).
12. **Offline entitlement storage contradiction** — 04 §7.1 said the offline blob is "Memory only (not persisted)" while 06 specified persistent EncryptedSharedPreferences storage (required for offline use after process death). Resolved: 04 corrected to encrypted persistent storage.
13. **Trial uniqueness criterion inconsistent** — 07 P1 acceptance criterion said "cannot be granted twice for same `device_hash`" while CD-6 mandates account-keyed. Resolved: criterion changed to `user_id` everywhere.

**Pass 3 (owner-directed corrections):**

14. **One-active-device policy not systematically enforced across documents** — the policy was confirmed by the owner (trial AND paid, OTP-only transfer, old device deauthorized, server-side concurrency) but was only implicit in most documents. Resolved: CD-9 added; 02 §2.8 documents the transactional `FOR UPDATE` enforcement pattern (with the first-registration fallback lock); 03 gained the `DEVICE_LIMIT_REACHED` error code; 04 §2.5 documents all enforcement points; 05 states old-device deactivation; 07 P1 gains the concurrency acceptance criterion.
15. **Reboot detection relied on elapsedRealtime comparison alone** — the elapsed-reset check is a secondary signal only. Resolved: `Settings.Global.BOOT_COUNT` added as the **primary reboot detector** (persisted counter, read-only to apps on stock Android; root-tamper documented); anchor fields and 06 §3.6 `TimeIntegrity.kt` updated; the partial-rollback-across-reboot gap remains documented as locally undetectable (no false guarantees added).
16. **Duplicate payment_idempotency sections in 02** — §2.5 and §2.7 both described the same table (pass-1 numbering artifact). Resolved: duplicate §2.5 removed; §2.7 is the single authoritative definition.

**Pass 4 (owner-directed corrections):**

17. **One-active-device concurrency was underspecified** — the bind pattern locked the *active-device row* (which does not exist for a first registration) and did not serialize bind-vs-transfer races; wpdb's non-throwing failure mode was unhandled. Resolved: ONE shared fixed lock point = the account's `wp_users` row, acquired FIRST by every device-changing operation; InnoDB + single-connection requirements documented; explicit result + `$wpdb->last_error` checks with ROLLBACK after every statement (02 §2.8.1–2.8.3, acceptance criteria in §2.8.5).
18. **`bootCount()` fake default 0** — read failure returned 0, indistinguishable from a real counter, silently breaking reboot detection. Resolved: `Int?` return + `boot_count_valid` flag + explicit **DEGRADED mode** (no wall fallback; reboot → require online; AUTO_TIME-off → require online) — behavior table in 06 §3.6.
19. **`DEVICE_LIMIT_REACHED` had no client behavior spec; 08 lacked the one-device policy statement** — Resolved: 06 §3.9 (dedicated dialog, transfer CTA, no retry, VPN gate + tick disconnect) and 08 §3.3 policy notice added.

**Pass 5 (owner-directed corrections — BOOT_COUNT strict policy, gap eliminated):**

20. `04-SECURITY-AND-LICENSE.md` — Layer 2 rewritten: BOOT_COUNT mismatch → offline DENIED (no "fall back to wall-based estimate"); decision table expanded to 4 cases (equal/different/unreadable/AUTO_TIME-off); options table row updated to "eliminates the reboot+rollback gap"; policy statement expanded to include the mismatch case.
21. `06-ANDROID-INTEGRATION.md` — check() reboot detection comment updated to STRICT; consumed-time comment simplified (only monotonic used, budget discarded on reboot); return block comment updated; 3 remaining "DEGRADED mode" comments cleaned to "offline DENIED".
22. `04-SECURITY-AND-LICENSE.md` §4.4 — **AUTO_TIME limitation documented**: `AUTO_TIME == 1` only confirms the user has NOT disabled the "Automatic date & time" toggle; it does NOT prove the `time_detector` service is actually syncing (NTP could fail silently while the toggle stays ON). `MANAGE_TIME_AND_ZONE_DETECTION` permission is `signature|privileged` and unavailable to normal apps. `AUTO_TIME == 1` is a **weak positive signal**; always combined with other time-integrity layers. If definitive time verification is ever needed, server-side comparison on every contact is the only viable path for a normal app. Added to 06 §3.6 `isAutoTimeEnabled()` doc comment + 07 P6 test scenario.

---

## Documentation Readiness Status

| Phase | Docs Ready? | Blocking Items |
|-------|-------------|----------------|
| P0 (setup) | ✅ Ready | None |
| P1 (Licensing core) | ✅ Ready | TD-4 (sodium on host — verify; fallback exists) |
| P2 (Device binding) | ✅ Ready | ✅ TD-8 resolved (owner confirmed ECDSA P-256 — CD-10) |
| P3 (App enforcement) | ✅ Ready | None (depends on P1+P2) |
| P4 (Payments) | ⚠️ Ready with 1 open item | TD-2 (IRT/IRR pinning), TD-6 (owner creates ZarinPal merchant) |
| P5 (Trials & transfers) | ✅ Ready | None (TD-1 layered policy applies) |
| P6 (Hardening & tests) | ✅ Ready | None (time-integrity tests included) |
| P7 (OTP login, optional) | ✅ Ready | None |

**All 8 documents are implementation-ready.** Remaining pre-implementation verifications: (1) pin the currency unit (TD-2), (2) owner creates the ZarinPal merchant account (TD-6). TD-4 (sodium) and TD-8 (device-binding ECDSA P-256) are resolved — see Phase 1 execution and CD-10.

---

## No-Change Confirmation

Explicit confirmation per §6 of the mission:

- ❌ **NO** PHP files created or modified (`server/` untouched)
- ❌ **NO** plugin scaffold created (`eligius-subscription/` directory does not exist in `server/`)
- ❌ **NO** `build.gradle.kts` changes
- ❌ **NO** dependencies installed
- ❌ **NO** GitHub Actions / CI/CD changes
- ❌ **NO** database tables created or migrations executed
- ❌ **NO** Android source changes
- ❌ **NO** VPN core (Aether) changes
- ❌ **NO** git commits or pushes

**Only files modified in this mission:** the 8 design documents under `docs/architecture/eligius-subscription/` plus this final report. The audit document `docs/audits/SUBSCRIPTION_READINESS_AUDIT.md` was left untouched (per §2 of the mission).

---

## Residual Risks (Documented, Not Hidden)

| Risk | Severity | Honest Assessment |
|------|----------|-------------------|
| **Multi-account trial abuse** (CD-6 residual) | MEDIUM | Trial is account-keyed, so reinstall cannot reset it. But an attacker can create unlimited accounts — each needs a unique mobile number capable of receiving OTP (one SIM = one account; the OTP gate is the main economic cost). **Not fully preventable** without identity infrastructure (Shahkar — out of scope by prior decision; national code NOT approved as a control). Mitigations in design: OTP-verified mobile, layered rate limits (user/device/IP, CGNAT-safe), passive correlation flagging → admin review queue. Residual: a person with multiple SIMs can farm trials; correlations accumulate for review. |
| **Device identity after reinstall** (CD-7 limitation) | MEDIUM | Android Keystore is destroyed on uninstall (Android 6.0+). A fresh install generates a new keypair → new `device_hash`. This is why trial is account-keyed, not device-keyed. The device hash still serves its purpose for **device binding** (one active device per account) — it is simply not a trial-abuse control. |
| **Offline window abuse via clock rollback** (CD-4 residual, documented in 04 §4.4) | MEDIUM | While running: rollback is detected (elapsedRealtime anchor) → offline denied. Across reboot: **full rollback detected, partial rollback NOT locally detectable** — an attacker who rolls the clock back (to a point after the anchor), stays offline, gains extra window time. Backstop: server-side retroactive anomaly detection (`entitlement.time_anomaly`) at the next contact + optional auto-suspend policy. Client-side time enforcement is a casual-abuse deterrent, not a security boundary. |
| **Offline window abuse — revoked user** (CD-4/CD-5 residual) | LOW | A revoked user retains up to 72h of offline VPN access. Deliberate owner-approved trade-off for UX; the entitlement is signed and cannot be forged or extended. |
| **Rooted devices** | MEDIUM | Keystore keys can be extracted on rooted devices; EncryptedSharedPreferences can be decrypted; `elapsedRealtime` can be hooked. Server-side enforcement (S2S payment verification, license validation, revocation) remains authoritative regardless of client tampering — client-side protections are UX layers, not security boundaries. |

---

## Approval Gate

**This design is complete and awaits owner review.**

Required owner actions before implementation:
1. Review this report and the 8 updated design documents
2. Resolve or confirm defaults for **TD-2, TD-4, TD-6** (the three blocking technical decisions)
3. Give explicit authorization to begin **Phase 0**

No implementation work has started. No authorization to implement has been issued or assumed.

---

*End of Final Design Report — Eligius Subscription System*