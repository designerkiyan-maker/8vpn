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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Bottom navigation: [Rescan] [Connection] — floating Connect — [Settings] [Account].
 * The big connect button floats half over the bar; Rescan is an action, not a screen, so it
 * dims unless an Aether route is connected.
 */
@Composable
fun BottomBar(
    connectionSelected: Boolean,
    settingsSelected: Boolean,
    accountSelected: Boolean,
    phase: Phase,
    paused: Boolean,
    family: Family,
    onConnection: () -> Unit,
    onSettings: () -> Unit,
    onAccount: () -> Unit,
    onRescan: () -> Unit,
    onConnect: () -> Unit,
) {
    val rescanEnabled = phase == Phase.CONNECTED && !paused && family == Family.AETHER
    Box(Modifier.fillMaxWidth()) {
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, modifier = Modifier.fillMaxWidth().align(Alignment.BottomCenter)) {
            Row(
                Modifier.fillMaxWidth().navigationBarsPadding().height(66.dp),
                horizontalArrangement = Arrangement.SpaceEvenly,
                verticalAlignment = Alignment.CenterVertically
            ) {
                BarSlot(Ico.REFRESH, stringResource(R.string.rescan), selected = false, enabled = rescanEnabled, onClick = onRescan)
                BarSlot(Ico.GLOBE, stringResource(R.string.nav_connection), selected = connectionSelected, enabled = true, onClick = onConnection)
                Spacer(Modifier.width(84.dp))   // room for the floating connect button
                BarSlot(Ico.SETTINGS, stringResource(R.string.settings), selected = settingsSelected, enabled = true, onClick = onSettings)
                BarSlot(Ico.USER, stringResource(R.string.account), selected = accountSelected, enabled = true, onClick = onAccount)
            }
        }
        Box(Modifier.align(Alignment.TopCenter).offset(y = (-16).dp)) {
            ConnectButton(phase, onConnect, 74.dp)
        }
    }
}

@Composable
private fun BarSlot(ico: Ico, label: String, selected: Boolean, enabled: Boolean, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val tint = when {
        !enabled -> c.onSurfaceVariant.copy(alpha = 0.35f)
        selected -> c.primary
        else -> c.onSurfaceVariant
    }
    Column(
        Modifier.clip(RoundedCornerShape(14.dp)).clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 6.dp, vertical = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Icon8(ico, tint, 22.dp)
        Spacer(Modifier.height(2.dp))
        Text(
            label, fontSize = 10.sp, color = tint, maxLines = 1,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal
        )
    }
}
