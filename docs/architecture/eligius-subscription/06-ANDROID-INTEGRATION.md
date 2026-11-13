# Eligius Subscription System — Android Integration Contract

**Version:** 1.0  
**Date:** 2026-11-10  
**Status:** Design Phase — Not Implemented

---

## 1. Overview

This document defines the exact integration contract between the Eligius Android application and the subscription backend. It specifies the new components to add, the existing components to modify, and the exact integration points — **without modifying the VPN core (Aether/sing-box/TunnelService internals).**

### 1.1 Integration Principles

1. **Minimal Intrusion** — Only additive changes; no modifications to `CoreProcess.kt`, `ArgBuilder.kt`, `SingBox.kt`, `Tun2Socks.kt`, or native libraries
2. **Single Enforcement Point** — License check only in `TunnelService.startSession()` (the single funnel)
3. **Existing Architecture Preserved** — Singleton state holders, hand-rolled router, `SharedPreferences` persistence all remain
4. **Additive UI** — New screens added to existing navigation; no redesign of existing screens
5. **Backward Compatible** — Existing users without license see graceful degradation (trial offer)

---

## 2. New Android Components

### 2.1 New Files to Create

| File | Purpose | Location |
|------|---------|----------|
| `LicenseStore.kt` | License state management, validation, offline entitlements | `app/src/main/java/ir/mrkiyan/eligius/LicenseStore.kt` |
| `DeviceIdentity.kt` | Keystore key management, device registration, challenge-response | `app/src/main/java/ir/mrkiyan/eligius/DeviceIdentity.kt` |
| `OfflineEntitlementStore.kt` | Encrypted storage for offline entitlement blobs | `app/src/main/java/ir/mrkiyan/eligius/OfflineEntitlementStore.kt` |
| `RevocationListManager.kt` | CRL caching (5-min TTL) | `app/src/main/java/ir/mrkiyan/eligius/RevocationListManager.kt` |
| `SecureTokenStore.kt` | Keystore-encrypted JWT storage | `app/src/main/java/ir/mrkiyan/eligius/SecureTokenStore.kt` |
| `AuthApiService.kt` | Retrofit interface for subscription API | `app/src/main/java/ir/mrkiyan/eligius/api/AuthApiService.kt` |
| `LicenseApiService.kt` | Retrofit interface for license API | `app/src/main/java/ir/mrkiyan/eligius/api/LicenseApiService.kt` |
| `DeviceApiService.kt` | Retrofit interface for device API | `app/src/main/java/ir/mrkiyan/eligius/api/DeviceApiService.kt` |
| `PaymentApiService.kt` | Retrofit interface for payment API | `app/src/main/java/ir/mrkiyan/eligius/api/PaymentApiService.kt` |
| `AuthForgotScreen.kt` | **Already exists** — Forgot password screen | (exists) |
| `PaymentActivity.kt` | New screen for payment flow | `app/src/main/java/ir/mrkiyan/eligius/PaymentActivity.kt` |
| `LicenseStatusDialog.kt` | Composable for expiry/renewal dialogs | `app/src/main/java/ir/mrkiyan/eligius/LicenseStatusDialog.kt` |
| `PlanSelectionScreen.kt` | Plan selection UI | `app/src/main/java/ir/mrkiyan/eligius/PlanSelectionScreen.kt` |
| `SplitOtpInput.kt` | **Already exists** — 6-digit OTP input | (exists) |
| `SmsOtp.kt` | **Already exists** — SMS Retriever + User Consent | (exists) |

### 2.2 Existing Files to Modify

| File | Change | Risk |
|------|--------|------|
| `AuthStore.kt` | Add `forgotStart`, `forgotVerify`, `forgotReset`, `hash` param to `registerOtpStart`; extend `login()` to call `LicenseStore.ensureValid()` | Low — additive methods |
| `AuthScreen.kt` | Add "Forgot Password?" link → `AUTH_FORGOT` route | Low |
| `AuthRegisterScreen.kt` | Add `hash` param to `registerOtpStart` call | Low |
| `AuthForgotScreen.kt` | Already exists — verify it uses `AuthStore.forgotStart/Verify/Reset` | Low |
| `TunnelService.kt` | **Add license gate in `startSession()`** — only modification to VPN path | **Medium** — single guard |
| `MainActivity.kt` | Add `AUTH_FORGOT` route, `toggleConnect()` pre-check license | Low |
| `BootReceiver.kt` | Add cached license check before autostart | Low |
| `QsTile.kt` | Add cached license check in `onClick()` | Low |
| `App.kt` | Initialize `LicenseStore`, `DeviceIdentity`, `OfflineEntitlementStore` | Low |
| `Models.kt` (`SettingsStore`) | Add `lastLicenseCheckAt`, `cachedLicenseBlob` via schema migration | Low |
| `App.kt` dependencies | Add `play-services-auth`, Retrofit, Moshi, OkHttp | Low |
| `app/build.gradle.kts` | Add dependencies | Low |
| `AndroidManifest.xml` | Add payment activity, deep-link intent filter | Low |

---

## 3. New Component Specifications

### 3.1 `LicenseStore.kt`

