# PROJECT_BASELINE — 8VPN (inherited state for the Eligius project)

Snapshot date: 2026-10-31
Git: branch `main` @ `9e11e61`, working tree **clean**, up to date with `origin/main`
Remote: `https://github.com/designerkiyan-maker/8vpn.git` (fetch + push)
Tags: none · Stashes: none · Other branches: none

> This document records the repository **exactly as inherited**. Nothing in the repo was modified
> while producing it. It is the reference point for every later change under the Eligius project.

---

## 1. What this repository contains

A **lightweight Android VPN client** named **"8 VPN"** (version `5.8`, `versionCode 59`),
built on top of the **Aether** core (CluvexStudio, AGPL-3.0) with **sing-box (libbox)** as the
default TUN engine and **hev-socks5-tunnel** as the fallback. The app is licensed **AGPL-3.0**
(because Aether is, and Aether's trademark policy explicitly requires derivatives to carry their
own name/branding — which is why the product is branded "8 VPN" and not "Aether").

The UI is **Jetpack Compose + Material 3**, dark by default, English + Persian (full RTL).

## 2. Technology stack

| Layer | Technology | Version |
|---|---|---|
| Language | Kotlin (JVM target 17) | 2.0.21 |
| UI | Jetpack Compose (BOM) + Material 3 | 2024.12.01 |
| Android Gradle Plugin | AGP | 8.7.3 |
| Android SDK | compile / target | 35 |
| Min SDK | Android 8.0 | 26 |
| Gradle (used by CI) | — | 8.11.1 (**no wrapper committed**) |
| App code | 22 Kotlin files in `app.vpn8` + 1 Java JNI binding (`hev.htproxy.TProxyService`) | ≈ 3,840 LOC |
| Native bridge | Go module `t2s-jni` (xjasonlyu/tun2socks v2.6.0 + JNI shim in C) | Go 1.22, ≈ 110 LOC |
| CI | GitHub Actions (`.github/workflows/main.yml`) | 3 jobs |

### External components built from upstream sources (not vendored)

| Component | Source | Role | License |
|---|---|---|---|
| Aether | `CluvexStudio/Aether` @ `v2.4.0` (Rust) | Core engine; runs as child process `libaether.so` | AGPL-3.0 |
| Psiphon | built via `aether-src/psiphon-build.sh` (Go) | Censorship circumvention route; `libpsiphon.so` | GPL-3.0 |
| Lyrebird | built via `aether-src/pt-build.sh` (Go) | Tor pluggable transports (obfs4, webtunnel); `liblyrebird.so` | BSD-3-Clause |
| sing-box | `SagerNet/sing-box` @ `v1.13.21` (Go, gomobile) | Default TUN engine as `app/libs/libbox.aar` | GPL-3.0 + terms |
| hev-socks5-tunnel | `heiher/hev-socks5-tunnel` @ `2.19.0` (C) | Fallback TUN→SOCKS engine; `libhev-socks5-tunnel.so` | MIT |
| tun2socks | `xjasonlyu/tun2socks/v2` @ `v2.6.0` (Go, in `t2s-jni/`) | Optional TUN engine (`libt2s-jni.so`) — **not built by current CI** | — |

## 3. Architecture

### 3.1 Data path

```
VPN mode:    apps -> VpnService (TUN) -> sing-box (gVisor) | hev-socks5-tunnel | tun2socks
                    -> SOCKS5 127.0.0.1:1819 -> Aether / Psiphon / Tor -> internet
Proxy-only:  apps -> SOCKS5 127.0.0.1:1819 (optional HTTP :1822) -> Aether / Psiphon / Tor
```

Ports: SOCKS5 `1819` (configurable), Tor `1820`, Psiphon `1821` (reserved), HTTP `1822` (optional).

### 3.2 Runtime components

