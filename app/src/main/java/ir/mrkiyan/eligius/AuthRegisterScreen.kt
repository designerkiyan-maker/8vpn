package ir.mrkiyan.eligius

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Three-step native registration wizard (no WebView):
 *   step 1 — نام، نام خانوادگی، کد ملی
 *   step 2 — ایمیل، شماره موبایل، رمز عبور + تکرار → POST /register/otp-start
 *   step 3 — کد تایید پیامک‌شده (۶ رقم)            → POST /register/otp-verify
 * On success the app signs the user in automatically (AuthStore.login) and the
 * session flow flips the router back to Home — same as the login screen.
 */
@Composable
fun AuthRegisterScreen(onBack: () -> Unit, onLogin: () -> Unit) {
    val scope = rememberCoroutineScope()
    var step by remember { mutableIntStateOf(1) }
    var firstName by remember { mutableStateOf("") }
    var lastName by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var mobile by remember { mutableStateOf("") }
    var nationalCode by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var confirmPassword by remember { mutableStateOf("") }
    var otp by remember { mutableStateOf("") }
    var resendIn by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    // stringResource is not callable inside onClick lambdas — hoist everything first.
    val fillAllFieldsMsg = stringResource(R.string.fill_all_fields)
    val passwordsMismatchMsg = stringResource(R.string.passwords_mismatch)
    val passwordTooShortMsg = stringResource(R.string.password_too_short)
    val invalidMobileMsg = stringResource(R.string.invalid_mobile)
    val invalidNationalCodeMsg = stringResource(R.string.invalid_national_code)
    val invalidEmailMsg = stringResource(R.string.invalid_email)
    val registerErrorMsg = stringResource(R.string.register_error)
    val otpErrorMsg = stringResource(R.string.otp_error)

    // Resend countdown: ticks one second at a time back to zero.
    LaunchedEffect(resendIn) {
        if (resendIn > 0) {
            delay(1_000)
            resendIn -= 1
        }
    }

    fun goBack() {
        when (step) {
            3 -> { step = 2; error = null }
            2 -> { step = 1; error = null }
            else -> onBack()
        }
    }
    BackHandler(enabled = true) { goBack() }

    Column(
        Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing)
            .verticalScroll(rememberScrollState()).padding(horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Spacer(Modifier.height(32.dp))
        LogoMark(64.dp)
        Spacer(Modifier.height(12.dp))
        Text(stringResource(R.string.register_title), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        // step indicator
        Row(verticalAlignment = Alignment.CenterVertically) {
            StepDot(active = step >= 1, label = "۱")
            StepLine(filled = step >= 2)
            StepDot(active = step >= 2, label = "۲")
            StepLine(filled = step >= 3)
            StepDot(active = step >= 3, label = "۳")
        }
        Spacer(Modifier.height(20.dp))
        AppCard {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (step == 1) {
                    OutlinedTextField(
                        value = firstName, onValueChange = { firstName = it },
                        label = { Text(stringResource(R.string.first_name)) },
                        singleLine = true, enabled = !busy,
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = lastName, onValueChange = { lastName = it },
                        label = { Text(stringResource(R.string.last_name)) },
                        singleLine = true, enabled = !busy,
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = nationalCode, onValueChange = { nationalCode = it },
                        label = { Text(stringResource(R.string.national_code)) },
                        singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.fillMaxWidth()
                    )
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (firstName.isBlank() || lastName.isBlank()) {
                                error = fillAllFieldsMsg
                            } else if (nationalCode.length != 10 || !nationalCode.all { it.isDigit() }) {
                                error = invalidNationalCodeMsg
                            } else {
                                error = null
                                step = 2
                            }
                        },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = RoundedCornerShape(16.dp)
                    ) { Text(stringResource(R.string.next_btn), fontWeight = FontWeight.SemiBold) }
                } else if (step == 2) {
                    OutlinedTextField(
                        value = email, onValueChange = { email = it },
                        label = { Text(stringResource(R.string.email)) },
                        singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = mobile, onValueChange = { mobile = it },
                        label = { Text(stringResource(R.string.mobile)) },
                        singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = password, onValueChange = { password = it },
                        label = { Text(stringResource(R.string.password)) },
                        singleLine = true, enabled = !busy,
                        visualTransformation = PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = confirmPassword, onValueChange = { confirmPassword = it },
                        label = { Text(stringResource(R.string.confirm_password)) },
                        singleLine = true, enabled = !busy,
                        visualTransformation = PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                        modifier = Modifier.fillMaxWidth()
                    )
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (busy) return@Button
                            if (email.isBlank() || mobile.isBlank() || password.isBlank() || confirmPassword.isBlank()) {
                                error = fillAllFieldsMsg
                                return@Button
                            }
                            if (!android.util.Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
                                error = invalidEmailMsg
                                return@Button
                            }
                            if (mobile.length != 11 || !mobile.all { it.isDigit() }) {
                                error = invalidMobileMsg
                                return@Button
                            }
                            if (password.length < 8) {
                                error = passwordTooShortMsg
                                return@Button
                            }
                            if (password != confirmPassword) {
                                error = passwordsMismatchMsg
                                return@Button
                            }
                            error = null
                            busy = true
                            scope.launch {
                                AuthStore.registerOtpStart(mobile.trim(), email.trim(), password, firstName.trim(), lastName.trim(), nationalCode).fold(
                                    onSuccess = {
                                        busy = false
                                        otp = ""
                                        resendIn = 60
                                        step = 3
                                    },
                                    onFailure = { e ->
                                        error = (e as? AuthException)?.uiMessage ?: registerErrorMsg
                                        busy = false
                                    }
                                )
                            }
                        },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = RoundedCornerShape(16.dp)
                    ) {
                        if (busy) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                        } else {
                            Text(stringResource(R.string.register_btn), fontWeight = FontWeight.SemiBold)
                        }
                    }
                    TextButton(onClick = { step = 1; error = null }) {
                        Text(stringResource(R.string.back), fontWeight = FontWeight.SemiBold)
                    }
                } else {
                    // Step 3: the 6-digit code sent to the mobile number.
                    Text(
                        stringResource(R.string.otp_sent_to, mobile),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = otp, onValueChange = { otp = it.filter { ch -> ch.isDigit() }.take(6) },
                        label = { Text(stringResource(R.string.otp_code_label)) },
                        singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.fillMaxWidth()
                    )
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (busy || otp.length != 6) return@Button
                            busy = true; error = null
                            scope.launch {
                                AuthStore.registerOtpVerify(mobile.trim(), otp).fold(
                                    onSuccess = {
                                        // Verified — sign in straight away; the session flow flips
                                        // the router back to Home (same as the login screen).
                                        AuthStore.login(mobile.trim(), password).fold(
                                            onSuccess = { busy = false },
                                            onFailure = { e ->
                                                busy = false
                                                error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
                                            }
                                        )
                                    },
                                    onFailure = { e ->
                                        busy = false
                                        error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
                                    }
                                )
                            }
                        },
                        enabled = !busy && otp.length == 6,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = RoundedCornerShape(16.dp)
                    ) {
                        if (busy) {
                            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                        } else {
                            Text(stringResource(R.string.otp_verify_btn), fontWeight = FontWeight.SemiBold)
                        }
                    }
                    // Resend: re-posts the same data; the server re-sends a fresh code
                    // as long as the account is still unverified.
                    val resendLabel = if (resendIn > 0) stringResource(R.string.otp_resend_in, resendIn)
                                      else stringResource(R.string.otp_resend)
                    TextButton(
                        onClick = {
                            if (busy || resendIn > 0) return@TextButton
                            busy = true; error = null
                            scope.launch {
                                AuthStore.registerOtpStart(mobile.trim(), email.trim(), password, firstName.trim(), lastName.trim(), nationalCode).fold(
                                    onSuccess = { resendIn = 60 },
                                    onFailure = { e ->
                                        error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
                                    }
                                )
                                busy = false
                            }
                        },
                        enabled = !busy && resendIn == 0
                    ) {
                        Text(resendLabel, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
        Spacer(Modifier.height(14.dp))
        Text(
            stringResource(R.string.have_account),
            style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center
        )
        TextButton(onClick = onLogin) { Text(stringResource(R.string.login_btn), fontWeight = FontWeight.SemiBold) }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun StepDot(active: Boolean, label: String) {
    val c = MaterialTheme.colorScheme
    Box(
        Modifier
            .size(30.dp)
            .clip(CircleShape)
            .background(if (active) c.primary else c.surfaceContainerHigh)
            .border(1.dp, if (active) c.primary else c.outlineVariant, CircleShape),
        contentAlignment = Alignment.Center
    ) {
        Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = if (active) c.onPrimary else c.onSurfaceVariant)
    }
}

@Composable
private fun StepLine(filled: Boolean) {
    val c = MaterialTheme.colorScheme
    Box(
        Modifier
            .width(44.dp)
            .height(2.dp)
            .background(if (filled) c.primary else c.outlineVariant)
    )
}
