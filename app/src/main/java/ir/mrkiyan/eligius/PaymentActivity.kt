package ir.mrkiyan.eligius

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import ir.mrkiyan.eligius.api.RetrofitClient
import kotlinx.coroutines.delay
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Payment result activity (Roadmap P4 — docs 06 §5.3).
 *
 * Security contract: deep-link parameters are INFORMATIONAL ONLY — the real
 * payment status is always re-polled from the backend (docs 07 §P4: "App
 * polls /licenses/status" / §6.2 payment status). While the payment is
 * INITIATED the activity polls every 3 s (the gateway callback may still be
 * landing on the server); a terminal state stops the polling.
 */
class PaymentActivity : ComponentActivity() {

    private var paymentId by mutableStateOf(0L)
    private var status by mutableStateOf("PENDING")
    private var reconcileBusy by mutableStateOf(false)
    private var planKey by mutableStateOf("")
    private var polling by mutableStateOf(true)
    private var loadedFromBackend by mutableStateOf(false)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        readIntent(intent)
        setContent { PaymentResultUi() }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        readIntent(intent)
        setContent { PaymentResultUi() }
    }

    private fun readIntent(intent: Intent?) {
        val data: Uri? = intent?.data
        if (data != null && data.scheme == "eligius") {
            paymentId = data.getQueryParameter("payment_id")?.toLongOrNull() ?: 0L
            status = data.getQueryParameter("status")?.uppercase() ?: "PENDING"
        }
    }

    private fun startPolling() {
        if (paymentId <= 0L) return
        polling = true
        lifecycleScope.launch {
            var attempts = 0
            while (polling && attempts < 40) { // ~2 minutes at 3 s intervals
                val code = runCatching {
                    val response = RetrofitClient.paymentApi.getPaymentStatus(paymentId)
                    if (response.isSuccessful) {
                        val data = response.body()?.data
                        if (data != null) {
                            loadedFromBackend = true
                            planKey = data.planKey
                            when (data.status) {
                                "VERIFIED" -> { status = "VERIFIED"; onLicenseRefresh(data.licenseId); return@launch }
                                "FAILED", "EXPIRED" -> { status = data.status; polling = false; return@launch }
                            }
                        }
                    }
                    response.code()
                }.getOrDefault(-1)
                attempts++
                delay(3_000)
            }
            if (polling) polling = false
        }
    }

    private fun onLicenseRefresh(licenseId: Long?) {
        polling = false
        // Refresh the license store so the VPN gate sees the new ACTIVE license.
        lifecycleScope.launch {
            runCatching { LicenseStore.ensureValid() }
        }
    }

    @Composable
    private fun PaymentResultUi() {
        LaunchedEffect(paymentId) { startPolling() }

        val c = MaterialTheme.colorScheme
        androidx.compose.foundation.layout.Column(
            Modifier
                .fillMaxSize()
                .background(c.background)
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Text("وضعیت پرداخت", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(16.dp))

            when {
                paymentId <= 0L -> {
                    Text("شناسه پرداخت نامعتبر است.", color = c.error)
                }
                status == "VERIFIED" && loadedFromBackend -> {
                    Text("✅", Modifier.size(48.dp))
                    Spacer(Modifier.height(8.dp))
                    Text("پرداخت با موفقیت انجام شد", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    Text(
                        "اشتراک فعال شد. در صورت قطع‌بودن تونل، یک‌بار آنلاین شوید تا مجوز آفلاین تازه شود.",
                        style = MaterialTheme.typography.bodySmall,
                        color = c.onSurfaceVariant
                    )
                    Button(onClick = { finish() }, Modifier.padding(top = 16.dp)) { Text("بازگشت") }
                }
                status == "FAILED" || status == "EXPIRED" -> {
                    Text("✖", Modifier.size(48.dp), color = c.error)
                    Spacer(Modifier.height(8.dp))
                    Text("پرداخت ناموفق بود", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = c.error)
                    Button(onClick = { finish() }, Modifier.padding(top = 16.dp)) { Text("بازگشت") }
                }
                else -> {
                    CircularProgressIndicator(Modifier.size(48.dp))
                    Spacer(Modifier.height(12.dp))
                    Text(
                        if (loadedFromBackend) "در انتظار تأیید درگاه…"
                        else "در حال استعلام وضعیت از سرور…",
                        style = MaterialTheme.typography.bodyMedium
                    )
                    if (status != "PENDING") {
                        Text(
                            "وضعیت گزارش‌شده درگاه: $status (در انتظار تأیید سرور)",
                            style = MaterialTheme.typography.bodySmall,
                            color = c.onSurfaceVariant
                        )
                    }
                    // Phase 6 — Payment Reconciliation: once the automatic
                    // polling window is exhausted, the user can explicitly ask
                    // the server to re-run the S2S verification (server uses
                    // the STORED authority; fail-closed 503 when the rate
                    // limiter is degraded). Simple status polling never
                    // triggers S2S calls.
                    if (!polling) {
                        Spacer(Modifier.height(12.dp))
                        Text(
                            "اگر پرداخت انجام شده و وضعیت به‌روز نشده، بررسی مجدد سرور را اجرا کنید.",
                            style = MaterialTheme.typography.bodySmall,
                            color = c.onSurfaceVariant
                        )
                        Button(
                            onClick = {
                                reconcileBusy = true
                                lifecycleScope.launch {
                                    runCatching {
                                        val response = RetrofitClient.paymentApi.reconcilePayment(paymentId)
                                        val data = response.body()?.data
                                        if (response.isSuccessful && data != null) {
                                            when (data.status) {
                                                "VERIFIED" -> { status = "VERIFIED"; reconcileBusy = false; onLicenseRefresh(data.licenseId); return@launch }
                                                "FAILED" -> { status = "FAILED"; reconcileBusy = false; return@launch }
                                            }
                                        }
                                    }.onFailure { /* network error — stay pending, retryable */ }
                                    reconcileBusy = false
                                }
                            },
                            modifier = Modifier.padding(top = 8.dp),
                            enabled = !reconcileBusy
                        ) { Text(if (reconcileBusy) "در حال بررسی…" else "بررسی مجدد پرداخت") }
                    }
                    Button(onClick = { finish() }, Modifier.padding(top = 16.dp)) { Text("بستن") }
                }
            }
        }
    }
}
