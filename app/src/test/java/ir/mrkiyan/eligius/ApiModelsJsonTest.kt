package ir.mrkiyan.eligius

import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import ir.mrkiyan.eligius.api.model.ApiResponse
import ir.mrkiyan.eligius.api.model.DeviceListData
import ir.mrkiyan.eligius.api.model.LicenseStatusData
import ir.mrkiyan.eligius.api.model.PlanData
import ir.mrkiyan.eligius.api.model.PaymentCreateData
import ir.mrkiyan.eligius.api.model.PaymentStatusData
import ir.mrkiyan.eligius.api.model.TransferStartData
import ir.mrkiyan.eligius.api.model.TransferVerifyData
import ir.mrkiyan.eligius.api.model.UsageReportData
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Phase 6 — JVM unit tests for the wire-format contracts (snake_case wire ↔
 * camelCase model). The server wire is snake_case (P3 bug history: a missing
 * @Json mapping once nulled EVERY field) — these tests pin that contract.
 */
class ApiModelsJsonTest {

    private val moshi = Moshi.Builder().add(KotlinJsonAdapterFactory()).build()

    @Test
    fun `license status parses snake_case wire with phase5b fields`() {
        val json = """
        {"valid":false,"error":"NO_ACTIVE_LICENSE","trial_eligible":true,
         "license_id":42,"plan_key":"monthly","plan_label":"Monthly",
         "status":"ACTIVE","started_at":"2026-11-01 00:00:00",
         "expires_at":"2026-12-01 00:00:00","device_id":17,"device_bound":true}
        """.trimIndent()
        val d = moshi.adapter(LicenseStatusData::class.java).fromJson(json)!!
        assertEquals(42L, d.licenseId)
        assertEquals("monthly", d.planKey)
        assertEquals("Monthly", d.planLabel)
        assertEquals("ACTIVE", d.status)
        assertEquals(17L, d.deviceId)
        assertEquals(true, d.deviceBound)
        // Phase 5b banner fields:
        assertTrue(d.trialEligible)
        assertEquals("NO_ACTIVE_LICENSE", d.error)
    }

    @Test
    fun `license status pending-transfer shape carries active device id`() {
        val json = """
        {"valid":false,"error":"DEVICE_LIMIT_REACHED","trial_eligible":false,"active_device_id":88}
        """.trimIndent()
        val d = moshi.adapter(LicenseStatusData::class.java).fromJson(json)!!
        assertFalse(d.valid)
        assertEquals("DEVICE_LIMIT_REACHED", d.error)
        assertFalse(d.trialEligible)
        assertEquals(88L, d.activeDeviceId)
    }

    @Test
    fun `trial_eligible defaults to false when absent`() {
        val d = moshi.adapter(LicenseStatusData::class.java).fromJson("""{"valid":true}""")!!
        assertFalse(d.trialEligible)
        assertNull(d.activeDeviceId)
    }

    @Test
    fun `p92 volume fields parse snake_case and default safely`() {
        val json = """
        {"valid":true,"plan_key":"ep-vol","volume_used_bytes":1073741824,
         "volume_total_bytes":1073741824,"volume_percent":100,"volume_exhausted":true}
        """.trimIndent()
        val d = moshi.adapter(LicenseStatusData::class.java).fromJson(json)!!
        assertEquals(1073741824L, d.volumeUsedBytes)
        assertEquals(1073741824L, d.volumeTotalBytes)
        assertEquals(100, d.volumePercent)
        assertTrue(d.volumeExhausted)
        // Older servers omit the fields entirely (P9.2 backward compatibility).
        val old = moshi.adapter(LicenseStatusData::class.java).fromJson("""{"valid":true}""")!!
        assertNull(old.volumeUsedBytes)
        assertNull(old.volumeTotalBytes)
        assertNull(old.volumePercent)
        assertFalse(old.volumeExhausted)
    }

    @Test
    fun `p92 plan_type and volume_gb parse with legacy fallback`() {
        val p = moshi.adapter(PlanData::class.java).fromJson(
            """{"key":"ep-free","label":"EP Free","plan_type":"free","volume_gb":5,
                "duration_days":14,"price_irt":0,"discount_percent":0,"is_trial":false}""")!!
        assertEquals("free", p.planType)
        assertEquals(5, p.volumeGb)
        val legacy = moshi.adapter(PlanData::class.java).fromJson(
            """{"key":"monthly","label":"Monthly","duration_days":30,"price_irt":150000}""")!!
        assertEquals("timed", legacy.planType)
        assertNull(legacy.volumeGb)
    }

