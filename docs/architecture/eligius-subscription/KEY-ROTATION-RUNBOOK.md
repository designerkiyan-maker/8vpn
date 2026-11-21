# Eligius — License Signing Key Rotation Runbook (90-day)

**Scope:** Ed25519 license/entitlement signing key ONLY (server side).
Device Binding stays ECDSA P-256 in Android Keystore and is NOT rotated by
this procedure (CD-10 separation). Payment credentials (ZarinPal merchant)
are rotated through the gateway panel, not here.

**Cycle:** every 90 days, or immediately on suspected key compromise.

**Verified machinery (Phase 6 tests — `tests/unit/backend/KeyRotationTest.php`):**
multiple kids coexist in the registry (`eligius_sub_pubkey_{kid}` options),
blobs verify per `kid`, unknown kids are rejected fail-closed, tampered
payloads and algorithm downgrades are rejected.

---

## Key registry

| Where | What |
|---|---|
| Server secret | `ELIGIUS_SUB_SIGNING_PRIVATE_KEY` (wp-config constant, base64 64-byte Ed25519 secret) — NEVER in the repo, never logged |
| Server key id | `ELIGIUS_SUB_SIGNING_KEY_ID` (wp-config constant; currently `v2`) |
| Server registry | wp_options `eligius_sub_pubkey_{kid}` (one row per kid, written automatically when the constant changes) |
| Android embedded | `EMBEDDED_PUBLIC_KEY_V1 / V2 / V3` in `app/build.gradle.kts` (BuildConfig; currently v1 empty, v2 = active key, v3 empty) |

## Safe rotation order (six steps — overlap windows, never a hard cut)

1. **Pre-publish:** generate the NEW keypair OFFLINE on the server host
   (`sodium_crypto_sign_keypair`). Store the secret in wp-config with the NEW
   kid (`ELIGIUS_SUB_SIGNING_KEY_ID = 'v3'` for the next slot) and deploy it
   **without** switching the active kid yet — or only register the new public
   key in the Android build. At no point put the private key in Git.
2. **Publish the new PUBLIC key to Android first:** fill the next empty
   `EMBEDDED_PUBLIC_KEY_*` slot (v1 → v2 → v3 cycle) and release an app update.
   Verify `OfflineEntitlementValidator` accepts blobs signed by BOTH the old
   and the new kid during this window (Phase 6 test covers the dual-kid
   registry behavior).
3. **Client coverage check:** wait until the update's adoption makes the
   target clients able to verify the new kid (staged rollout %; old clients
   keep working — they still hold the old public key and the server keeps
   signing with the OLD kid).
4. **Activate the new key on the server:** switch `ELIGIUS_SUB_SIGNING_KEY_ID`
   to the new kid and restart/re-deploy PHP (OPcache). From this moment new
   entitlements carry `kid: vNEW`; existing offline blobs signed with the old
   kid remain valid until their own 72-hour expiry (max offline window, CD-4).
5. **Compatibility window:** keep BOTH old and new public keys registered on
   the server (`eligius_sub_pubkey_v2`, `eligius_sub_pubkey_v3`) and inside
   the app for at least 72 hours + 1 app-release cycle. Old clients verify
   new-kid blobs only after they installed the step-2 update — never revoke
   the old kid before that rollout completes.
6. **Retire the old kid:** only after the compatibility window — clear the old
   `eligius_sub_pubkey_vOLD` option and blank the old `EMBEDDED_PUBLIC_KEY_*`
   slot in the NEXT release. Verify with `tests/unit/backend/KeyRotationTest.php`
   that unknown kids are still rejected fail-closed.

## Rollback

If the new key misbehaves: revert `ELIGIUS_SUB_SIGNING_KEY_ID` to the previous
kid (the old secret + registry entry are still intact during the window) —
clients hold both public keys, so nothing breaks. No data migration is
involved (keys live in wp-config + wp_options only).

## Compromise (out-of-band)

If the PRIVATE key is suspected compromised: skip the gentle order — register
a fresh keypair under a new kid, switch the server immediately, and rely on
the ≤5-minute CRL + online re-validation to replace outstanding blobs; revoke
suspect licenses via admin (manual revoke path) and let offline entitlements
die within their remaining window (≤72h ceiling).

## Checklist (each rotation)

- [ ] New keypair generated offline; secret only in wp-config (never Git/log)
- [ ] `ELIGIUS_SUB_SIGNING_KEY_ID` switched; `eligius_sub_pubkey_{new}` written
- [ ] New PUBLIC key embedded in the next Android release slot (v1→v2→v3)
- [ ] `KeyRotationTest` green (multi-kid verify, unknown-kid reject, tamper reject)
- [ ] Real device pulls an entitlement with the new kid and validates offline
- [ ] Old kid kept ≥72h + 1 release cycle, then retired (registry + app slot)
- [ ] No private key material in logs, audits, or the repository (M-1 policy)
