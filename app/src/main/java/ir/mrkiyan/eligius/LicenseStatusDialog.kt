package ir.mrkiyan.eligius

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

/**
 * License gate dialog (Roadmap P3 UI, docs 06 §5.2 + §3.9).
 *
 * Variants per error code:
 *  - DEVICE_LIMIT_REACHED → shows the active device id + OTP-transfer guidance
 *  - EXPIRED / NO_ACTIVE_LICENSE → plan-selection CTA
 *  - LICENSE_REVOKED / TIME_TAMPERING / DEVICE_NOT_BOUND → informational
 *
 * The success state is NEVER shown here — only backend-verified failures.
 */
@Composable
fun LicenseStatusDialog(
    error: String?,
    activeDeviceId: Long?,
    onChoosePlan: () -> Unit,
    onDismiss: () -> Unit
) {
    val (title, body, showPlanCta) = when {
        error == "DEVICE_LIMIT_REACHED" -> Quadruple(
            "دستگاه دیگری فعال است",
            "این حساب روی دستگاه دیگری فعال است" +
                (activeDeviceId?.let { " (شناسه دستگاه: $it)" } ?: "") +
                ".\n\nبرای استفاده از این دستگاه، از بخش «حساب» روی همین دستگاه درخواست انتقال با کد پیامکی بدهید و کد دریافتی را روی همین دستگاه وارد کنید؛ یا از دستگاه فعلی درخواست انتقال بدهید و کد را روی دستگاه جدید وارد کنید.",
            false
        )
        error == "LICENSE_REVOKED" -> Quadruple(
            "اشتراک لغو شده",
            "اشتراک شما لغو شده است. برای ادامه استفاده، اشتراک جدید تهیه کنید.",
            true
        )
        error == "OFFLINE_GRACE_EXPIRED" || error?.startsWith("SUBSCRIPTION_EXPIRED") == true ||
            error == "NO_ACTIVE_LICENSE" -> Quadruple(
            "اشتراک منقضی شده",
            "اشتراک فعال یا اعتبار آفلاین شما به پایان رسیده است. برای ادامه، اشتراک جدید تهیه کنید.",
            true
        )
        error == "DEVICE_NOT_REGISTERED" || error == "DEVICE_NOT_BOUND" -> Quadruple(
            "دستگاه ثبت نشده",
            "دستگاه شما به حساب متصل نیست. لطفاً چند لحظه بعد تلاش کنید؛ اگر مشکل ادامه داشت از بخش حساب، وضعیت دستگاه را بررسی کنید.",
            false
        )
        error?.startsWith("TIME_TAMPERING") == true -> Quadruple(
            "زمان دستگاه نامعتبر است",
            "تنظیمات زمان دستگاه امکان اعتبارسنجی آفلاین را نمی‌دهد (تغییر ساعت، روشن‌نبودن زمان خودکار یا ریستارت دستگاه).\n\nلطفاً یک‌بار آنلاین شوید تا مجوز دوباره تأیید شود.",
            false
        )
        error?.startsWith("NETWORK_ERROR") == true -> Quadruple(
            "خطای شبکه",
            "بررسی اشتراک با خطای شبکه مواجه شد. اتصال اینترنت را بررسی و دوباره تلاش کنید.",
            false
        )
        error == "NOT_LOGGED_IN" -> Quadruple(
            "ورود لازم است",
            "برای اتصال، ابتدا وارد حساب خود شوید.",
            false
        )
        else -> Quadruple(
            "اشتراک فعال نیست",
            "برای اتصال به VPN، اشتراک فعال لازم است.",
            true
        )
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title, fontWeight = FontWeight.Bold) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(body, style = MaterialTheme.typography.bodyMedium)
                if (activeDeviceId != null && error == "DEVICE_LIMIT_REACHED") {
                    Text(
                        "فعال‌سازی فقط روی یک دستگاه امکان‌پذیر است (سیاست یک دستگاه فعال).",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        },
        confirmButton = {
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                if (showPlanCta) {
                    TextButton(onClick = onChoosePlan) { Text("مشاهده پلن‌ها") }
                }
                TextButton(onClick = onDismiss) { Text("بستن") }
            }
        }
    )
}

/**
 * Phase 5b preview (owner requirement: changed Compose files carry previews).
 */
@androidx.compose.ui.tooling.preview.Preview(showBackground = true, name = "LicenseStatusDialog — limit reached")
@Composable
private fun LicenseStatusDialogPreview() {
    MaterialTheme {
        LicenseStatusDialog(
            error = "DEVICE_LIMIT_REACHED",
            activeDeviceId = 21,
            onChoosePlan = {},
            onDismiss = {}
        )
    }
}

private data class Quadruple(val title: String, val body: String, val showPlanCta: Boolean)
