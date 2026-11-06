package ir.mrkiyan.eligius

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * Self-hosted update channel — zero external dependencies.
 *
 * The check goes to the project's own WordPress site (wp.mrkiyan.ir):
 *   GET /wp-json/eligius/v1/version  ->  { "version": "1.3.4", "apk_url": "https://..." }
 * The site owner publishes a new release by updating two constants in
 * server/eligius-register.php and uploading the new APK to the site.
 * No GitHub, no third-party service is involved anywhere in the flow.
 */
object Updater {
    const val ENABLED = true
    private const val VERSION_API = "https://wp.mrkiyan.ir/wp-json/eligius/v1/version"

    data class Info(val version: String, val newer: Boolean, val apkUrl: String)

    suspend fun check(): Result<Info> = withContext(Dispatchers.IO) {
        runCatching {
            val con = (URL(VERSION_API).openConnection() as HttpURLConnection).apply {
                connectTimeout = 10_000
                readTimeout = 15_000
                setRequestProperty("User-Agent", "eligius-android")
            }
            try {
                if (con.responseCode != 200) error("HTTP ${con.responseCode}")
                val j = JSONObject(con.inputStream.bufferedReader().use { it.readText() })
                val version = j.getString("version")
                Info(
                    version = version,
                    newer = isNewer(version, BuildConfig.VERSION_NAME),
                    apkUrl = j.optString("apk_url"),
                )
            } finally {
                con.disconnect()
            }
        }
    }

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

    /** True when this app may hand APKs to the system installer (one-time user grant). */
    fun canInstall(ctx: Context): Boolean = ctx.packageManager.canRequestPackageInstalls()

    /** The system screen where the user flips the "install unknown apps" switch for this app. */
    fun unknownSourcesIntent(ctx: Context): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES)
            .setData(Uri.parse("package:" + ctx.packageName))
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

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
