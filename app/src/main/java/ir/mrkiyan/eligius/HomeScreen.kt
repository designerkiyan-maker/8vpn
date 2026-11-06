@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package ir.mrkiyan.eligius

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private fun up(f: (Settings) -> Settings) = SettingsStore.update(f)


private fun fmtRate(b: Long): String = when {
    b < 0 -> "—"
    b >= 1_048_576 -> String.format(Locale.US, "%.1f MB/s", b / 1_048_576.0)
    b >= 1024 -> String.format(Locale.US, "%.0f KB/s", b / 1024.0)
    else -> "$b B/s"
}

@Composable
fun HomeScreen(openLogs: () -> Unit) {
    val ctx = LocalContext.current
    val haptic = LocalHapticFeedback.current
    val s by SettingsStore.flow.collectAsStateWithLifecycle()
    val st by Engine.status.collectAsStateWithLifecycle()
    val ping by Engine.pingMs.collectAsStateWithLifecycle()
    val rx by Engine.rxBps.collectAsStateWithLifecycle()
    val tx by Engine.txBps.collectAsStateWithLifecycle()
    val exit by Engine.exitInfo.collectAsStateWithLifecycle()
    val exitCc by Engine.exitCountry.collectAsStateWithLifecycle()
    val speed by Engine.speedBps.collectAsStateWithLifecycle()
    val totalRx by Engine.totalRx.collectAsStateWithLifecycle()
    val totalTx by Engine.totalTx.collectAsStateWithLifecycle()
    val lastSession by Engine.lastSession.collectAsStateWithLifecycle()
    val netType by Engine.networkType.collectAsStateWithLifecycle()

    val idle = st.phase == Phase.IDLE
    val scope = rememberCoroutineScope()
    val paused by Engine.paused.collectAsStateWithLifecycle()
    var testing by remember { mutableStateOf(false) }

    // The result goes into the Ping box of the stats card above; no separate text next to the button.
    fun runPing() {
        if (testing || st.phase != Phase.CONNECTED) return
        testing = true
        val port = ArgBuilder.exitPort(s)
        scope.launch {
            val ms: Long? = withContext(Dispatchers.IO) {
                Socks.httpGet(port, "cp.cloudflare.com", "/generate_204", 8000)?.first
            }
            if (ms != null) Engine.pingMs.value = ms.toInt()
            testing = false
        }
    }

    val c = MaterialTheme.colorScheme
    val x = LocalExtra.current
    val title = when (st.phase) {
        Phase.IDLE -> R.string.disconnected
        Phase.CONNECTING -> R.string.connecting
        Phase.CONNECTED -> if (paused) R.string.vpn_paused else R.string.connected
        Phase.RECONNECTING -> R.string.reconnecting
        Phase.STOPPING -> R.string.stopping
    }
    val statusColor = when (st.phase) {
        Phase.CONNECTED -> if (paused) x.busy else x.a
        Phase.IDLE -> c.onSurfaceVariant
        else -> x.busy
    }
    val live = st.phase == Phase.CONNECTED

    var liveDurationMs by remember { mutableStateOf(0L) }
    LaunchedEffect(live) {
        while (live) {
            Engine.sessionStartWallMs?.let { liveDurationMs = System.currentTimeMillis() - it }
            kotlinx.coroutines.delay(1000)
        }
    }

    Column(
        Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        // ---- Top bar
        Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            LogoMark(36.dp)
            Spacer(Modifier.width(10.dp))
            Text(
                stringResource(R.string.app_name), Modifier.weight(1f),
                style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold
            )
        }

        // ---- Status (the connect button lives in the bottom bar)
        Spacer(Modifier.height(8.dp))
        Row(
            Modifier.clip(RoundedCornerShape(50)).background(statusColor.copy(alpha = 0.12f)).padding(horizontal = 14.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(Modifier.size(8.dp).clip(CircleShape).background(statusColor))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(title), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, color = statusColor)
            // Country flag of the exit IP, shown next to "Connected" (like the Windows app).
            val flagCc = if (live && !paused) exitCc ?: s.psiphonRegion.takeIf { s.route.usesPsiphon && it.length == 2 } else null
            if (live && !paused && s.route.family == Family.TOR) {
                // Tor: the exit changes all the time, so show the same "auto" globe as the region picker.
                Spacer(Modifier.width(8.dp))
                Text("\uD83C\uDF10", style = MaterialTheme.typography.titleMedium)
            } else if (flagCc != null) {
                Spacer(Modifier.width(8.dp))
                CountryFlag(flagCc)
            }
        }
        Spacer(Modifier.height(8.dp))
        val showAttempt = st.phase == Phase.RECONNECTING || (st.phase == Phase.CONNECTING && st.attempt > 1)
        if (showAttempt) {
            Text(stringResource(R.string.attempt_of, st.attempt, s.tries()), style = MaterialTheme.typography.bodySmall, color = x.busy)
        } else if (idle && st.error != null) {
            Text(st.error!!, style = MaterialTheme.typography.bodySmall, color = c.error, textAlign = TextAlign.Center)
        } else if (idle) {
            Text(stringResource(R.string.tap_to_connect), style = MaterialTheme.typography.bodyMedium, color = c.onSurfaceVariant)
        } else if (live && paused) {
            Text(stringResource(R.string.paused_hint), style = MaterialTheme.typography.bodyMedium, color = c.onSurfaceVariant, textAlign = TextAlign.Center)
        } else if (live) {
            val netLabel = when (netType) {
                "wifi" -> stringResource(R.string.net_wifi)
                "mobile" -> stringResource(R.string.net_mobile)
                "ethernet" -> stringResource(R.string.net_ethernet)
                else -> null
            }
            Text(
                s.activeLabel() + (netLabel?.let { "  ·  $it" } ?: "") + (exit?.let { "  ·  $it" } ?: ""),
                style = MaterialTheme.typography.bodyMedium, color = c.onSurfaceVariant, textAlign = TextAlign.Center
            )
        }
        val reason by Engine.lastReason.collectAsStateWithLifecycle()
        reason?.let {
            Text(
                stringResource(R.string.last_drop, it), style = MaterialTheme.typography.bodySmall,
                color = c.onSurfaceVariant, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 4.dp)
            )
        }

        // ---- Stats: live throughput graph on top, numbers below
        Spacer(Modifier.height(18.dp))
        AppCard {
            ThroughputGraph(live = live && s.vpnMode, paused = paused, modifier = Modifier.padding(8.dp))
            Row(Modifier.padding(horizontal = 6.dp, vertical = 16.dp)) {
                Stat(Ico.BOLT, stringResource(R.string.ping), ping?.let { stringResource(R.string.ms_fmt, it) } ?: "—", Modifier.weight(1f))
                Stat(Ico.DOWN, stringResource(R.string.download), if (s.vpnMode && live) fmtRate(rx) else "—", Modifier.weight(1f))
                Stat(Ico.UP, stringResource(R.string.upload), if (s.vpnMode && live) fmtRate(tx) else "—", Modifier.weight(1f))
                Stat(Ico.GLOBE, stringResource(R.string.link_speed), if (live) speed?.let { fmtRate(it) } ?: "…" else "—", Modifier.weight(1f))
            }
        }
        if (!s.vpnMode) {
            Spacer(Modifier.height(10.dp))
            AppCard {
                Text(
                    stringResource(R.string.proxy_info, "127.0.0.1:${s.socksPort}") +
                        if (s.httpEnabled) "   " + stringResource(R.string.http_info, "127.0.0.1:${s.httpPort}") else "",
                    modifier = Modifier.padding(14.dp), style = MaterialTheme.typography.bodySmall, fontFamily = FontFamily.Monospace
                )
            }
        }

        // ---- Actions: only while connected, so there are no dead buttons
        if (live) {
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                TonalPill(Ico.BOLT, if (testing) stringResource(R.string.ping_testing) else stringResource(R.string.ping_test), enabled = !testing && !paused) { runPing() }
                // Start / Stop: Stop switches the VPN interface off but keeps the core running,
                // so Start brings the VPN back at once (handy for banking apps that dislike VPNs).
                if (s.vpnMode) {
                    TonalPill(
                        if (paused) Ico.PLAY else Ico.PAUSE,
                        stringResource(if (paused) R.string.vpn_start else R.string.vpn_stop),
                        danger = !paused
                    ) {
                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                        if (paused) TunnelService.resume(ctx) else TunnelService.pause(ctx)
                    }
                }
                // Rescan lives here, its original place (also reachable from the Connection tab bar era).
                if (s.route.family == Family.AETHER) {
                    TonalPill(Ico.REFRESH, stringResource(R.string.rescan), enabled = !paused) { TunnelService.rescan(ctx) }
                }
            }
        }

        // ---- Last / Current Connection Info Card (persists after disconnect)
        Spacer(Modifier.height(16.dp))
        LastConnectionCard(
            live = live && !paused,
            durationMs = if (live) liveDurationMs else lastSession?.durationMs ?: 0L,
            downloadBytes = if (live) totalRx else lastSession?.rxBytes ?: 0L,
            uploadBytes = if (live) totalTx else lastSession?.txBytes ?: 0L,
            speedBps = if (live) (speed ?: 0L) else lastSession?.avgSpeedBps ?: 0L
        )

        Spacer(Modifier.height(96.dp))
    }
}

