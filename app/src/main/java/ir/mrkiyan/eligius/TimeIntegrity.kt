package ir.mrkiyan.eligius

import android.content.Context
import android.os.SystemClock
import android.provider.Settings
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Monotonic time accounting for the 72-hour offline entitlement (CD-4/CD-10
 * docs — 06 §3.6, 04 §4.4, owner-confirmed STRICT policy):
 *
 *  - SystemClock.elapsedRealtime() anchors the consumed budget within one boot
 *    (monotonic, includes deep sleep; wall clock is user-controllable).
 *  - Settings.Global.BOOT_COUNT is the PRIMARY reboot detector (persists
 *    across reboots, read-only to apps; available since API 24, minSdk 26).
 *  - STRICT policy: BOOT_COUNT unreadable at anchor OR at check → offline
 *    DENIED immediately (no wall fallback, no monotonic-only path).
 *    Any BOOT_COUNT change → offline DENIED, online validation required.
 *  - AUTO_TIME off → offline DENIED (hardening signal).
 *  - No DEGRADED mode exists. Wall clock is never a substitute after reboot.
 */
object TimeIntegrity {
    private const val TOLERANCE_MS = 60_000L // wall-clock rollback tolerance: 60s
    const val MAX_OFFLINE_MS: Long = 72L * 60 * 60 * 1000 // CD-4: 72 hours

    sealed class Result {
        object Valid : Result()      // budget still available
        object Expired : Result()    // 72h consumed — deny offline, require online
        data class Tampered(val reason: String) : Result() // deny + require online
    }

    /** Call on every successful online validation (re-anchors the budget).
     *  BOOT_COUNT read failure is stored as an explicit INVALID marker
     *  (-1 + boot_count_valid=false) — distinguishable from any real value. */
    fun anchor(context: Context, entitlementExpMs: Long) {
        val boot = bootCount(context)
        prefs(context).edit()
            .putLong("ent_exp", entitlementExpMs)
            .putLong("anchor_elapsed", SystemClock.elapsedRealtime())
            .putInt("anchor_boot_count", boot ?: -1)        // -1 = read FAILED
            .putBoolean("boot_count_valid", boot != null)   // false → offline DENIED
            .putLong("anchor_wall", System.currentTimeMillis())
            .apply()
    }

    /** Called before granting offline usage (VPN connect / validation tick). */
    fun check(context: Context): Result {
        val p = prefs(context)
        val exp = p.getLong("ent_exp", 0L)
        val anchorElapsed = p.getLong("anchor_elapsed", -1L)
        val bootValid = p.getBoolean("boot_count_valid", false) // false = UNAVAILABLE
        val anchorBoot = p.getInt("anchor_boot_count", -1)      // -1 = FAILED at anchor
        val anchorWall = p.getLong("anchor_wall", 0L)
        if (exp == 0L || anchorElapsed == -1L) return Result.Tampered("NO_ANCHOR")

        // HARD GATE: BOOT_COUNT unavailable at anchor → offline DENIED.
        if (!bootValid) return Result.Tampered("BOOT_COUNT_UNAVAILABLE")

        val nowElapsed = SystemClock.elapsedRealtime()
        val nowBoot = bootCount(context) ?: return Result.Tampered("BOOT_COUNT_UNREADABLE")
        val nowWall = System.currentTimeMillis()

        // 1. Wall-clock rollback while running (past the anchor).
        if (nowWall < anchorWall - TOLERANCE_MS) {
            return Result.Tampered("WALL_CLOCK_ROLLBACK")
        }

        // 2. Reboot detection (STRICT): any BOOT_COUNT mismatch → offline DENIED.
        //    elapsed-reset is the secondary signal.
        val rebooted = nowBoot != anchorBoot || nowElapsed < anchorElapsed

        // 3. Consumed-time estimate (only meaningful when NOT rebooted):
        //    monotonic elapsed is AUTHORITATIVE within the same boot.
        val consumed = nowElapsed - anchorElapsed

        // 4. Hardening signal: auto-time disabled → user controls the clock.
        val autoTime = try {
            Settings.Global.getInt(context.contentResolver, Settings.Global.AUTO_TIME, 1)
        } catch (_: Exception) {
            1
        }
        if (autoTime == 0) {
            return Result.Tampered("AUTO_TIME_DISABLED")
        }

        return when {
            rebooted -> Result.Tampered("REBOOT_DETECTED") // offline DENIED — online required
            consumed >= MAX_OFFLINE_MS || nowWall >= exp -> Result.Expired
            else -> Result.Valid
        }
    }

    /** System boot counter — increments on every boot, persists across reboots.
     *  Available since API 24 (minSdk 26 covers it). Returns NULL on read
     *  failure — distinguishable from any real value; null → offline DENIED. */
    private fun bootCount(context: Context): Int? = try {
        Settings.Global.getInt(context.contentResolver, Settings.Global.BOOT_COUNT)
    } catch (_: Exception) {
        null
    }

    /** Anchor invalidation (logout / license revoked / entitlement cleared). */
    fun clear(context: Context) {
        prefs(context).edit().clear().apply()
    }

    private fun prefs(context: Context) = EncryptedSharedPreferences.create(
        context,
        "eligius_time_anchor",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )
}
