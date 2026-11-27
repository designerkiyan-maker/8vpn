package ir.mrkiyan.eligius

/**
 * P9.2: pure volume-gate math (no Android imports — JVM unit-testable).
 *
 * The server only learns used bytes at session end (client-trusted metering,
 * doc limitation), so the tunnel runs a LOCAL pre-check on the 5-min tick:
 * if used + this session's projected bytes would cross the quota, the tunnel
 * stops before the overage happens.
 */
object VolumeGate {

    /** True when usedBytes + sessionBytes would reach the quota. Unlimited
     *  (null total), offline-granted state and non-positive quotas never cross. */
    fun wouldCross(usedBytes: Long, totalBytes: Long?, sessionBytes: Long, offline: Boolean = false): Boolean {
        if (offline || totalBytes == null || totalBytes <= 0) return false
        val used = usedBytes.coerceAtLeast(0)
        val session = sessionBytes.coerceAtLeast(0)
        return used + session >= totalBytes
    }
}
