# DEVELOPMENT_ROADMAP — Eligius

> Preliminary roadmap derived from the 2026-10-31 baseline assessment.
> Guiding principle: **Eligius is founded on the existing, working engine — it is a re-establishment
> of identity and a foundation for future work, not a rewrite.** Nothing in the existing feature set
> is redesigned in place unless a concrete defect or requirement demands it.

## 1. What is worth preserving as-is (do not touch)

The current data path and its hard-won operational logic are the product's core value:

- **Aether child-process architecture** with readiness heuristics, leftover-process cleanup, and SIGTERM→SIGKILL teardown (`CoreProcess`, `TunnelService`).
- **Smart reconnect logic**: probe-based liveness with data-flow grace, network-change detection, fresh-scan-after-drop, h3↔h2 alternation, patient Psiphon/Tor monitor windows.
- **TUN engine stack with graceful fallback** (sing-box → hev; tun2socks as a prepared option) and the documented DNS semantics (remote-names mode vs sing-box DNS-over-TCP, QUIC rejection rules).
- **Route/protocol matrix** (MASQUE, WireGuard, Gool, Gool classic, MIM; Psiphon/Tor direct and reverse routes) and `ArgBuilder`'s flag mapping.
- **Split tunneling, routing rules, upstream proxy, LAN proxy sharing, pause/resume semantics.**
- **Zero-dependency UI** (hand-drawn icons, no libraries) and the bilingual en/fa + RTL setup.
- The **CI pipeline concept** (upstream-built native engines + libbox + signed split APKs).

These stay functionally untouched through the Eligius transition.

## 2. Phase 0 — Assessment (complete)

- Full repository inspection, branding census, and the three foundation documents
  (`PROJECT_BASELINE.md`, `BRAND_MIGRATION_PLAN.md`, `DEVELOPMENT_ROADMAP.md`). No code modified.

## 3. Phase 1 — Identity decisions & foundation hygiene (small, fast)

Goal: make the repo a *healthy, buildable* home for Eligius before any branding edits.

1. Owner decisions D1–D6 from the migration plan (applicationId, release repo, Telegram, versioning, stale-dir fate, interim glyph).
2. **Add the Gradle wrapper** (8.11.1) so builds don't depend on a machine-installed Gradle.
3. **Add `.gitignore`** (`.gradle/`, `build/`, `local.properties`, `app/libs/libbox.aar`, `app/src/main/jniLibs/`, `*.iml`, `.idea/`).
4. **Local build documentation** — how to obtain `libbox.aar` + native libs (run the CI jobs or download release artifacts) so a local `assembleDebug` is possible.
5. Decide and implement the **updater/About target fix** (the app currently checks `Mrmmd2004/8vpn`).
6. On approval: archive `8vpn-fix5/` and `backup-old-ui/` (removable later; history preserves them).

Exit criteria: repo builds locally with documented steps; update check points at the product's own home.

## 4. Phase 2 — Brand implementation (Eligius identity lands)

Sequenced exactly per `BRAND_MIGRATION_PLAN.md` §3 (Steps A–F):

1. **Step A (atomic):** namespace/applicationId/package migration + intent actions + proguard keep + `jni_shim.c` symbols.
2. **Step B:** display strings (`app_name`, license texts EN/FA, VPN session "Eligius", interim `LogoMark` glyph "E").
3. **Step C:** visual identity — electric purple/violet palette on the dark base in `Theme.kt` + `themes.xml`; gradient accents in `LocalExtra`.
4. **Step D:** external links (About GitHub row, Telegram) per decisions.
5. **Step E:** CI artifact names (`Eligius-*.apk`), version scheme, then tag the **first Eligius release**.
6. **Step F:** designed assets — launcher icons (all densities + adaptive), README banners (EN/FA), fresh screenshots, bilingual README rewrite (keep AGPL + engine credits; add the "Beyond Boundaries." tagline).

Exit criteria: all validation procedures in the migration plan pass; a tagged CI run produces signed Eligius APKs.

## 5. Phase 3 — Engineering cleanup (no behavior changes)

1. **CI job for `t2s-jni`** (Go + NDK → `libt2s-jni.so`) — either ship the tun2socks engine for real or, if the owner prefers fewer moving parts, remove the dormant engine code (`Tun2Socks.kt`, `t2s-jni/`) in one deliberate step. *Do not leave it half-wired.*
2. **Unit tests for the pure logic** that currently has zero coverage: `Endpoints`, `Hosts`, `ArgBuilder.build/splitArgs`, `Updater.isNewer`, `SettingsStore.sanitize`, `Socks` helpers. These are testable without Android.
3. **Consolidate settings migrations** — the schema 5→11 blocks are historical; after a fresh-product release (new applicationId) they can be collapsed to a single baseline.
4. **Naming hygiene** — `Icon8` → neutral name; remove "old FCAE build" comments.
5. **Lint pass** — decide whether `abortOnError=false` should stay; fix what's cheap.
6. **Optional:** version-catalog (`libs.versions.toml`) for dependency coordinates.

## 6. Phase 4 — Future development opportunities (proposals, not commitments)

Ordered by value/risk, all deferred until Phases 1–3 are done:

1. **Connection profile presets** (save/load named configurations) — builds on existing `Settings` persistence.
2. **Settings backup/restore** (export/import JSON) — cheap, high user value, no new permissions.
3. **Quick-connect widget** and richer QS tile (state text) — uses existing `Engine` state flows.
4. **Per-route automatic failover** (e.g., fall back Aether→Psiphon when probes fail N times) — extends the existing reconnect loop.
5. **Crash/analytics, opt-in only** — nothing today; would help debug native-core exits.
6. **Distribution hardening** — Play Store listing (needs the `specialUse` FGS justification reviewed), or F-Droid-compatible metadata (AGPL is fine; needs reproducible/verbatim upstream builds).
7. **New translations** beyond en/fa (the `LocaleHelper` + `values-*` pattern makes this mechanical).
8. **Visual identity extensions** — app icon theming (A13 `ThemedIcon`), splash, "Beyond Boundaries." onboarding card.

## 7. Explicitly out of scope (do not do)

- No rewrite of `TunnelService`, `SingBox`, or the Aether integration.
- No new VPN protocols/engines before Phase 3 stabilizes the build.
- No removal or weakening of AGPL-3.0 licensing or upstream credits.
- No changes to the Git remote, the repo directory name, or upstream pins (Aether/sing-box/hev versions) without a separate explicit decision.
- No asset generation during the assessment phase (design assets come from the owner).
