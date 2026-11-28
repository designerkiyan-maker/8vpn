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
    /** Phase 5b: REAL backend trial eligibility (server gate = has_trial_ever). */
    @Json(name = "trial_eligible") val trialEligible: Boolean = false,
    /** Phase 5b: active device id when the caller device is pending transfer. */
    @Json(name = "active_device_id") val activeDeviceId: Long? = null,
    /** P9.2: volume quota (client-trusted metering; null total = unlimited). */
    @Json(name = "volume_used_bytes") val volumeUsedBytes: Long? = null,
    @Json(name = "volume_total_bytes") val volumeTotalBytes: Long? = null,
    @Json(name = "volume_percent") val volumePercent: Int? = null,
    @Json(name = "volume_exhausted") val volumeExhausted: Boolean = false,
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
    @Json(name = "active_device_id") val activeDeviceId: Long? = null,
    /** Phase 5b: present when THIS device is the destination of a pending OTP transfer. */
    @Json(name = "pending_transfer") val pendingTransfer: PendingTransferData? = null
)

data class PendingTransferData(
    @Json(name = "transfer_id") val transferId: Long = 0,
    @Json(name = "expires_at") val expiresAt: String? = null,
    @Json(name = "expires_in") val expiresIn: Int = 0
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
    /** P9.2: 'volume' | 'timed' | 'free' (older servers omit → timed). */
    @Json(name = "plan_type") val planType: String = "timed",
    /** P9.2: GB quota for volume plans (null = unlimited / timed). */
    @Json(name = "volume_gb") val volumeGb: Int? = null,
    @Json(name = "duration_days") val durationDays: Int = 0,
    @Json(name = "price_irt") val priceIrt: Long = 0,
    @Json(name = "discount_percent") val discountPercent: Int = 0,
    @Json(name = "is_trial") val isTrial: Boolean = false
)

data class PlanListData(
    val plans: List<PlanData> = emptyList()
)

/** P9.2: response of GET/POST /licenses/usage — per-subscription-period
 *  stats (a fresh purchase row starts at zero → the usage card resets). */
data class UsageReportData(
    val attributed: Boolean = false,
    @Json(name = "license_id") val licenseId: Long? = null,
    @Json(name = "used_bytes") val usedBytes: Long = 0,
    @Json(name = "used_rx_bytes") val usedRxBytes: Long = 0,
    @Json(name = "used_tx_bytes") val usedTxBytes: Long = 0,
    @Json(name = "used_duration_ms") val usedDurationMs: Long = 0,
    @Json(name = "used_sessions") val usedSessions: Int = 0,
    @Json(name = "volume_total_bytes") val volumeTotalBytes: Long? = null,
    @Json(name = "volume_percent") val volumePercent: Int? = null,
    @Json(name = "volume_exhausted") val volumeExhausted: Boolean = false
)

/** P9.2: response of POST /licenses/activate-free. */
data class FreeActivationData(
    @Json(name = "license_id") val licenseId: Long = 0,
    @Json(name = "expires_at") val expiresAt: String = "",
    @Json(name = "offline_entitlement") val offlineEntitlement: OfflineEntitlementData? = null
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
