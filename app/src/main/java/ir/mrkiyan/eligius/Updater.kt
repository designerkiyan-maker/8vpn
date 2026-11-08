package ir.mrkiyan.eligius

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * In-app update channel, currently checking GitHub releases
 * (repos/designerkiyan-maker/8vpn/releases/latest) — the APK matching the
 * device ABI is downloaded into the app cache and handed to the system
 * installer. Switching the check to the self-hosted WordPress endpoint
 * (/wp-json/eligius/v1/version) later is a drop-in change in check() alone.
 */
object Updater {
    const val ENABLED = true
    private const val RELEASES_API = "https://api.github.com/repos/designerkiyan-maker/8vpn/releases/latest"
    private const val ASSET_BASE = "https://github.com/designerkiyan-maker/8vpn/releases/download"

    data class Info(val version: String, val newer: Boolean, val apkUrl: String)

    suspend fun check(): Result<Info> = withContext(Dispatchers.IO) {
        runCatching {
            val con = (URL(RELEASES_API).openConnection() as HttpURLConnection).apply {
                connectTimeout = 10_000
                readTimeout = 15_000
                setRequestProperty("Accept", "application/vnd.github+json")
                setRequestProperty("User-Agent", "eligius-android")
            }
            try {
                if (con.responseCode != 200) error("HTTP ${con.responseCode}")
                val j = JSONObject(con.inputStream.bufferedReader().use { it.readText() })
                val tag = j.getString("tag_name")
                Info(
                    version = tag,
                    newer = isNewer(tag, BuildConfig.VERSION_NAME),
                    apkUrl = apkUrl(tag),
                )
            } finally {
                con.disconnect()
            }
        }
    }

    /** The release APK that matches this device's primary ABI (universal as the safe default). */
    fun apkUrl(tag: String): String = "$ASSET_BASE/$tag/Eligius-${abiAsset()}.apk"

    private fun abiAsset(): String {
        val abi = Build.SUPPORTED_ABIS.firstOrNull() ?: return "universal"
        return when {
            abi.startsWith("arm64") -> "arm64-v8a"
            abi.startsWith("armeabi") -> "armeabi-v7a"
            else -> "universal"
        }
    }

    /** True when this app may hand APKs to the system installer (one-time user grant). */
    fun canInstall(ctx: Context): Boolean = ctx.packageManager.canRequestPackageInstalls()

    /** The system screen where the user flips the "install unknown apps" switch for this app. */
    fun unknownSourcesIntent(ctx: Context): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES)
            .setData(Uri.parse("package:" + ctx.packageName))
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    /** Streams [url] into [dest], reporting 0-100 progress while it runs. */
    suspend fun download(url: String, dest: File, onProgress: (Int) -> Unit): Result<Unit> =
        withContext(Dispatchers.IO) {
            runCatching {
                dest.parentFile?.mkdirs()
                if (dest.exists()) dest.delete()
                val con = (URL(url).openConnection() as HttpURLConnection).apply {
                    connectTimeout = 15_000
                    readTimeout = 30_000
                    setRequestProperty("User-Agent", "eligius-android")
                }
                try {
                    if (con.responseCode != 200) error("HTTP ${con.responseCode}")
                    val total = con.contentLengthLong
                    con.inputStream.use { input ->
                        dest.outputStream().use { out ->
                            val buf = ByteArray(64 * 1024)
                            var read = 0L
                            var lastPct = -1
                            while (true) {
                                val n = input.read(buf)
                                if (n < 0) break
                                out.write(buf, 0, n)
                                read += n
                                if (total > 0) {
                                    val pct = (read * 100 / total).toInt()
                                    if (pct != lastPct) {
                                        lastPct = pct
                                        onProgress(pct.coerceIn(0, 100))
                                    }
                                }
                            }
                        }
                    }
                    if (total > 0 && dest.length() != total) error("Incomplete download")
                    // Sanity: the payload must really be an APK (PK zip magic), not an error page.
                    val magic = ByteArray(2)
                    dest.inputStream().use { it.read(magic) }
                    if (magic[0] != 'P'.code.toByte() || magic[1] != 'K'.code.toByte()) {
                        dest.delete()
                        error("Downloaded file is not an APK")
                    }
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Hands the downloaded APK to the system installer. Returns false when nothing could start it. */
    fun launchInstall(ctx: Context, file: File): Boolean = try {
        val uri = FileProvider.getUriForFile(ctx, ctx.packageName + ".fileprovider", file)
        ctx.startActivity(
            Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        )
        true
    } catch (_: Exception) {
        false
    }

    private fun parts(v: String) = v.trim().trimStart('v', 'V').split('.', '-', '_')
        .map { p -> p.takeWhile { it.isDigit() }.toIntOrNull() ?: 0 }

    fun isNewer(remote: String, local: String): Boolean {
        val a = parts(remote); val b = parts(local)
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrElse(i) { 0 }; val y = b.getOrElse(i) { 0 }
            if (x != y) return x > y
        }
        return false
    }
}
