package ir.mrkiyan.eligius

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch

/**
 * Native registration screen — no WebView.
 * Sends data to WordPress REST API via AuthStore.register().
 */
@Composable
fun AuthRegisterScreen(onBack: () -> Unit, onLogin: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val scope = rememberCoroutineScope()
    var firstName by remember { mutableStateOf("") }
    var lastName by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var mobile by remember { mutableStateOf("") }
    var nationalCode by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var confirmPassword by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val genericError = stringResource(R.string.register_error)
    
    // Pre-load string resources outside @Composable context for button click
    val fillAllFieldsMsg = stringResource(R.string.fill_all_fields)
    val passwordsMismatchMsg = stringResource(R.string.passwords_mismatch)
    val passwordTooShortMsg = stringResource(R.string.password_too_short)
    val invalidMobileMsg = stringResource(R.string.invalid_mobile)
    val invalidNationalCodeMsg = stringResource(R.string.invalid_national_code)
    val invalidEmailMsg = stringResource(R.string.invalid_email)
    val registerErrorMsg = stringResource(R.string.register_error)
    val registerBtnText = stringResource(R.string.register_btn)

    Column(
        Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing)
            .verticalScroll(rememberScrollState()).padding(horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Spacer(Modifier.height(48.dp))
        LogoMark(84.dp)
        Spacer(Modifier.height(14.dp))
        Text(stringResource(R.string.app_name), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(4.dp))
        Text("Beyond Boundaries.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(24.dp))
        AppCard {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(stringResource(R.string.register_hint), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

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
                    value = nationalCode, onValueChange = { nationalCode = it },
                    label = { Text(stringResource(R.string.national_code)) },
                    singleLine = true, enabled = !busy,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
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
                        if (password != confirmPassword) {
                            error = passwordsMismatchMsg
                            return@Button
                        }
                        if (firstName.isBlank() || lastName.isBlank() || email.isBlank() || mobile.isBlank() || nationalCode.isBlank() || password.isBlank()) {
                            error = fillAllFieldsMsg
                            return@Button
                        }
                        if (password.length < 8) {
                            error = passwordTooShortMsg
                            return@Button
                        }
                        if (mobile.length != 11 || !mobile.all { it.isDigit() }) {
                            error = invalidMobileMsg
                            return@Button
                        }
                        if (nationalCode.length != 10 || !nationalCode.all { it.isDigit() }) {
                            error = invalidNationalCodeMsg
                            return@Button
                        }
                        if (!android.util.Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
                            error = invalidEmailMsg
                            return@Button
                        }
                        error = null
                        busy = true
                        scope.launch {
                            val result = AuthStore.register(firstName, lastName, email, mobile, nationalCode, password)
                            result.fold(
                                onSuccess = { onLogin() },
                                onFailure = { e -> error = (e as? AuthException)?.uiMessage ?: registerErrorMsg }
                            )
                            busy = false
                        }
                    },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    shape = RoundedCornerShape(16.dp)
                ) {
                    if (busy) {
                        CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                    } else {
                        Text(registerBtnText, fontWeight = FontWeight.SemiBold)
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