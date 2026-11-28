@file:OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)

package ir.mrkiyan.eligius

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// ───────────────────────── Icons (Solar set — 480 Design, via Iconify) ─────────────────────────

enum class Ico {
    BACK, SETTINGS, LOGS, POWER, CHECK, CHEVRON, LOCK, DOWN, UP, BOLT, GLOBE, REFRESH,
    COPY, SHARE, TRASH, INFO, CODE, SEND, PAUSE, PLAY, USER
}

/** Ico → bundled Solar VectorDrawable (owner directive: Solar icons everywhere). */
private val IcoSolarRes = mapOf(
    Ico.BACK to R.drawable.solar_back,
    Ico.SETTINGS to R.drawable.solar_settings,
    Ico.LOGS to R.drawable.solar_logs,
    Ico.POWER to R.drawable.solar_power,
    Ico.CHECK to R.drawable.solar_check,
    Ico.CHEVRON to R.drawable.solar_chevron,
    Ico.LOCK to R.drawable.solar_lock,
    Ico.DOWN to R.drawable.solar_down,
    Ico.UP to R.drawable.solar_up,
    Ico.BOLT to R.drawable.solar_bolt,
    Ico.GLOBE to R.drawable.solar_globe,
    Ico.REFRESH to R.drawable.solar_refresh,
    Ico.COPY to R.drawable.solar_copy,
    Ico.SHARE to R.drawable.solar_share,
    Ico.TRASH to R.drawable.solar_trash,
    Ico.INFO to R.drawable.solar_info,
    Ico.CODE to R.drawable.solar_code,
    Ico.SEND to R.drawable.solar_send,
    Ico.PAUSE to R.drawable.solar_pause,
    Ico.PLAY to R.drawable.solar_play,
    Ico.USER to R.drawable.solar_user,
)

@Composable
fun Icon8(ico: Ico, tint: Color, sz: Dp = 22.dp, modifier: Modifier = Modifier, strokeW: Float = 2f) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val flip = rtl && ico == Ico.BACK
    Icon(
        painter = painterResource(IcoSolarRes[ico] ?: R.drawable.solar_info),
        contentDescription = null,
        tint = tint,
        modifier = modifier
            .size(sz)
            .let { if (flip) it.graphicsLayer(scaleX = -1f) else it }
    )
}

@Composable
fun LogoMark(sz: Dp) {
    Box(
        Modifier.size(sz).clip(RoundedCornerShape(sz * 0.3f)).background(Color(0xFF000003)),
        contentAlignment = Alignment.Center
    ) {
        Image(
            painterResource(R.drawable.brand_mark),
            contentDescription = null,
            modifier = Modifier.fillMaxSize(),
            contentScale = ContentScale.Crop
        )
    }
}

@Composable
fun IconBtn(ico: Ico, onClick: () -> Unit, enabled: Boolean = true, tint: Color = MaterialTheme.colorScheme.onSurface) {
    val c = MaterialTheme.colorScheme
    Box(
        Modifier.size(42.dp).clip(CircleShape).background(c.surfaceContainer)
            .border(1.dp, c.outlineVariant, CircleShape).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center
    ) { Icon8(ico, tint, 20.dp) }
}

// ───────────────────────── Containers ─────────────────────────

@Composable
fun AppCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val c = MaterialTheme.colorScheme
    val shape = RoundedCornerShape(22.dp)
    Column(
        modifier.fillMaxWidth().clip(shape).background(c.surfaceContainer).border(1.dp, c.outlineVariant, shape),
        content = content
    )
}

/** A titled settings group: small heading + one card holding the rows. */
@Composable
fun Group(title: String, content: @Composable ColumnScope.() -> Unit) {
    SectionTitle(title)
    AppCard {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(16.dp), content = content)
    }
}

@Composable
fun Field(title: String, content: @Composable () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Label(title)
        content()
    }
}

/** Page frame: safe-area padding, round back button, title and optional actions. */
@Composable
fun Page(title: String, onBack: () -> Unit, actions: @Composable RowScope.() -> Unit = {}, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing).padding(horizontal = 16.dp)) {
        Row(Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            IconBtn(Ico.BACK, onBack)
            Spacer(Modifier.width(12.dp))
            Text(
                title, Modifier.weight(1f), style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) { actions() }
        }
        content()
    }
}

