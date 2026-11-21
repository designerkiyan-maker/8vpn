import java.io.File
import java.time.Instant
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    // Phase 6: JVM unit-test coverage reporting (testDebugUnitTest scope only).
    id("jacoco")
}

android {
    namespace = "ir.mrkiyan.eligius"
    compileSdk = 35

    buildTypes {
        debug {
            // Phase 6: enable JVM unit-test coverage instrumentation (JaCoCo).
            isTestCoverageEnabled = true
        }
        getByName("release") {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // P8.4 (H-1): NO debug fallback. When the release signing config is
            // absent, the task-graph guard below fails the build loudly — an
            // unsigned or debug-signed release APK can never be produced.
            signingConfig = signingConfigs.findByName("release")
        }
    }

    // Phase 6 final closure: combined coverage report — JVM unit exec AND
    // connected (instrumented) .ec from the device, so the business-logic
    // classes exercised by the instrumented suite are measured too.
    tasks.register<JacocoReport>("jacocoUnitTestReport") {
        dependsOn("testDebugUnitTest")
        val buildDirFile = layout.buildDirectory.get().asFile
        sourceDirectories.from(files("src/main/java"))
        classDirectories.from(files("${buildDirFile}/tmp/kotlin-classes/debug"))
        executionData.from(fileTree(buildDirFile) {
            include("**/testDebugUnitTest.exec", "**/coverage.ec", "**/*.ec")
        })
        reports {
            xml.required.set(true)
            html.required.set(true)
        }
    }

    // P6 FINAL FIX (owner-directed): the instrumented suite proves the offline
    // entitlement grant with an UNCONDITIONAL assertion (LicenseStoreTest.t12).
    // A freshly-issued blob fails validation as "Token not yet valid" when the
    // emulator's guest clock trails the server (drift accumulates between
    // sessions), so this pre-run task syncs the guest clock to host UTC via the
    // previously-documented `adb root` + `date -u` procedure and VERIFIES the
    // result. Sync failure aborts the build — the instrumented suite must NOT
    // run against a skewed clock.
    tasks.register("syncEmulatorClock") {
        group = "verification"
        description = "Sync the emulator guest clock to host UTC (adb root + date -u), verify, and abort when sync is impossible."
        doLast {
            val adb = File(
                System.getenv("ANDROID_HOME") ?: "C:/Users/Bardia/AppData/Local/Android/Sdk",
                "platform-tools/adb.exe"
            )
            if (!adb.isFile) throw GradleException("adb not found at ${adb.path}")
            fun adbRun(vararg args: String): String {
                val p = ProcessBuilder(adb.absolutePath, *args)
                    .redirectErrorStream(true).start()
                val out = p.inputStream.bufferedReader().readText().trim()
                p.waitFor()
                return out
            }
            val devList = adbRun("devices")
            if (!Regex("emulator-\\d+\\s+device").containsMatchIn(devList)) {
                throw GradleException("no running emulator — clock sync impossible; instrumented suite NOT run\n$devList")
            }
            println("P6 sync: ${adbRun("root")}")
            Thread.sleep(1500) // adbd restarts when it was non-root
            // The server stamps freshly-issued entitlement blobs with
            // nbf = exact issue second (zero leeway), so a device clock even
            // 1s BEHIND the host rejects its own fresh blob ("Token not yet
            // valid" — observed with a nominal 1s sync delta). The device is
            // therefore set 2s AHEAD of host UTC: never behind, and a 2s lead
            // is harmless for every other check (exp is +72h, TimeIntegrity
            // compares only the device's own monotonic/wall pair).
            val fmt = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").withZone(ZoneOffset.UTC)
            repeat(2) {
                adbRun("shell", "date", "-u", "-s", fmt.format(Instant.now().plusSeconds(2)))
            }
            var behind = false
            var lead = Long.MAX_VALUE
            repeat(3) {
                Thread.sleep(400)
                val devEpoch = adbRun("shell", "date", "-u", "+%s").trim().toLongOrNull()
                    ?: throw GradleException("cannot read the emulator clock — sync unverified")
                val hostEpoch = Instant.now().epochSecond
                behind = behind || devEpoch < hostEpoch - 3 // >3s behind = gross desync
                lead = if (devEpoch > hostEpoch) devEpoch - hostEpoch else hostEpoch - devEpoch
                println("P6 sync verify: device-host = ${devEpoch - hostEpoch}s")
            }
            if (behind || lead > 5) throw GradleException(
                "emulator clock sync FAILED (behind=$behind, lead=${lead}s; required within 5s of host) - instrumented suite NOT run"
            )
        }
    }
    tasks.matching { it.name == "connectedDebugAndroidTest" }.configureEach {
        dependsOn("syncEmulatorClock")
    }

    defaultConfig {
        applicationId = "ir.mrkiyan.eligius"
        minSdk = 26
        targetSdk = 35
        versionCode = 20
        versionName = "1.3.11"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Embedded Ed25519 license-signing public keys (docs 04 §6.1, 06 §9).
        // base64 of the RAW 32-byte public keys, per kid; rotated via app updates.
        // v2 = current server signing key; v1/v3 reserved for rotation.
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V1", "\"\"")
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V2", "\"TvFx8z9/Mf441s+PK7FtHzYTtAocHwONRwRJ9TAYV+Y=\"")
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V3", "\"\"")
        buildConfigField("int", "MAX_OFFLINE_HOURS", "72")
        buildConfigField("String", "ELIGIUS_API_BASE", "\"https://wp.mrkiyan.ir/wp-json/eligius/v1/\"")
    }

    /* ── P8.4 (H-1): Release signing — NO debug fallback ──────────────────────
     * Two supported sources (first match wins for each property):
     *   1. keystore.properties at the repo ROOT (git-ignored) — local owner
     *      builds: storeFile/storePassword/keyAlias/keyPassword
     *   2. env: KEYSTORE_FILE / KEYSTORE_PASSWORD / KEY_ALIAS / KEY_PASSWORD —
     *      CI (main.yml decodes the keystore from GitHub Secrets)
     * An incomplete or missing configuration does NOT break assembleDebug:
     * the failure fires on the task graph ONLY when a Release build is
     * requested, with a clear GradleException and NO password values printed.
     * An unsigned or debug-signed release APK can never be produced.        */
    val ksProps = Properties().apply {
        val f = rootProject.file("keystore.properties")
        if (f.isFile) f.inputStream().use { load(it) }
    }
    fun ksProp(propsName: String, envName: String): String? =
        ksProps.getProperty(propsName)?.takeIf { it.isNotBlank() }
            ?: System.getenv(envName)?.takeIf { it.isNotBlank() }
    val ksFile    = ksProp("storeFile",     "KEYSTORE_FILE")?.let { rootProject.file(it) }
    val ksPass    = ksProp("storePassword", "KEYSTORE_PASSWORD")
    val ksAlias   = ksProp("keyAlias",      "KEY_ALIAS")
    val ksKeyPass = ksProp("keyPassword",   "KEY_PASSWORD")
    var releaseSigningError: String? = null
    when {
        ksFile == null -> releaseSigningError =
            "Release signing is not configured. Provide keystore.properties at the repo root (git-ignored, see keystore.properties.example) or set KEYSTORE_FILE/KEYSTORE_PASSWORD/KEY_ALIAS/KEY_PASSWORD. Debug fallback is disabled (H-1, Phase 8.3)."
        !ksFile.isFile -> releaseSigningError =
            "Release keystore not found at the configured path - Release build aborted (H-1, Phase 8.3)."
        ksPass == null || ksAlias == null || ksKeyPass == null -> releaseSigningError =
            "Release signing configuration is incomplete (missing storePassword/keyAlias/keyPassword) - Release build aborted (H-1, Phase 8.3)."
    }
    signingConfigs {
        if (releaseSigningError == null) {
            create("release") {
                storeFile = ksFile
                storePassword = ksPass
                keyAlias = ksAlias
                keyPassword = ksKeyPass
            }
        }
    }
    gradle.taskGraph.whenReady {
        // P8.4.1 (fix 3): guard the EXACT Release packaging tasks —
        // assembleRelease (APKs), bundleRelease (AAB) and packageRelease (the
        // app packaging task; with splits it produces every split). Helper
        // tasks that merely contain "Release" (packageReleaseResources,
        // assembleReleaseUnitTest, bundleReleaseClassesTo*Jar) must NOT trip
        // the guard — they build no shippable artifact.
        val wantsRelease = allTasks.any {
            it.name == "assembleRelease" || it.name == "bundleRelease" || it.name == "packageRelease"
        }
        if (wantsRelease && releaseSigningError != null) {
            // Include the triggering tasks — makes any false positive of the
            // matcher immediately diagnosable.
            val triggered = allTasks.filter {
                it.name == "assembleRelease" || it.name == "bundleRelease" || it.name == "packageRelease"
            }.map { it.name }.toSortedSet().joinToString(", ")
            throw GradleException("RELEASE SIGNING ERROR: $releaseSigningError (triggering task(s): $triggered)")
        }
    }

    // Three outputs: armeabi-v7a, arm64-v8a and a universal APK (v7a + v8a + x86_64).
    splits {
        abi {
            isEnable = true
            reset()
            include("armeabi-v7a", "arm64-v8a")
            isUniversalApk = true
        }
    }

    // The Aether / Psiphon / Lyrebird executables ship as lib*.so and are run from
    // nativeLibraryDir, so they must be extracted to disk.
    packaging { jniLibs { useLegacyPackaging = true } }
    // Local builds run without the NDK: keep .so debug symbols instead of stripping
    // (CI strips them with the NDK — the difference is a few MB, purely cosmetic).
    packaging { jniLibs { keepDebugSymbols += "**/*.so" } }

    buildFeatures {
        compose = true
        buildConfig = true
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2024.12.01"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui-tooling")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    // SMS Retriever + SMS User Consent (auto-fill of the OTP code, no READ_SMS permission).
    implementation("com.google.android.gms:play-services-auth:21.2.0")
    // Subscription client stack (Roadmap P0 deliverable, executed at P1 start
    // with explicit owner authorization): REST client, JSON, HTTP, encrypted
    // token storage (doc 06 §3.7 / §7.2). No code changes in this phase.
    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-moshi:2.11.0")
    implementation("com.squareup.moshi:moshi-kotlin:1.15.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    // Ed25519 entitlement verification on device (docs 06 §3.5 — platform
    // Ed25519 exists only on API 33+; BouncyCastle covers minSdk 26).
    implementation("org.bouncycastle:bcprov-jdk18on:1.78.1")
    // sing-box (libbox), built by the "libbox" job of the GitHub workflow into app/libs/libbox.aar.
    implementation(files("libs/libbox.aar"))

    // Instrumented tests (Phase 2 — Android Keystore ECDSA device binding).
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test:runner:1.6.2")
    // Phase 6: JVM unit tests (pure-logic classes — no Android framework).
    testImplementation("junit:junit:4.13.2")
    // Phase 5b: Compose UI tests (TrialGrantBanner states — owner acceptance).
    androidTestImplementation(platform("androidx.compose:compose-bom:2024.12.01"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
