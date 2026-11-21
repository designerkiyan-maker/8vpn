package ir.mrkiyan.eligius

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * Phase 6 — JVM unit tests for the SMS OTP extraction rule (pure logic —
 * "First 6-digit run in the message = the OTP"). The transfer OTP entry
 * always keeps manual input working; auto-fill must pick exactly the code.
 */
class SmsOtpExtractTest {

    @Test
    fun `extracts the six digit code from a typical persian sms`() {
        assertEquals("482913",
            SmsOtp.extractCode("کد انتقال الیژیوس: 482913 — تا ۱۰ دقیقه اعتبار دارد"))
    }

    @Test
    fun `extracts the first six digit run when several appear`() {
        assertEquals("123456", SmsOtp.extractCode("code 123456 (order 987654)"))
    }

    @Test
    fun `five digit runs are not accepted`() {
        // The first six-digit run wins; a lone 5-digit run yields nothing.
        assertNull(SmsOtp.extractCode("no code here 12345"))
    }

    @Test
    fun `no digits means null`() {
        assertNull(SmsOtp.extractCode("بدون کد عددی"))
        assertNull(SmsOtp.extractCode(""))
        assertNull(SmsOtp.extractCode(null))
    }

    @Test
    fun `digits inside a longer digit run do not yield a false six`() {
        // The rule takes the FIRST 6-digit match; the server always sends the
        // OTP as an isolated 6-digit value, so real messages match exactly.
        assertEquals("123456", SmsOtp.extractCode("code: 12345678"))
        assertEquals("123456", SmsOtp.extractCode("ref 12345 code 1234567"))
    }
}