// ───────────────────────── Text ─────────────────────────

@Composable
fun Label(text: String) {
    Text(text, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
}

@Composable
fun SectionTitle(text: String) {
    Row(Modifier.padding(start = 4.dp, top = 22.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.width(4.dp).height(14.dp).clip(RoundedCornerShape(2.dp)).background(LocalExtra.current.gradient))
        Spacer(Modifier.width(8.dp))
        Text(text, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onSurface)
    }
}

@Composable
fun Hint(text: String, error: Boolean = false) {
    Text(
        text, style = MaterialTheme.typography.bodySmall,
        color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant
    )
}

/** Soft tinted note with an info icon. */
@Composable
fun Banner(text: String) {
    val c = MaterialTheme.colorScheme
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(c.primary.copy(alpha = 0.10f)).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon8(Ico.INFO, c.primary, 20.dp)
        Spacer(Modifier.width(10.dp))
        Text(text, style = MaterialTheme.typography.bodySmall, color = c.onSurface)
    }
}

// ───────────────────────── Selection ─────────────────────────

@Composable
fun Pill(text: String, selected: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val shape = RoundedCornerShape(14.dp)
    val bg by animateColorAsState(if (selected) c.primary.copy(alpha = 0.16f) else c.surfaceContainerHigh, label = "pillBg")
    val fg = if (selected) c.primary else c.onSurfaceVariant
    Row(
        Modifier.alpha(if (enabled) 1f else 0.5f).clip(shape).background(bg)
            .border(1.dp, if (selected) c.primary.copy(alpha = 0.55f) else Color.Transparent, shape)
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (selected) {
            Icon8(Ico.CHECK, fg, 14.dp, strokeW = 2.6f)
            Spacer(Modifier.width(6.dp))
        }
        Text(text, color = fg, fontSize = 13.sp, fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal)
    }
}

@Composable
fun <T> Chips(options: List<Pair<T, String>>, selected: T, enabled: Boolean = true, onSelect: (T) -> Unit) {
    // Wraps onto the next line instead of running off the screen.
    FlowRow(
        Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        options.forEach { (v, label) -> Pill(label, v == selected, enabled) { onSelect(v) } }
    }
}

/** Full-width segmented switch (2–3 options) used on the home screen. */
@Composable
fun <T> Segmented(options: List<Pair<T, String>>, selected: T, enabled: Boolean = true, onSelect: (T) -> Unit) {
    val c = MaterialTheme.colorScheme
    Row(
        Modifier.fillMaxWidth().alpha(if (enabled) 1f else 0.6f).clip(RoundedCornerShape(16.dp))
            .background(c.surfaceContainerHigh).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp)
    ) {
        options.forEach { (v, label) ->
            val sel = v == selected
            val bg by animateColorAsState(if (sel) c.primary else Color.Transparent, label = "segBg")
            Box(
                Modifier.weight(1f).height(40.dp).clip(RoundedCornerShape(12.dp)).background(bg)
                    .clickable(enabled = enabled) { onSelect(v) },
                contentAlignment = Alignment.Center
            ) {
                Text(
                    label, maxLines = 1, softWrap = false, overflow = TextOverflow.Ellipsis, fontSize = 13.sp,
                    fontWeight = if (sel) FontWeight.SemiBold else FontWeight.Medium,
                    color = if (sel) c.onPrimary else c.onSurfaceVariant
                )
            }
        }
    }
}

// ───────────────────────── Buttons ─────────────────────────

