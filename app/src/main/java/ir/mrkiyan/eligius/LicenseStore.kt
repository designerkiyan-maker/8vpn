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
        val error: String? = null,
        /** P9.2: volume quota (null total = unlimited; client-trusted metering). */
        val volumeUsedBytes: Long = 0,
        val volumeTotalBytes: Long? = null,
        val volumePercent: Int? = null,
        val volumeExhausted: Boolean = false
    )

    data class ValidationResult(
        val isValid: Boolean,
        val error: String? = null,
        /** Extra payload for UI flows (e.g. active device id on limit reached). */
        val activeDeviceId: Long? = null,
        /**
         * Phase 5b: REAL backend trial eligibility (server gate = has_trial_ever).
         * Only true when the server reported NO_ACTIVE_LICENSE on an ACTIVE
         * device for an account that has never used its trial — the trial
         * banner must never be driven by local cache emptiness alone.
         */
        val trialEligible: Boolean = false,
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
                // P9.2: a cached quota exhaustion blocks the VPN even though
                // the license itself is still valid — refresh happens online.
                if (cached.volumeExhausted) {
                    return ValidationResult(false, "VOLUME_EXHAUSTED", licenseState = cached)
                }
                return ValidationResult(true, licenseState = cached)
            }
        }

        // 4. Offline entitlement (72h max) — gated by TimeIntegrity (STRICT).
        //    Phase 6 final closure (owner: "Recovery پس از رفع خطا"): a
        //    TimeIntegrity DENIAL (Expired/Tampered) denies the OFFLINE path
        //    but must NOT block the ONLINE path — docs 04 §4.4 states
        //    "offline DENIED, online validation REQUIRED". The denial reason
        //    surfaces only when the online validation ALSO fails; a successful
        //    online validation re-anchors the budget (full recovery).
        var timeIntegrityDenial: String? = null
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
                            offline = true,
                            volumeUsedBytes = cached?.volumeUsedBytes ?: 0,
                            volumeTotalBytes = cached?.volumeTotalBytes,
                            volumePercent = cached?.volumePercent,
                            volumeExhausted = cached?.volumeExhausted ?: false
                        )
                        // P9.2: a cached quota exhaustion blocks the offline
                        // grant too (offline volume enforcement is cache-based).
                        if (cached?.volumeExhausted == true) {
                            licenseState.value = state
                            return ValidationResult(false, "VOLUME_EXHAUSTED", licenseState = state)
                        }
                        licenseState.value = state
                        lastCheckedAt.value = System.currentTimeMillis()
                        return ValidationResult(true, licenseState = state)
                    }
                    // Invalid/expired blob → fall through to online validation.
                }
                is TimeIntegrity.Result.Expired ->
                    timeIntegrityDenial = "OFFLINE_GRACE_EXPIRED"
                is TimeIntegrity.Result.Tampered ->
                    timeIntegrityDenial = "TIME_TAMPERING: ${t.reason}"
            }
        }

        // 5. Online validation (server-authoritative) — always attempted when
        //    the offline path did not grant. On failure, the TimeIntegrity
        //    denial (when present) is the more specific error surfaced to the
        //    user; a successful online validation re-anchors everything.
        val online = onlineValidation(ctx)
        if (online.isValid || timeIntegrityDenial == null) return online
        return ValidationResult(false, timeIntegrityDenial)
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
                deviceId = data.deviceId,
                volumeUsedBytes = data.volumeUsedBytes ?: 0,
                volumeTotalBytes = data.volumeTotalBytes,
                volumePercent = data.volumePercent,
                volumeExhausted = data.volumeExhausted
            )

            if (state.valid && state.volumeExhausted) {
                // P9.2: quota consumed — the license is valid but the VPN gate
                // blocks; the offline entitlement is NOT refreshed (offline
                // enforcement below relies on this cached flag instead).
                licenseState.value = state
                lastCheckedAt.value = System.currentTimeMillis()
                return ValidationResult(false, "VOLUME_EXHAUSTED", licenseState = state)
            }

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
            // Phase 5b: carry the REAL backend trial eligibility and the
            // active-device id (pending-transfer path) into the gate result.
            val errCode = data.error ?: "NO_ACTIVE_LICENSE"
            licenseState.value = state.copy(valid = false)
            return ValidationResult(
                false,
                errCode,
                activeDeviceId = data.activeDeviceId,
                trialEligible = data.trialEligible
            )
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

    /**
     * P9.2.1 (owner bug report): refresh the cached state's quota fields from
     * a session-report response — the subscription card shows fresh numbers
     * immediately after a disconnect instead of waiting for the next
     * ensureValid (which does not run while the tunnel is down).
     */
    fun updateVolumeFromReport(usedBytes: Long, totalBytes: Long?, percent: Int?, exhausted: Boolean) {
        val cached = licenseState.value ?: return
        if (!cached.valid) return
        licenseState.value = cached.copy(
            volumeUsedBytes = usedBytes.coerceAtLeast(0),
            volumeTotalBytes = totalBytes,
            volumePercent = percent,
            volumeExhausted = exhausted
        )
        // The server now includes the finished session — the carry-over must
        // go or the card would double-count it.
        Engine.finishedSessionBytes.value = 0L
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