```kotlin
package ir.mrkiyan.eligius

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

object LicenseStore {
    private const val WP_BASE = "https://wp.mrkiyan.ir"
    private const val GRACE_PERIOD_HOURS = 72
    private const val CACHE_TTL_MINUTES = 5
    
    /** License state as reported by server */
    data class LicenseState(
        val valid: Boolean,
        val licenseId: Long? = null,
        val planKey: String? = null,
        val planLabel: String? = null,
        val status: String? = null, // ACTIVE, EXPIRED, REVOKED, TRIAL
        val startedAt: String? = null,
        val expiresAt: String? = null,
        val daysRemaining: Long? = null,
        val deviceBound: Boolean = false,
        val deviceId: Long? = null,
        val offlineEntitlement: OfflineEntitlement? = null,
        val error: String? = null
    )
    
    data class OfflineEntitlement(
        val blob: String,
        val expiresAt: String,
        val kid: String
    )
    
    data class ValidationResult(
        val isValid: Boolean,
        val error: String? = null,
        val licenseState: LicenseState? = null
    )
    
    private var context: Context? = null
    private var apiService: LicenseApiService? = null
    private var offlineStore: OfflineEntitlementStore? = null
    private var revocationManager: RevocationListManager? = null
    
    val licenseState = MutableStateFlow<LicenseState?>(null)
    val isChecking = MutableStateFlow<Boolean>(false)
    val lastCheckedAt = MutableStateFlow<Long>(0)
    
    fun init(ctx: Context) {
        context = ctx.applicationContext
        apiService = RetrofitClient.create(LicenseApiService::class.java)
        offlineStore = OfflineEntitlementStore(ctx)
        revocationManager = RevocationListManager(ctx)
        
        // Load cached state
        loadCachedState()
    }
    
    /** Main entry point — called before VPN connection */
    suspend fun ensureValid(): ValidationResult = withContext(Dispatchers.IO) {
        isChecking.value = true
        defer { isChecking.value = false }
        
        // 1. Check auth
        val auth = AuthStore.isLoggedIn
        if (!auth) return ValidationResult(false, "NOT_LOGGED_IN")
        
        // 2. Check cached license (fast path)
        val cached = licenseState.value
        if (cached != null && cached.valid && cached.expiresAt != null) {
            val expiresMs = parseIso8601(cached.expiresAt!!) ?: 0
            if (System.currentTimeMillis() < expiresMs) {
                // Check revocation list (cached 5 min)
                if (cached.licenseId != null && revocationManager!!.isRevoked(cached.licenseId!!)) {
                    return ValidationResult(false, "LICENSE_REVOKED")
                }
                return ValidationResult(true, licenseState = cached)
            }
        }
        
        // 3. Try offline entitlement
        val offline = offlineStore!!.get()
        if (offline != null) {
            val entitlementValid = OfflineEntitlementValidator.validate(offline.blob)
            if (entitlementValid.isValid) {
                // Check revocation
                if (entitlementValid.licenseId != null && revocationManager!!.isRevoked(entitlementValid.licenseId!!)) {
                    return ValidationResult(false, "LICENSE_REVOKED")
                }
                return ValidationResult(true, LicenseState(
                    valid = true,
                    licenseId = entitlementValid.licenseId,
                    valid = true,
                    expiresAt = entitlementValid.expiresAt.toString(),
                    offlineEntitlement = OfflineEntitlement(
                        blob = "", // Don't expose blob
                        expiresAt = entitlementValid.expiresAt.toString(),
                        kid = ""
                    )
                ))
            }
        }
        
        // 4. Online validation
        return onlineValidation()
    }
    
    private suspend fun onlineValidation(): ValidationResult = withContext(Dispatchers.IO) {
        val deviceHash = DeviceIdentity.getDeviceHash() ?: return ValidationResult(false, "DEVICE_NOT_REGISTERED")
        
        try {
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
                    data?.offlineEntitlement?.let { offlineStore!!.save(OfflineEntitlement(
                        blob = it.blob,
                        expiresAt = it.expiresAt,
                        kid = it.kid
                    ))}
                }
                
                return ValidationResult(state.valid, error = if (state.valid) null else "NO_ACTIVE_LICENSE", licenseState = state)
            } else {
                val errorBody = response.errorBody()?.string() ?: ""
                return ValidationResult(false, parseError(errorBody))
            }
        } catch (e: Exception) {
            // Network error — try offline entitlement as last resort
            val offline = offlineStore!!.get()
            if (offline != null && OfflineEntitlementValidator.validate(offline.blob).isValid) {
                return ValidationResult(true, LicenseState(valid = true, offlineEntitlement = offline))
            }
            return ValidationResult(false, "NETWORK_ERROR: ${e.message}")
        }
    }
    
    private fun parseError(json: String): String {
        return try {
            val obj = org.json.JSONObject(json)
            obj.optString("message", obj.optString("error", "UNKNOWN_ERROR"))
        } catch (_: Exception) {
            "UNKNOWN_ERROR"
        }
    }
    
    private fun parseIso8601(date: String): Long? = try {
        java.time.Instant.parse(date).toEpochMilli()
    } catch (_: Exception) {
        null
    }
    
    private fun loadCachedState() {
        // Load from SharedPreferences if needed
    }
    
    /** Call after successful payment to refresh license immediately */
    fun refreshAfterPayment() {
        // Invalidate cache, trigger fresh validation
        lastCheckedAt.value = 0
        _ = ensureValid()
    }
    
    companion object {
        @Volatile private var INSTANCE: LicenseStore? = null
        fun getInstance(): LicenseStore = INSTANCE ?: synchronized(this) {
            INSTANCE ?: LicenseStore().also { INSTANCE = it }
        }
    }
}
```

### 3.2 `DeviceIdentity.kt`

