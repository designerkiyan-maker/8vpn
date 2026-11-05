package ir.mrkiyan.eligius

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle

private fun up(f: (Settings) -> Settings) = SettingsStore.update(f)

/** Connection setup: mode, core/route, protocol, region and bridges — locked while connected. */
@Composable
fun ConnectionScreen(back: () -> Unit) {
    val ctx = LocalContext.current
    val s by SettingsStore.flow.collectAsStateWithLifecycle()
    val st by Engine.status.collectAsStateWithLifecycle()
    val idle = st.phase == Phase.IDLE
    var lockedDialog by remember { mutableStateOf(false) }
    Page(stringResource(R.string.nav_connection), back) {
        Box {
            Column(
                Modifier.verticalScroll(rememberScrollState()).padding(bottom = 96.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Segmented(
                    listOf(true to stringResource(R.string.mode_vpn), false to stringResource(R.string.mode_proxy)),
                    s.vpnMode, idle
                ) { v -> if (s.vpnMode != v) up { it.copy(vpnMode = v) } }
                Segmented(
                    listOf(
                        Family.AETHER to stringResource(R.string.core_aether),
                        Family.PSIPHON to stringResource(R.string.core_psiphon),
                        Family.TOR to stringResource(R.string.core_tor)
                    ),
                    s.route.family, idle
                ) { fam ->
                    if (s.route.family != fam) up {
                        it.copy(route = when (fam) { Family.AETHER -> Route.AETHER; Family.PSIPHON -> Route.PSIPHON_ONLY; Family.TOR -> Route.TOR_ONLY })
                    }
                }
                if (!idle) Hint(stringResource(R.string.locked_hint))
                FamilyPanel(s.route.family, s, idle)
            }
            // While connected the whole panel is locked: any tap explains why and offers to disconnect.
            if (!idle) {
                Box(Modifier.matchParentSize().clickable { lockedDialog = true })
            }
        }
    }
    if (lockedDialog) {
        AlertDialog(
            onDismissRequest = { lockedDialog = false },
            shape = RoundedCornerShape(28.dp),
            containerColor = MaterialTheme.colorScheme.surfaceContainer,
            title = { Text(stringResource(R.string.locked_title), fontWeight = FontWeight.Bold) },
            text = { Text(stringResource(R.string.locked_hint)) },
            confirmButton = {
                TextButton(onClick = { TunnelService.stop(ctx); lockedDialog = false }) {
                    Text(stringResource(R.string.disconnect), color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold)
                }
            },
            dismissButton = {
                TextButton(onClick = { lockedDialog = false }) { Text(stringResource(R.string.cancel)) }
            }
        )
    }
}

@Composable
private fun FamilyPanel(fam: Family, s: Settings, enabled: Boolean) {
    var regionDialog by remember { mutableStateOf(false) }
    when (fam) {
        Family.AETHER -> {
            Field(stringResource(R.string.route)) {
                Chips(
                    listOf(Route.AETHER to "Aether", Route.AETHER_PSIPHON to "Aether → Psiphon", Route.AETHER_TOR to "Aether → Tor"),
                    s.route, enabled
                ) { v -> up { it.copy(route = v) } }
            }
            // The Psiphon region belongs to the route choice, so it opens right under it.
            if (s.route == Route.AETHER_PSIPHON) RegionButton(s, enabled) { regionDialog = true }
            Field(stringResource(R.string.protocol)) {
                Chips(Protocol.values().map { it to it.label }, s.protocol, enabled) { v -> up { it.copy(protocol = v) } }
            }
            if (s.protocol == Protocol.MASQUE || s.protocol == Protocol.MIM) {
                Field(stringResource(R.string.transport)) {
                    Chips(listOf("h3" to "HTTP/3 (QUIC)", "h2" to "HTTP/2 (TCP)"), s.carrier, enabled) { v -> up { it.copy(carrier = v) } }
                }
            }
        }
        Family.PSIPHON -> {
            Field(stringResource(R.string.route)) {
                Chips(
                    listOf(Route.PSIPHON_ONLY to "Psiphon only", Route.PSIPHON_REVERSE to "Psiphon → Aether"),
                    s.route, enabled
                ) { v -> up { it.copy(route = v) } }
            }
            RegionButton(s, enabled) { regionDialog = true }
            Field(stringResource(R.string.psiphon_mode)) {
                Chips(listOf("auto" to "Auto", "cdn" to "CDN"), s.psiphonMode, enabled) { v -> up { it.copy(psiphonMode = v) } }
            }
        }
        Family.TOR -> {
            Field(stringResource(R.string.route)) {
                Chips(
                    listOf(Route.TOR_ONLY to "Tor only", Route.TOR_REVERSE to "Tor → Aether"),
                    s.route, enabled
                ) { v -> up { it.copy(route = v) } }
            }
            Field(stringResource(R.string.bridges)) {
                Chips(
                    listOf(
                        "auto" to stringResource(R.string.bridges_auto),
                        "always" to stringResource(R.string.bridges_always),
                        "never" to stringResource(R.string.bridges_never)
                    ),
                    s.torBridges, enabled
                ) { v -> up { it.copy(torBridges = v) } }
            }
        }
    }
    if (regionDialog) RegionDialog(s.psiphonRegion, onPick = { cc -> up { it.copy(psiphonRegion = cc) }; regionDialog = false }) { regionDialog = false }
}

@Composable
fun RegionButton(s: Settings, enabled: Boolean, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val text = if (s.psiphonRegion.isBlank()) stringResource(R.string.region_auto)
    else PsiphonRegions.name(s.psiphonRegion, s.language)
    val flag = if (s.psiphonRegion.isBlank()) "🌐" else PsiphonRegions.flag(s.psiphonRegion)
    Field(stringResource(R.string.region)) {
        Row(
            Modifier.fillMaxWidth().alpha(if (enabled) 1f else 0.5f).clip(RoundedCornerShape(14.dp)).background(c.surfaceContainerHigh)
                .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(flag, fontSize = 20.sp)
            Spacer(Modifier.width(12.dp))
            Text(text, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
            Icon8(Ico.CHEVRON, c.onSurfaceVariant, 20.dp, Modifier.rotate(-90f))
        }
    }
}

@Composable
fun RegionDialog(current: String, onPick: (String) -> Unit, onDismiss: () -> Unit) {
    val lang = SettingsStore.current.language
    var q by remember { mutableStateOf("") }
    val all = remember(lang) { PsiphonRegions.codes.map { it to PsiphonRegions.name(it, lang) } }
    val list = all.filter { q.isBlank() || it.second.contains(q, true) || it.first.contains(q, true) }
    AlertDialog(
        onDismissRequest = onDismiss,
        shape = RoundedCornerShape(28.dp),
        containerColor = MaterialTheme.colorScheme.surfaceContainer,
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
        title = { Text(stringResource(R.string.region), fontWeight = FontWeight.Bold) },
        text = {
            Column {
                SearchField(q, { q = it }, stringResource(R.string.search))
                Spacer(Modifier.height(8.dp))
                LazyColumn(Modifier.height(320.dp)) {
                    item { RegionRow("🌐", stringResource(R.string.region_auto), current.isBlank()) { onPick("") } }
                    items(list.size) { i ->
                        val (cc, name) = list[i]
                        RegionRow(PsiphonRegions.flag(cc), "$name  ($cc)", cc == current) { onPick(cc) }
                    }
                }
            }
        }
    )
}

@Composable
private fun RegionRow(flag: String, name: String, selected: Boolean, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    Row(
        Modifier.fillMaxWidth().padding(vertical = 2.dp).clip(RoundedCornerShape(12.dp))
            .background(if (selected) c.primary.copy(alpha = 0.14f) else Color.Transparent)
            .clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(flag, fontSize = 22.sp)
        Spacer(Modifier.width(12.dp))
        Text(name, Modifier.weight(1f), color = if (selected) c.primary else c.onSurface)
        if (selected) Icon8(Ico.CHECK, c.primary, 18.dp, strokeW = 2.6f)
    }
}
