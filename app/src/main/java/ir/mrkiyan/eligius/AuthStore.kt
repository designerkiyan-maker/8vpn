package ir.mrkiyan.eligius

import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/** Login failure carrying a message ready for the UI (server text when available). */
class AuthException(val uiMessage: String?) : Exception(uiMessage)

/**
 * Eligius account session, backed by the WordPress installation at [WP_BASE].
 *
 * Login is tried against the standard JWT endpoint first (installing the free
 * "JWT Authentication for WP REST API" plugin upgrades the flow to tokens automatically);
 * when that route is missing the classic wp-login.php form POST is used instead, so the
 * gate works against a stock WordPress with no server changes. Registration itself happens
 * in-app on the WordPress register page (see [registerUrl]); after confirming the email the
 * user signs in with username + password.
 */
object AuthStore {
    private const val WP_BASE = "https://wp.mrkiyan.ir"

    /** A signed-in account. [exp] is epoch seconds; 0 = no known expiry. */
    data class Session(val user: String, val token: String?, val exp: Long)

    val flow = MutableStateFlow<Session?>(null)
    val isLoggedIn: Boolean get() = flow.value != null
    val registerUrl: String get() = "$WP_BASE/wp-login.php?action=register"

    private var prefs: SharedPreferences? = null

    fun init(ctx: Context) {
        if (prefs != null) return
        prefs = ctx.applicationContext.getSharedPreferences("auth", Context.MODE_PRIVATE)
        val user = prefs!!.getString("user", null)
        val exp = prefs!!.getLong("exp", 0L)
        if (user != null && (exp == 0L || exp > System.currentTimeMillis() / 1000)) {
            flow.value = Session(user, prefs!!.getString("token", null), exp)
        } else {
            prefs!!.edit().clear().apply()
        }
    }

    fun logout() {
        flow.value = null
        prefs?.edit()?.clear()?.apply()
    }

    suspend fun login(user: String, pass: String): Result<Session> = withContext(Dispatchers.IO) {
        runCatching {
            jwtLogin(user, pass)   // null when the JWT route does not exist on the server
                ?: formLogin(user, pass)
                ?: throw AuthException(null)
        }
    }

    /** Login through the JWT plugin. Returns null when the route is not installed. */
    private fun jwtLogin(user: String, pass: String): Session? {
        val payload = "{\"username\":${jsonStr(user)},\"password\":${jsonStr(pass)}}"
        val con = (URL("$WP_BASE/wp-json/jwt-auth/v1/token").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            setRequestProperty("Content-Type", "application/json")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        try {
            con.outputStream.use { it.write(payload.toByteArray()) }
            val code = con.responseCode
            val body = (if (code in 200..299) con.inputStream else con.errorStream)
                ?.bufferedReader()?.use { it.readText() } ?: ""
            if (body.contains("rest_no_route")) return null   // plugin missing → form fallback
            val j = JSONObject(body)
            if (code != 200) {
                throw AuthException(
                    j.optJSONObject("data")?.optString("message")?.takeIf { it.isNotEmpty() }
                        ?: j.optString("message").takeIf { it.isNotEmpty() }
                )
            }
            val token = j.optString("token")
            val name = j.optJSONObject("data")?.optString("user_display_name")?.takeIf { it.isNotEmpty() } ?: user
            return save(Session(name, token.ifEmpty { null }, jwtExp(token)))
        } finally {
            con.disconnect()
        }
    }

    /** Login through the classic wp-login.php form — works on a stock WordPress, no plugins. */
    private fun formLogin(user: String, pass: String): Session? {
        val body = "log=${URLEncoder.encode(user, "UTF-8")}" +
            "&pwd=${URLEncoder.encode(pass, "UTF-8")}" +
            "&wp-submit=Log+In" +
            "&redirect_to=${URLEncoder.encode("$WP_BASE/", "UTF-8")}" +
            "&testcookie=1"
        val con = (URL("$WP_BASE/wp-login.php").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            instanceFollowRedirects = false
            setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            setRequestProperty("Cookie", "wordpress_test_cookie=WP%20Cookie%20check")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        try {
            con.outputStream.use { it.write(body.toByteArray()) }
            val code = con.responseCode
            val cookies = con.headerFields?.get("set-cookie")?.joinToString("; ") ?: ""
            if (cookies.contains("wordpress_logged_in")) {
                // 14 days, the same lifetime WordPress gives "remember me" sessions.
                return save(Session(user, null, System.currentTimeMillis() / 1000 + 14 * 24 * 3600L))
            }
            val html = (if (code in 200..299) con.inputStream else con.errorStream)
                ?.bufferedReader()?.use { it.readText() } ?: ""
            throw AuthException(extractWpError(html))
        } finally {
            con.disconnect()
        }
    }

    /** Pulls the human-readable error out of the failed login page, when present. */
    private fun extractWpError(html: String): String? = try {
        Regex("<div[^>]*id=\"login_error\"[^>]*>(.*?)</div>", RegexOption.DOT_MATCHES_ALL)
            .find(html)?.groupValues?.get(1)
            ?.replace(Regex("<[^>]+>"), " ")
            ?.trim()
            ?.take(200)
            ?.takeIf { it.isNotEmpty() }
    } catch (_: Exception) {
        null
    }

    private fun jwtExp(token: String): Long = try {
        val payload = String(
            Base64.decode(token.split(".")[1], Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP)
        )
        JSONObject(payload).optLong("exp", 0L)
    } catch (_: Exception) {
        0L
    }

    private fun save(s: Session): Session {
        flow.value = s
        prefs?.edit()?.putString("user", s.user)?.putString("token", s.token)?.putLong("exp", s.exp)?.apply()
        return s
    }

    private fun jsonStr(v: String) = "\"" + v.replace("\\", "\\\\").replace("\"", "\\\"") + "\""
}
