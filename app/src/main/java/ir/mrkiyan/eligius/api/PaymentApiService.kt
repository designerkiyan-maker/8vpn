package ir.mrkiyan.eligius.api

import ir.mrkiyan.eligius.api.model.ApiResponse
import ir.mrkiyan.eligius.api.model.PaymentCreateData
import ir.mrkiyan.eligius.api.model.PaymentHistoryData
import ir.mrkiyan.eligius.api.model.PaymentStatusData
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** Payment endpoints (docs 03 §6, Roadmap P4). */
interface PaymentApiService {

    @POST("payments/create")
    suspend fun createPayment(
        @Body body: Map<String, String>
    ): Response<ApiResponse<PaymentCreateData>>

    @GET("payments/{payment_id}")
    suspend fun getPaymentStatus(
        @Path("payment_id") paymentId: Long
    ): Response<ApiResponse<PaymentStatusData>>

    /** Phase 6 — user-initiated reconciliation: the server re-runs S2S
     *  verification for an INITIATED payment using the STORED authority and
     *  amount (client input is never trusted). Fails closed with 503
     *  RATE_LIMIT_UNAVAILABLE when the rate limiter is degraded. */
    @POST("payments/{payment_id}/reconcile")
    suspend fun reconcilePayment(
        @Path("payment_id") paymentId: Long
    ): Response<ApiResponse<PaymentStatusData>>

    @GET("payments/history")
    suspend fun getPaymentHistory(
        @Query("page") page: Int = 1
    ): Response<ApiResponse<PaymentHistoryData>>
}
