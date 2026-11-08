package ir.mrkiyan.eligius

import android.app.PendingIntent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import com.google.android.gms.auth.api.phone.SmsRetriever
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Split OTP input (Stage 21): six separate boxes, one digit each, digits
 * distributed correctly from PASTED text, "focus" advancing visually with
 * every keystroke. Digit codes always read left→right, even in the Persian
 * RTL layout — hence the fixed LTR direction on the box row.
 *
 * Implementation: ONE real (invisible) text field overlaid on the six
 * decorated boxes — paste, backspace and IME behaviour come for free, the
 * boxes are pure rendering of [value].
 */
@Composable
fun SplitOtpInput(
    value: String,
    onValueChange: (String) -> Unit,
    enabled: Boolean = true,
    modifier: Modifier = Modifier,
    autoFocus: Boolean = true,
) {
    val focus = remember { FocusRequester() }
    val c = MaterialTheme.colorScheme
    val fieldHeight = 58.dp

    Box(modifier.fillMaxWidth().height(fieldHeight), contentAlignment = Alignment.Center) {
        CompositionLocalLtr {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 2.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                repeat(6) { i ->
                    val ch = value.getOrNull(i)
                    Box(
                        Modifier
                            .weight(1f)
                            .height(fieldHeight)
                            .clip(RoundedCornerShape(14.dp))
                            .background(
                                if (ch != null) c.primary.copy(alpha = 0.14f) else Color.Transparent
                            )
                            .border(
                                if (ch != null) 1.5.dp else 1.dp,
                                if (ch != null) c.primary else c.outlineVariant,
                                RoundedCornerShape(14.dp)
                            ),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            ch?.toString() ?: "",
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.Bold,
                            color = c.onSurface
                        )
                    }
                }
            }
        }
        // The invisible real field: taps land on it, IME opens, paste works.
        BasicTextField(
            value = value,
            onValueChange = { v -> onValueChange(v.filter { ch -> ch.isDigit() }.take(6)) },
            enabled = enabled,
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            textStyle = TextStyle(color = Color.Transparent, fontSize = 1.sp),
            cursorBrush = Brush.verticalGradient(listOf(Color.Transparent, Color.Transparent)),
            modifier = Modifier
                .fillMaxWidth()
                .height(fieldHeight)
                .focusRequester(focus)
        )
    }
    if (autoFocus) {
        LaunchedEffect(Unit) { focus.requestFocus() }
    }
}

/** Forces LTR content for OTP digits inside the RTL app. */
@Composable
private fun CompositionLocalLtr(content: @Composable () -> Unit) {
    androidx.compose.runtime.CompositionLocalProvider(
        LocalLayoutDirection provides LayoutDirection.Ltr,
        content = content
    )
}

/**
 * Arms the SMS auto-fill listeners for as long as the calling screen is
 * composed. Auto (Retriever) codes arrive silently; consent-path codes need
 * one tap on the system dialog, which is launched here automatically.
 */
@Composable
fun SmsOtpEffect(onCode: (String) -> Unit) {
    val ctx = LocalContext.current
    val consentLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.StartIntentSenderForResult()
    ) { r ->
        val msg = r.data?.getStringExtra(SmsRetriever.EXTRA_SMS_MESSAGE)
        val code = SmsOtp.extractCode(msg)
        if (code != null) onCode(code)
    }
    DisposableEffect(Unit) {
        val session = SmsOtp.start(
            ctx,
            onCode = onCode,
            onConsent = { pending: PendingIntent ->
                runCatching {
                    consentLauncher.launch(IntentSenderRequest.Builder(pending.intentSender).build())
                }
            }
        )
        onDispose { session.dispose() }
    }
}
