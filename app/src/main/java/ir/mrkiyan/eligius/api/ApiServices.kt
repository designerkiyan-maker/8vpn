package ir.mrkiyan.eligius.api

import ir.mrkiyan.eligius.api.model.ApiResponse
import ir.mrkiyan.eligius.api.model.ChallengeData
import ir.mrkiyan.eligius.api.model.DeviceListData
import ir.mrkiyan.eligius.api.model.DeviceVerifyData
import ir.mrkiyan.eligius.api.model.LicenseStatusData
import ir.mrkiyan.eligius.api.model.PlanListData
import ir.mrkiyan.eligius.api.model.RevocationListData
import ir.mrkiyan.eligius.api.model.TransferStartData
import ir.mrkiyan.eligius.api.model.TransferVerifyData
import ir.mrkiyan.eligius.api.model.ValidationData
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST

/** License/subscription endpoints (docs 03 §7 + Roadmap P3). */
interface LicenseApiService {
    @GET("licenses/status")
    suspend fun getLicenseStatus(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<LicenseStatusData>>

    @GET("licenses/validate")
    suspend fun validateLicense(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<ValidationData>>

    @GET("licenses/revocation-list")
    suspend fun getRevocationList(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<RevocationListData>>

    @GET("plans")
    suspend fun getPlans(): Response<ApiResponse<PlanListData>>
}

/** Device endpoints (docs 03 §3, Roadmap P3 — DeviceApiService). */
interface DeviceApiService {
    @POST("devices/register")
    suspend fun registerDevice(
        @Body body: Map<String, String>
    ): Response<ApiResponse<Map<String, Any?>>>

    @POST("devices/challenge")
    suspend fun getChallenge(
        @Header("X-Device-Hash") deviceHash: String,
        @Body body: Map<String, String>
    ): Response<ApiResponse<ChallengeData>>

    @POST("devices/verify")
    suspend fun verifyDevice(
        @Header("X-Device-Hash") deviceHash: String,
        @Body body: Map<String, String>
    ): Response<ApiResponse<DeviceVerifyData>>

    @GET("devices")
    suspend fun listDevices(
        @Header("X-Device-Hash") deviceHash: String
    ): Response<ApiResponse<DeviceListData>>

    @POST("devices/transfer/start")
    suspend fun startTransfer(
        @Header("X-Device-Hash") deviceHash: String,
        @Body body: Map<String, String>
    ): Response<ApiResponse<TransferStartData>>

    @POST("devices/transfer/verify")
    suspend fun verifyTransfer(
        @Body body: Map<String, String>
    ): Response<ApiResponse<TransferVerifyData>>
}
