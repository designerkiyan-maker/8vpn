package ir.mrkiyan.eligius

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Forgot-password recovery (Stage 21), three steps on ONE verified-account path:
 *   step 1 — mobile number of the account (server: must be an existing,
 *            mobile-VERIFIED account; never creates anything)
 *   step 2 — the 6-digit code (split boxes + SMS auto-fill), exchanged for a
 *            single-use reset token
 *   step 3 — mandatory new password → server replaces it → auto sign-in and
 *            the session flow flips the router to Home. Nothing is unlocked
 *            before the new password is actually set.
 */
@Composable
fun AuthForgotScreen(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    val smsHash = remember { SmsOtp.appHash(ctx) }

    var step by remember { mutableIntStateOf(1) }
    var mobile by remember { mutableStateOf("") }
    var otp by remember { mutableStateOf("") }
    var token by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var confirmPassword by remember { mutableStateOf("") }
    var showPass by remember { mutableStateOf(false) }
    var resendIn by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val fillAllFieldsMsg = stringResource(R.string.fill_all_fields)
    val passwordsMismatchMsg = stringResource(R.string.passwords_mismatch)
    val passwordTooShortMsg = stringResource(R.string.password_too_short)
    val invalidMobileMsg = stringResource(R.string.invalid_mobile)
    val otpErrorMsg = stringResource(R.string.otp_error)
    val loginErrorMsg = stringResource(R.string.login_error)

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
        LanguageTogglePill()
        Spacer(Modifier.height(20.dp))
        Text(stringResource(R.string.forgot_title), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
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
                    Text(
                        stringResource(R.string.forgot_hint),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = mobile, onValueChange = { mobile = it },
                        label = { Text(stringResource(R.string.mobile)) },
                        singleLine = true, enabled = !busy,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                        modifier = Modifier.fillMaxWidth()
                    )
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (busy) return@Button
                            if (mobile.length != 11 || !mobile.all { ch -> ch.isDigit() }) {
                                error = invalidMobileMsg
                                return@Button
                            }
                            error = null
                            busy = true
                            scope.launch {
                                AuthStore.forgotStart(mobile.trim(), smsHash).fold(
                                    onSuccess = {
                                        busy = false
                                        otp = ""
                                        resendIn = 60
                                        step = 2
                                    },
                                    onFailure = { e ->
                                        error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
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
                            Text(stringResource(R.string.send_code), fontWeight = FontWeight.SemiBold)
                        }
                    }
                } else if (step == 2) {
                    Text(
                        stringResource(R.string.otp_sent_to, mobile),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                    SmsOtpEffect(onCode = { code -> if (!busy && otp.length < 6) otp = code })
                    SplitOtpInput(value = otp, onValueChange = { otp = it }, enabled = !busy)
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (busy || otp.length != 6) return@Button
                            busy = true; error = null
                            scope.launch {
                                AuthStore.forgotVerify(mobile.trim(), otp).fold(
                                    onSuccess = { t ->
                                        busy = false
                                        token = t
                                        password = ""
                                        confirmPassword = ""
                                        step = 3
                                    },
                                    onFailure = { e ->
                                        error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
                                        busy = false
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
                    val resendLabel = if (resendIn > 0) stringResource(R.string.otp_resend_in, resendIn)
                                      else stringResource(R.string.otp_resend)
                    TextButton(
                        onClick = {
                            if (busy || resendIn > 0) return@TextButton
                            busy = true; error = null
                            scope.launch {
                                AuthStore.forgotStart(mobile.trim(), smsHash).fold(
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
                } else {
                    Text(
                        stringResource(R.string.new_password_hint),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = password, onValueChange = { password = it },
                        label = { Text(stringResource(R.string.new_password)) },
                        singleLine = true, enabled = !busy,
                        visualTransformation = if (showPass) VisualTransformation.None else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                        trailingIcon = {
                            IconButton(onClick = { showPass = !showPass }) {
                                Icon(
                                    painterResource(if (showPass) R.drawable.ic_solar_eye_closed else R.drawable.ic_solar_eye),
                                    contentDescription = stringResource(if (showPass) R.string.hide_password else R.string.show_password),
                                    tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(22.dp)
                                )
                            }
                        },
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = confirmPassword, onValueChange = { confirmPassword = it },
                        label = { Text(stringResource(R.string.confirm_password)) },
                        singleLine = true, enabled = !busy,
                        visualTransformation = if (showPass) VisualTransformation.None else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                        modifier = Modifier.fillMaxWidth()
                    )
                    error?.let {
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = {
                            if (busy) return@Button
                            if (password.isBlank() || confirmPassword.isBlank()) {
                                error = fillAllFieldsMsg
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
                                AuthStore.forgotReset(token, password).fold(
                                    onSuccess = {
                                        // The new password is server-side now — sign in
                                        // with it; the session flow flips the router Home.
                                        AuthStore.login(mobile.trim(), password).fold(
                                            onSuccess = { busy = false },
                                            onFailure = { e ->
                                                busy = false
                                                error = (e as? AuthException)?.uiMessage
                                                    ?: loginErrorMsg
                                            }
                                        )
                                    },
                                    onFailure = { e ->
                                        error = (e as? AuthException)?.uiMessage ?: otpErrorMsg
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
                            Text(stringResource(R.string.reset_btn), fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(14.dp))
        TextButton(onClick = { step = 1; error = null; onBack() }) {
            Text(stringResource(R.string.back), fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.height(24.dp))
    }
}