```kotlin
package ir.mrkiyan.eligius

import android.content.Context
import android.content.ContextWrapper
import android.os.Build
import android.provider.Settings
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import androidx.core.content.ContextCompat
import com.google.android.gms.auth.api.phone.SmsRetriever
import com.google.android.gms.common.ConnectionResult
import com.google.android.gms.common.GoogleApiAvailability
import com.google.android.gms.common.api.CommonStatusCodes
import com.google.android.gms.common.api.Status
import java.nio.charset.StandardCharsets
import java.security.KeyPair
import java.security.KeyPairGenerator
import android.content.Context
import android.provider.Settings
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyPair
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.ECGenParameterSpec

/**
 * Device Identity & Registration (Phase 2 — CD-10) — Keystore-backed device identity.
 *
 * Confirmed mechanism: Android Keystore ECDSA P-256 (secp256r1).
 *   - private key is generated inside the Keystore and is NON-EXPORTABLE
 *   - device_hash = SHA-256(pubkey_der || ANDROID_ID)
 *   - ownership proof = signing the server challenge with SHA256withECDSA
 *
 * NOTE (CD-10): generation inside the Keystore must NOT be presented as proof
 * of hardware-backed storage (TEE/StrongBox varies; software Keystore is
 * acceptable on emulators).
 */
object DeviceIdentity {

    private const val KEY_ALIAS = "eligius_device_identity"
    private const val KEY_ALGORITHM = "EC"
    private const val KEY_SPEC = "secp256r1"
    private const val SIGN_ALGORITHM = "SHA256withECDSA"
    private const val KEYSTORE = "AndroidKeyStore"

    /** device_hash = SHA-256(pubkey_der || ANDROID_ID), lowercase hex. */
    fun getDeviceHash(context: Context): String {
        val keyPair = getOrCreateKeyPair(context)
        val androidId = getAndroidId(context)
        val pubKeyDer = keyPair.public.encoded
        val input = ByteArray(pubKeyDer.size + androidId.toByteArray().size)
        System.arraycopy(pubKeyDer, 0, input, 0, pubKeyDer.size)
        System.arraycopy(androidId.toByteArray(), 0, input, pubKeyDer.size, androidId.toByteArray().size)
        return sha256Hex(input)
    }

    /** Public key as a PEM "PUBLIC KEY" block (SubjectPublicKeyInfo DER). */
    fun getPublicKeyPem(context: Context): String {
        val keyPair = getOrCreateKeyPair(context)
        return derToPem(keyPair.public.encoded)
    }

    /**
     * Sign [data] with the device private key.
     * The signature is DER-encoded ECDSA (as produced by SHA256withECDSA),
     * which PHP openssl_verify accepts directly.
     */
    fun sign(context: Context, data: ByteArray): ByteArray {
        val privateKey = getPrivateKey(context)
        return Signature.getInstance(SIGN_ALGORITHM).apply {
            initSign(privateKey)
            update(data)
        }.sign()
    }

    /** Sign the server challenge and return base64 (DER) signature. */
    fun signChallenge(context: Context, challenge: ByteArray): String {
        val der = sign(context, challenge)
        return Base64.encodeToString(der, Base64.NO_WRAP)
    }

    private fun getOrCreateKeyPair(context: Context): KeyPair {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        if (keyStore.containsAlias(KEY_ALIAS)) {
            val entry = keyStore.getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry
            return KeyPair(entry.certificate.publicKey, entry.privateKey)
        }
        val generator = KeyPairGenerator.getInstance(KEY_ALGORITHM, KEYSTORE)
        val spec = KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec(KEY_SPEC))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .build()
        generator.initialize(spec)
        return generator.generateKeyPair()
    }

    private fun getPrivateKey(context: Context): java.security.PrivateKey {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        val entry = keyStore.getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry
        return entry.privateKey
    }

    private fun getAndroidId(context: Context): String {
        return Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID) ?: ""
    }

    private fun sha256Hex(input: ByteArray): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(input)
        val sb = StringBuilder(digest.size * 2)
        for (b in digest) sb.append(String.format("%02x", b))
        return sb.toString()
    }

    private fun derToPem(der: ByteArray): String {
        val b64 = Base64.encodeToString(der, Base64.NO_WRAP)
        val sb = StringBuilder()
        sb.append("-----BEGIN PUBLIC KEY-----\n")
        var i = 0
        while (i < b64.length) {
            val end = minOf(i + 64, b64.length)
            sb.append(b64, i, end).append('\n')
            i = end
        }
        sb.append("-----END PUBLIC KEY-----")
        return sb.toString()
    }
}
    
    /** Sign challenge with device private key */
    fun signChallenge(context: Context, challengeBase64: String): String {
        val challenge = android.util.Base64.decode(challengeBase64, android.util.Base64.NO_WRAP)
        val signature = sign(context, challenge)
        return android.util.Base64.encodeToString(signature, android.util.Base64.NO_WRAP)
    }
}
```

### 3.3 `OfflineEntitlementStore.kt`

```kotlin
package ir.mrkiyan.eligius

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class OfflineEntitlementStore @Inject constructor(
    @androidx.annotation.NonNull context: Context
) {
    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()
    
    private val encryptedPrefs = EncryptedSharedPreferences.create(
        "eligius_offline_entitlement",
        masterKey,
        context,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )
    
    data class OfflineEntitlement(
        val blob: String,
        val expiresAt: Long, // epoch millis
        val kid: String
    )
    
    fun save(entitlement: OfflineEntitlement) {
        encryptedPrefs.edit()
            .putString("blob", entitlement.blob)
            .putLong("expires_at", entitlement.expiresAt)
            .putString("kid", entitlement.kid)
            .apply()
    }
    
    fun get(): OfflineEntitlement? {
        val blob = encryptedPrefs.getString("blob", null) ?: return null
        val expiresAt = encryptedPrefs.getLong("expires_at", 0)
        val kid = encryptedPrefs.getString("kid", "") ?: return null
        
        if (System.currentTimeMillis() >= expiresAt) {
            clear()
            return null
        }
        
        return OfflineEntitlement(blob, expiresAt, kid)
    }
    
    fun clear() {
        encryptedPrefs.edit().clear().apply()
    }
    
    fun isValid(): Boolean {
        val entitlement = get()
        return entitlement != null && System.currentTimeMillis() < entitlement.expiresAt
    }
}
```

### 3.4 `RevocationListManager.kt`

```kotlin
package ir.mrkiyan.eligius

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class RevocationListManager @Inject constructor(
    private val context: Context,
    private val apiService: LicenseApiService
) {
    private val cache = mutableMapOf<Long, Boolean>()
    private var lastUpdated = 0L
    private val CACHE_TTL = 5 * 60 * 1000 // 5 minutes
    
    fun isRevoked(licenseId: Long): Boolean {
        if (System.currentTimeMillis() - lastUpdated > CACHE_TTL) {
            refresh()
        }
        return cache[licenseId] == true
    }
    
    suspend fun refresh() = withContext(Dispatchers.IO) {
        try {
            val response = RetrofitClient.create(LicenseApiService::class.java)
                .getRevocationList()
            
            if (response.isSuccessful) {
                val data = response.body()?.data
                cache.clear()
                data?.revokedLicenseIds?.forEach { cache[it] = true }
                lastUpdated = System.currentTimeMillis()
            }
        } catch (e: Exception) {
            // Keep stale cache on error
        }
    }
}
```

### 3.5 `OfflineEntitlementValidator.kt`

