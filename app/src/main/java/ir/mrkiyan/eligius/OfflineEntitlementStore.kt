package ir.mrkiyan.eligius

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Encrypted storage for the signed Offline Entitlement blob
 * (Roadmap P3 — OfflineEntitlementStore, doc 06 §3.3).
 */
class OfflineEntitlementStore(context: Context) {

    data class OfflineEntitlement(
        val blob: String,
        val expiresAt: Long, // epoch millis
        val kid: String
    )

    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()

    private val encryptedPrefs = EncryptedSharedPreferences.create(
        context,
        "eligius_offline_entitlement",
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    fun save(entitlement: OfflineEntitlement) {
        encryptedPrefs.edit()
            .putString("blob", entitlement.blob)
            .putLong("expires_at", entitlement.expiresAt)
            .putString("kid", entitlement.kid)
            .apply()
    }

    /** Returns the stored blob when present and within its wall-clock validity. */
    fun get(): OfflineEntitlement? {
        val blob = encryptedPrefs.getString("blob", null) ?: return null
        val expiresAt = encryptedPrefs.getLong("expires_at", 0L)
        val kid = encryptedPrefs.getString("kid", "") ?: ""
        if (System.currentTimeMillis() >= expiresAt) {
            clear()
            return null
        }
        return OfflineEntitlement(blob, expiresAt, kid)
    }

    fun clear() {
        encryptedPrefs.edit().clear().apply()
    }
}
