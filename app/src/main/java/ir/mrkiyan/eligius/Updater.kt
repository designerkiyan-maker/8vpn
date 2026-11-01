package ir.mrkiyan.eligius

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * Only checks the latest GitHub release of Eligius. It never downloads or installs anything.
 * It points at this project's own release feed, which is the official Eligius release
 * infrastructure (github.com/designerkiyan-maker/8vpn) — never at the old 8 VPN feed.
 */
object Updater {
    const val ENABLED = true
    private const val RELEASES_API = "https://api.github.com/repos/designerkiyan-maker/8vpn/releases/latest"
    private const val RELEASES_PAGE = "https://github.com/designerkiyan-maker/8vpn/releases/latest"

    data class Info(val tag: String, val newer: Boolean, val url: String)

    suspend fun check(): Result<Info> = withContext(Dispatchers.IO) {
        runCatching {
            if (RELEASES_API.isEmpty()) error("Update checking is not configured for this build")
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
                val page = j.optString("html_url", RELEASES_PAGE)
                Info(tag, isNewer(tag, BuildConfig.VERSION_NAME), page)
            } finally {
                con.disconnect()
            }
        }
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
