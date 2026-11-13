package ir.mrkiyan.eligius

import android.content.Context
import ir.mrkiyan.eligius.api.RetrofitClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.format.DateTimeParseException

/**
 * License state, validation and the VPN enforcement gate (Roadmap P3,
 * docs 06 §3.1/§4.1, CD-4/CD-5/CD-9).
 *
 * ensureValid() resolution order (per docs):
 *   1. Authentication check
 *   2. Device binding VERIFIED (Phase 2 async binding must be confirmed
 *      before the VPN is allowed — CD-9)
 *   3. Cached license state (5 min TTL) + CRL revocation check
 *   4. Offline entitlement (72h max) gated by TimeIntegrity (strict
 *      BOOT_COUNT policy — no DEGRADED mode, no wall fallback)
 *   5. Online validation (server-authoritative; re-anchors TimeIntegrity)
 */
object LicenseStore {

    private const val CACHE_TTL_MS: Long = 5 * 60 * 1000L

    data class LicenseState(
        val valid: Boolean,
        val licenseId: Long? = null,
        val planKey: String? = null,
        val planLabel: String? = null,
        val status: String? = null, // ACTIVE, EXPIRED, REVOKED, TRIAL
        val startedAt: String? = null,
        val expiresAt: String? = null,
        val deviceBound: Boolean = false,
        val deviceId: Long? = null,
        val offline: Boolean = false, // true when granted from the offline entitlement
        val error: String? = null
    )

    data class ValidationResult(
        val isValid: Boolean,
        val error: String? = null,
        /** Extra payload for UI flows (e.g. active device id on limit reached). */
        val activeDeviceId: Long? = null,
        val licenseState: LicenseState? = null
    )

    private var context: Context? = null
    private var offlineStore: OfflineEntitlementStore? = null
    private var revocationManager: RevocationListManager? = null

    val licenseState = MutableStateFlow<LicenseState?>(null)
    val isChecking = MutableStateFlow<Boolean>(false)
    val lastCheckedAt = MutableStateFlow<Long>(0L)

    private val gateMutex = Mutex()

    fun init(ctx: Context) {
        context = ctx.applicationContext
        offlineStore = OfflineEntitlementStore(ctx)
        revocationManager = RevocationListManager(ctx)
    }

    private fun ctx(): Context = context ?: error("LicenseStore.init(context) not called")

    /** Main entry point — called before VPN connection and on the 5-min tick. */
    suspend fun ensureValid(): ValidationResult = withContext(Dispatchers.IO) {
        gateMutex.withLock {
            isChecking.value = true
            try {
                ensureValidLocked()
            } finally {
                isChecking.value = false
            }
        }
    }

    private suspend fun ensureValidLocked(): ValidationResult {
        val ctx = try { ctx() } catch (_: IllegalStateException) {
            return ValidationResult(false, "NOT_INITIALIZED")
        }

        // 1. Authentication
        if (!AuthStore.isLoggedIn) return ValidationResult(false, "NOT_LOGGED_IN")

        // 2. Device binding must be VERIFIED (Phase 2 registers asynchronously
        //    after login; the VPN is not allowed before the binding is proven).
        when (val bind = AuthStore.deviceBinding.value) {
            is AuthStore.DeviceBindingState.Verified -> { /* proceed */ }
            is AuthStore.DeviceBindingState.LimitReached ->
                return ValidationResult(false, "DEVICE_LIMIT_REACHED", bind.activeDeviceId)
            else -> {
                // Registration/verification may still be in flight (or was
                // skipped) — run the idempotent binding pass once now.
                runCatching { AuthStore.ensureDeviceRegistered() }
                when (val st = AuthStore.deviceBinding.value) {
                    is AuthStore.DeviceBindingState.Verified -> { /* proceed */ }
                    is AuthStore.DeviceBindingState.LimitReached ->
                        return ValidationResult(false, "DEVICE_LIMIT_REACHED", st.activeDeviceId)
                    else -> return ValidationResult(false, "DEVICE_NOT_BOUND")
                }
            }
        }

        // 3. Cached license state (5 min TTL) + revocation check.
        val cached = licenseState.value
        if (cached != null && cached.valid && !cached.offline &&
            System.currentTimeMillis() - lastCheckedAt.value <= CACHE_TTL_MS
        ) {
            val licenseId = cached.licenseId
            if (licenseId != null && revocationManager!!.isRevoked(licenseId)) {
                licenseState.value = cached.copy(valid = false, status = "REVOKED")
                return ValidationResult(false, "LICENSE_REVOKED")
            }
            if (!isExpiredIso(cached.expiresAt)) {
                return ValidationResult(true, licenseState = cached)
            }
        }

        // 4. Offline entitlement (72h max) — gated by TimeIntegrity (STRICT).
        val offline = offlineStore!!.get()
        if (offline != null) {
            when (val t = TimeIntegrity.check(ctx)) {
                is TimeIntegrity.Result.Valid -> {
                    val v = OfflineEntitlementValidator.validate(offline.blob)
                    if (v.isValid) {
                        // The blob is bound to the device it was issued for —
                        // an entitlement from another device must not apply here.
                        val currentHash = runCatching { DeviceIdentity.getDeviceHash(ctx) }.getOrNull()
                        if (currentHash != null && v.deviceHash != null && v.deviceHash != currentHash) {
                            return ValidationResult(false, "DEVICE_NOT_BOUND")
                        }
                        val licenseId = v.licenseId ?: 0L
                        if (revocationManager!!.isRevoked(licenseId)) {
                            return ValidationResult(false, "LICENSE_REVOKED")
                        }
                        val state = LicenseState(
                            valid = true,
                            licenseId = v.licenseId,
                            status = cached?.status ?: "ACTIVE",
                            expiresAt = epochToIso(v.expiresAt),
                            deviceBound = true,
                            offline = true
                        )
                        licenseState.value = state
                        lastCheckedAt.value = System.currentTimeMillis()
                        return ValidationResult(true, licenseState = state)
                    }
                    // Invalid/expired blob → fall through to online validation.
                }
                is TimeIntegrity.Result.Expired ->
                    return ValidationResult(false, "OFFLINE_GRACE_EXPIRED")
                is TimeIntegrity.Result.Tampered ->
                    return ValidationResult(false, "TIME_TAMPERING: ${t.reason}")
            }
        }

        // 5. Online validation (server-authoritative).
        return onlineValidation(ctx)
    }