/** Capacity of the home graph window, in one-second samples. */
private const val TRAFFIC_CAPACITY = 60

/**
 * Live throughput graph over the last minute: download fills upward from the middle axis,
 * upload mirrors downward, both scrolling in from the right. Drawn with the theme's own
 * primary (download) and secondary (upload) colors.
 */
@Composable
private fun ThroughputGraph(live: Boolean, paused: Boolean, modifier: Modifier = Modifier) {
    val c = MaterialTheme.colorScheme
    val rxColor = c.primary
    val txColor = c.secondary
    val hist by Engine.traffic.collectAsStateWithLifecycle()
    Box(
        modifier.fillMaxWidth().height(116.dp)
            .clip(RoundedCornerShape(18.dp)).background(c.surfaceContainerHigh)
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val w = size.width
            val h = size.height
            val mid = h / 2f
            val edge = 5.dp.toPx()
            for (i in intArrayOf(1, 3)) {
                val y = h * i / 4f
                drawLine(c.outlineVariant.copy(alpha = 0.35f), Offset(0f, y), Offset(w, y), strokeWidth = 1f)
            }
            drawLine(c.outline.copy(alpha = 0.5f), Offset(0f, mid), Offset(w, mid), strokeWidth = 1f)
            val samples = if (live && !paused) hist else emptyList()
            val n = samples.size
            if (n >= 2) {
                val peak = samples.maxOf { maxOf(it.rx, it.tx) }.coerceAtLeast(64L * 1024L).toFloat()
                val step = w / (TRAFFIC_CAPACITY - 1)
                fun xAt(i: Int) = w - (n - 1 - i) * step
                fun yRx(i: Int) = mid - (samples[i].rx / peak) * (mid - edge)
                fun yTx(i: Int) = mid + (samples[i].tx / peak) * (mid - edge)
                fun linePath(yOf: (Int) -> Float): Path {
                    val p = Path()
                    p.moveTo(xAt(0), yOf(0))
                    for (i in 1 until n) {
                        val px = xAt(i - 1); val py = yOf(i - 1); val x = xAt(i); val y = yOf(i)
                        p.cubicTo((px + x) / 2f, py, (px + x) / 2f, y, x, y)
                    }
                    return p
                }
                fun areaPath(yOf: (Int) -> Float): Path {
                    val p = linePath(yOf)
                    p.lineTo(xAt(n - 1), mid)
                    p.lineTo(xAt(0), mid)
                    p.close()
                    return p
                }
                drawPath(
                    areaPath(::yRx),
                    Brush.verticalGradient(listOf(rxColor.copy(alpha = 0.42f), rxColor.copy(alpha = 0.04f)), startY = 0f, endY = mid)
                )
                drawPath(
                    areaPath(::yTx),
                    Brush.verticalGradient(listOf(txColor.copy(alpha = 0.05f), txColor.copy(alpha = 0.42f)), startY = mid, endY = h)
                )
                drawPath(linePath(::yRx), rxColor, style = Stroke(2.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
                drawPath(linePath(::yTx), txColor, style = Stroke(2.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
            } else {
                drawLine(
                    c.outline.copy(alpha = 0.35f), Offset(w * 0.12f, mid), Offset(w * 0.88f, mid),
                    strokeWidth = 1.5f, cap = StrokeCap.Round
                )
            }
        }
    }
}

@Composable
private fun Stat(ico: Ico, label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Icon8(ico, LocalExtra.current.a, 18.dp)
        Spacer(Modifier.height(8.dp))
        Text(
            value, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, textAlign = TextAlign.Center,
            overflow = TextOverflow.Ellipsis
        )
        Spacer(Modifier.height(2.dp))
        Text(
            label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center
        )
    }
}

/** Big round power button with soft expanding rings while the link is up or being set up. */
@Composable
fun ConnectButton(phase: Phase, onClick: () -> Unit, diameter: Dp = 158.dp) {
    val c = MaterialTheme.colorScheme
    val x = LocalExtra.current
    val live = phase == Phase.CONNECTED
    val busy = phase == Phase.CONNECTING || phase == Phase.RECONNECTING || phase == Phase.STOPPING

    val inf = rememberInfiniteTransition(label = "hero")
    val t by inf.animateFloat(0f, 1f, infiniteRepeatable(tween(2600, easing = LinearEasing)), label = "rings")
    val breathe by inf.animateFloat(
        0.95f, 1.04f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "breathe"
    )
    val glow by animateFloatAsState(if (live) 1f else if (busy) 0.75f else 0f, tween(500), label = "glow")
    val liveA by animateFloatAsState(if (live) 1f else 0f, tween(450), label = "liveA")
    val busyA by animateFloatAsState(if (busy) 1f else 0f, tween(300), label = "busyA")
    val ring = if (busy) x.busy else x.a
    val fg by animateColorAsState(
        when { live -> Color.White; busy -> Color(0xFF2B1E00); else -> c.onSurfaceVariant }, label = "fg"
    )

    Box(Modifier.size(diameter * 1.5f), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            if (glow > 0.01f) {
                val outer = this.size.minDimension / 2f
                val core = outer / 1.5f
                drawCircle(
                    Brush.radialGradient(listOf(ring.copy(alpha = 0.26f * glow), Color.Transparent), radius = outer),
                    radius = outer
                )
                for (i in 0 until 3) {
                    val p = (t + i / 3f) % 1f
                    drawCircle(
                        color = ring.copy(alpha = (1f - p) * 0.30f * glow),
                        radius = core + (outer - core) * p,
                        style = androidx.compose.ui.graphics.drawscope.Stroke(width = 1.5.dp.toPx())
                    )
                }
            }
        }
        Box(
            Modifier.size(diameter).scale(if (busy) breathe else 1f).clip(CircleShape)
                .background(c.surfaceContainerHigh).border(1.dp, c.outlineVariant, CircleShape)
                .clickable(onClick = onClick),
            contentAlignment = Alignment.Center
        ) {
            Box(Modifier.fillMaxSize().alpha(liveA).background(x.gradient))
            Box(Modifier.fillMaxSize().alpha(busyA).background(x.busy))
            Icon8(Ico.POWER, fg, diameter * 0.40f, strokeW = 2.3f)
        }
    }
}

// RegionDialog / RegionButton / FamilyPanel moved to ConnectionScreen.kt.

@Composable
private fun LastConnectionCard(
    live: Boolean,
    durationMs: Long,
    downloadBytes: Long,
    uploadBytes: Long,
    speedBps: Long
) {
    val c = MaterialTheme.colorScheme
    val x = LocalExtra.current

    AppCard(modifier = Modifier.fillMaxWidth().padding(horizontal = 6.dp)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(stringResource(R.string.last_connection), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = c.onSurface)
                Text(
                    if (live) stringResource(R.string.live) else stringResource(R.string.last_session),
                    style = MaterialTheme.typography.labelMedium, color = c.primary
                )
            }
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                InfoColumn(
                    label = stringResource(R.string.duration),
                    value = formatDuration(durationMs),
                    color = x.a,
                    modifier = Modifier.weight(1f)
                )
                InfoColumn(
                    label = stringResource(R.string.download),
                    value = fmtBytes(downloadBytes),
                    color = c.primary,
                    modifier = Modifier.weight(1f)
                )
                InfoColumn(
                    label = stringResource(R.string.upload),
                    value = fmtBytes(uploadBytes),
                    color = c.secondary,
                    modifier = Modifier.weight(1f)
                )
                InfoColumn(
                    label = stringResource(R.string.speed),
                    value = if (speedBps > 0) fmtRate(speedBps) else "—",
                    color = c.tertiary,
                    modifier = Modifier.weight(1f)
                )
            }
        }
    }
}

@Composable
private fun InfoColumn(
    label: String,
    value: String,
    color: Color,
    modifier: Modifier = Modifier
) {
    val c = MaterialTheme.colorScheme
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = color, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Spacer(Modifier.height(2.dp))
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, textAlign = TextAlign.Center)
    }
}

// RegionDialog / RegionButton / FamilyPanel moved to ConnectionScreen.kt.
