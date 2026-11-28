package ir.mrkiyan.eligius

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.TextButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import kotlinx.coroutines.launch
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.platform.LocalContext
import ir.mrkiyan.eligius.api.RetrofitClient
import ir.mrkiyan.eligius.api.model.ApiResponse
import ir.mrkiyan.eligius.api.model.PlanListData
import retrofit2.Response

/**
 * Plan selection screen (Roadmap P3 UI — docs 06 §5.1).
 *
 * Read-only catalog from the public GET /plans endpoint. Payment execution
 * (gateways, deep link) belongs to Phase 4 — the purchase button is present
 * but disabled with a P4 note until then.
 */
@Composable
fun PlanSelectionScreen(onBack: () -> Unit) {
    val ctx = LocalContext.current
    var plans by remember { mutableStateOf<List<ir.mrkiyan.eligius.api.model.PlanData>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var buying by remember { mutableStateOf(false) }
    var buyError by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    // P9.2.1 (owner request): confirm BEFORE any purchase/free activation —
    // an accidental tap must not start a payment or consume the one-shot free plan.
    var confirmPlan by remember { mutableStateOf<ir.mrkiyan.eligius.api.model.PlanData?>(null) }
    var activatingKey by remember { mutableStateOf<String?>(null) }
    var activatedKey by remember { mutableStateOf<String?>(null) }

    fun doBuy(plan: ir.mrkiyan.eligius.api.model.PlanData) {
        buying = true
        scope.launch {
            runCatching {
                val response = RetrofitClient.paymentApi.createPayment(
                    mapOf(
                        "plan_key" to plan.planKey,
                        "gateway" to "zarinpal"
                    )
                )
                if (response.isSuccessful) {
                    val data = response.body()?.data
                    val redirect = data?.redirectUrl ?: ""
                    if (redirect.isNotBlank()) {
                        buyError = null
                        // Open the gateway payment page in the browser.
                        ctx.startActivity(
                            android.content.Intent(
                                android.content.Intent.ACTION_VIEW,
                                android.net.Uri.parse(redirect)
                            ).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
                        )
                    } else {
                        buyError = "درگاه پرداخت پاسخ معتبر نداد."
                    }
                } else {
                    buyError = RetrofitClient.errorMessage(response) ?: "خطا در ایجاد پرداخت"
                }
            }.onFailure { buyError = "خطای شبکه: ${it.message}" }
            buying = false
        }
    }

    fun doActivate(plan: ir.mrkiyan.eligius.api.model.PlanData) {
        activatingKey = plan.planKey
        scope.launch {
            runCatching {
                val response = RetrofitClient.licenseApi.activateFree(
                    mapOf("plan_key" to plan.planKey)
                )
                if (response.isSuccessful && response.body()?.data != null) {
                    LicenseStore.invalidateCache()
                    activatedKey = plan.planKey
                    buyError = null
                } else {
                    buyError = RetrofitClient.errorMessage(response)
                        ?: "خطا در فعال‌سازی پلن رایگان"
                }
            }.onFailure { buyError = "خطای شبکه: ${it.message}" }
            activatingKey = null
        }
    }

    LaunchedEffect(Unit) {
        runCatching {
            val response: Response<ApiResponse<PlanListData>> = RetrofitClient.licenseApi.getPlans()
            val data = response.body()?.data
            if (response.isSuccessful && data != null) {
                plans = data.plans
            } else {
                error = RetrofitClient.errorMessage(response) ?: "خطا در دریافت پلن‌ها"
            }
        }.onFailure { error = "خطای شبکه: ${it.message}" }
        loading = false
    }

    Column(Modifier.fillMaxSize()) {
        Row(
            Modifier.fillMaxWidth().padding(top = 12.dp, start = 4.dp, end = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            IconButton(onClick = onBack) {
                Icon8(Ico.BACK, MaterialTheme.colorScheme.onSurface)
            }
            Text("انتخاب اشتراک", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }

        when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
            error != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(error!!, color = MaterialTheme.colorScheme.error)
            }
            else -> LazyColumn(
                Modifier.fillMaxSize().padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                items(plans.filter { !it.isTrial }, key = { it.planKey }) { plan ->
                    // P9.2: plan_type aware cards (حجمی / زمان‌دار / رایگان).
                    val isFree = plan.planType == "free"
                    val typeFa = when (plan.planType) {
                        "volume" -> "حجمی"
                        "free" -> "رایگان"
                        else -> "زمان‌دار"
                    }
                    val volumeText = when {
                        (plan.planType == "volume" || plan.planType == "free") && plan.volumeGb != null ->
                            "${plan.volumeGb} گیگ"
                        else -> "نامحدود"
                    }
                    val activating = activatingKey == plan.planKey
                    val activated = activatedKey == plan.planKey
                    Card(
                        Modifier.fillMaxWidth(),
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.surfaceVariant
                        ),
                        shape = RoundedCornerShape(16.dp)
                    ) {
                        Column(Modifier.fillMaxWidth().padding(16.dp)) {
                            Row(
                                Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Text(plan.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                Box(
                                    Modifier
                                        .background(
                                            MaterialTheme.colorScheme.primaryContainer,
                                            RoundedCornerShape(999.dp)
                                        )
                                        .padding(horizontal = 10.dp, vertical = 3.dp)
                                ) {
                                    Text(
                                        typeFa,
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onPrimaryContainer
                                    )
                                }
                            }
                            Text(
                                "$volumeText · ${plan.durationDays} روز",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                            Row(
                                Modifier.fillMaxWidth().padding(top = 8.dp),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                val priceText = when {
                                    isFree -> "رایگان"
                                    plan.discountPercent > 0 ->
                                        "${plan.priceIrt / 10} تومان (-${plan.discountPercent}٪)"
                                    else -> "${plan.priceIrt / 10} تومان"
                                }
                                Text(priceText, fontWeight = FontWeight.Bold)
                                if (isFree) {
                                    Button(
                                        enabled = !activating && !activated,
                                        onClick = { confirmPlan = plan }   // confirm first (owner request)
                                    ) {
                                        Text(
                                            when {
                                                activated -> "فعال شد ✓"
                                                activating -> "..."
                                                else -> "فعال‌سازی"
                                            }
                                        )
                                    }
                                } else {
                                    Button(
                                        enabled = plan.priceIrt > 0 && !buying,
                                        onClick = { confirmPlan = plan }   // confirm first (owner request)
                                    ) {
                                        Text(if (buying) "..." else "خرید")
                                    }
                                }
                            }
                            if (plan.discountPercent > 0) {
                                Text(
                                    "قیمت پایه: ${plan.priceIrt / 10} تومان",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                            if (activated) {
                                Text(
                                    "اشتراک رایگان روی حساب شما فعال شد — به صفحه اصلی برگردید.",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.primary,
                                    modifier = Modifier.padding(top = 6.dp)
                                )
                            }
                        }
                    }
                }
                buyError?.let {
                    item {
                        Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(vertical = 4.dp))
                    }
                }
                item {
                    Text(
                        "پس از پرداخت، درگاه به‌صورت خودکار به برنامه بازمی‌گردد و وضعیت واقعی از سرور استعلام می‌شود.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(vertical = 8.dp)
                    )
                }
            }
        }
    }

    // P9.2.1: purchase/activation confirmation (owner request)
    confirmPlan?.let { plan ->
        val isFree = plan.planType == "free"
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { confirmPlan = null },
            title = {
                Text(
                    if (isFree) "فعال‌سازی پلن رایگان" else "تأیید خرید",
                    fontWeight = FontWeight.Bold
                )
            },
            text = {
                Text(
                    if (isFree)
                        "پلن «${plan.label}» — ${plan.durationDays} روز" +
                            (plan.volumeGb?.let { " — $it گیگابایت" } ?: "") +
                            "\n\nروی حساب شما فعال شود؟ فعال‌سازی پلن رایگان برای هر حساب فقط یک‌بار ممکن است."
                    else
                        "پلن «${plan.label}» — ${plan.durationDays} روز" +
                            (plan.volumeGb?.let { " — $it گیگابایت" } ?: "") +
                            "\n\nمبلغ: ${plan.priceIrt / 10} تومان" +
                            (if (plan.discountPercent > 0) " (با ${plan.discountPercent}٪ تخفیف)" else "") +
                            "\n\nبرای پرداخت به درگاه منتقل می‌شوید. ادامه می‌دهید؟"
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmPlan = null
                    if (isFree) doActivate(plan) else doBuy(plan)
                }) { Text("بله، ادامه", fontWeight = FontWeight.Bold) }
            },
            dismissButton = {
                TextButton(onClick = { confirmPlan = null }) { Text("انصراف") }
            }
        )
    }
}
