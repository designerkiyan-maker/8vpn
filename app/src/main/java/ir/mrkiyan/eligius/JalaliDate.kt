package ir.mrkiyan.eligius

/**
 * P9.2.1: Gregorian→Jalali (Shamsi) conversion + Persian-digit formatting.
 * Pure JVM (no Android imports) so the unit suite pins the math. The classic
 * jdf.scr.ir algorithm — verified against Nowruz anchor dates.
 */
object JalaliDate {

    /** Returns [jy, jm, jd] for a Gregorian date. */
    fun toJalali(gy: Int, gm: Int, gd: Int): IntArray {
        val gDm = intArrayOf(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)
        var jy = if (gy <= 1600) 0 else 979
        var g = if (gy <= 1600) gy - 621 else gy - 1600
        val g2 = if (gm > 2) g + 1 else g
        var days = 365L * g + (g2 + 3) / 4 - (g2 + 99) / 100 + (g2 + 399) / 400 - 80 + gd + gDm[gm - 1]
        jy += 33 * (days / 12053).toInt()
        days %= 12053
        jy += 4 * (days / 1461).toInt()
        days %= 1461
        if (days > 365) {
            jy += ((days - 1) / 365).toInt()
            days = (days - 1) % 365
        }
        val jm = if (days < 186) (1 + days / 31).toInt() else (7 + (days - 186) / 30).toInt()
        val jd = if (days < 186) (1 + days % 31).toInt() else (1 + (days - 186) % 30).toInt()
        return intArrayOf(jy, jm, jd)
    }

    /** "۱۴۰۵/۰۹/۰۵" style Shamsi date from epoch millis (device timezone). */
    fun formatShamsi(epochMs: Long): String {
        val d = java.time.Instant.ofEpochMilli(epochMs)
            .atZone(java.time.ZoneId.systemDefault()).toLocalDate()
        val j = toJalali(d.year, d.monthValue, d.dayOfMonth)
        val mm = if (j[1] < 10) "۰${toFaDigits(j[1].toString())}" else toFaDigits(j[1].toString())
        val dd = if (j[2] < 10) "۰${toFaDigits(j[2].toString())}" else toFaDigits(j[2].toString())
        return "${toFaDigits(j[0].toString())}/$mm/$dd"
    }

    /** Latin digits → Persian digits (۰-۹). */
    fun toFaDigits(s: String): String {
        val fa = charArrayOf('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹')
        val sb = StringBuilder(s.length)
        for (ch in s) sb.append(if (ch in '0'..'9') fa[ch - '0'] else ch)
        return sb.toString()
    }
}
