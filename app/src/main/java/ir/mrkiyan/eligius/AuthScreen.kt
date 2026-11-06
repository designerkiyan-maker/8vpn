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

/** Login gate shown until a valid mrkiyan.ir account session exists. */
@Composable
fun AuthScreen(onRegister: () -> Unit) {
    val c = MaterialTheme.colorScheme
    val scope = rememberCoroutineScope()
    var user by remember { mutableStateOf("") }
    var pass by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val genericError = stringResource(R.string.login_error)

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
        Text("Beyond Boundaries.", style = MaterialTheme.typography.bodyMedium, color = c.onSurfaceVariant)
        Spacer(Modifier.height(24.dp))
        AppCard {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(stringResource(R.string.login_hint), style = MaterialTheme.typography.bodySmall, color = c.onSurfaceVariant)
                OutlinedTextField(
                    value = user, onValueChange = { user = it },
                    label = { Text(stringResource(R.string.username)) },
                    singleLine = true, enabled = !busy,
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = pass, onValueChange = { pass = it },
                    label = { Text(stringResource(R.string.password)) },
                    singleLine = true, enabled = !busy,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth()
                )
                error?.let {
                    Text(it, color = c.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                }
                Button(
                    onClick = {
                        if (busy) return@Button
                        busy = true; error = null
                        scope.launch {
                            AuthStore.login(user.trim(), pass).fold(
                                onSuccess = { /* the session flow flips the router back to Home */ },
                                onFailure = { e -> error = (e as? AuthException)?.uiMessage ?: genericError }
                            )
                            busy = false
                        }
                    },
                    enabled = !busy && user.isNotBlank() && pass.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    shape = RoundedCornerShape(16.dp)
                ) {
                    if (busy) {
                        CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                    } else {
                        Text(stringResource(R.string.login_btn), fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
        Spacer(Modifier.height(14.dp))
        Text(
            stringResource(R.string.no_account),
            style = MaterialTheme.typography.bodySmall, color = c.onSurfaceVariant, textAlign = TextAlign.Center
        )
        TextButton(onClick = onRegister) { Text(stringResource(R.string.create_account), fontWeight = FontWeight.SemiBold) }
        Spacer(Modifier.height(24.dp))
    }
}

/** In-app browser for the WordPress registration page (register → confirm email → come back and sign in). */
@Composable
fun AuthWebScreen(onBack: () -> Unit) {
    var web by remember { mutableStateOf<android.webkit.WebView?>(null) }
    BackHandler(enabled = true) {
        val w = web
        if (w != null && w.canGoBack()) w.goBack() else onBack()
    }
    Page(stringResource(R.string.register_title), onBack) {
        androidx.compose.ui.viewinterop.AndroidView(
            modifier = androidx.compose.ui.Modifier.fillMaxSize(),
            factory = { ctx ->
                android.webkit.WebView(ctx).apply {
                    settings.javaScriptEnabled = true
                    settings.domStorageEnabled = true
                    webViewClient = android.webkit.WebViewClient()
                    loadUrl(AuthStore.registerUrl)
                }
            }
        )
    }
}

private const val BRAND_JS = """(function() {
  if (location.hostname.indexOf('wp.mrkiyan.ir') === -1) return;
  var css = ''
    + 'body.login{background:#0D0A1A !important;}'
    + 'body.login a{text-decoration:none;}'
    + 'body.login #login{width:320px;padding:0;}'
    + 'body.login #login h1{display:none !important;}'
    + 'body.login form{background:#171130 !important;border:1px solid #352A58 !important;border-radius:18px !important;box-shadow:0 10px 40px rgba(0,0,0,.5) !important;padding:26px 24px !important;}'
    + 'body.login form .input, body.login input[type=text], body.login input[type=password], body.login input[type=email]{background:#1F1840 !important;border:1px solid #352A58 !important;color:#E9E4F7 !important;border-radius:10px !important;padding:10px 12px !important;font-size:16px !important;}'
    + 'body.login form .input:focus{border-color:#B07CFF !important;outline:none !important;box-shadow:0 0 0 3px rgba(176,124,255,.25) !important;}'
    + 'body.login form label{color:#B2A6CE !important;font-size:13px !important;}'
    + 'body.login #reg_passmail, body.login #nav, body.login #backtoblog{color:#B2A6CE !important;font-size:13px !important;}'
    + 'body.login #nav a, body.login #backtoblog a, body.login #login_error a, body.login .message a{color:#B07CFF !important;font-weight:600;}'
    + 'body.login #wp-submit{background:linear-gradient(135deg,#7E6BFF,#B07CFF) !important;border:none !important;color:#fff !important;border-radius:12px !important;padding:10px 14px !important;font-size:15px !important;font-weight:600 !important;width:100% !important;height:auto !important;text-shadow:none !important;cursor:pointer !important;}'
    + 'body.login .message, body.login #login_error{background:#1F1840 !important;border-left:4px solid #B07CFF !important;color:#E9E4F7 !important;border-radius:10px !important;box-shadow:none !important;}'
    + 'body.login #login_error{border-left-color:#FF8A8A !important;}'
    + 'body.login .language-switcher{display:none !important;}'
    + 'body.login #eligius-brand{margin-bottom:18px;text-align:center;font-family:system-ui,-apple-system,Segoe UI,sans-serif;}';
  var head = document.head || document.getElementsByTagName('head')[0];
  var style = document.createElement('style');
  style.textContent = css;
  head.appendChild(style);
  var h1 = document.querySelector('#login h1');
  if (h1) h1.parentNode.removeChild(h1);
  var login = document.querySelector('#login');
  if (login && !document.getElementById('eligius-brand')) {
    var b = document.createElement('div');
    b.id = 'eligius-brand';
    b.innerHTML = ''
      + '<div style="width:64px;height:64px;border-radius:18px;margin:0 auto 10px;background:linear-gradient(135deg,#7E6BFF,#B07CFF);display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;color:#fff;box-shadow:0 0 30px rgba(176,124,255,.45);font-family:system-ui,sans-serif;">E</div>'
      + '<div style="font-size:22px;font-weight:700;color:#ECE7F8;margin-bottom:4px;">Eligius VPN</div>'
      + '<div style="font-size:11px;letter-spacing:3px;color:#B3A8CE;margin-bottom:18px;">BEYOND BOUNDARIES.</div>';
    login.insertBefore(b, login.firstChild);
  }
})()"""