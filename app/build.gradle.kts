plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "ir.mrkiyan.eligius"
    compileSdk = 35

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

    val ksPath = System.getenv("KEYSTORE_FILE")
    signingConfigs {
        if (ksPath != null && file(ksPath).exists()) {
            create("release") {
                storeFile = file(ksPath)
                storePassword = System.getenv("KEYSTORE_PASSWORD")
                keyAlias = System.getenv("KEY_ALIAS")
                keyPassword = System.getenv("KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Falls back to the debug key when no release keystore is provided.
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
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
    // Phase 5b: Compose UI tests (TrialGrantBanner states — owner acceptance).
    androidTestImplementation(platform("androidx.compose:compose-bom:2024.12.01"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
