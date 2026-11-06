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
    data class Session(
        val user: String,        // display name
        val username: String,    // login name
        val email: String,
        val token: String?,
        val exp: Long,
    )

    /** Full profile as reported by /wp-json/wp/v2/users/me (null = not fetched / form session). */
    data class Profile(
        val id: Long,
        val username: String,
        val name: String,
        val email: String,
        val registered: String,
        val role: String,
    )

    val flow = MutableStateFlow<Session?>(null)
    val profile = MutableStateFlow<Profile?>(null)
    val isLoggedIn: Boolean get() = flow.value != null
    val registerUrl: String get() = "$WP_BASE/wp-login.php?action=register"

    private var prefs: SharedPreferences? = null

    fun init(ctx: Context) {
        if (prefs != null) return
        prefs = ctx.applicationContext.getSharedPreferences("auth", Context.MODE_PRIVATE)
        val user = prefs!!.getString("user", null)
        val exp = prefs!!.getLong("exp", 0L)
        if (user != null && (exp == 0L || exp > System.currentTimeMillis() / 1000)) {
            flow.value = Session(
                user,
                prefs!!.getString("username", null) ?: user,
                prefs!!.getString("email", "") ?: "",
                prefs!!.getString("token", null),
                exp,
            )
        } else {
            prefs!!.edit().clear().apply()
        }
    }

    fun logout() {
        flow.value = null
        profile.value = null
        prefs?.edit()?.clear()?.apply()
    }

    /**
     * Fetches the full WordPress profile (id, username, email, registered date, role) with the
     * stored JWT. Only possible for token sessions; form-login sessions stay on the basics.
     */
    suspend fun fetchProfile(): Result<Profile> = withContext(Dispatchers.IO) {
        runCatching {
            val token = flow.value?.token ?: error("No token session")
            val con = (URL("$WP_BASE/wp-json/wp/v2/users/me").openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("User-Agent", "eligius-android")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                if (con.responseCode != 200) error("HTTP ${con.responseCode}")
                val j = JSONObject(con.inputStream.bufferedReader().use { it.readText() })
                val roles = j.optJSONArray("roles")
                Profile(
                    id = j.optLong("id", 0L),
                    username = j.optString("username", flow.value?.username ?: ""),
                    name = j.optString("name", flow.value?.user ?: ""),
                    email = j.optString("email", flow.value?.email ?: ""),
                    registered = j.optString("registered_date", ""),
                    role = if (roles != null && roles.length() > 0) roles.getString(0) else "",
                ).also { profile.value = it }
            } finally {
                con.disconnect()
            }
        }
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
            val data = j.optJSONObject("data")
            val name = data?.optString("user_display_name")?.takeIf { it.isNotEmpty() } ?: user
            val email = data?.optString("user_email")?.takeIf { it.isNotEmpty() } ?: ""
            return save(Session(name, user, email, token.ifEmpty { null }, jwtExp(token)))
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
                return save(Session(user, user, "", null, System.currentTimeMillis() / 1000 + 14 * 24 * 3600L))
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
        prefs?.edit()
            ?.putString("user", s.user)
            ?.putString("username", s.username)
            ?.putString("email", s.email)
            ?.putString("token", s.token)
            ?.putLong("exp", s.exp)
            ?.apply()
        return s
    }

    private fun jsonStr(v: String) = "\"" + v.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

    /**
     * Registers a new account via the Eligius register endpoint
     * (server/eligius-register.php on the WordPress host):
     * POST /wp-json/eligius/v1/register — mobile doubles as the username,
     * mobile + national code are stored as user meta. Auto-login on success.
     */
    suspend fun register(
        firstName: String,
        lastName: String,
        email: String,
        mobile: String,
        nationalCode: String,
        password: String
    ): Result<Session> = withContext(Dispatchers.IO) {
        runCatching {
            val payload = JSONObject().apply {
                put("username", mobile)
                put("email", email)
                put("password", password)
                put("first_name", firstName)
                put("last_name", lastName)
                put("mobile", mobile)
                put("national_code", nationalCode)
            }.toString()

            val con = (URL("$WP_BASE/wp-json/eligius/v1/register").openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
                setRequestProperty("User-Agent", "eligius-android")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                con.outputStream.use { it.write(payload.toByteArray()) }
                val code = con.responseCode
                val body = (if (code in 200..299) con.inputStream else con.errorStream)
                    ?.bufferedReader()?.use { it.readText() } ?: ""
                if (body.contains("rest_no_route")) {
                    // The server/eligius-register.php mu-plugin is not installed on the host yet.
                    throw AuthException(
                        "Registration service is not ready. / سرویس ثبت‌نام هنوز روی سرور فعال نیست."
                    )
                }
                val j = JSONObject(body)
                if (code != 200) {
                    throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                }
                login(mobile, password).getOrElse {
                    Session(mobile, mobile, email, null, 0L)   // account exists; user signs in
                }
            } finally {
                con.disconnect()
            }
        }
    }
}
