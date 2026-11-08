package ir.mrkiyan.eligius

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.os.Build
import android.util.Base64
import android.util.Log
import androidx.core.content.ContextCompat
import com.google.android.gms.auth.api.phone.SmsRetriever
import com.google.android.gms.common.ConnectionResult
import com.google.android.gms.common.GoogleApiAvailability
import com.google.android.gms.common.api.CommonStatusCodes
import com.google.android.gms.common.api.Status
import java.nio.charset.StandardCharsets
import java.security.MessageDigest

/**
 * SMS OTP auto-fill (Stage 21) — two complementary mechanisms, both
 * permission-free (no READ_SMS):
 *
 *  1. SMS Retriever  — fully automatic; requires the app's 11-char signature
 *     hash to be the LAST thing in the SMS text. The app sends [appHash] to the
 *     server with every OTP request and the server appends it to the message.
 *  2. SMS User Consent — fallback that works with ANY incoming SMS (including
 *     the plain pattern text): the system shows a small consent dialog and the
 *     user taps once; the message then lands in the app.
 *
 * Both fire the same callbacks; manual entry always stays available on top.
 */
object SmsOtp {

    private const val TAG = "SmsOtp"

    /** Which hash candidate to send to the server (index into [hashCandidates]).
     *  0 = the Google-sample algorithm (9 hash bytes + 0x00 + package-name-length
     *  byte, Base64, first 11 chars). The emulator brute-force test proves which
     *  variant actually fires the Retriever — see the Stage 21 test notes. */
    private const val HASH_VARIANT = 0

    /** First 6-digit run in the message = the OTP. */
    fun extractCode(message: String?): String? =
        message?.let { Regex("\\d{6}").find(it)?.value }

    /** The hash to append to OTP SMS texts (empty when unavailable / no Play services). */
    fun appHash(ctx: Context): String =
        hashCandidates(ctx).getOrNull(HASH_VARIANT)?.takeIf { it.length == 11 } ?: ""

    /**
     * Candidate 11-char signature hashes. Variant 0 follows the official
     * AppSignatureHelper sample (SHA-256 over "package cert-hex", 9 bytes +
     * 0x00 + package-name length byte, Base64, first 11 chars); the others
     * exist because the reference source was unreachable from this network —
     * the emulator brute-force test (adb emu sms send + logcat) shows which
     * one the Retriever accepts.
     */
    fun hashCandidates(ctx: Context): List<String> {
        val pn = ctx.packageName
        val sig = signingHex(ctx) ?: return emptyList()
        val appInfo = "$pn $sig"
        return runCatching {
            val digest = MessageDigest.getInstance("SHA-256").digest(appInfo.toByteArray(StandardCharsets.UTF_8))
            val b64 = { bytes: ByteArray ->
                Base64.encodeToString(bytes, Base64.NO_WRAP or Base64.NO_PADDING)
            }
            listOf(
                b64(digest.copyOf(9) + byteArrayOf(0x00, pn.length.toByte())).take(11), // A: official-sample shape
                b64(digest.copyOf(9) + byteArrayOf(pn.length.toByte())).take(11),       // B: 9+1 bytes
                b64(digest.copyOf(11)).take(11),                                        // C: 11 raw bytes
                b64(digest.copyOf(9)).take(11),                                         // D: 9 bytes only
            )
        }.getOrElse {
            Log.w(TAG, "hashCandidates failed", it)
            emptyList()
        }
    }

    private fun signingHex(ctx: Context): String? = try {
        val pm = ctx.packageManager
        if (Build.VERSION.SDK_INT >= 28) {
            val info = pm.getPackageInfo(ctx.packageName, PackageManager.GET_SIGNING_CERTIFICATES)
            info.signingInfo?.apkContentsSigners?.firstOrNull()?.toCharsString()
        } else {
            @Suppress("DEPRECATION")
            pm.getPackageInfo(ctx.packageName, PackageManager.GET_SIGNATURES)
                .signatures?.firstOrNull()?.toCharsString()
        }
    } catch (_: Exception) {
        null
    }

    /**
     * Starts both listeners and registers the broadcast receiver.
     * Returns a handle whose [AutoFill.dispose] unregisters it — call from
     * a DisposableEffect.
     */
    fun start(
        ctx: Context,
        onCode: (String) -> Unit,          // Retriever hit: message parsed, code extracted
        onConsent: (PendingIntent) -> Unit // Consent path: UI must launch the dialog
    ): AutoFill {
        val gms = GoogleApiAvailability.getInstance().isGooglePlayServicesAvailable(ctx)
        if (gms != ConnectionResult.SUCCESS) {
            Log.w(TAG, "Google Play services unavailable ($gms) — manual OTP entry only")
            return AutoFill { }
        }

        val receiver = object : BroadcastReceiver() {
            override fun onReceive(context: Context, intent: Intent) {
                if (intent.action != SmsRetriever.SMS_RETRIEVED_ACTION) return
                val extras = intent.extras ?: return
                val status = extras.get(SmsRetriever.EXTRA_STATUS) as? Status ?: return
                if (status.statusCode != CommonStatusCodes.SUCCESS) {
                    Log.w(TAG, "SMS_RETRIEVED with status ${status.statusCode}")
                    return
                }
                val message = extras.getString(SmsRetriever.EXTRA_SMS_MESSAGE)
                if (message != null) {
                    val code = extractCode(message)
                    Log.i(TAG, "Retriever: got SMS (${message.length} chars), code=$code")
                    if (code != null) onCode(code)
                } else {
                    @Suppress("DEPRECATION")
                    val consent = extras.getParcelable<PendingIntent>(SmsRetriever.EXTRA_CONSENT_INTENT)
                    if (consent != null) {
                        Log.i(TAG, "Consent dialog needed for the incoming SMS")
                        onConsent(consent)
                    }
                }
            }
        }
        val filter = IntentFilter(SmsRetriever.SMS_RETRIEVED_ACTION)
        ContextCompat.registerReceiver(ctx, receiver, filter, ContextCompat.RECEIVER_NOT_EXPORTED)

        // Arm both listeners; whichever matches first delivers via the receiver.
        SmsRetriever.getClient(ctx).startSmsRetriever()
            .addOnSuccessListener { Log.i(TAG, "Retriever armed") }
            .addOnFailureListener { Log.w(TAG, "Retriever failed: ${it.message}") }
        SmsRetriever.getClient(ctx).startSmsUserConsent(null)
            .addOnSuccessListener { Log.i(TAG, "Consent listener armed") }
            .addOnFailureListener { Log.w(TAG, "Consent failed: ${it.message}") }

        return AutoFill { runCatching { ctx.unregisterReceiver(receiver) } }
    }

    class AutoFill(val dispose: () -> Unit)
}
