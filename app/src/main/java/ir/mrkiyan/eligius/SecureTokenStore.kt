package ir.mrkiyan.eligius

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Encrypted storage for the session JWT (Roadmap P3 — SecureTokenStore,
 * doc 06 §7.2). AES256-GCM master key in the Android Keystore; pref values
 * AES256-GCM encrypted, keys AES256-SIV.
 */
object SecureTokenStore {

    private const val FILE = "eligius_tokens"
    private const val KEY_TOKEN = "access_token"
    private const val KEY_EXP = "token_expires_at"

    private var prefs: android.content.SharedPreferences? = null

    @Synchronized
    private fun prefs(ctx: Context): android.content.SharedPreferences {
        prefs?.let { return it }
        val masterKey = MasterKey.Builder(ctx)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        val created = EncryptedSharedPreferences.create(
            ctx,
            FILE,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
        prefs = created
        return created
    }

    fun saveToken(ctx: Context, token: String, expiresAtEpochSec: Long) {
        prefs(ctx).edit()
            .putString(KEY_TOKEN, token)
            .putLong(KEY_EXP, expiresAtEpochSec)
            .apply()
    }

    /** Returns the stored JWT when present and not expired; null otherwise. */
    fun getToken(ctx: Context): String? {
        val p = prefs(ctx)
        val token = p.getString(KEY_TOKEN, null) ?: return null
        val exp = p.getLong(KEY_EXP, 0L)
        if (exp > 0 && System.currentTimeMillis() / 1000 >= exp) return null
        return token
    }

    fun clear(ctx: Context) {
        prefs(ctx).edit().clear().apply()
    }
}
