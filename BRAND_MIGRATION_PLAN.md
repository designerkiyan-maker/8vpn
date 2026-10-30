# BRAND_MIGRATION_PLAN — 8 VPN → Eligius

> Status: **plan only — nothing has been changed yet.**
> Search performed 2026-10-31 over the full working tree (`main` @ `9e11e61`) for
> `8vpn`, `8 VPN`, `8Vpn`, `vpn8`, and related identifiers (`Mrmmd2004/8vpn`, `Clubapp8`),
> case-insensitive, across all text file types.

## 0. Principles

1. **No blind global replace.** Every occurrence below is individually classified; several
   references that *look* like branding are actually load-bearing (JNI symbols, proguard keeps,
   upstream repo pins) and must be handled as code changes, not string edits.
2. **Preserve upstream credits.** Aether's AGPL-3.0 + trademark policy is the *reason* this app
   carries its own brand. Renaming to **Eligius** is compliant; removing the Aether/sing-box/
   Psiphon/Tor/hev credits and license notices is **not**.
3. **Untouchable by default:** the Git remote, the local directory name `8VPN`, third-party repo
   pins (`CluvexStudio/Aether`, `SagerNet/sing-box`, `heiher/hev-socks5-tunnel`), protocol names,
   ports, and all upstream license references.
4. The product identity: display name **Eligius**, full description **Eligius VPN**,
   tagline **"Beyond Boundaries."**, dark + electric purple/violet UI.

## 1. Complete occurrence inventory

Category codes:
**[U]** user-facing branding · **[I]** internal identifiers · **[T]** technical configuration ·
**[P]** package names / namespaces · **[R]** resources & assets · **[D]** documentation & metadata ·
**[E]** external integration references

Risk legend: 🟢 safe (no behavior impact) · 🟡 caution (change as a group, verify) · 🔴 decision/impact (product decision or rebuild required)

### 1.1 User-facing branding [U]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| U1 | `app/src/main/res/values/strings.xml:3` | `<string name="app_name" translatable="false">8 VPN</string>` | `Eligius` | 🟢 | Launcher label, notification title, QS-tile label, About heading. `translatable="false"`; `values-fa` does **not** override it — single point of change. |
| U2 | `app/src/main/res/values/strings.xml:134` | license_text: "8 VPN is free software…" | "Eligius is free software…" (keep all upstream credits) | 🟢 | English About screen. |
| U3 | `app/src/main/res/values-fa/strings.xml:129` | license_text: "برنامه 8 VPN…" | "برنامه Eligius…" | 🟢 | Persian About screen. |
| U4 | `app/src/main/java/app/vpn8/TunnelService.kt:525` | `.setSession("8 VPN")` | `.setSession("Eligius")` | 🟢 | Shown by Android in **Settings → VPN** as the session name. Pure string. |
| U5 | `app/src/main/java/app/vpn8/Components.kt:155-160` | `LogoMark` renders the text `"8"` on a gradient box | render `"E"` (interim glyph until the designed E-logo is delivered) | 🟡 | Used on Home and About. Visual change only; color comes from `LocalExtra` gradient. |
| U6 | `banners/*.png` (9 files, ≈2.8 MB) | 8 VPN banner artwork | replace with Eligius banners when design assets exist | 🟡 | Referenced only by `README.md` (+ Telegram banners are external-use). No code impact. |
| U7 | `screenshots/*.jpg` (5 files) | old-UI screenshots | re-capture after the UI rebrand | 🟢 | README only. |