```kotlin
package ir.mrkiyan.eligius

import android.util.Base64
import org.json.JSONObject

object OfflineEntitlementValidator {
    data class ValidationResult(
        val isValid: Boolean,
        val licenseId: Long? = null,
        val deviceHash: String? = null,
        val expiresAt: Long = 0,
        val permissions: List<String> = emptyList(),
        val error: String? = null
    ) {
        companion object {
            fun Valid(licenseId: Long, deviceHash: String, expiresAt: Long, permissions: List<String>) =
                ValidationResult(true, licenseId, null, expiresAt, permissions)
            
            fun Expired() = ValidationResult(false, error = "EXPIRED")
            fun Invalid(error: String) = ValidationResult(false, error = error)
        }
    }
    
    // Embedded public keys (rotated via app updates)
    private const val EMBEDDED_PUBLIC_KEYS = mapOf(
        "v1" to "MCowBQYDK2VwAyEA...",
        "v2" to "MCowBQYDK2VwAyEA...",
        "v3" to "MCowBQYDK2VwAyEA..."
    )
    
    fun validate(blob: String): ValidationResult {
        val parts = blob.split(".")
        if (parts.size != 3) return ValidationResult.Invalid("Invalid JWT format")
        
        val (headerB64, payloadB64, signatureB64) = parts.toList()
        
        // Decode header
        val headerJson = base64UrlDecode(headerB64)
        val header = JSONObject(headerJson)
        val kid = header.optString("kid")
        val alg = header.optString("alg")
        
        val pubKeyPem = EMBEDDED_PUBLIC_KEYS[kid] 
            ?: return ValidationResult.Invalid("Unknown key ID: $kid")
        
        // Verify signature
        val signingInput = "$headerB64.$payloadB64"
        val signature = base64UrlDecode(signatureB64)
        if (!verifySignature(signingInput, signature, pubKeyPem, alg)) {
            return ValidationResult.Invalid("Signature verification failed")
        }
        
        // Parse payload
        val payloadJson = base64UrlDecode(payloadB64)
        val payload = JSONObject(payloadJson)
        
        // Validate claims
        val now = System.currentTimeMillis() / 1000
        val exp = payload.optLong("exp", 0)
        val nbf = payload.optLong("nbf", 0)
        
        if (now >= exp) return ValidationResult.Expired()
        if (now < nbf) return ValidationResult.Invalid("Token not yet valid")
        
        if (payload.optString("aud") != "eligius-android") {
            return ValidationResult.Invalid("Invalid audience")
        }
        
        return ValidationResult.Valid(
            licenseId = payload.optLong("license_id"),
            deviceHash = payload.optString("device_hash"),
            expiresAt = exp * 1000L,
            permissions = payload.optJSONArray("permissions")?.toStringList() ?: emptyList()
        )
    }
    
    private fun base64UrlDecode(input: String): String {
        var output = input.replace('-', '+').replace('_', '/')
        val padding = 4 - input.length % 4
        if (padding != 4) output += "=".repeat(padding)
        return String(Base64.decode(output, Base64.URL_SAFE or Base64.NO_WRAP), StandardCharsets.UTF_8)
    }
    
    private fun verifySignature(data: String, signature: ByteArray, pubKeyPem: String, alg: String): Boolean {
        return if (alg == "EdDSA") {
            // Ed25519 verification using sodium or bouncycastle
            org.bouncycastle.crypto.signers.Ed25519Signer().run {
                init(false, org.bouncycastle.crypto.params.Ed25519PublicKeyParameters(
                    org.bouncycastle.util.encoders.Base64.decode(pubKeyPem.replace("-----BEGIN PUBLIC KEY-----", "").replace("-----END PUBLIC KEY-----", "").trim()),
                    0
                ))
                update(data.toByteArray(StandardCharsets.UTF_8))
                verifySignature(signature)
            }
        } else if (alg == "ES256") {
            // ECDSA P-256
            val pubKey = java.security.KeyFactory.getInstance("EC").generatePublic(
                java.security.spec.X509EncodedKeySpec(
                    android.util.Base64.decode(pubKeyPem.replace("-----BEGIN PUBLIC KEY-----", "").replace("-----END PUBLIC KEY-----", "").trim(), Base64.NO_WRAP)
                )
            )
            val sig = java.security.Signature.getInstance("SHA256withECDSA").apply { initVerify(pubKey) }
            sig.update(data.toByteArray(StandardCharsets.UTF_8))
            sig.verify(signature)
        } else false
    }
}
```

### 3.6 `TimeIntegrity.kt`

Monotonic time accounting for the 72-hour offline entitlement (CD-4). The wall clock (`System.currentTimeMillis()`) is user-controllable and **must not** be the sole validity signal. This component anchors the entitlement to `SystemClock.elapsedRealtime()` (monotonic since boot, includes deep-sleep time), detects wall-clock rollback while running, and applies best-effort checks across reboots. Full analysis: `04-SECURITY-AND-LICENSE.md` §4.4.