- **`App`** — Application subclass; initializes `SettingsStore` (SharedPreferences file `"settings"`, schema migrations up to level 11).
- **`MainActivity`** — Compose host; internal screen router with 5 screens: HOME, SETTINGS, LOGS, ABOUT, SPLIT (no Navigation library; `rememberSaveable` int + `AnimatedContent`).
- **`TunnelService`** (895 LOC, the heart) — foreground `VpnService` (`specialUse` FGS type):
  - session loop: start core → wait for readiness (port checks + log heuristics) → bring up TUN → monitor with HTTP probes via local SOCKS (`Socks.httpGet` to `cp.cloudflare.com` / `www.gstatic.com` generate_204);
  - smart reconnect on: core death, N failed probes (no data flowing), default-network change (Wi-Fi ↔ mobile);
  - pause/resume: closes/reopens the TUN while the core keeps running;
  - speed test + exit-IP/country fetch through the tunnel (Cloudflare trace);
  - TUN engine selection with graceful fallback: `singbox` → `hev` (logs the reason); `tun2socks` is accepted in settings but only used if `libt2s-jni.so` is present;
  - wake lock / Wi-Fi lock ("8vpn:core", "8vpn:wifi"), leftover-process killer (`/proc` scan for our own uid's helper binaries), Psiphon datastore slot cleanup;
  - h3/h2 carrier alternation after drops on MASQUE routes.
- **`CoreProcess`** — runs `libaether.so` from `nativeLibraryDir` as a child process (`ProcessBuilder`), parses its stdout for readiness markers (`psiphon is ready`, `tunnel validated`, `socks5 server listening`), SIGTERM→SIGKILL stop.
- **`ArgBuilder`** — maps user settings to Aether CLI flags (`--protocol`, `--psiphon`, `--tor`, `--peer`, `--scan`, `--noize`, perf profile, extra args…).
- **`SingBox`** — wraps libbox: minimal `PlatformInterface` implementation (TUN fd handover, fd protection, interface monitoring, system certs), builds the JSON config (DNS-over-TCP via proxy, QUIC reject rule, UDP reject on Tor).
- **`Tun2Socks`** — JNI binding to `libt2s-jni.so` (`start(fd, port, mtu, bufKb)` / `stop()`); dormant unless the .so ships.
- **`Socks`** — tiny hand-rolled SOCKS5 client used for probes, exit-IP lookup, and speed measurement.
- **`Engine`** — process-wide state (`MutableStateFlow`): phase, ping, rx/tx bps, exit info/country, logs (last 400 lines).
- **`QsTile`** — Quick Settings tile; **`BootReceiver`** — start-on-boot; **`LanShare`** — enumerates hotspot/USB interfaces for proxy sharing; **`Updater`** — checks the latest GitHub release (never downloads); **`LionSunFlag`** — country-flag rendering; **`LocaleHelper`** — manual in-app locale (en/fa).

### 3.3 State & persistence

- All settings in one `Settings` data class (≈40 fields), persisted in SharedPreferences `"settings"`, sanitized on write (port ranges/reserved ports), with one-time schema migration blocks (levels 5→11) that reset connection-shaping defaults.
- No database, no accounts, no server-side component. Fully offline logic except the update check.

## 4. Directory structure

```
8VPN/
├── .github/workflows/main.yml      # CI: native libs + libbox + signed APKs (3 jobs)
├── app/                            # the Android application (Gradle module :app)
│   ├── build.gradle.kts            # namespace/applicationId app.vpn8, v5.8 (59), ABI splits
│   ├── proguard-rules.pro          # keeps hev + Tun2Socks (JNI) + go.** / io.nekohasekai.**
│   └── src/main/
│       ├── AndroidManifest.xml     # MainActivity, TunnelService (VpnService), QsTile, BootReceiver
│       ├── java/app/vpn8/          # 22 Kotlin files (UI + service + core glue)
│       ├── java/hev/htproxy/TProxyService.java  # hev-socks5-tunnel JNI binding
│       └── res/                    # strings (en + fa), themes, launcher mipmaps, ic_stat, update_paths.xml
├── t2s-jni/                        # Go module: tun2socks as libt2s-jni.so (+ jni_shim.c)
├── 8vpn-fix5/                      # STALE patch snapshot ("fix5") + NOTES.txt (references deleted build.yml)
├── backup-old-ui/                  # STALE: HomeScreen.kt.bak from an earlier UI iteration
├── banners/                        # 9 README/Telegram branding PNGs (≈2.8 MB, old 8VPN branding)
├── screenshots/                    # 5 store/README screenshots (≈1.1 MB, old UI)
├── build.gradle.kts / settings.gradle.kts / gradle.properties
├── LICENSE                         # GNU AGPL-3.0 (verbatim)
└── README.md                       # bilingual (EN + FA) project description
```

Not present in the repo (by design, produced only by CI): `gradlew` + `gradle/wrapper/`,
`app/libs/libbox.aar`, `app/src/main/jniLibs/`, `local.properties`, `.gitignore`, `src/test/`, `src/androidTest/`.

## 5. Entry points & manifest components

| Component | Trigger | Notes |
|---|---|---|
| `.MainActivity` | launcher activity | Compose UI, singleTop |
| `.TunnelService` | `startForegroundService` from UI/tile/boot; actions `app.vpn8.STOP/RESCAN/PAUSE/RESUME` | `BIND_VPN_SERVICE`, `specialUse` FGS (subtype `vpn`) |
| `.QsTile` | Quick Settings tile | label = `app_name` |
| `.BootReceiver` | `BOOT_COMPLETED` | honors `autoStart` + VPN-permission check |

Permissions: INTERNET, ACCESS_NETWORK_STATE, FOREGROUND_SERVICE(+SPECIAL_USE), POST_NOTIFICATIONS, RECEIVE_BOOT_COMPLETED, WAKE_LOCK, REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, CHANGE_WIFI_STATE, ACCESS_WIFI_STATE.

## 6. Build & execution requirements

### Local build (currently NOT turnkey)

1. JDK 17, Android SDK platform 35, Android SDK Build-Tools (AGP 8.7.3 handles the rest).
2. **Gradle 8.11.1** — must be installed separately or a wrapper added (none committed).
3. `app/libs/libbox.aar` — must be produced by the CI `libbox` job (gomobile bind of sing-box v1.13.21) and copied into `app/libs/`, otherwise `SingBox.available == false` and the app silently falls back to hev.
4. `app/src/main/jniLibs/{arm64-v8a,armeabi-v7a,x86_64}/libaether.so, libpsiphon.so, liblyrebird.so, libhev-socks5-tunnel.so` — produced by the CI `native` job. Without them the app installs but cannot connect.
5. (Optional) `libt2s-jni.so` — built from `t2s-jni/` with Go + NDK; **no CI job builds it today**, so the `tun2socks` engine code path is dormant in every CI-produced APK.
6. Signing: release uses env vars `KEYSTORE_FILE/KEYSTORE_PASSWORD/KEY_ALIAS/KEY_PASSWORD`; falls back to the debug key when unset. CI **fails on purpose** when the `KEYSTORE_BASE64` secret is missing (an APK signed with a random debug key cannot update an installed 8 VPN).

### CI (`.github/workflows/main.yml`)

- Triggers: push to main/master, tags `v*`, manual dispatch (inputs: `aether_repo`, `aether_ref`, `singbox_ref`).
- Job `native` (matrix over arm64-v8a / armeabi-v7a / x86_64): checks out this repo + Aether, builds Psiphon+Lyrebird (Go), Aether (Rust via cargo-ndk, NDK r26d), hev (ndk-build), stages all as `lib*.so` artifacts.
- Job `libbox`: gomobile bind of sing-box → `libbox.aar` (android/arm + arm64, NDK r28, Go 1.25, `io.nekohasekai` java package).
- Job `apk`: downloads artifacts, decodes keystore from `KEYSTORE_BASE64`, runs `gradle :app:assembleRelease`, renames outputs to `8VPN-armeabi-v7a.apk / 8VPN-arm64-v8a.apk / 8VPN-universal.apk`, uploads artifact `8VPN-apks`; on tags publishes a GitHub release (`softprops/action-gh-release`).

## 7. Feature inventory (existing functionality)

1. Two connection modes: full-device VPN (TUN) and proxy-only (local SOCKS5 + optional HTTP).
2. Route switcher: Aether, Aether→Psiphon, Aether→Tor, Psiphon-only, Tor-only + reverse routes (Psiphon→MASQUE, Tor→MASQUE). Aether protocols: MASQUE, WireGuard, Gool (WARP-in-MASQUE), Gool classic, MASQUE-in-MASQUE; h2/h3 carrier; Psiphon region/mode; Tor bridge options.
3. Smart reconnect (core death / probe failures / network change), fresh-scan-after-drop, quick reconnect to last gateway, h3↔h2 alternation.
4. Custom endpoint (outer/inner hop) with validation.
5. Proxy sharing to LAN/hotspot/USB (SOCKS5 + HTTP listen addresses), with guidance UI.
6. Split tunneling (include/exclude app lists).
7. Routing rules: block list, direct list, custom DNS inside tunnel, upstream proxy.
8. Pause/Resume without killing the core.
9. Tuning: scan level, obfuscation profile, IP version, perf profile, MTU, TCP buffer, ping interval/failure count, attempts/delay, extra Aether args, TUN engine choice.
10. Live info: ping, download/upload/link speed, exit IP + country flag, last-drop reason, copyable logs.
11. Update check via GitHub releases (check-only).
12. Quick Settings tile, start on boot, battery-optimization shortcut, dynamic color (A12+), dark/light theme, English + Persian with RTL.

## 8. Dependencies (Gradle)

```
androidx.compose:compose-bom:2024.12.01  (ui, material3)
androidx.activity:activity-compose:1.9.3
androidx.lifecycle:lifecycle-runtime-compose:2.8.7
androidx.core:core-ktx:1.15.0
org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0
files("libs/libbox.aar")                 # built by CI from sing-box
```

No other third-party libraries: all icons are hand-drawn in Compose (`Ico` enum + Canvas), no image-loading, networking, or DI libraries.

## 9. Known issues & observations

1. **No Gradle wrapper** — local builds depend on an externally installed Gradle 8.11.1; CI uses `setup-gradle`.
2. **No `.gitignore`** — CI-staged artifacts (`app/libs/libbox.aar`, `app/src/main/jniLibs/**`, `.gradle/`, `build/`, `local.properties`) risk being committed accidentally once a local build runs.
3. **Repo is not locally buildable as-is** — requires CI-produced native libs and libbox.aar (documented above).
4. **`tun2socks` engine is dormant** — the UI only offers sing-box / hev / hev-tcp (`SettingsScreen.kt:68`), stored `tun2socks` values are migrated to `singbox`, and no CI job builds `libt2s-jni.so`; `Tun2Socks.kt` + `t2s-jni/` are kept as a supported-but-unshipped path.
5. **Update check and About screen point at the *original author's* repo** — `Updater.kt:11` → `api.github.com/repos/Mrmmd2004/8vpn`, `OtherScreens.kt:65` → `github.com/Mrmmd2004/8vpn`, Telegram `@Clubapp8`. This repo (`designerkiyan-maker/8vpn`) is a fork/derivative; as-is, the app would report *8VPN's* releases as updates.
6. **Stale snapshot directories** — `8vpn-fix5/` (older patch bundle; its NOTES reference a `.github/workflows/build.yml` that no longer exists) and `backup-old-ui/HomeScreen.kt.bak`. Their content is already superseded by `app/`.
7. **No tests at all** — no `src/test` or `src/androidTest` source sets.
8. **No git tags** — the CI release path (tags `v*`) has never been used in this repo.
9. **Lint** — `abortOnError = false`, `checkReleaseBuilds = false`.
10. **Current palette** is dark-navy + periwinkle/teal/amber (`Theme.kt`, `themes.xml` `#FF0A0E1A`) — does **not** match the target Eligius visual direction (electric purple/violet).
11. Minor: helper names carry history — `Icon8` (icon helper), comments referencing "the old FCAE build" (`t2s-jni/main.go:76`, `Models.kt` schema notes), notification `NOTIF_ID = 8`.

## 10. Git status (verified 2026-10-31)

- Branch `main`, tracking `origin/main`, **clean working tree**, nothing staged.
- History: linear; recent commits are GitHub-web uploads ("Add files via upload"), README/CI adjustments, "Lan share".
- No tags, no stashes, no other local branches.
- Remote unchanged: `https://github.com/designerkiyan-maker/8vpn.git`.
