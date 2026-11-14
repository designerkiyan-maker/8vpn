package ir.mrkiyan.eligius.api.model

import com.squareup.moshi.Json

/** Envelope per docs/architecture/eligius-subscription/03 §1.1 (snake_case wire format). */
data class ApiResponse<T>(
    val success: Boolean = false,
    val data: T? = null,
    @Json(name = "error") val error: ApiError? = null,
    val message: String? = null,
    val code: String? = null
)

data class ApiError(
    val code: String? = null,
    val message: String? = null,
    val details: Map<String, Any?>? = null
)

data class LicenseStatusData(
    val valid: Boolean = false,
    val error: String? = null,
    @Json(name = "license_id") val licenseId: Long? = null,
    @Json(name = "plan_key") val planKey: String? = null,
    @Json(name = "plan_label") val planLabel: String? = null,
    val status: String? = null,
    @Json(name = "started_at") val startedAt: String? = null,
    @Json(name = "expires_at") val expiresAt: String? = null,
    @Json(name = "device_id") val deviceId: Long? = null,
    @Json(name = "device_bound") val deviceBound: Boolean? = null,
    @Json(name = "offline_entitlement") val offlineEntitlement: OfflineEntitlementData? = null
)

data class OfflineEntitlementData(
    val blob: String,
    @Json(name = "expires_at") val expiresAt: String,
    @Json(name = "public_key_id") val publicKeyId: String? = null,
    val kid: String? = null
) {
    /** Server returns publicKeyId (issuer); the validator keys off "kid". */
    val kidOrPublicKeyId: String get() = kid ?: publicKeyId ?: ""
}

data class ValidationData(
    val valid: Boolean = false,
    val error: String? = null,
    @Json(name = "license_id") val licenseId: Long? = null,
    @Json(name = "plan_key") val planKey: String? = null,
    @Json(name = "expires_at") val expiresAt: String? = null
)

data class RevocationListData(
    @Json(name = "revoked_license_ids") val revokedLicenseIds: List<Long> = emptyList(),
    @Json(name = "updated_at") val updatedAt: String? = null,
    @Json(name = "next_update") val nextUpdate: String? = null,
    val now: String? = null
)

data class DeviceInfo(
    @Json(name = "device_id") val deviceId: Long = 0,
    @Json(name = "device_hash") val deviceHash: String = "",
    val label: String? = null,
    @Json(name = "is_active") val isActive: Boolean = false,
    @Json(name = "created_at") val createdAt: String? = null,
    @Json(name = "last_seen_at") val lastSeenAt: String? = null
)

data class DeviceListData(
    val devices: List<DeviceInfo> = emptyList(),
    @Json(name = "active_device_id") val activeDeviceId: Long? = null
)

data class ChallengeData(
    val challenge: String,
    @Json(name = "expires_in") val expiresIn: Int = 60
)

data class DeviceVerifyData(
    val verified: Boolean = false,
    @Json(name = "device_id") val deviceId: Long = 0,
    @Json(name = "is_active") val isActive: Boolean = false
)

data class TransferStartData(
    @Json(name = "transfer_id") val transferId: Long = 0,
    @Json(name = "otp_sent") val otpSent: Boolean = false,
    @Json(name = "expires_in") val expiresIn: Int = 600
)

data class TransferVerifyData(
    val verified: Boolean = false,
    @Json(name = "new_active_device_id") val newActiveDeviceId: Long = 0
)

data class PlanData(
    @Json(name = "key") val planKey: String = "",
    val label: String = "",
    @Json(name = "duration_days") val durationDays: Int = 0,
    @Json(name = "price_irt") val priceIrt: Long = 0,
    @Json(name = "discount_percent") val discountPercent: Int = 0,
    @Json(name = "is_trial") val isTrial: Boolean = false
)

data class PlanListData(
    val plans: List<PlanData> = emptyList()
)


data class PaymentCreateData(
    @Json(name = "payment_id") val paymentId: Long = 0,
    val gateway: String = "",
    val authority: String = "",
    @Json(name = "redirect_url") val redirectUrl: String = "",
    @Json(name = "amount_irt") val amountIrt: Long = 0,
    @Json(name = "expires_in") val expiresIn: Int = 1800
)

data class PaymentStatusData(
    @Json(name = "payment_id") val paymentId: Long = 0,
    val gateway: String = "",
    @Json(name = "amount_irt") val amountIrt: Long = 0,
    @Json(name = "plan_key") val planKey: String = "",
    val status: String = "",
    @Json(name = "initiated_at") val initiatedAt: String? = null,
    @Json(name = "verified_at") val verifiedAt: String? = null,
    @Json(name = "license_id") val licenseId: Long? = null
)

data class PaymentHistoryData(
    val payments: List<PaymentStatusData> = emptyList(),
    val page: Int = 1
)