```kotlin
package ir.mrkiyan.eligius

import android.content.Context
import android.os.SystemClock
import android.provider.Settings
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

object TimeIntegrity {
    private const val TOLERANCE_MS = 60_000L   // wall-clock tolerance: 60s
    const val MAX_OFFLINE_MS: Long = 72L * 60 * 60 * 1000  // CD-4: 72 hours

    data class Anchor(
        val entitlementExp: Long,        // JWT exp claim (server epoch, ms)
        val anchorElapsedRealtime: Long, // SystemClock.elapsedRealtime() at anchor time
        val anchorBootCount: Int?,       // BOOT_COUNT at anchor; null = read FAILED (distinguishable from any real value)
        val bootCountValid: Boolean,     // false = BOOT_COUNT UNAVAILABLE -> offline DENIED
        val anchorWallClock: Long        // System.currentTimeMillis() at anchor time
    )

    sealed class Result {
        object Valid : Result()      // budget still available
        object Expired : Result()    // 72h consumed — deny offline, require online
        data class Tampered(val reason: String) : Result() // rollback detected — deny + require online
    }

    /** Call on every successful online validation (re-anchors the budget).
     *  BOOT_COUNT read failure is stored as an explicit INVALID marker (-1 +
     *  validity flag) — distinguishable from any real counter value. */
    fun anchor(context: Context, entitlementExpMs: Long) {
        val boot = bootCount(context)
        prefs(context).edit()
            .putLong("ent_exp", entitlementExpMs)
            .putLong("anchor_elapsed", SystemClock.elapsedRealtime())
            .putInt("anchor_boot_count", boot ?: -1)       // -1 = read FAILED
            .putBoolean("boot_count_valid", boot != null)  // false → offline DENIED
            .putLong("anchor_wall", System.currentTimeMillis())
            .apply()
    }

        /** Called before granting offline usage (VPN connect / validation tick).
     *  BOOT_COUNT unavailable (unreadable at anchor or now) -> offline DENIED
     *  immediately - online validation is the only path. No wall fallback.
     *  This is the owner-confirmed policy (finalization pass 5). */
    fun check(context: Context): Result {
        val p = prefs(context)
        val exp = p.getLong("ent_exp", 0L)
        val anchorElapsed = p.getLong("anchor_elapsed", -1L)
        val bootValid = p.getBoolean("boot_count_valid", false) // false = BOOT_COUNT UNAVAILABLE
        val anchorBoot = p.getInt("anchor_boot_count", -1)      // -1 = read FAILED at anchor
        val anchorWall = p.getLong("anchor_wall", 0L)
        if (exp == 0L || anchorElapsed == -1L) return Result.Tampered("NO_ANCHOR")

        // HARD GATE: BOOT_COUNT unavailable at anchor -> offline DENIED.
        // No wall-clock fallback, no monotonic-only path, no exceptions.
        if (!bootValid) return Result.Tampered("BOOT_COUNT_UNAVAILABLE")

        val nowElapsed = SystemClock.elapsedRealtime()
        val nowBoot = bootCount(context) ?: return Result.Tampered("BOOT_COUNT_UNREADABLE")
        val nowWall = System.currentTimeMillis()

        // 1. Wall-clock rollback while running (and across reboots past the anchor)
        if (nowWall < anchorWall - TOLERANCE_MS) {
            return Result.Tampered("WALL_CLOCK_ROLLBACK")
        }

        // 2. Reboot detection (STRICT): any BOOT_COUNT mismatch -> offline
        //    DENIED immediately. elapsed-reset is the secondary signal.
        val rebooted = nowBoot != anchorBoot || nowElapsed < anchorElapsed

        // 3. Consumed-time estimate (only meaningful when NOT rebooted):
        //    - No reboot since anchor -> monotonic elapsed is AUTHORITATIVE
        //    - Reboot since anchor -> Result.Tampered (below) - budget discarded
        val consumed = nowElapsed - anchorElapsed

        // 4. Hardening signal: auto-time disabled -> user controls the clock
        val autoTime = Settings.Global.getInt(
            context.contentResolver, Settings.Global.AUTO_TIME, 1
        )
        if (autoTime == 0) {
            return Result.Tampered("AUTO_TIME_DISABLED")
        }

        return when {
            rebooted -> Result.Tampered("REBOOT_DETECTED")  // offline DENIED - online required
            consumed >= MAX_OFFLINE_MS || nowWall >= exp -> Result.Expired
            else -> Result.Valid
        }
    }

    /** System boot counter — increments on every boot, persists across reboots.
     *  Available since API 24 (app minSdk = 26 > 24, so always available).
     *  Read-only to apps on stock Android (root aside). Primary reboot detector.
     *  Returns NULL on READ FAILURE — distinguishable from any real value.
     *  When null → offline DENIED, online is the only path (04 §4.4 policy). */
    private fun bootCount(context: Context): Int? = try {
        Settings.Global.getInt(context.contentResolver, Settings.Global.BOOT_COUNT)
    } catch (_: Exception) {
        null   // setting unreadable → offline DENIED, online is the only path
    }

    /** Automatic time detection signal — Settings.Global.AUTO_TIME (API 17+).
     *  World-readable, no permissions needed. Value 1 = network time ON,
     *  0 = user controls the clock manually.
     *
     *  LIMITATION (04 §4.4): AUTO_TIME == 1 only confirms the user has NOT
     *  disabled the "Automatic date & time" toggle — it does NOT prove the
     *  time_detector service is actually syncing (NTP could fail silently
     *  while the toggle stays ON). MANAGE_TIME_AND_ZONE_DETECTION is
     *  signature|privileged and unavailable to normal apps. This is a weak
     *  positive signal; always combined with other time-integrity layers. */
    private fun isAutoTimeEnabled(context: Context): Boolean =
        Settings.Global.getInt(context.contentResolver, Settings.Global.AUTO_TIME, 1) == 1

    /** Anchor invalidation (logout / license revoked / entitlement cleared). */
    fun clear(context: Context) {
        prefs(context).edit().clear().apply()
    }

    private fun prefs(context: Context) = EncryptedSharedPreferences.create(
        "eligius_time_anchor",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        context,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )
}
```

**Offline behavior when `BOOT_COUNT` is unavailable or invalid** (owner-confirmed policy, finalization pass 5 — **no offline usage without BOOT_COUNT**):

| Condition | Mode | Offline behavior |
|-----------|------|------------------|
| BOOT_COUNT readable at anchor **AND** at check | **FULL** | Full checks — monotonic budget + BOOT_COUNT reboot detection. Offline granted while budget remains |
| BOOT_COUNT **unreadable at anchor** (read failure — distinguishable from value 0 via `boot_count_valid`) | **DENIED** | **Offline NOT granted at all** — online validation is the only path. No wall fallback, no monotonic-only path |
| Readable at anchor but **unreadable at check** | **DENIED** | **Offline terminated immediately** — require online validation |
| **Device rebooted** (BOOT_COUNT mismatch) | — | **Offline terminated** — require online validation (server re-anchors on success) |
| AUTO_TIME disabled | — | `Tampered("AUTO_TIME_DISABLED")` → require online |

**Policy summary:** BOOT_COUNT is a **hard prerequisite** for any offline usage. Without it, no wall-clock or monotonic fallback is attempted — the app simply requires one successful online validation to re-anchor. This is the strictest safe policy and avoids all documented clock-manipulation gaps.

