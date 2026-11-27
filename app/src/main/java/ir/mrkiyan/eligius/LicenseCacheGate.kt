package ir.mrkiyan.eligius

import android.content.Context

/**
 * Phase 3 license cache gate for passive entry points (BootReceiver, QsTile)
 * where a network call is not possible: checks the CACHED license state and
 * the offline entitlement locally (no network). Cached-state-only check:
 * the authoritative gate runs in TunnelService.startSession() anyway.
 */
object LicenseCacheGate {

    /** True when a previously-verified license state is still valid locally:
     *  cached ACTIVE/TRIAL state not past expiresAt, or a valid offline
     *  entitlement under TimeIntegrity. No network is performed. */
    fun isCachedLicenseUsable(context: Context): Boolean {
        val cached = LicenseStore.licenseState.value
        if (cached != null && cached.valid && !cached.offline) {
            // P9.2: a cached quota exhaustion is NOT usable (no network here —
            // the authoritative refresh happens on the next online validation).
            if (cached.volumeExhausted) return false
            val expMs = runCatching { java.time.Instant.parse(cached.expiresAt).toEpochMilli() }.getOrNull()
            if (expMs == null || System.currentTimeMillis() < expMs) return true
        }
        // Offline entitlement path (TimeIntegrity strict check, no network).
        val entitlement = LicenseStore.offlineEntitlementSnapshot() ?: return false
        when (TimeIntegrity.check(context)) {
            is TimeIntegrity.Result.Valid -> {
                val v = OfflineEntitlementValidator.validate(entitlement.blob)
                return v.isValid
            }
            else -> return false
        }
    }
}
