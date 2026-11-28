package ir.mrkiyan.eligius

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * P9.2.1: Shamsi (Jalali) conversion + Persian-digit formatting (pure JVM).
 */
class JalaliDateTest {

    @Test
    fun `nowruz anchors convert correctly`() {
        // 1 Farvardin 1405 = 2026-03-21 ; 1 Farvardin 1404 = 2025-03-21
        assertEquals(intArrayOf(1405, 1, 1).toList(), JalaliDate.toJalali(2026, 3, 21).toList())
        assertEquals(intArrayOf(1404, 1, 1).toList(), JalaliDate.toJalali(2025, 3, 21).toList())
    }

    @Test
    fun `late-year date converts correctly`() {
        // 2026-11-28 → 1405/09/07
        assertEquals(intArrayOf(1405, 9, 7).toList(), JalaliDate.toJalali(2026, 11, 28).toList())
        // leap-year edge: 2025-02-20 → 1403/12/02 (1403 is a Jalali leap year)
        assertEquals(intArrayOf(1403, 12, 2).toList(), JalaliDate.toJalali(2025, 2, 20).toList())
    }

    @Test
    fun `persian digits translate`() {
        assertEquals("۳۲۷", JalaliDate.toFaDigits("327"))
        assertEquals("۱۴۰۵/۰۹/۰۷", JalaliDate.toFaDigits("1405/09/07"))
        assertEquals("abc", JalaliDate.toFaDigits("abc"))
    }

    @Test
    fun `formatShamsi renders persian digits for a known date`() {
        val ms = java.time.LocalDate.of(2026, 11, 28)
            .atStartOfDay(java.time.ZoneId.systemDefault()).toInstant().toEpochMilli()
        assertEquals("۱۴۰۵/۰۹/۰۷", JalaliDate.formatShamsi(ms))
    }
}