**Integration into `LicenseStore.ensureValid()`** — the offline branch becomes:

```kotlin
// 3. Offline entitlement — gated by TimeIntegrity
//    BOOT_COUNT unavailable at anchor or check → offline DENIED (no fallback).
//    Only FULL mode allows offline usage; all Tampered results → online required.
val offline = offlineStore!!.get()
if (offline != null) {
    when (val t = TimeIntegrity.check(context!!)) {
        is TimeIntegrity.Result.Valid -> { /* proceed with offline state */ }
        is TimeIntegrity.Result.Expired ->
            return ValidationResult(false, "OFFLINE_GRACE_EXPIRED")
        is TimeIntegrity.Result.Tampered ->
            return ValidationResult(false, "TIME_TAMPERING: ${t.reason}") // BOOT_COUNT_UNAVAILABLE / WALL_CLOCK_ROLLBACK / etc
    }
}
```

**Integration into the periodic tick (P3)** — every 5 minutes, `LicenseStore` re-runs `ensureValid()`; if the result is `Tampered` or `Expired` while the tunnel is UP, `TunnelService.stop(ctx)` is invoked (CD-5). On the next successful online contact, `TimeIntegrity.anchor()` re-anchors the budget.

**Documented limitation (NOT hidden):** after a reboot with the network off, a **partial** wall-clock rollback (to a time still after the anchor) cannot be detected locally — the attacker gains the rolled-back duration. Full rollback (before the anchor) IS detected. Server-side retroactive anomaly detection (`entitlement.time_anomaly` audit event at the next contact) is the authoritative backstop — see 04 §4.4 Layer 3.

### 3.7 Retrofit API Services

```kotlin
// api/LicenseApiService.kt
package ir.mrkiyan.eligius.api

import ir.mrkiyan.eligius.api.model.*
import retrofit2.Response
import retrofit2.http.*

interface LicenseApiService {
    @GET("licenses/status")
    suspend fun getLicenseStatus(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<LicenseStatusResponse>>
    
    @GET("subscriptions/validate")
    suspend fun validateSubscription(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<ValidationResponse>>
    
    @GET("subscriptions/offline-entitlement")
    suspend fun getOfflineEntitlement(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<OfflineEntitlementResponse>>
    
    @GET("licenses/revocation-list")
    suspend fun getRevocationList(): Response<ApiResponse<RevocationListResponse>>
    
    @POST("licenses/renew")
    suspend fun renewLicense(
        @Body request: RenewLicenseRequest
    ): Response<ApiResponse<RenewResponse>>
}

// api/DeviceApiService.kt
interface DeviceApiService {
    @POST("devices/register")
    suspend fun registerDevice(
        @Header("X-Device-Hash") deviceHash: String,
        @Body request: DeviceRegisterRequest
    ): Response<ApiResponse<DeviceRegisterResponse>>
    
    @POST("devices/challenge")
    suspend fun getChallenge(
        @Header("X-Device-Hash") deviceHash: String,
        @Body request: ChallengeRequest
    ): Response<ApiResponse<ChallengeResponse>>
    
    @POST("devices/verify")
    suspend fun verifyDevice(
        @Header("X-Device-Hash") deviceHash: String,
        @Body request: DeviceVerifyRequest
    ): Response<ApiResponse<DeviceVerifyResponse>>
    
    @GET("devices")
    suspend fun listDevices(): Response<ApiResponse<DeviceListResponse>>
    
    @POST("devices/transfer/start")
    suspend fun startTransfer(
        @Header("X-Device-Hash") deviceHash: String,
        @Body request: TransferStartRequest
    ): Response<ApiResponse<TransferStartResponse>>
    
    @POST("devices/transfer/verify")
    suspend fun verifyTransfer(
        @Body request: TransferVerifyRequest
    ): Response<ApiResponse<TransferVerifyResponse>>
}

// api/PaymentApiService.kt
interface PaymentApiService {
    @POST("payments/create")
    suspend fun createPayment(
        @Body request: CreatePaymentRequest
    ): Response<ApiResponse<CreatePaymentResponse>>
    
    @GET("payments/{payment_id}")
    suspend fun getPaymentStatus(
        @Path("payment_id") paymentId: Long
    ): Response<ApiResponse<PaymentStatusResponse>>
    
    @GET("payments/history")
    suspend fun getPaymentHistory(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20
    ): Response<ApiResponse<PaymentHistoryResponse>>
}

// api/AuthApiService.kt (extends existing auth)
interface AuthApiService {
    @POST("auth/refresh")
    suspend fun refreshToken(): Response<ApiResponse<RefreshTokenResponse>>
    
    @POST("auth/otp-login-start")
    suspend fun otpLoginStart(@Body request: OtpLoginStartRequest): Response<ApiResponse<OtpStartResponse>>
    
    @POST("auth/otp-login-verify")
    suspend fun otpLoginVerify(@Body request: OtpLoginVerifyRequest): Response<ApiResponse<LoginResponse>>
}
```

### 3.8 API Models

```kotlin
// api/model/LicenseStatusResponse.kt
package ir.mrkiyan.eligius.api.model

data class LicenseStatusResponse(
    val valid: Boolean,
    val licenseId: Long?,
    val planKey: String?,
    val planLabel: String?,
    val status: String?,
    val startedAt: String?,
    val expiresAt: String?,
    val daysRemaining: Long?,
    val deviceBound: Boolean?,
    val deviceId: Long?,
    val offlineEntitlement: OfflineEntitlementData?
)

data class OfflineEntitlementData(
    val blob: String,
    val expiresAt: String,
    val kid: String
)

data class ValidationResponse(
    val valid: Boolean,
    val licenseId: Long?,
    val planKey: String?,
    val expiresAt: String?,
    val offlineEntitlement: OfflineEntitlementData?
)

data class OfflineEntitlementResponse(
    val blob: String,
    val expiresAt: String,
    val kid: String
)

data class RevocationListResponse(
    val revokedLicenseIds: List<Long>,
    val updatedAt: String,
    val nextUpdate: String
)

data class RenewResponse(
    val paymentId: Long,
    val redirectUrl: String,
    val extendsFrom: String,
    val newExpiresAt: String
)

// Requests
data class RenewLicenseRequest(
    val planKey: String,
    val gateway: String
)
```

