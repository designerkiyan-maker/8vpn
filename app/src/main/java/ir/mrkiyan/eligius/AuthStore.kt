package ir.mrkiyan.eligius

import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import ir.mrkiyan.eligius.api.RetrofitClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
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

    /** Lifetime usage as stored in the WordPress database (server-authoritative). */
    data class UsageServer(val rxBytes: Long, val txBytes: Long, val durationMs: Long, val sessions: Int)

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

    /** Current JWT for API layers (null = no token session). */
    fun sessionToken(): String? = flow.value?.token

    /**
     * Phase 6: TEST-ONLY session restore — lets the instrumented suite drive
     * ensureValid() with a real JWT (fetched from the test backend) without
     * driving the login UI. Never called by any production flow.
     */
    @androidx.annotation.VisibleForTesting
    internal fun restoreSessionForTest(user: String, token: String, exp: Long) {
        flow.value = Session(user, user, "", token, exp)
    }

    val profile = MutableStateFlow<Profile?>(null)
    val usageServer = MutableStateFlow<UsageServer?>(null)
    val isLoggedIn: Boolean get() = flow.value != null
    val registerUrl: String get() = "$WP_BASE/wp-login.php?action=register"

    private var prefs: SharedPreferences? = null
    private var appContext: Context? = null

    fun init(ctx: Context) {
        if (prefs != null) return
        appContext = ctx.applicationContext
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

    /** Lifetime usage of the signed-in user, from the WordPress database (JWT session only). */
    suspend fun fetchUsage(): Result<UsageServer> = withContext(Dispatchers.IO) {
        runCatching {
            val token = flow.value?.token ?: error("no JWT session")
            val con = (URL("$WP_BASE/wp-json/eligius/v1/licenses/usage").openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("User-Agent", "eligius-android")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                if (con.responseCode != 200) error("HTTP ${con.responseCode}")
                val j = JSONObject(con.inputStream.bufferedReader().use { it.readText() })
                val d = j.optJSONObject("data") ?: JSONObject()
                // P9.2.1: PER-SUBSCRIPTION-PERIOD stats (owner decision) — a
                // fresh purchase row starts at zero, so the Account usage card
                // resets when the user buys a plan. Server is authoritative.
                UsageServer(
                    rxBytes = d.optLong("used_rx_bytes"),
                    txBytes = d.optLong("used_tx_bytes"),
                    durationMs = d.optLong("used_duration_ms"),
                    sessions = d.optInt("used_sessions"),
                ).also { usageServer.value = it }
            } finally {
                con.disconnect()
            }
        }
    }

    /** Reports one finished session; the server accumulates it into lifetime usage. */
    suspend fun reportUsage(rx: Long, tx: Long, durationMs: Long): Result<UsageServer> =
        withContext(Dispatchers.IO) {
            runCatching {
                val token = flow.value?.token ?: error("no JWT session")
                val payload = JSONObject()
                    .put("rx_bytes", rx.coerceAtLeast(0))
                    .put("tx_bytes", tx.coerceAtLeast(0))
                    .put("duration_ms", durationMs.coerceAtLeast(0))
                    .put("sessions", 1)
                    .toString()
                val con = (URL("$WP_BASE/wp-json/eligius/v1/licenses/usage").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    doOutput = true
                    setRequestProperty("Authorization", "Bearer $token")
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
                    val j = JSONObject(body)
                    if (code != 200) {
                        throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                    }
                    // P9.2.1: PER-SUBSCRIPTION-PERIOD stats (owner decision —
                    // resets on a new purchase; server row starts at zero).
                    val d = j.optJSONObject("data") ?: JSONObject()
                    UsageServer(
                        rxBytes = d.optLong("used_rx_bytes"),
                        txBytes = d.optLong("used_tx_bytes"),
                        durationMs = d.optLong("used_duration_ms"),
                        sessions = d.optInt("used_sessions"),
                    ).also { usageServer.value = it }
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Step 1 of the OTP registration: validates everything server-side, creates
     *  the account (username = mobile) and sends a 6-digit code via SMS.
     *  [hash] = the SMS-Retriever signature hash so the SMS can be auto-read.
     *  TD-10 (owner decision, CD-6): NO identity data beyond the essential
     *  fields — national_code is no longer collected or sent. */
    suspend fun registerOtpStart(
        mobile: String,
        email: String,
        password: String,
        firstName: String,
        lastName: String,
        hash: String? = null
    ): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching {
            val payload = org.json.JSONObject()
                .put("mobile", mobile)
                .put("email", email)
                .put("password", password)
                .put("first_name", firstName)
                .put("last_name", lastName)
                .apply { if (!hash.isNullOrEmpty()) put("hash", hash) }
                .apply {
                    // Phase 2 (CD-10): announce the Keystore device identity with the
                    // registration attempt. The server validates it on device register.
                    appContext?.let { put("device_hash", DeviceIdentity.getDeviceHash(it)) }
                }
                .toString()
            val con = (URL("$WP_BASE/wp-json/eligius/v1/register/otp-start").openConnection() as HttpURLConnection).apply {
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
                val j = JSONObject(body)
                if (code != 200) {
                    throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                }
            } finally {
                con.disconnect()
            }
        }
    }

    /** Step 2: verifies the SMS code; the account then logs in with mobile + password. */
    suspend fun registerOtpVerify(mobile: String, code: String): Result<Unit> =
        withContext(Dispatchers.IO) {
            runCatching {
                val payload = org.json.JSONObject()
                    .put("mobile", mobile)
                    .put("code", code)
                    .toString()
                val con = (URL("$WP_BASE/wp-json/eligius/v1/register/otp-verify").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json")
                    setRequestProperty("User-Agent", "eligius-android")
                    connectTimeout = 10_000
                    readTimeout = 15_000
                }
                try {
                    con.outputStream.use { it.write(payload.toByteArray()) }
                    val rcode = con.responseCode
                    val body = (if (rcode in 200..299) con.inputStream else con.errorStream)
                        ?.bufferedReader()?.use { it.readText() } ?: ""
                    val j = JSONObject(body)
                    if (rcode != 200) {
                        throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                    }
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Forgot password step 1: asks the server to OTP-verify an EXISTING,
     *  mobile-verified account and send the code. */
    suspend fun forgotStart(mobile: String, hash: String? = null): Result<Unit> =
        withContext(Dispatchers.IO) {
            runCatching {
                val payload = org.json.JSONObject()
                    .put("mobile", mobile)
                    .apply { if (!hash.isNullOrEmpty()) put("hash", hash) }
                    .apply {
                        appContext?.let { put("device_hash", DeviceIdentity.getDeviceHash(it)) }
                    }
                    .toString()
                val con = (URL("$WP_BASE/wp-json/eligius/v1/register/forgot-start").openConnection() as HttpURLConnection).apply {
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
                    val j = JSONObject(body)
                    if (code != 200) {
                        throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                    }
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Forgot password step 2: verifies the code, returns the single-use reset token. */
    suspend fun forgotVerify(mobile: String, code: String): Result<String> =
        withContext(Dispatchers.IO) {
            runCatching {
                val payload = org.json.JSONObject()
                    .put("mobile", mobile)
                    .put("code", code)
                    .toString()
                val con = (URL("$WP_BASE/wp-json/eligius/v1/register/forgot-verify").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json")
                    setRequestProperty("User-Agent", "eligius-android")
                    connectTimeout = 10_000
                    readTimeout = 15_000
                }
                try {
                    con.outputStream.use { it.write(payload.toByteArray()) }
                    val rcode = con.responseCode
                    val body = (if (rcode in 200..299) con.inputStream else con.errorStream)
                        ?.bufferedReader()?.use { it.readText() } ?: ""
                    val j = JSONObject(body)
                    if (rcode != 200) {
                        throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                    }
                    j.optString("reset_token").takeIf { it.isNotEmpty() }
                        ?: error("no reset token in response")
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Forgot password step 3: exchanges the reset token for the new password. */
    suspend fun forgotReset(token: String, password: String): Result<Unit> =
        withContext(Dispatchers.IO) {
            runCatching {
                val payload = org.json.JSONObject()
                    .put("reset_token", token)
                    .put("password", password)
                    .toString()
                val con = (URL("$WP_BASE/wp-json/eligius/v1/register/forgot-reset").openConnection() as HttpURLConnection).apply {
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
                    val j = JSONObject(body)
                    if (code != 200) {
                        throw AuthException(j.optString("message").takeIf { it.isNotEmpty() })
                    }
                } finally {
                    con.disconnect()
                }
            }
        }

    /** Minimal JSON string escaping for hand-built payloads. */
    private fun jsonStr(s: String): String =
        "\"" + s.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

    /** Login through the JWT plugin. Returns null when the route is not installed. */
    private fun jwtLogin(user: String, pass: String): Session? {
        val payload = "{\"username\":${jsonStr(user)},\"password\":${jsonStr(pass)}}"
        val con = (URL("$WP_BASE/wp-json/jwt-auth/v1/token").openConnection() as HttpURLConnection).apply {
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
            if (code != 200) return null
            val j = JSONObject(body)
            val token = j.optString("token", "")
            if (token.isEmpty()) return null
            return Session(
                user = j.optString("user_display_name", user),
                username = j.optString("user_nicename", user),
                email = j.optString("user_email", ""),
                token = token,
                exp = (System.currentTimeMillis() / 1000) + 14 * 24 * 3600L
            ).also {
                // Owner bug report (v1.3.13 live test): the session was
                // persisted but flow was never emitted → the router stayed on
                // AUTH until an app restart. Publishing the session here is
                // what flips the router to Home (MainActivity LaunchedEffect).
                flow.value = it
                prefs?.edit()
                    ?.putString("user", it.user)
                    ?.putString("username", it.username)
                    ?.putString("email", it.email)
                    ?.putString("token", token)
                    ?.putLong("exp", it.exp)
                    ?.apply()
                appContext?.let { ctx -> SecureTokenStore.saveToken(ctx, token, it.exp) }
            }
        } finally {
            con.disconnect()
        }
    }

    /**
     * Legacy wp-login.php form fallback (cookie session, no JWT plugin).
     * Kept for stock-WordPress hosts without the JWT plugin.
     */
    private fun formLogin(user: String, pass: String): Session? {
        val body = StringBuilder().apply {
            append("log=").append(URLEncoder.encode(user, "UTF-8"))
            append("&pwd=").append(URLEncoder.encode(pass, "UTF-8"))
            append("&rememberme=forever")
            append("&wp-submit=Log+In")
            append("&redirect_to=").append(URLEncoder.encode("$WP_BASE/", "UTF-8"))
            append("&testcookie=1")
        }.toString()
        val con = (URL("$WP_BASE/wp-login.php").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            instanceFollowRedirects = false
            setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            setRequestProperty("Cookie", "wordpress_test_cookie=WP%20Cookie%20check")
            setRequestProperty("User-Agent", "eligius-android")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        try {
            con.outputStream.use { it.write(body.toByteArray()) }
            val code = con.responseCode
            val cookies = con.headerFields?.get("Set-Cookie") ?: emptyList()
            val sessionCookies = cookies.filter { it.startsWith("wordpress_logged_in") }
            if (code !in 200..302 || sessionCookies.isEmpty()) return null
            val cookie = sessionCookies.joinToString("; ") { it.substringBefore(';') }
            return Session(
                user = user,
                username = user,
                email = "",
                token = null,
                exp = (System.currentTimeMillis() / 1000) + 14 * 24 * 3600L
            ).also {
                flow.value = it   // same router-flip fix as jwtLogin
                prefs?.edit()
                    ?.putString("user", user)
                    ?.putString("username", user)
                    ?.putString("email", "")
                    ?.putString("cookie", cookie)
                    ?.putLong("exp", it.exp)
                    ?.apply()
            }
        } finally {
            con.disconnect()
        }
    }

    suspend fun login(user: String, pass: String): Result<Session> = withContext(Dispatchers.IO) {
        runCatching {
            jwtLogin(user, pass)   // null when the JWT route does not exist on the server
                ?: formLogin(user, pass)
                ?: throw AuthException(null)
        }.also { result ->
            // Phase 2 (CD-10): after a successful JWT session, bind the Keystore
            // device in the background — never blocks/fails the login itself.
            val session = result.getOrNull()
            if (session?.token != null) {
                kotlinx.coroutines.CoroutineScope(Dispatchers.IO).launch {
                    // Phase 2: bind the Keystore device (idempotent, once per session).
                    runCatching { ensureDeviceRegistered() }
                    // Phase 3: fetch the license state right after auth so the UI
                    // and the VPN gate have it. Never fails the login itself —
                    // the license gate enforces at connect time.
                    runCatching { LicenseStore.ensureValid() }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Device binding state (Phase 2) — surfaced for the P3 device-limit UI.
    // ------------------------------------------------------------------

    /** Outcome of the automatic Keystore device binding attempt. */
    sealed class DeviceBindingState {
        object NotChecked : DeviceBindingState()
        object Verified : DeviceBindingState()
        /** Another device is active on the account (transfer required). */
        data class LimitReached(val activeDeviceId: Long) : DeviceBindingState()
        /** Binding could not complete this session (server old/offline/other). */
        data class Failed(val message: String?) : DeviceBindingState()
    }

    val deviceBinding = kotlinx.coroutines.flow.MutableStateFlow<DeviceBindingState>(DeviceBindingState.NotChecked)

    /**
     * Registers and verifies the current Keystore device — exactly once per
     * JWT session (guarded by a prefs flag so app restarts with a live
     * session do not re-register). Never throws.
     */

    internal suspend fun ensureDeviceRegistered() {
        val session = flow.value ?: run {
            deviceBinding.value = DeviceBindingState.Failed("no session")
            return
        }
        val token = session.token ?: run {
            deviceBinding.value = DeviceBindingState.Failed("no JWT (form session)")
            return
        }
        val ctx = appContext ?: run {
            deviceBinding.value = DeviceBindingState.Failed("not initialized")
            return
        }

        val exp = session.exp
        if (exp > 0 && prefs?.getLong("device_verified_exp", 0L) ?: 0L >= exp) {
            deviceBinding.value = DeviceBindingState.Verified
            return
        }

        val deviceHash = DeviceIdentity.getDeviceHash(ctx)

        // Phase 2 AC: rooted device detection logs a warning — NEVER blocking.
        if (DeviceIdentity.isDeviceRooted()) {
            android.util.Log.w(
                "DeviceIdentity",
                "Rooted device detected — device binding proceeds (warning only; server remains authoritative)."
            )
        }

        // 1. Does this device already exist for the account?
        runCatching {
            val response = RetrofitClient.deviceApi.listDevices(deviceHash)
            val data = response.body()?.data
            if (!response.isSuccessful || data == null) {
                deviceBinding.value = DeviceBindingState.Failed(
                    "devices list HTTP " + response.code() + ": " + (RetrofitClient.errorMessage(response) ?: "")
                )
                return
            }
            val mine = data.devices.firstOrNull { it.deviceHash == deviceHash }
            val activeId = data.activeDeviceId ?: 0L

            if (mine != null && mine.isActive) {
                // 2a. Already bound+active: prove ownership once, done.
                val ok = verifyDeviceChallenge().getOrDefault(false)
                deviceBinding.value = if (ok) {
                    if (exp > 0) prefs?.edit()?.putLong("device_verified_exp", exp)?.apply()
                    DeviceBindingState.Verified
                } else {
                    DeviceBindingState.Failed("challenge verification failed")
                }
                return
            }

            // 2b. Phase 5b: a device whose row is INACTIVE (pending transfer)
            //     or absent proceeds to registration below — the SERVER
            //     decides the outcome under the CD-9 lock:
            //       • absent  + slot taken → row created PENDING (inactive);
            //       • absent  + slot free  → row created ACTIVE (as before);
            //       • inactive + slot free → row re-activated (admin-release
            //         recovery); inactive + slot taken → row stays PENDING.
            //     The device is NEVER activated by the client; activation
            //     happens exclusively via the OTP transfer verify. When the
            //     register is refused, the pre-P5 LimitReached guidance is
            //     preserved as the fallback.
            val registered = registerDevice("Android")
            if (registered.isFailure) {
                deviceBinding.value = if (activeId > 0L) {
                    DeviceBindingState.LimitReached(activeId)
                } else {
                    DeviceBindingState.Failed(
                        (registered.exceptionOrNull() as? AuthException)?.uiMessage ?: "register failed"
                    )
                }
                return
            }
        }.onFailure {
            deviceBinding.value = DeviceBindingState.Failed("devices list: " + (it.message ?: ""))
            return
        }

        // 3. Prove key ownership; persist the verification for the session.
        //     For a PENDING device this proves the key WITHOUT activating it —
        //     the license fetch then reports DEVICE_LIMIT_REACHED and the UI
        //     directs the user to the OTP transfer flow.
        val verified = verifyDeviceChallenge().getOrDefault(false)
        deviceBinding.value = if (verified) {
            if (exp > 0) prefs?.edit()?.putLong("device_verified_exp", exp)?.apply()
            DeviceBindingState.Verified
        } else {
            DeviceBindingState.Failed("challenge verification failed")
        }
    }

    /**
     * Registers the current Keystore device with the backend.
     * Requires an authenticated JWT session; the CD-9 one-active-device
     * policy is enforced server-side — when the account's slot is taken the
     * server creates/keeps the row PENDING (inactive, Phase 5b) and the
     * device is activated exclusively via the OTP transfer verify. An
     * inactive row with a FREE slot is re-activated (admin-release recovery).
     */
    suspend fun registerDevice(label: String = "Android"): Result<String> =
        withContext(Dispatchers.IO) {
            runCatching {
                val ctx = appContext ?: error("AuthStore.init(context) not called")
                flow.value?.token ?: error("no JWT session")
                val deviceHash = DeviceIdentity.getDeviceHash(ctx)
                val response = RetrofitClient.deviceApi.registerDevice(
                    mapOf(
                        "public_key" to DeviceIdentity.getPublicKeyPem(ctx),
                        "fingerprint" to deviceHash,
                        "label" to label
                    )
                )
                if (!response.isSuccessful) {
                    throw AuthException(RetrofitClient.errorMessage(response))
                }
                val data = response.body()?.data
                val id = (data?.get("device_id") as? Double)?.toInt()?.toString()
                    ?: (data?.get("device_id")?.toString() ?: "")
                id
            }
        }

    /**
     * Completes challenge-response verification of the device private key
     * (server-issued challenge signed with SHA256withECDSA).
     */
    suspend fun verifyDeviceChallenge(): Result<Boolean> = withContext(Dispatchers.IO) {
        runCatching {
            val ctx = appContext ?: error("AuthStore.init(context) not called")
            flow.value?.token ?: error("no JWT session")
            val deviceHash = DeviceIdentity.getDeviceHash(ctx)

            // 1. Request challenge
            val chalResponse = RetrofitClient.deviceApi.getChallenge(
                deviceHash, mapOf("device_hash" to deviceHash)
            )
            if (!chalResponse.isSuccessful) {
                throw AuthException(RetrofitClient.errorMessage(chalResponse))
            }
            val challenge = chalResponse.body()?.data?.challenge
                ?: error("empty challenge")

            // 2. Sign the challenge with the Keystore key
            val signature = DeviceIdentity.signChallenge(ctx, challenge.toByteArray())

            // 3. Verify on the server
            val verResponse = RetrofitClient.deviceApi.verifyDevice(
                deviceHash,
                mapOf(
                    "device_hash" to deviceHash,
                    "challenge" to challenge,
                    "signature" to signature
                )
            )
            if (!verResponse.isSuccessful) {
                throw AuthException(RetrofitClient.errorMessage(verResponse))
            }
            verResponse.body()?.data?.verified ?: false
        }
    }

    /**
     * Phase 5: grant the 30-day trial (POST /licenses/trial) for the current
     * user. Returns success when the trial is granted or already used.
     */
    suspend fun grantTrial(): Result<String> = withContext(Dispatchers.IO) {
        runCatching {
            val token = flow.value?.token ?: error("no JWT session")
            val deviceHash = DeviceIdentity.getDeviceHash(appContext ?: error("AuthStore.init not called"))
            val con = (URL("$WP_BASE/wp-json/eligius/v1/licenses/trial").openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("X-Device-Hash", deviceHash)
                setRequestProperty("Content-Type", "application/json")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                con.outputStream.use { it.write(JSONObject().put("device_hash", deviceHash).toString().toByteArray()) }
                val code = con.responseCode
                val body = (if (code in 200..299) con.inputStream else con.errorStream)
                    ?.bufferedReader()?.use { it.readText() } ?: ""
                val j = JSONObject(body)
                if (code in 200..299) {
                    "trial_granted"
                } else {
                    val errMsg = j.optJSONObject("error")?.optString("message") ?: j.optString("message")
                    throw AuthException(errMsg.takeIf { it.isNotEmpty() })
                }
            } finally {
                con.disconnect()
            }
        }
    }

    /**
     * Phase 5: initiate transfer from the NEW device (currently inactive) —
     * the server finds the ACTIVE device, sends OTP, creates a pending transfer.
     */
    suspend fun startTransferFromNewDevice(): Result<String> = withContext(Dispatchers.IO) {
        runCatching {
            val token = flow.value?.token ?: error("no JWT session")
            val deviceHash = DeviceIdentity.getDeviceHash(appContext ?: error("AuthStore.init not called"))
            val con = (URL("$WP_BASE/wp-json/eligius/v1/devices/transfer/start").openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("X-Device-Hash", deviceHash)
                setRequestProperty("Content-Type", "application/json")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                con.outputStream.use { it.write(JSONObject().toString().toByteArray()) }
                val code = con.responseCode
                val body = (if (code in 200..299) con.inputStream else con.errorStream)
                    ?.bufferedReader()?.use { it.readText() } ?: ""
                val j = JSONObject(body)
                if (code in 200..299) {
                    j.optJSONObject("data")?.optString("transfer_id") ?: ""
                } else {
                    throw AuthException(j.optJSONObject("error")?.optString("message")?.takeIf { it.isNotEmpty() })
                }
            } finally {
                con.disconnect()
            }
        }
    }

    /** Verify the transfer OTP (JWT-only — works from any signed-in device). */
    suspend fun verifyTransferOtp(transferId: Long, otp: String): Result<Boolean> = withContext(Dispatchers.IO) {
        runCatching {
            val token = flow.value?.token ?: error("no JWT session")
            val con = (URL("$WP_BASE/wp-json/eligius/v1/devices/transfer/verify").openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("Content-Type", "application/json")
                connectTimeout = 10_000
                readTimeout = 15_000
            }
            try {
                con.outputStream.use {
                    it.write(JSONObject().put("transfer_id", transferId).put("otp_code", otp).toString().toByteArray())
                }
                val code = con.responseCode
                val body = (if (code in 200..299) con.inputStream else con.errorStream)
                    ?.bufferedReader()?.use { it.readText() } ?: ""
                val j = JSONObject(body)
                if (code in 200..299) {
                    j.optJSONObject("data")?.optBoolean("verified", false) ?: false
                } else {
                    throw AuthException(j.optJSONObject("error")?.optString("message")?.takeIf { it.isNotEmpty() })
                }
            } finally {
                con.disconnect()
            }
        }
    }

    /** Current Keystore device hash (creates the keypair on first use). */
    fun deviceHash(): String {
        val ctx = appContext ?: error("AuthStore.init(context) not called")
        return DeviceIdentity.getDeviceHash(ctx)
    }

}
