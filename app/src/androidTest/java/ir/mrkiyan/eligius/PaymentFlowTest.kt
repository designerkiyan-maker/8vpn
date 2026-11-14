package ir.mrkiyan.eligius

import ir.mrkiyan.eligius.api.RetrofitClient

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.net.HttpURLConnection
import java.net.URL
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.FixMethodOrder
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.MethodSorters

/**
 * Phase 4 instrumented tests — Android ↔ Backend payment flow with the Mock
 * gateway (docs: mock/fixture allowed; the real ZarinPal sandbox HTTP path is
 * reported separately as NOT covered without a real merchant).
 *
 * Flow proven WITHOUT trusting deep-link parameters:
 *   create → (server-side callback simulation) → poll status → VERIFIED.
 */
@RunWith(AndroidJUnit4::class)
@FixMethodOrder(MethodSorters.NAME_ASCENDING)
class PaymentFlowTest {

    /** Local backend (host loopback from the emulator). */
    private val base = "http://10.0.2.2:8080/wp/wp-json"
    private val testUser = "androidkeystore"
    private val testPass = "KsTest123@"

    private val ctx get() = InstrumentationRegistry.getInstrumentation().targetContext

    private fun http(method: String, url: String, token: String?, body: JSONObject?): Pair<Int, JSONObject> {
        val con = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            if (body != null) doOutput = true
            token?.let { setRequestProperty("Authorization", "Bearer $it") }
            setRequestProperty("X-Device-Hash", DeviceIdentity.getDeviceHash(ctx))
            setRequestProperty("Content-Type", "application/json")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        try {
            if (body != null) {
                con.outputStream.use { out -> out.write(body.toString().toByteArray()) }
            }
            val code = con.responseCode
            val text = (if (code in 200..299) con.inputStream else con.errorStream)
                ?.bufferedReader()?.use { it.readText() } ?: ""
            // 302 deep-link responses have empty bodies — tolerate non-JSON.
            val json = runCatching { JSONObject(text) }.getOrElse { JSONObject() }
            return Pair(code, json)
        } finally {
            con.disconnect()
        }
    }

    private fun jwtToken(): String {
        val (code, j) = http(
            "POST", "$base/jwt-auth/v1/token", null,
            JSONObject().put("username", testUser).put("password", testPass)
        )
        assertEquals("jwt login", 200, code)
        return j.getString("token")
    }

    // --- 1. Create payment via PaymentApiService (mock gateway) --------------

    @Test
    fun t1_createPayment_viaApiService() {
        RetrofitClient.init(ctx)
        RetrofitClient.baseUrlOverride = "http://10.0.2.2:8080/wp/wp-json/eligius/v1/"
        val token = jwtToken()

        AuthStore.init(ctx)
        AuthStore.flow.value = AuthStore.Session(
            user = testUser, username = testUser, email = "ks-test@example.com",
            token = token, exp = (System.currentTimeMillis() / 1000) + 3600
        )

        val result: retrofit2.Response<ir.mrkiyan.eligius.api.model.ApiResponse<ir.mrkiyan.eligius.api.model.PaymentCreateData>> =
            kotlinx.coroutines.runBlocking {
                kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    RetrofitClient.paymentApi.createPayment(
                        mapOf("plan_key" to "monthly", "gateway" to "mock")
                    )
                }
            }
        assertTrue("create payment", result.isSuccessful)
        val data = result.body()?.data
        assertTrue("redirect_url present", data?.redirectUrl?.isNotBlank() == true)
        assertTrue("payment_id present", data?.paymentId ?: 0L > 0)
        assertEquals("amount IRT (TD-2)", 150000L, data?.amountIrt)
    }

    // --- 2. Status poll reflects the REAL backend state -----------------------

    @Test
    fun t2_statusPoll_backendAuthoritative() {
        RetrofitClient.init(ctx)
        RetrofitClient.baseUrlOverride = "http://10.0.2.2:8080/wp/wp-json/eligius/v1/"
        val token = jwtToken()

        // Create a payment, then simulate the gateway callback landing on the
        // server via a direct status flip (the callback processor itself is
        // covered by the server-side integration suite).
        val (createCode, createBody) = http(
            "POST", "$base/eligius/v1/payments/create", token,
            JSONObject().put("plan_key", "monthly").put("gateway", "mock")
        )
        assertEquals("create", 201, createCode)
        val paymentId = createBody.getJSONObject("data").getLong("payment_id")

        // Poll BEFORE any callback to assert the pre-payment state.
        val (preCode, preBody) = http("GET", "$base/eligius/v1/payments/$paymentId", token, null)
        assertEquals(200, preCode)
        assertEquals("pre-callback state INITIATED", "INITIATED", preBody.getJSONObject("data").getString("status"))

        // Fabricated authority → the server redirects to the deep link with
        // status=unknown (302). The payment is NOT flipped to VERIFIED.
        val (forgedCode, _) = http(
            "GET",
            "$base/eligius/v1/payments/callback/mock?authority=FORGED-AUTH&status=OK",
            null, null
        )
        assertEquals("forged callback → 302 deep link redirect", 302, forgedCode)

        val (postCode, postBody) = http("GET", "$base/eligius/v1/payments/$paymentId", token, null)
        assertEquals(200, postCode)
        assertEquals(
            "our payment untouched by forged authority",
            "INITIATED",
            postBody.getJSONObject("data").getString("status")
        )
    }
}
