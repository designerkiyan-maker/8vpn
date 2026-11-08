package ir.mrkiyan.eligius

import android.content.Context
import android.content.SharedPreferences
import kotlinx.coroutines.flow.MutableStateFlow

enum class Phase { IDLE, CONNECTING, CONNECTED, RECONNECTING, STOPPING }

data class Status(val phase: Phase = Phase.IDLE, val attempt: Int = 0, val error: String? = null)

/** One-second throughput sample (bytes/s) for the home screen graph. */
data class TrafficSample(val rx: Long, val tx: Long)

/** Persisted summary of the last finished session, shown on the home screen. */
data class LastSession(
    val durationMs: Long,
    val rxBytes: Long,
    val txBytes: Long,
    val avgSpeedBps: Long,
    val endedAt: Long,
)

/** Lifetime usage accumulated across every session, kept per account. */
data class Usage(
    val rxBytes: Long,
    val txBytes: Long,
    val durationMs: Long,
    val sessions: Int,
)

/** Process-wide connection state shared by the service, the tile and the UI. */
object Engine {
    val status = MutableStateFlow(Status())
    val pingMs = MutableStateFlow<Int?>(null)
    val rxBps = MutableStateFlow(0L)
    val txBps = MutableStateFlow(0L)
    val exitInfo = MutableStateFlow<String?>(null)
    /** Two-letter country code of the exit IP (null = unknown yet); drives the flag next to the status. */
    val exitCountry = MutableStateFlow<String?>(null)
    /** Measured download speed through the tunnel in bytes/s (null = not measured yet). */
    val speedBps = MutableStateFlow<Long?>(null)
    /** True while the VPN interface is switched off but the core keeps running (Stop button). */
    val paused = MutableStateFlow(false)
    val logs = MutableStateFlow<List<String>>(emptyList())
    /** Why the link last dropped (shown under the connect button); null = nothing dropped yet. */
    val lastReason = MutableStateFlow<String?>(null)
    /** Last minute of throughput samples, oldest first; the home screen graph draws it. */
    val traffic = MutableStateFlow<List<TrafficSample>>(emptyList())

    /** Wall-clock start of the current session (null = not counting). Survives reconnects. */
    var sessionStartWallMs: Long? = null
    /** Accumulated session totals in bytes (reset when a new session starts). */
    val totalRx = MutableStateFlow(0L)
    val totalTx = MutableStateFlow(0L)
    /** Persisted summary of the previous session (survives disconnects and app restarts). */
    val lastSession = MutableStateFlow<LastSession?>(null)
    /** Lifetime usage of the signed-in account (accumulated across every session). */
    val usage = MutableStateFlow(Usage(0, 0, 0, 0))
    /** Key of the network the status labels show: "wifi" / "mobile" / "ethernet". */
    val networkType = MutableStateFlow<String?>(null)

    private var statsPrefs: SharedPreferences? = null

    fun init(ctx: Context) {
        if (statsPrefs != null) return
        statsPrefs = ctx.applicationContext.getSharedPreferences("lastsession", Context.MODE_PRIVATE)
        val p = statsPrefs!!
        if (p.getLong("endedAt", 0L) > 0) {
            lastSession.value = LastSession(
                durationMs = p.getLong("durationMs", 0L),
                rxBytes = p.getLong("rxBytes", 0L),
                txBytes = p.getLong("txBytes", 0L),
                avgSpeedBps = p.getLong("avgSpeedBps", 0L),
                endedAt = p.getLong("endedAt", 0L),
            )
        }
    }

    /** Called once per service run when the tunnel first reports CONNECTED. */
    fun markSessionStart() {
        if (sessionStartWallMs == null) {
            sessionStartWallMs = System.currentTimeMillis()
            totalRx.value = 0
            totalTx.value = 0
        }
    }

    /** Persists the finished session so the home card keeps showing it after disconnect. */
    fun saveLastSession(): LastSession? {
        val start = sessionStartWallMs ?: return null
        val dur = System.currentTimeMillis() - start
        sessionStartWallMs = null
        if (dur <= 0) return null
        val rx = totalRx.value
        val tx = totalTx.value
        val avg = if (dur > 0) (rx + tx) * 1000 / dur else 0L
        val ls = LastSession(durationMs = dur, rxBytes = rx, txBytes = tx, avgSpeedBps = avg, endedAt = System.currentTimeMillis())
        lastSession.value = ls
        statsPrefs?.edit()
            ?.putLong("durationMs", ls.durationMs)
            ?.putLong("rxBytes", ls.rxBytes)
            ?.putLong("txBytes", ls.txBytes)
            ?.putLong("avgSpeedBps", ls.avgSpeedBps)
            ?.putLong("endedAt", ls.endedAt)
            ?.apply()
        accumulateUsage(rx, tx, dur)
        return ls
    }

    /** Adds one finished session to the lifetime usage of the signed-in account. */
    private fun accumulateUsage(rx: Long, tx: Long, durationMs: Long) {
        val key = AuthStore.flow.value?.username ?: return
        val cur = usage.value
        val next = Usage(
            rxBytes = cur.rxBytes + rx,
            txBytes = cur.txBytes + tx,
            durationMs = cur.durationMs + durationMs,
            sessions = cur.sessions + 1,
        )
        usage.value = next
        statsPrefs?.edit()
            ?.putLong("usage_rx_$key", next.rxBytes)
            ?.putLong("usage_tx_$key", next.txBytes)
            ?.putLong("usage_dur_$key", next.durationMs)
            ?.putInt("usage_sessions_$key", next.sessions)
            ?.apply()
    }

    /** Loads the lifetime usage of the given account (call when auth changes). */
    fun loadUsage(username: String?) {
        val p = statsPrefs ?: return
        val key = username?.takeIf { it.isNotBlank() } ?: run { usage.value = Usage(0, 0, 0, 0); return }
        usage.value = Usage(
            rxBytes = p.getLong("usage_rx_$key", 0L),
            txBytes = p.getLong("usage_tx_$key", 0L),
            durationMs = p.getLong("usage_dur_$key", 0L),
            sessions = p.getInt("usage_sessions_$key", 0),
        )
    }

    /** Appends one sample, keeps the graph window and accumulates session totals. */
    fun pushTraffic(rx: Long, tx: Long) {
        val r = rx.coerceAtLeast(0)
        val t = tx.coerceAtLeast(0)
        totalRx.value += r
        totalTx.value += t
        traffic.value = (traffic.value + TrafficSample(r, t)).takeLast(TRAFFIC_WINDOW)
    }

    private val ansi = Regex("\u001B\\[[0-9;]*[A-Za-z]")

    fun log(line: String) {
        val clean = ansi.replace(line, "").trimEnd()
        if (clean.isEmpty()) return
        val stamp = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.US).format(java.util.Date())
        logs.value = (logs.value + "$stamp  $clean").takeLast(400)
    }

    fun resetLive() {
        pingMs.value = null
        rxBps.value = 0
        txBps.value = 0
        exitInfo.value = null
        exitCountry.value = null
        speedBps.value = null
        traffic.value = emptyList()
    }

    private const val TRAFFIC_WINDOW = 60
}
