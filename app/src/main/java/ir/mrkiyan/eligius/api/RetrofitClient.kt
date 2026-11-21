package ir.mrkiyan.eligius.api

import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import ir.mrkiyan.eligius.AuthStore
import ir.mrkiyan.eligius.BuildConfig
import ir.mrkiyan.eligius.DeviceIdentity
import ir.mrkiyan.eligius.api.model.ApiResponse
import ir.mrkiyan.eligius.api.model.ApiError
import ir.mrkiyan.eligius.api.model.ChallengeData
import ir.mrkiyan.eligius.api.model.DeviceListData
import ir.mrkiyan.eligius.api.model.DeviceVerifyData
import ir.mrkiyan.eligius.api.model.LicenseStatusData
import ir.mrkiyan.eligius.api.model.PlanListData
import ir.mrkiyan.eligius.api.model.RevocationListData
import ir.mrkiyan.eligius.api.model.TransferStartData
import ir.mrkiyan.eligius.api.model.TransferVerifyData
import ir.mrkiyan.eligius.api.model.ValidationData
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Response as OkHttpResponse
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Retrofit client for the Eligius subscription backend (Roadmap P3).
 *
 * Every request carries the session JWT (Authorization: Bearer) and the
 * current Keystore device hash (X-Device-Hash) — the two authentication
 * factors the server-side device-binding endpoints require (docs 03 §1.2).
 */
object RetrofitClient {

    @Volatile private var appContext: android.content.Context? = null

    /** Test-only base URL override (androidTest points at 10.0.2.2:8080).
     *  Production code never sets it; lazy retrofit reads it on first use. */
    @Volatile var baseUrlOverride: String? = null

    fun init(ctx: android.content.Context) {
        appContext = ctx.applicationContext
    }

    private val authInterceptor = Interceptor { chain ->
        val ctx = appContext
        val builder = chain.request().newBuilder()
            .header("User-Agent", "eligius-android")
        if (ctx != null) {
            AuthStore.sessionToken()?.let { builder.header("Authorization", "Bearer $it") }
            runCatching { DeviceIdentity.getDeviceHash(ctx) }
                .getOrNull()?.let { builder.header("X-Device-Hash", it) }
        }
        chain.proceed(builder.build())
    }

    private val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .build()
    }

    private val moshi: Moshi by lazy {
        Moshi.Builder()
            .add(KotlinJsonAdapterFactory())
            .build()
    }

    // Phase 6: backing fields (not `by lazy`) so the TEST-ONLY rebuild below
    // can drop instances when the base-url override changes mid-suite.
    @Volatile private var retrofitInstance: Retrofit? = null
    @Volatile private var licenseApiInstance: LicenseApiService? = null
    @Volatile private var deviceApiInstance: DeviceApiService? = null
    @Volatile private var paymentApiInstance: ir.mrkiyan.eligius.api.PaymentApiService? = null

    private val retrofit: Retrofit
        get() = retrofitInstance ?: synchronized(this) {
            retrofitInstance ?: Retrofit.Builder()
                .baseUrl(baseUrlOverride ?: BuildConfig.ELIGIUS_API_BASE)
                .client(client)
                .addConverterFactory(MoshiConverterFactory.create(moshi))
                .build()
                .also { retrofitInstance = it }
        }

    val licenseApi: LicenseApiService
        get() = licenseApiInstance ?: synchronized(this) {
            licenseApiInstance ?: retrofit.create(LicenseApiService::class.java)
                .also { licenseApiInstance = it }
        }
    val deviceApi: DeviceApiService
        get() = deviceApiInstance ?: synchronized(this) {
            deviceApiInstance ?: retrofit.create(DeviceApiService::class.java)
                .also { deviceApiInstance = it }
        }
    val paymentApi: ir.mrkiyan.eligius.api.PaymentApiService
        get() = paymentApiInstance ?: synchronized(this) {
            paymentApiInstance ?: retrofit.create(ir.mrkiyan.eligius.api.PaymentApiService::class.java)
                .also { paymentApiInstance = it }
        }

    /**
     * Phase 6: TEST-ONLY — drops the lazily built Retrofit and service
     * instances so the next use rebuilds them with the CURRENT
     * [baseUrlOverride] (the instrumented suite switches between the local
     * backend and a closed port). Never called by any production flow.
     */
    @androidx.annotation.VisibleForTesting
    internal fun rebuildForTest() {
        retrofitInstance = null
        licenseApiInstance = null
        deviceApiInstance = null
        paymentApiInstance = null
    }

    /** Extract the human-readable error from a failed envelope. */
    fun errorMessage(response: retrofit2.Response<out ApiResponse<*>>): String? {
        val err = response.body()?.error
        if (err?.message != null) return err.message
        val raw = runCatching { response.errorBody()?.string() }.getOrNull() ?: return null
        return parseError(raw)
    }

    /** Extract the machine-readable error code from a failed envelope. */
    fun errorCode(response: retrofit2.Response<out ApiResponse<*>>): String? {
        return response.body()?.error?.code
            ?: runCatching {
                val raw = response.errorBody()?.string() ?: return@runCatching null
                val j = com.squareup.moshi.Moshi.Builder().build()
                    .adapter(ApiError::class.java).lenient().fromJson(raw)
                j?.code
            }.getOrNull()
    }

    fun parseError(json: String?): String? {
        if (json.isNullOrBlank()) return null
        return try {
            val j = com.squareup.moshi.Moshi.Builder().build()
                .adapter(ApiResponse::class.java).lenient().fromJson(json)
            j?.error?.message ?: j?.message
        } catch (_: Exception) {
            null
        }
    }
}