@Composable
fun TonalPill(ico: Ico, text: String, enabled: Boolean = true, danger: Boolean = false, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val tone = if (danger) c.error else c.primary
    Row(
        Modifier.alpha(if (enabled) 1f else 0.5f).clip(RoundedCornerShape(14.dp)).background(tone.copy(alpha = 0.13f))
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon8(ico, tone, 18.dp)
        Spacer(Modifier.width(8.dp))
        Text(text, color = tone, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** Full-width action row used in settings (battery, logs, clear cache…). */
@Composable
fun ActionRow(ico: Ico, text: String, enabled: Boolean = true, danger: Boolean = false, onClick: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val tone = if (danger) c.error else c.onSurface
    Row(
        Modifier.fillMaxWidth().alpha(if (enabled) 1f else 0.5f).clip(RoundedCornerShape(16.dp))
            .background(c.surfaceContainerHigh).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon8(ico, if (danger) c.error else c.primary, 20.dp)
        Spacer(Modifier.width(12.dp))
        Text(text, Modifier.weight(1f), color = tone, style = MaterialTheme.typography.bodyMedium)
    }
}

// ───────────────────────── Inputs ─────────────────────────

@Composable
private fun fieldColors() = OutlinedTextFieldDefaults.colors(
    focusedContainerColor = MaterialTheme.colorScheme.surfaceContainerHigh,
    unfocusedContainerColor = MaterialTheme.colorScheme.surfaceContainerHigh,
    focusedBorderColor = MaterialTheme.colorScheme.primary,
    unfocusedBorderColor = Color.Transparent,
    errorContainerColor = MaterialTheme.colorScheme.surfaceContainerHigh,
)

@Composable
fun SearchField(value: String, onChange: (String) -> Unit, hint: String) {
    OutlinedTextField(
        value = value, onValueChange = onChange, singleLine = true, shape = RoundedCornerShape(16.dp),
        placeholder = { Text(hint) }, colors = fieldColors(), modifier = Modifier.fillMaxWidth()
    )
}

@Composable
fun SwitchRow(title: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { onChange(!checked) },
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(title, Modifier.weight(1f).padding(end = 12.dp), style = MaterialTheme.typography.bodyMedium)
        Switch(
            checked = checked, onCheckedChange = onChange,
            colors = SwitchDefaults.colors(
                checkedTrackColor = MaterialTheme.colorScheme.primary,
                checkedThumbColor = MaterialTheme.colorScheme.onPrimary,
            )
        )
    }
}

@Composable
fun TextRow(title: String, value: String, hint: String = "", singleLine: Boolean = true, isError: Boolean = false, onChange: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Label(title)
        OutlinedTextField(
            value = value, onValueChange = onChange, singleLine = singleLine, isError = isError,
            shape = RoundedCornerShape(14.dp), colors = fieldColors(),
            placeholder = { if (hint.isNotEmpty()) Text(hint) }, modifier = Modifier.fillMaxWidth()
        )
    }
}

@Composable
fun NumberRow(title: String, value: Int, onChange: (Int) -> Unit) {
    var t by remember(value) { mutableStateOf(value.toString()) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Label(title)
        OutlinedTextField(
            value = t,
            onValueChange = { v ->
                t = v.filter { it.isDigit() }.take(5)
                t.toIntOrNull()?.let(onChange)
            },
            singleLine = true, shape = RoundedCornerShape(14.dp), colors = fieldColors(),
            modifier = Modifier.fillMaxWidth()
        )
    }
}

/**
 * Port field. The text is kept exactly as typed and a value is saved only once it is a valid, free
 * port, so a half-typed number never gets rewritten under the user's fingers.
 */
@Composable
fun PortRow(title: String, value: Int, otherPort: Int, errorText: String, onValid: (Int) -> Unit) {
    var t by remember { mutableStateOf(value.toString()) }
    val n = t.toIntOrNull()
    val bad = n == null || n !in 1024..65535 || n == TOR_PORT || n == PSIPHON_PORT || n == otherPort
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Label(title)
        OutlinedTextField(
            value = t,
            onValueChange = { v ->
                t = v.filter { it.isDigit() }.take(5)
                val x = t.toIntOrNull()
                if (x != null && x in 1024..65535 && x != TOR_PORT && x != PSIPHON_PORT && x != otherPort) onValid(x)
            },
            isError = bad,
            supportingText = if (bad) ({ Text(errorText) }) else null,
            singleLine = true, shape = RoundedCornerShape(14.dp), colors = fieldColors(),
            modifier = Modifier.fillMaxWidth()
        )
    }
}