### 1.2 Internal application identifiers [I] / [P]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| P1 | `app/build.gradle.kts:8` | `namespace = "app.vpn8"` | e.g. `app.eligius` (final choice = decision D1) | 🔴 | Determines R class + `BuildConfig` package. Requires updating `package app.vpn8` in all 22 Kotlin files **in the same commit**. |
| P2 | `app/build.gradle.kts:12` | `applicationId = "app.vpn8"` | same value as namespace | 🔴 | Store/install identity. Changing it means the Eligius APK **cannot update an installed 8 VPN** (fresh install, new data dir). For a *new product* this is normally desired — but it is an explicit decision. |
| P3 | 22 Kotlin files under `app/src/main/java/app/vpn8/` | `package app.vpn8` | `package <new>` | 🔴 | Mechanical, must be atomic with P1/P2 and I3 (below). Directory rename `java/app/vpn8/` → `java/<new path>/` too. |
| I1 | `TunnelService.kt:869-872` | `ACTION_STOP/RESCAN/PAUSE/RESUME = "app.vpn8.STOP"` etc. | mirror the new namespace | 🟡 | Used only inside the app (notification action, tile, boot receiver) — all via the same constants. No external callers. |
| I2 | `TunnelService.kt:814, 822` | wakelock tags `"8vpn:core"`, `"8vpn:wifi"` | `"eligius:core"`, `"eligius:wifi"` | 🟢 | Debug labels only (visible in `adb shell dumpsys power`). |
| I3 | `app/proguard-rules.pro:5-6` | `-keep class app.vpn8.Tun2Socks { *; }` (+ comment) | keep the class under its new name | 🔴 | JNI resolves `Java_app_vpn8_Tun2Socks_*` **by name**; if the class moves and this keep doesn't, release builds break (or worse: keep mismatches the native lib — see I4). |
| I4 | `t2s-jni/jni_shim.c:5,10` | `Java_app_vpn8_Tun2Socks_start/stop` | rename symbols to match the new package | 🔴 | The .so is built from this source; any package rename **requires rebuilding `libt2s-jni.so`** (Go 1.22 + NDK). Note: current CI doesn't build it at all, so this is only blocking if/when the tun2socks engine is shipped (see Roadmap). |
| I5 | `OtherScreens.kt:77` | clipboard label `"8vpn-log"` | `"eligius-log"` | 🟢 | Clipboard metadata only. |
| I6 | `Updater.kt:21` | HTTP User-Agent `"8vpn-android"` | `"eligius-android"` | 🟢 | GitHub API etiquette only. |

### 1.3 Technical configuration [T]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| T1 | `settings.gradle.kts:8` | `rootProject.name = "8vpn"` | `"Eligius"` | 🟢 | IDE/Gradle display name only. |
| T2 | `.github/workflows/main.yml:241-243` | APK names `8VPN-armeabi-v7a.apk`, `8VPN-arm64-v8a.apk`, `8VPN-universal.apk` | `Eligius-*.apk` | 🟡 | Renaming changes release-attachment filenames; do it **before** the first Eligius tag so release pages are consistent. |
| T3 | `.github/workflows/main.yml:248` | artifact name `8VPN-apks` | `Eligius-apks` | 🟢 | Internal to Actions. |
| T4 | `.github/workflows/main.yml:225` | error text mentions "8 VPN" | reword | 🟢 | CI log message. |
| T5 | `app/build.gradle.kts:15-16` | `versionCode 59`, `versionName "5.8"` | versioning scheme for Eligius (e.g. restart at 1.0.0 or continue 5.9) | 🔴 | Product decision; interacts with the updater's semver comparison. |

### 1.4 Resources & assets [R]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| R1 | `res/mipmap-*/ic_launcher*.png` (12 files) + `res/drawable-nodpi/ic_launcher_background.png`, `ic_launcher_foreground.png` | 8 VPN launcher icon | replace with the designed Eligius "E" mark (all densities + adaptive foreground/background) | 🟡 | `mipmap-anydpi-v26/ic_launcher.xml` / `ic_launcher_round.xml` keep the same resource names — only the PNGs change. **Explicitly deferred: no asset generation during assessment** (per instructions). |
| R2 | `res/drawable/ic_stat.xml` | white shield vector (notification/status-bar icon) | brand-neutral; may be kept, or restyled to the E mark later | 🟢 | Must stay a flat white alpha-only vector. |
| R3 | `res/values/themes.xml` (`window_bg #FF0A0E1A`) + `Theme.kt` `Dark`/`Light`/`Extra` palette | navy + periwinkle/teal/amber | electric purple/violet on dark (`#FF0A0E1A` → deep violet-navy, primary/secondary → purple/violet) | 🟢 | Pure Compose/XML color values; no layout impact. Part of visual identity work, not string migration. |

