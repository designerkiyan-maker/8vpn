package ir.mrkiyan.eligius

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.pm.PackageManager
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.activity.SystemBarStyle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
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
    val haptic = LocalHapticFeedback.current
    val auth by AuthStore.flow.collectAsStateWithLifecycle()
    val st by Engine.status.collectAsStateWithLifecycle()
    val paused by Engine.paused.collectAsStateWithLifecycle()
    val s by SettingsStore.flow.collectAsStateWithLifecycle()
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
            screen = if (auth != null) HOME else AUTH
            lastAuth = auth
        }
    }

    BackHandler(enabled = screen == AUTH) { }   // the gate: no backing out of the login screen
    BackHandler(enabled = screen == LOGS) { screen = HOME }
    BackHandler(enabled = screen == SPLIT) { screen = SETTINGS }
    // AUTH_WEB: AuthWebScreen registers its own handler (WebView history first).

    // The big connect button in the bottom bar: start / stop the tunnel, then land on Home.
    val vpnLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        if (r.resultCode == Activity.RESULT_OK) TunnelService.start(ctx)
    }
    fun toggleConnect() {
        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
        if (st.phase == Phase.IDLE) {
            val i = if (s.vpnMode) VpnService.prepare(ctx) else null
            if (i != null) vpnLauncher.launch(i) else TunnelService.start(ctx)
        } else if (st.phase != Phase.STOPPING) {
            TunnelService.stop(ctx)
        }
        screen = HOME
    }

    val glow = LocalExtra.current.a
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Box(Modifier.weight(1f)) {
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
                            HOME -> HomeScreen(openLogs = { screen = LOGS })
                            CONNECTION -> ConnectionScreen(back = { screen = HOME })
                            SETTINGS -> SettingsScreen(
                                back = { screen = HOME }, openLogs = { screen = LOGS },
                                openSplit = { screen = SPLIT }, onLanguageChanged = onLanguageChanged
                            )
                            ACCOUNT -> AccountScreen(back = { screen = HOME })
                            LOGS -> LogsScreen(back = { screen = HOME })
                            SPLIT -> SplitScreen(back = { screen = SETTINGS })
                            AUTH -> AuthScreen(onRegister = { screen = AUTH_REGISTER })
                            AUTH_REGISTER -> AuthRegisterScreen(onBack = { screen = AUTH }, onLogin = { screen = HOME })
                            AUTH_WEB -> AuthWebScreen(onBack = { screen = AUTH })
                        }
                    }
                }
            }
        }
        if (screen == HOME || screen == CONNECTION || screen == SETTINGS || screen == ACCOUNT) {
            BottomBar(
                connectionSelected = screen == CONNECTION,
                settingsSelected = screen == SETTINGS,
                accountSelected = screen == ACCOUNT,
                logsSelected = screen == LOGS,
                phase = st.phase,
                onConnection = { screen = CONNECTION },
                onSettings = { screen = SETTINGS },
                onAccount = { screen = ACCOUNT },
                onLogs = { screen = LOGS },
                onConnect = { toggleConnect() }
            )
        }
    }
}

private const val HOME = 0
private const val CONNECTION = 1
private const val SETTINGS = 2
private const val ACCOUNT = 3
private const val LOGS = 4
private const val SPLIT = 5
private const val AUTH = 6
private const val AUTH_WEB = 7
private const val AUTH_REGISTER = 8