    private suspend fun onlineValidation(ctx: Context): ValidationResult {
        val deviceHash = runCatching { DeviceIdentity.getDeviceHash(ctx) }.getOrNull()
            ?: return ValidationResult(false, "DEVICE_NOT_REGISTERED")

        try {
            val response = RetrofitClient.licenseApi.getLicenseStatus(deviceHash)
            if (!response.isSuccessful) {
                val code = RetrofitClient.errorCode(response)
                val msg = RetrofitClient.errorMessage(response)
                return ValidationResult(false, code ?: msg ?: "HTTP ${response.code()}")
            }
            val data = response.body()?.data
                ?: return ValidationResult(false, "EMPTY_RESPONSE")

            val state = LicenseState(
                valid = data.valid,
                licenseId = data.licenseId,
                planKey = data.planKey,
                planLabel = data.planLabel,
                status = data.status,
                startedAt = data.startedAt,
                expiresAt = data.expiresAt,
                deviceBound = data.deviceBound == true,
                deviceId = data.deviceId
            )

            if (state.valid) {
                licenseState.value = state
                lastCheckedAt.value = System.currentTimeMillis()

                // Store the offline entitlement + anchor the time budget.
                data.offlineEntitlement?.let { ent ->
                    val kid = ent.kidOrPublicKeyId
                    if (kid.isNotBlank()) {
                        val expMs = parseIso8601(ent.expiresAt)
                            ?: (System.currentTimeMillis() + TimeIntegrity.MAX_OFFLINE_MS)
                        offlineStore!!.save(
                            OfflineEntitlementStore.OfflineEntitlement(ent.blob, expMs, kid)
                        )
                        val v = OfflineEntitlementValidator.validate(ent.blob)
                        if (v.isValid) {
                            TimeIntegrity.anchor(ctx, v.expiresAt)
                        }
                    }
                }
                return ValidationResult(true, licenseState = state)
            }

            // Server answered with a non-valid license state.
            val errCode = data.error ?: "NO_ACTIVE_LICENSE"
            licenseState.value = state.copy(valid = false)
            return ValidationResult(false, errCode)
        } catch (e: Exception) {
            // Network error — the offline entitlement was already tried above
            // (step 4); if we reached here, no valid offline path exists.
            return ValidationResult(false, "NETWORK_ERROR: ${e.message}")
        }
    }

    /** Non-network snapshot of the stored offline entitlement (LicenseCacheGate). */
    fun offlineEntitlementSnapshot(): OfflineEntitlementStore.OfflineEntitlement? =
        try { offlineStore?.get() } catch (_: Exception) { null }

    /** Force the next ensureValid() to go online (payments, manual refresh). */
    fun invalidateCache() {
        lastCheckedAt.value = 0L
    }

    /** Logout/revocation cleanup. */
    fun clearAll(ctx: Context) {
        licenseState.value = null
        lastCheckedAt.value = 0L
        offlineStore?.clear()
        TimeIntegrity.clear(ctx)
    }

    // ------------------------------------------------------------------ util

    private fun isExpiredIso(iso: String?): Boolean {
        val ms = parseIso8601(iso) ?: return false // unknown → treat as valid
        return System.currentTimeMillis() >= ms
    }

    private fun parseIso8601(date: String?): Long? {
        if (date.isNullOrBlank()) return null
        return try {
            Instant.parse(date).toEpochMilli()
        } catch (_: DateTimeParseException) {
            try {
                // Fallback for "yyyy-MM-dd HH:mm:ss" (server DATETIME, UTC).
                java.time.LocalDateTime.parse(date.replace(' ', 'T'))
                    .toInstant(java.time.ZoneOffset.UTC).toEpochMilli()
            } catch (_: Exception) {
                null
            }
        }
    }

    private fun epochToIso(ms: Long): String =
        Instant.ofEpochMilli(ms).toString()
}