### 1.5 Documentation & metadata [D]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| D1 | `README.md` (EN §1, FA §2) | title "8 VPN"/"8VPN", version badge 5.8, Telegram badge `@Clubapp8` | rewrite for Eligius (both languages), keep AGPL + engines/credits tables | 🟢 | Also the `alt` texts on banners. |
| D2 | `8vpn-fix5/NOTES.txt` + whole `8vpn-fix5/` dir | old patch bundle | candidate for archival/removal (content already superseded in `app/`) | 🟡 | Deleting is a history-preserving, reversible change; needs owner approval. |
| D3 | `backup-old-ui/HomeScreen.kt.bak` | old UI backup | same treatment as D2 | 🟡 | — |
| D4 | `LICENSE` | AGPL-3.0 verbatim | **keep as-is** | — | Contains no app name; Eligius must remain AGPL-3.0 (derivative of Aether). |

### 1.6 External integration references [E]

| # | Location | Current | Proposed | Risk | Notes |
|---|---|---|---|---|---|
| E1 | `Updater.kt:11` | `https://api.github.com/repos/Mrmmd2004/8vpn/releases/latest` | point at the repo that will publish **Eligius** releases | 🔴 | **Currently the app checks the original author's repo.** Until this is changed, Eligius builds would see 8VPN 5.8+ as "updates". |
| E2 | `Updater.kt:27` | fallback URL `…/Mrmmd2004/8vpn/releases/latest` | same target as E1 | 🔴 | — |
| E3 | `OtherScreens.kt:65` | `GITHUB_URL = "https://github.com/Mrmmd2004/8vpn"` (About → GitHub row, label `Mrmmd2004/8vpn` at `:166`) | the Eligius repo URL | 🔴 | Decision D2. |
| E4 | `OtherScreens.kt:66,167` | `TELEGRAM_URL = "https://t.me/Clubapp8"`, label `@Clubapp8` | keep or switch to an Eligius channel | 🔴 | Decision D3; the channel belongs to the original project. |
| E5 | `.github/workflows/main.yml:11,24-27` | `CluvexStudio/Aether @ v2.4.0`, `SagerNet/sing-box @ v1.13.21`, `heiher/hev-socks5-tunnel @ 2.19.0` | **do not change** | — | Engine dependencies, not branding. |
| E6 | Git remote `origin → designerkiyan-maker/8vpn.git` | — | **keep** (explicit instruction; a future decision may revisit) | — | Local folder `E:\xampp\htdocs\8VPN` also stays. |

## 2. Compatibility risk analysis

1. **JNI name binding (highest technical risk).** `Tun2Socks`' natives are resolved by symbol name
   (`Java_app_vpn8_Tun2Socks_start`) from `libt2s-jni.so`. Renaming the package without rebuilding
   the .so makes `System.loadLibrary` succeed but every call fail — and since CI never builds the
   .so today, the safest sequencing is: do the package rename with the shim source updated together,
   and accept that the tun2socks engine remains unshipped (it already is).
2. **applicationId change = clean break.** New applicationId → new install, no data migration from
   8 VPN installs (SharedPreferences would otherwise have carried over; with a new id Android
   creates a fresh data dir anyway). Acceptable for a new product; users of 8 VPN keep their old app.
3. **Signing continuity.** Releases are signed from the `KEYSTORE_BASE64` secret in whichever repo
   runs Actions. If Eligius releases come from this same repo, keep the same keystore material so
   *Eligius-to-Eligius* updates work from day one. The 8 VPN→Eligius transition is a fresh install
   regardless (new applicationId).