---

### 3.9 `DEVICE_LIMIT_REACHED` — Explicit Client Behavior (CD-9)

When the server rejects a device operation with the one-active-device policy, the client MUST surface it explicitly — it is a **product state, not a generic error**:

1. **Detection:** the server responds `409` with `error.code = "eligius_device_limit_reached"` (or the API maps it to `DEVICE_LIMIT_REACHED`). `LicenseStore`/`DeviceApiService` map this code to a dedicated `DeviceLimitReached(activeDeviceId)` result — never into the generic error string.
2. **UI (LicenseStatusDialog variant):**
   - Title: «دستگاه دیگری فعال است» / "Another device is active"
   - Body: the **active device's label** (returned as `active_device_id` → resolved label via `/devices`) + two actions:
     - **انتقال با OTP** — starts the transfer workflow (`/devices/transfer/start` → SMS OTP → `/devices/transfer/verify`); after success the new device is bound and the old one is deactivated server-side
     - **مدیریت دستگاه‌ها** — deep link to the devices section
   - No retry button: retrying registration cannot succeed until a transfer/deactivation happens server-side
3. **VPN gate:** if `ensureValid()` receives this code at connect time → same dialog; the tunnel is never started.
4. **Periodic tick:** a bound device that loses its binding after a transfer from it gets `DEVICE_NOT_BOUND` on its next ≤5-min tick → tunnel stops (CD-5 latency), dialog «دستگاه دیگر فعال است».
5. **Audit:** the server has already logged `device.bound`/`device.transferred`; the client adds no local audit.

---

## 4. Integration Points in Existing Code

### 4.1 `TunnelService.kt` — License Gate (THE Critical Change)

```kotlin
// In TunnelService.kt, modify startSession()
private fun startSession() {
    promote()
    if (stopping || job?.isActive == true) return
    
    // ===== LICENSE GATE — THE ONLY MODIFICATION TO VPN CORE =====
    val licenseResult = LicenseStore.getInstance().ensureValid()
    
    if (!licenseResult.isValid) {
        val errorMsg = when (licenseResult.error) {
            "SUBSCRIPTION_EXPIRED" -> context.getString(R.string.license_expired)
            "NO_ACTIVE_LICENSE" -> context.getString(R.string.no_active_license)
            "DEVICE_NOT_REGISTERED" -> context.getString(R.string.device_not_registered)
            "LICENSE_REVOKED" -> context.getString(R.string.license_revoked)
            "DEVICE_NOT_BOUND" -> context.getString(R.string.device_not_bound)
            "NETWORK_ERROR" -> context.getString(R.string.network_error_try_later)
            else -> licenseResult.error ?: context.getString(R.string.license_check_failed)
        }
        
        Engine.lastReason.value = errorMsg
        Engine.status.value = Status(Phase.IDLE, 0)
        return
    }
    // ================================================================
    
    val s = SettingsStore.current
    Engine.logs.value = emptyList()
    Engine.lastReason.value = null
    Engine.resetLive()
    Engine.paused.value = false
    Engine.status.value = Status(Phase.CONNECTING, 1)
    networkChanged = false
    psiSlot = 0
    watchNetwork()
    job = scope.launch { run(s) }
}
```

### 4.2 `MainActivity.kt` — Connect Button Guard

```kotlin
// In MainActivity.kt, modify toggleConnect()
fun toggleConnect() {
    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
    if (st.phase == Phase.IDLE) {
        // LICENSE PRE-CHECK
        val licenseResult = LicenseStore.getInstance().ensureValid()
        if (!licenseResult.isValid) {
            showLicenseErrorDialog(licenseResult.error)
            return
        }
        
        val i = if (s.vpnMode) VpnService.prepare(ctx) else null
        if (i != null) vpnLauncher.launch(i) else TunnelService.start(ctx)
    } else if (st.phase != Phase.STOPPING) {
        TunnelService.stop(ctx)
    }
    screen = HOME
}
```

### 4.3 `AuthStore.kt` — Extended Methods (Already Reviewed)

```kotlin
// Already added in AuthStore.kt review:
// - registerOtpStart(..., hash: String?)
// - registerOtpVerify(mobile, code)
// - forgotStart(mobile, hash)
// - forgotVerify(mobile, code) -> Result<String> (reset_token)
// - forgotReset(token, password)
```

### 4.4 `AuthStore.kt` — License-Aware Login

```kotlin
suspend fun login(user: String, pass: String): Result<Session> = withContext(Dispatchers.IO) {
    runCatching {
        jwtLogin(user, pass) ?: formLogin(user, pass)
            ?: throw AuthException(null)
    }.flatMap { session ->
        // After successful login, validate license immediately
        LicenseStore.getInstance().ensureValid().fold(
            onSuccess = { Result.success(session) },
            onFailure = { Result.failure(AuthException(it)) }
        )
    }
}
```

### 4.5 `MainActivity.kt` — Router Addition

```kotlin
// Add to router constants
private const val AUTH_FORGOT = 9

// In Root() when block:
AUTH_FORGOT -> AuthForgotScreen(onBack = { screen = AUTH })
```

### 4.6 `AuthScreen.kt` — Forgot Password Link

```kotlin
// Add after login button, before register link
TextButton(
    onClick = onForgot,
    modifier = Modifier.align(Alignment.CenterHorizontally),
    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 0.dp)
) {
    Text(
        stringResource(R.string.forgot_password),
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.primary
    )
}
```

---

## 5. UI Components (New Screens)

### 5.1 `PlanSelectionScreen.kt`

