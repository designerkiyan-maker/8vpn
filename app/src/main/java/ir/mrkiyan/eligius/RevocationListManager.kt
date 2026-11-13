package ir.mrkiyan.eligius

import android.content.Context
import ir.mrkiyan.eligius.api.RetrofitClient

/**
 * CRL (revocation list) caching — 5-minute TTL (docs 06 §3.4, 03 §9,
 * CD-5: revocation latency ≤5 minutes online; offline revocations are not
 * detectable and the entitlement stays valid until its 72h expiry).
 */
class RevocationListManager(private val context: Context) {

    private val cache = mutableMapOf<Long, Boolean>()
    @Volatile private var lastUpdated = 0L
    @Volatile private var refreshing = false

    companion object {
        const val CACHE_TTL_MS: Long = 5 * 60 * 1000L // 5 minutes (CD-5)
    }

    /**
     * Suspends: refreshes the list when the cache is stale (5 min), then
     * checks [licenseId]. On network failure the STALE cache is kept
     * (fail-open to the last known list — revocation latency stays ≤5 min
     * online; offline freshness follows connectivity).
     */
    suspend fun isRevoked(licenseId: Long): Boolean {
        if (System.currentTimeMillis() - lastUpdated > CACHE_TTL_MS) {
            refresh()
        }
        return cache[licenseId] == true
    }

    suspend fun refresh() {
        if (refreshing) return
        refreshing = true
        try {
            val ctx = context
            val response = RetrofitClient.licenseApi.getRevocationList(
                DeviceIdentity.getDeviceHash(ctx)
            )
            if (response.isSuccessful) {
                val data = response.body()?.data
                if (data != null) {
                    cache.clear()
                    data.revokedLicenseIds.forEach { cache[it] = true }
                    lastUpdated = System.currentTimeMillis()
                }
            }
        } catch (_: Exception) {
            // Keep stale cache on error (docs 06 §3.4).
        } finally {
            refreshing = false
        }
    }
}