4. **Updater semantics.** `isNewer()` compares `BuildConfig.VERSION_NAME` against the remote tag.
   If the versioning restarts (e.g. `1.0.0`), any *old-repo* comparison would immediately flag
   updates — another reason E1 must be switched in the same release as T5.
5. **Proguard/keep pairing.** `app.vpn8.Tun2Socks` keep + `go.**`/`io.nekohasekai.**` keeps: only the
   Tun2Socks line tracks the rename; the others stay.
6. **RTL/Persian.** Only `license_text` in `values-fa` mentions the old name; `app_name` is
   non-translatable, so no RTL layout risk from the rename itself.
7. **No external contracts.** The app has no backend, no accounts, no deep links, no content
   providers, no exported services beyond the QS tile; intent actions are private constants. There
   is no integration surface that a rename can silently break.

## 3. Dependency graph & safe ordering

```
Decisions D1 (applicationId) / D2 (repo URL) / D3 (Telegram) / D4 (versioning)
        │
        ├─► STEP A (atomic): namespace+applicationId+package dirs+intent actions
        │                    + proguard keep + jni_shim.c symbols        [P1,P2,P3,I1,I3,I4]
        ├─► STEP B (atomic, independent): strings + session + LogoMark    [U1,U2,U3,U4,U5]
        ├─► STEP C (independent): color palette (visual identity)         [R3]
        ├─► STEP D (needs D2/D3 + release plan): Updater + About URLs     [E1,E2,E3,E4,I6]
        ├─► STEP E (needs D4, before first tag): CI names + versioning    [T2,T3,T4,T5,T1]
        └─► STEP F (needs design assets): icons, banners, screenshots, README [R1,U6,U7,D1]
```

B and C can ship independently of A (they don't depend on the namespace). A must be one commit and
one CI run. D should land before the first public Eligius release. F lands when the designed assets
are provided.

## 4. Validation procedures (per step)

**After Step A (identity):**
1. `gradle :app:assembleDebug assembleRelease` green (with libbox.aar + jniLibs staged).
2. APK installs and launches; `adb shell pm list packages | grep eligius` shows the new id.
3. Release build with R8: log screen shows engine messages, VPN connects (sing-box path), and
   fallback to hev still works when sing-box is unavailable.
4. Notification action (STOP), QS tile toggle, boot autostart still dispatch the (renamed) actions.

**After Step B (branding):** launcher name, notification title, VPN session name in system
Settings, About screen (EN + FA) all read "Eligius"; Persian RTL unaffected.

**After Step C (palette):** dark theme, light theme, dynamic-color ON/OFF all render; contrast
spot-check on primary text/buttons.

**After Step D (integrations):** About links open the intended repo/channel; "Check for updates"
hits the new repo and reports "latest" against a published tag (verify once with a real tag).

**After Step E/F (CI + assets):** a tagged CI run produces `Eligius-*.apk` attachments; icons render
at all densities and in the adaptive-icon mask; README images resolve.

**Regression checklist (any step):** connect via Aether MASQUE; connect via Psiphon and Tor routes;
pause/resume; split tunneling include/exclude; LAN share addresses; Persian locale switching;
start-on-boot; logs copy/share.

## 5. Decisions required from the product owner

| ID | Decision | Options |
|---|---|---|
| D1 | New `applicationId`/namespace | `app.eligius`, `com.eligius.vpn`, `io.eligius.vpn`, … |
| D2 | Release/update repository | keep `designerkiyan-maker/8vpn`, or a new `…/eligius` repo |
| D3 | Telegram channel | keep `@Clubapp8` or a new Eligius channel |
| D4 | Version scheme | continue `5.9`, or restart at `1.0.0` for Eligius |
| D5 | Fate of `8vpn-fix5/` and `backup-old-ui/` | archive (delete, recoverable from git) vs keep |
| D6 | Interim logo glyph in-app | text "E" in `LogoMark` until designed assets are supplied |