```kotlin
@Composable
fun PlanSelectionScreen(
    onPlanSelected: (Plan) -> Unit,
    onBack: () -> Unit
) {
    val plans = remember { Plan.allPlans() }
    
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text("Choose Your Plan", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(8.dp))
        Text("Select a subscription plan", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(24.dp))
        
        LazyColumn(
            verticalArrangement = Arrangement.spacedBy(16.dp),
            contentPadding = PaddingValues(vertical = 8.dp)
        ) {
            items(plans.filterNot { it.isTrial }) { plan ->
                PlanCard(plan = plan, onClick = { onPlanSelected(plan) })
            }
        }
        
        Spacer(Modifier.height(16.dp))
        TextButton(onClick = onBack) {
            Text("Back", fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
fun PlanCard(plan: Plan, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val discount = plan.discountPercent
    
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = c.surfaceContainer),
        onClick = onClick
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Column {
                    Text(plan.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                    Text("${plan.durationDays} days", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("₹${formatPrice(plan.priceIrt)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = c.primary)
                    if (plan.discountPercent > 0) {
                        Text("${plan.discountPercent}% OFF", style = MaterialTheming.typography.labelSmall, color = c.tertiary)
                    }
                }
            }
        }
    }
}
```

### 5.2 `LicenseStatusDialog.kt`

```kotlin
@Composable
fun LicenseStatusDialog(
    licenseState: LicenseStore.LicenseState,
    onDismiss: () -> Unit,
    onRenew: () -> Unit
) {
    val c = MaterialTheme.colorScheme
    val isExpired = licenseState.status == "EXPIRED"
    val isRevoked = licenseState.status == "REVOKED"
    val isTrial = licenseState.planKey == "trial"
    
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (isExpired) "Subscription Expired" else if (isRevoked) "License Revoked" else "Subscription Required") },
        text = {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(when {
                    isRevoked -> "Your license has been revoked. Contact support."
                    isExpired -> "Your subscription has expired. Renew to continue using Eligius."
                    licenseState.planKey == "trial" -> "Your free trial has ended. Choose a plan to continue."
                    else -> "No active subscription found."
                }, style = MaterialTheme.typography.bodyMedium)
                
                licenseState.planLabel?.let { plan ->
                    Text("Plan: $plan", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                
                licenseState.expiresAt?.let { exp ->
                    Text("Expires: $exp", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onDismiss(); onRenew() }) {
                Text("Choose Plan")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("Later")
            }
        }
    )
}
```

### 5.3 `PaymentActivity.kt`

```kotlin
class PaymentActivity : ComponentActivity() {
    private val paymentViewModel: PaymentViewModel by viewModels()
    
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            EligiusTheme {
                var plan by remember { mutableStateOf<Plan?>(null) }
                var gateway by remember { mutableStateOf("zarinpal") }
                
                PaymentScreen(
                    onPlanSelected = { plan = it },
                    onGatewaySelected = { gateway = it },
                    onPay = { plan, gateway ->
                        paymentViewModel.createPayment(plan, gateway)
                    },
                    onCancel = { finish() }
                )
            }
        }
    }
}
```

---

## 6. Dependency Injection (Manual)

```kotlin
// App.kt additions
class App : Application() {
    override fun onCreate() {
        super.onCreate()
        SettingsStore.init(this)
        AuthStore.init(this)
        Engine.init(this)
        
        // NEW: Initialize subscription components
        LicenseStore.init(this)
        DeviceIdentity.init(this)
        OfflineEntitlementStore.init(this)
        RevocationListManager.init(this)
        SecureTokenStore.init(this)
    }
}
```

---

## 7. Gradle Dependencies

```kotlin
// app/build.gradle.kts
dependencies {
    // ... existing ...
    
    // Network
    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-moshi:2.11.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.moshi:moshi:1.15.1")
    implementation("com.squareup.moshi:moshi-kotlin:1.15.1")
    kapt("com.squareup.moshi:moshi-kotlin-codegen:1.15.1")
    
    // Security
    implementation("androidx.security:security-crypto:1.1.0-alpha03")
    implementation("org.bouncycastle:bcprov-jdk18on:1.78")
    
    // Google Play Services (SMS Retriever)
    implementation("com.google.android.gms:play-services-auth:21.2.0")
    implementation("com.google.android.gms:play-services-auth-api-phone:18.0.2")
    
    // Coroutines
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    
    // Serialization
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.6.3")
}
```

---

## 8. ProGuard Rules

```proguard
# Retrofit/Moshi
-keep class com.squareup.moshi.** { *; }
-keep class com.squareup.retrofit2.** { *; }
-keep interface retrofit2.** { *; }
-keep class com.squareup.okhttp3.** { *; }

# Moshi JSON
-keep class ir.mrkiyan.eligius.api.model.** { *; }
-keep class ir.mrkiyan.eligius.api.** { *; }

# Security crypto
-keep class androidx.security.crypto.** { *; }

# BouncyCastle
-keep class org.bouncycastle.** { *; }

# OkHttp/Okio
-keep class okhttp3.** { *; }
-keep class okio.** { *; }

# Kotlinx serialization
-keep class kotlinx.serialization.** { *; }
```

---

## 9. Build Config (for embedded keys)

```kotlin
// app/build.gradle.kts
android {
    defaultConfig {
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V1", "\"MCowBQYDK2VwAyEA...\"")
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V2", "\"MCowBQYDK2VwAyEA...\"")
        buildConfigField("String", "EMBEDDED_PUBLIC_KEY_V3", "\"MCowBQYDK2VwAyEA...\"")
        buildConfigField("int", "MAX_OFFLINE_HOURS", "72")
    }
}
```

```kotlin
// Usage in OfflineEntitlementValidator
private val EMBEDDED_PUBLIC_KEYS = mapOf(
    "v1" to BuildConfig.EMBEDDED_PUBLIC_KEY_V1,
    "v2" to BuildConfig.EMBEDDED_PUBLIC_KEY_V2,
    "v3" to BuildConfig.EMBEDDED_PUBLIC_KEY_V3
)
```

---

## 10. Permissions (AndroidManifest.xml)

```xml
<!-- Add to manifest if not present -->
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
<uses-permission android:name="android.permission.WAKE_LOCK" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_SPECIAL_USE" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<!-- Payment activity with deep link -->
<activity
    android:name=".PaymentActivity"
    android:exported="true"
    android:launchMode="singleTop">
    <intent-filter>
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="eligius" android:host="payment" android:pathPrefix="/result" />
    </intent-filter>
</activity>
```

---

*End of Android Integration Contract*