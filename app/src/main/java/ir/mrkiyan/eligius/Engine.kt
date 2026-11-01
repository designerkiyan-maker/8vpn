package ir.mrkiyan.eligius

import kotlinx.coroutines.flow.MutableStateFlow

enum class Phase { IDLE, CONNECTING, CONNECTED, RECONNECTING, STOPPING }

data class Status(val phase: Phase = Phase.IDLE, val attempt: Int = 0, val error: String? = null)

/** One-second throughput sample (bytes/s) for the home screen graph. */
data class TrafficSample(val rx: Long, val tx: Long)

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

    private val ansi = Regex("\u001B\\[[0-9;]*[A-Za-z]")

    fun log(line: String) {
        val clean = ansi.replace(line, "").trimEnd()
        if (clean.isEmpty()) return
        val stamp = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.US).format(java.util.Date())
        logs.value = (logs.value + "$stamp  $clean").takeLast(400)
    }

    /** Appends one sample and keeps only the newest [TRAFFIC_WINDOW] seconds. */
    fun pushTraffic(rx: Long, tx: Long) {
        traffic.value = (traffic.value + TrafficSample(rx.coerceAtLeast(0), tx.coerceAtLeast(0)))
            .takeLast(TRAFFIC_WINDOW)
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
