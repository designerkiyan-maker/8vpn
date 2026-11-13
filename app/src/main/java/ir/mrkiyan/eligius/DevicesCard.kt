package ir.mrkiyan.eligius

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.mrkiyan.eligius.api.RetrofitClient
import kotlinx.coroutines.launch

/**
 * Devices management card for the Account screen (Roadmap P3 — CD-9):
 * lists the account's devices with the active one highlighted; the ACTIVE
 * device can start an OTP transfer to another registered device. The OTP
 * arrives via SMS and is verified with POST /devices/transfer/verify
 * (JWT-only — works from any signed-in device).
 */
@Composable
fun DevicesCard() {
    val ctx = LocalContext.current
    val c = MaterialTheme.colorScheme
    val scope = rememberCoroutineScope()

    var devices by remember { mutableStateOf<List<ir.mrkiyan.eligius.api.model.DeviceInfo>>(emptyList()) }
    var activeDeviceId by remember { mutableStateOf<Long?>(null) }
    var loading by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }

    // Transfer flow state
    var transferTarget by remember { mutableStateOf<ir.mrkiyan.eligius.api.model.DeviceInfo?>(null) }
    var transferId by remember { mutableStateOf<Long?>(null) }
    var otpInput by remember { mutableStateOf("") }
    var transferBusy by remember { mutableStateOf(false) }

    suspend fun loadDevices() {
        loading = true
        runCatching {
            val hash = DeviceIdentity.getDeviceHash(ctx)
            val response = RetrofitClient.deviceApi.listDevices(hash)
            val data = response.body()?.data
            if (response.isSuccessful && data != null) {
                devices = data.devices
                activeDeviceId = data.activeDeviceId
            } else {
                message = RetrofitClient.errorMessage(response) ?: "خطا در دریافت دستگاه‌ها"
            }
        }.onFailure { message = "خطای شبکه: " + (it.message ?: "") }
        loading = false
    }

    LaunchedEffect(Unit) { loadDevices() }

    Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            "دستگاه‌ها",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold,
            color = c.primary
        )
        Text(
            "هر حساب فقط روی یک دستگاه فعال است. انتقال با کد پیامکی انجام می‌شود.",
            style = MaterialTheme.typography.bodySmall,
            color = c.onSurfaceVariant
        )

        if (loading && devices.isEmpty()) {
            CircularProgressIndicator(Modifier.size(28.dp))
        }

        devices.forEach { d ->
            val isActive = d.deviceId == activeDeviceId
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(Modifier.weight(1f)) {
                    Text(
                        d.label?.takeIf { it.isNotBlank() } ?: "دستگاه",
                        fontWeight = if (isActive) FontWeight.Bold else FontWeight.Normal
                    )
                    Text(
                        if (isActive) "دستگاه فعال" else "غیرفعال",
                        style = MaterialTheme.typography.bodySmall,
                        color = if (isActive) c.primary else c.onSurfaceVariant
                    )
                }
                if (isActive) {
                    TextButton(onClick = {
                        scope.launch {
                            transferBusy = true
                            message = null
                            runCatching {
                                val hash = DeviceIdentity.getDeviceHash(ctx)
                                val target = devices.firstOrNull { !it.isActive && it.deviceId != d.deviceId }
                                if (target == null) {
                                    message = "دستگاه دیگری برای انتقال ثبت نشده است. ابتدا برنامه را روی دستگاه جدید نصب و وارد کنید."
                                } else {
                                    val response = RetrofitClient.deviceApi.startTransfer(
                                        hash,
                                        mapOf("to_device_hash" to target.deviceHash)
                                    )
                                    val data = response.body()?.data
                                    if (response.isSuccessful && data != null) {
                                        transferId = data.transferId
                                        transferTarget = target
                                        otpInput = ""
                                        message = "کد تأیید با پیامک ارسال شد. کد را وارد کنید."
                                    } else {
                                        message = RetrofitClient.errorMessage(response) ?: "خطا در شروع انتقال"
                                    }
                                }
                            }.onFailure { message = "خطای شبکه: " + (it.message ?: "") }
                            transferBusy = false
                        }
                    }) { Text(if (transferBusy) "..." else "انتقال") }
                }
            }
        }

        if (transferId != null) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    "کد تأیید پیامک‌شده برای انتقال را وارد کنید:",
                    style = MaterialTheme.typography.bodySmall
                )
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    OutlinedTextField(
                        value = otpInput,
                        onValueChange = { otpInput = it.take(6) },
                        modifier = Modifier.weight(1f),
                        enabled = !transferBusy,
                        singleLine = true,
                        placeholder = { Text("------") }
                    )
                    Button(
                        onClick = {
                            scope.launch {
                                transferBusy = true
                                message = null
                                runCatching {
                                    val response = RetrofitClient.deviceApi.verifyTransfer(
                                        mapOf(
                                            "transfer_id" to (transferId ?: 0L).toString(),
                                            "otp_code" to otpInput
                                        )
                                    )
                                    val data = response.body()?.data
                                    if (response.isSuccessful && data?.verified == true) {
                                        message = "انتقال با موفقیت انجام شد."
                                        transferId = null
                                        transferTarget = null
                                        otpInput = ""
                                        loadDevices()
                                    } else {
                                        message = RetrofitClient.errorMessage(response) ?: "کد نامعتبر است"
                                    }
                                }.onFailure { message = "خطای شبکه: " + (it.message ?: "") }
                                transferBusy = false
                            }
                        },
                        enabled = otpInput.length == 6 && !transferBusy
                    ) { Text(if (transferBusy) "..." else "تأیید") }
                }
            }
        }

        message?.let {
            Text(it, style = MaterialTheme.typography.bodySmall, color = c.onSurfaceVariant)
        }
    }
}