    @Test
    fun `p92 usage report wire shape`() {
        val d = moshi.adapter(UsageReportData::class.java).fromJson(
            """{"attributed":true,"license_id":42,"used_bytes":1500,
                "used_rx_bytes":1000,"used_tx_bytes":500,"used_duration_ms":180000,
                "used_sessions":2,"volume_total_bytes":1073741824,
                "volume_percent":0,"volume_exhausted":false}""")!!
        assertTrue(d.attributed)
        assertEquals(42L, d.licenseId)
        assertEquals(1500L, d.usedBytes)
        assertEquals(1000L, d.usedRxBytes)
        assertEquals(500L, d.usedTxBytes)
        assertEquals(180000L, d.usedDurationMs)
        assertEquals(2, d.usedSessions)
        assertFalse(d.volumeExhausted)
    }

    @Test
    fun `device list surfaces pending transfer for destination device`() {
        val json = """
        {"devices":[{"device_id":21,"device_hash":"ab12","label":"Old","is_active":true,
                     "created_at":"2026-11-01 10:00:00","last_seen_at":"2026-11-15 09:00:00"},
                    {"device_id":44,"device_hash":"cd34","label":"New","is_active":false}],
         "active_device_id":21,
         "pending_transfer":{"transfer_id":77,"expires_at":"2026-11-16 20:00:00","expires_in":480}}
        """.trimIndent()
        val d = moshi.adapter(DeviceListData::class.java).fromJson(json)!!
        assertEquals(2, d.devices.size)
        assertEquals(21L, d.activeDeviceId)
        assertEquals(77L, d.pendingTransfer?.transferId)
        assertEquals(480, d.pendingTransfer?.expiresIn)
        assertFalse(d.devices[1].isActive)
    }

    @Test
    fun `device list without pending transfer parses null`() {
        val d = moshi.adapter(DeviceListData::class.java)
            .fromJson("""{"devices":[],"active_device_id":0}""")!!
        assertNull(d.pendingTransfer)
    }

    @Test
    fun `transfer wire shapes`() {
        val s = moshi.adapter(TransferStartData::class.java)
            .fromJson("""{"transfer_id":55,"otp_sent":true,"expires_in":600}""")!!
        assertEquals(55L, s.transferId)
        assertTrue(s.otpSent)
        assertEquals(600, s.expiresIn)
        val v = moshi.adapter(TransferVerifyData::class.java)
            .fromJson("""{"verified":true,"new_active_device_id":44}""")!!
        assertTrue(v.verified)
        assertEquals(44L, v.newActiveDeviceId)
    }

    @Test
    fun `payment wire shapes`() {
        val c = moshi.adapter(PaymentCreateData::class.java).fromJson(
            """{"payment_id":9,"gateway":"zarinpal","authority":"AUTH1",
                "redirect_url":"https://sandbox.zarinpal.com/pg/StartPay/AUTH1",
                "amount_irt":150000,"expires_in":1800}""")!!
        assertEquals(150000L, c.amountIrt)
        assertEquals("AUTH1", c.authority)
        val st = moshi.adapter(PaymentStatusData::class.java).fromJson(
            """{"payment_id":9,"gateway":"zarinpal","amount_irt":150000,"plan_key":"monthly",
                "status":"VERIFIED","verified_at":"2026-11-16 10:00:00","license_id":42}""")!!
        assertEquals("VERIFIED", st.status)
        assertEquals(42L, st.licenseId)
    }

    @Test
    fun `error envelope maps to code for gate errors`() {
        // Generic ApiResponse<T> needs a parameterized type (exactly what
        // Retrofit's converter resolves at runtime).
        val type = com.squareup.moshi.Types.newParameterizedType(
            ApiResponse::class.java, LicenseStatusData::class.java)
        val env = moshi.adapter<ApiResponse<LicenseStatusData>>(type).fromJson(
            """{"success":false,"error":{"code":"DEVICE_BLOCKED","message":"blocked by administrator"},
                "data":null}""")!!
        assertEquals("DEVICE_BLOCKED", env.error?.code)
        assertEquals("blocked by administrator", env.error?.message)
        assertFalse(env.success)
    }
}
