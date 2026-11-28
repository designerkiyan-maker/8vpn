package ir.mrkiyan.eligius

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.AlertDialog
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
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ir.mrkiyan.eligius.api.RetrofitClient
import kotlinx.coroutines.launch

/**
 * Devices management card for the Account screen (Roadmap P3 — CD-9):
 * lists the account's devices with the active one highlighted. The OTP
 * transfer is verified EXCLUSIVELY on the destination device (Phase 5b):
 *  • a pending/inactive device initiates its own takeover
 *    (POST /devices/transfer/start from the new device);
 *  • an active device can start a transfer TO another registered device —
 *    the OTP entry then appears on the destination (server-reported
 *    pending transfer), never on the initiating device.
 *
 * P9.2.1 (owner live-test feedback):
 *  • BOTH transfer buttons arm a CONFIRMATION dialog — an accidental tap
 *    starts an SMS flow and flips the active device;
 *  • each device shows the phone model (label) with its serial underneath.
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
    // P9.2.1 (owner request): BOTH transfer buttons arm a confirmation — an
    // accidental tap starts an SMS flow and flips the active device.
    var confirmTransferFrom by remember { mutableStateOf(false) }
    var confirmTransferToNew by remember { mutableStateOf(false) }

    suspend fun loadDevices() {
        loading = true
        runCatching {
            val hash = DeviceIdentity.getDeviceHash(ctx)
            val response = RetrofitClient.deviceApi.listDevices(hash)
            val data = response.body()?.data
            if (response.isSuccessful && data != null) {
                devices = data.devices
                activeDeviceId = data.activeDeviceId
                // Phase 5b: when THIS device is the DESTINATION of a pending
                // OTP transfer (initiated from the old device, or an app
                // restart mid-transfer), surface the OTP entry here — verify
                // must come from the destination device only.
                val pending = data.pendingTransfer
                if (pending != null && transferId == null) {
                    transferId = pending.transferId
                    transferTarget = null
                    otpInput = ""
                    message = "کد تأیید پیامک‌شده برای انتقال را وارد کنید:"
                }
            } else {
                message = RetrofitClient.errorMessage(response) ?: "خطا در دریافت دستگاه‌ها"
            }
        }.onFailure { message = "خطای شبکه: " + (it.message ?: "") }
        loading = false
    }

    LaunchedEffect(Unit) { loadDevices() }

    // Phase 5: SMS Retriever auto-fill for transfer OTP (when transferId is active).
    val autofill = remember { SmsOtp.start(ctx, onCode = { otpInput = it }, onConsent = { }) }
    androidx.compose.runtime.DisposableEffect(Unit) {
        onDispose { autofill.dispose() }
    }

    Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            "دستگاه‌ها",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold
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
            val isThisDevice = d.deviceHash == DeviceIdentity.getDeviceHash(ctx)
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(Modifier.weight(1f)) {
                    Text(
                        (d.label?.takeIf { it.isNotBlank() } ?: "دستگاه") + if (isThisDevice) " (این دستگاه)" else "",
                        fontWeight = if (isActive) FontWeight.Bold else FontWeight.Normal
                    )
                    // P9.2.1 (owner request): the device serial under the model.
                    Text(
                        "سریال: " + (if (isThisDevice) DeviceIdentity.getSerial(ctx) else d.deviceHash.takeLast(12)),
                        style = MaterialTheme.typography.bodySmall,
                        fontFamily = androidx.compose.ui.text.font.FontFamily.Monospace,
                        fontSize = 11.sp,
                        color = c.onSurfaceVariant
                    )
                    Text(
                        when {
                            isActive && isThisDevice -> "دستگاه فعال"
                            isActive -> "دستگاه فعال (قدیمی)"
                            isThisDevice -> "این دستگاه (در انتظار انتقال)"
                            else -> "غیرفعال"
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = if (isActive) c.primary else c.onSurfaceVariant
                    )
                }
                if (!isActive && isThisDevice) {
                    // Phase 5: transfer-from-NEW-device — the owner's confirmed flow.
                    // P9.2.1: confirm first (accidental taps start an SMS flow).
                    TextButton(onClick = { confirmTransferToNew = true }) {
                        Text(if (transferBusy) "..." else "انتقال اشتراک به این دستگاه")
                    }
                }
                if (isActive) {
                    TextButton(onClick = { confirmTransferFrom = true }) {
                        Text(if (transferBusy) "..." else "انتقال")
                    }
                }
            }
        }

        // ---- P9.2.1: transfer confirmations (owner request) — the actual
        // flows run only after the owner confirms.
        if (confirmTransferToNew) {
            AlertDialog(
                onDismissRequest = { confirmTransferToNew = false },
                title = { Text("تأیید انتقال اشتراک", fontWeight = FontWeight.Bold) },
                text = {
                    Text("این دستگاه غیرفعال است و اشتراک به آن منتقل می‌شود؛ دستگاه فعلی غیرفعال خواهد شد و کد تأیید با پیامک ارسال می‌شود.\n\nادامه می‌دهید؟")
                },
                confirmButton = {
                    TextButton(onClick = {
                        confirmTransferToNew = false
                        scope.launch {
                            transferBusy = true
                            message = null
                            runCatching {
                                val result = AuthStore.startTransferFromNewDevice()
                                result.fold(
                                    onSuccess = { transferIdStr ->
                                        transferId = transferIdStr.toLongOrNull()
                                        message = "کد تأیید با پیامک ارسال شد. کد را وارد کنید:"
                                    },
                                    onFailure = { message = "خطا: ${it.message}" }
                                )
                            }.onFailure { message = "خطای شبکه: ${it.message}" }
                            transferBusy = false
                        }
                    }) { Text("بله، ادامه", fontWeight = FontWeight.Bold) }
                },
                dismissButton = {
                    TextButton(onClick = { confirmTransferToNew = false }) { Text("انصراف") }
                }
            )
        }
        if (confirmTransferFrom) {
            AlertDialog(
                onDismissRequest = { confirmTransferFrom = false },
                title = { Text("تأیید انتقال اشتراک", fontWeight = FontWeight.Bold) },
                text = {
                    Text("اشتراک به دستگاه دیگری منتقل می‌شود؛ کد تأیید باید روی دستگاه جدید وارد شود و دستگاه فعلی غیرفعال خواهد شد. کد تأیید با پیامک ارسال می‌شود.\n\nادامه می‌دهید؟")
                },
                confirmButton = {
                    TextButton(onClick = {
                        confirmTransferFrom = false
                        scope.launch {
                            transferBusy = true
                            message = null
                            runCatching {
                                val hash = DeviceIdentity.getDeviceHash(ctx)
                                val target = devices.firstOrNull { !it.isActive && it.deviceId != activeDeviceId }
                                if (target == null) {
                                    message = "دستگاه دیگری برای انتقال ثبت نشده است. ابتدا برنامه را روی دستگاه جدید نصب و وارد کنید."
                                } else {
                                    val response = RetrofitClient.deviceApi.startTransfer(
                                        hash,
                                        mapOf("to_device_hash" to target.deviceHash)
                                    )
                                    val data = response.body()?.data
                                    if (response.isSuccessful && data != null) {
                                        // Phase 5b: verify MUST come from the
                                        // DESTINATION device — the OTP entry
                                        // appears there (server-list pending
                                        // transfer), never on this device.
                                        transferId = null
                                        transferTarget = null
                                        otpInput = ""
                                        message = "کد تأیید پیامک‌شده را روی دستگاه جدید وارد کنید تا انتقال کامل شود."
                                    } else {
                                        message = RetrofitClient.errorMessage(response) ?: "خطا در شروع انتقال"
                                    }
                                }
                            }.onFailure { message = "خطای شبکه: " + (it.message ?: "") }
                            transferBusy = false
                        }
                    }) { Text("بله، ادامه", fontWeight = FontWeight.Bold) }
                },
                dismissButton = {
                    TextButton(onClick = { confirmTransferFrom = false }) { Text("انصراف") }
                }
            )
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
