package ir.mrkiyan.eligius

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.foundation.layout.fillMaxSize
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle

class MainActivity : ComponentActivity() {
    override fun attachBaseContext(newBase: Context) = super.attachBaseContext(LocaleHelper.wrap(newBase))

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            val s by SettingsStore.flow.collectAsStateWithLifecycle()
            val dark = isDark(s)
            DisposableEffect(dark) {
                val transparent = android.graphics.Color.TRANSPARENT
                enableEdgeToEdge(
                    statusBarStyle = SystemBarStyle.auto(transparent, transparent) { dark },
                    navigationBarStyle = SystemBarStyle.auto(transparent, transparent) { dark }
                )
                onDispose { }
            }
            AppTheme(s, dark) { Root(onLanguageChanged = { recreate() }) }
        }
    }
}

@Composable
private fun Root(onLanguageChanged: () -> Unit) {
    val ctx = LocalContext.current
    val auth by AuthStore.flow.collectAsStateWithLifecycle()
    var screen by rememberSaveable { mutableIntStateOf(if (AuthStore.isLoggedIn) HOME else AUTH) }

    val notifLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }
    LaunchedEffect(Unit) {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) notifLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    // Log in / log out drives the gate: jump between the login screen and Home — only then,
    // and not on recreation, so manual navigation survives a rotation.
    var lastAuth by remember { mutableStateOf(auth) }
    LaunchedEffect(auth) {
        if (auth != lastAuth) {
            screen = if (auth) HOME else AUTH
            lastAuth = auth
        }
    }

    BackHandler(enabled = screen == AUTH) { }   // the gate: no backing out of the login screen
    BackHandler(enabled = screen != HOME && screen != AUTH) {
        screen = if (screen == SPLIT || screen == ABOUT) SETTINGS else HOME
    }

    val glow = LocalExtra.current.a
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Box(
            Modifier.fillMaxSize().background(
                Brush.verticalGradient(listOf(glow.copy(alpha = 0.10f), Color.Transparent), endY = 900f)
            )
        ) {
            AnimatedContent(
                targetState = screen,
                transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(140)) },
                label = "nav"
            ) { sc ->
                when (sc) {
                    HOME -> HomeScreen(openSettings = { screen = SETTINGS }, openLogs = { screen = LOGS })
                    SETTINGS -> SettingsScreen(
                        back = { screen = HOME }, openLogs = { screen = LOGS }, openAbout = { screen = ABOUT },
                        openSplit = { screen = SPLIT }, onLanguageChanged = onLanguageChanged
                    )
                    LOGS -> LogsScreen(back = { screen = HOME })
                    ABOUT -> AboutScreen(back = { screen = SETTINGS })
                    SPLIT -> SplitScreen(back = { screen = SETTINGS })
                    AUTH -> AuthScreen(onRegister = { screen = AUTH_WEB })
                    AUTH_WEB -> AuthWebScreen(onBack = { screen = AUTH })
                }
            }
        }
    }
}

private const val HOME = 0
private const val SETTINGS = 1
private const val LOGS = 2
private const val ABOUT = 3
private const val SPLIT = 4
private const val AUTH = 5
private const val AUTH_WEB = 6
