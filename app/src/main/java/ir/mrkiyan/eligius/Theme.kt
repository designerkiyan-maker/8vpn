package ir.mrkiyan.eligius

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** Extra brand colors that Material's color scheme has no slot for. */
@Immutable
class Extra(val a: Color, val b: Color, val busy: Color) {
    val gradient: Brush get() = Brush.linearGradient(listOf(a, b))
}

val LocalExtra = staticCompositionLocalOf { Extra(Color(0xFF7E6BFF), Color(0xFFB07CFF), Color(0xFFE385FF)) }

private val Dark = darkColorScheme(
    primary = Color(0xFFB07CFF), onPrimary = Color(0xFF270A52),
    primaryContainer = Color(0xFF3F1D7E), onPrimaryContainer = Color(0xFFEBDCFF),
    secondary = Color(0xFF7E6BFF), onSecondary = Color(0xFF17114F),
    tertiary = Color(0xFFE385FF), onTertiary = Color(0xFF3C0A54),
    background = Color(0xFF0D0A1A), onBackground = Color(0xFFE9E4F7),
    surface = Color(0xFF0D0A1A), onSurface = Color(0xFFE9E4F7),
    surfaceVariant = Color(0xFF271D42), onSurfaceVariant = Color(0xFFB2A6CE),
    surfaceContainer = Color(0xFF171130), surfaceContainerHigh = Color(0xFF1F1840),
    surfaceContainerHighest = Color(0xFF2A2052),
    outline = Color(0xFF6C5F93), outlineVariant = Color(0xFF352A58),
    error = Color(0xFFFF8A8A),
)

private val Light = lightColorScheme(
    primary = Color(0xFF6C3AD9), onPrimary = Color.White,
    primaryContainer = Color(0xFFEADCFF), onPrimaryContainer = Color(0xFF2A0763),
    secondary = Color(0xFF5A49C9), onSecondary = Color.White,
    tertiary = Color(0xFFA030B5), onTertiary = Color.White,
    background = Color(0xFFF7F4FC), onBackground = Color(0xFF1C1330),
    surface = Color(0xFFF7F4FC), onSurface = Color(0xFF1C1330),
    surfaceVariant = Color(0xFFE8E0F6), onSurfaceVariant = Color(0xFF4C4364),
    surfaceContainer = Color(0xFFFFFFFF), surfaceContainerHigh = Color(0xFFF2EDFB),
    surfaceContainerHighest = Color(0xFFECE5F9),
    outline = Color(0xFF7D749B), outlineVariant = Color(0xFFDED4F1),
    error = Color(0xFFD93A3A),
)

private val AppShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

private val IranSansFontFamily = FontFamily(
    Font(R.font.iransans_light, FontWeight.Light),
    Font(R.font.iransans_regular, FontWeight.Normal),
    Font(R.font.iransans_medium, FontWeight.Medium),
    Font(R.font.iransans_bold, FontWeight.Bold),
    Font(R.font.iransans_black, FontWeight.Black),
    Font(R.font.iransans_ultralight, FontWeight.Thin)
)

private val AppTypography = Typography(
    bodyLarge = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Normal,
        fontSize = 16.sp,
        lineHeight = 24.sp,
        letterSpacing = 0.5.sp
    ),
    bodyMedium = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Normal,
        fontSize = 14.sp,
        lineHeight = 20.sp,
        letterSpacing = 0.25.sp
    ),
    bodySmall = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Normal,
        fontSize = 12.sp,
        lineHeight = 16.sp,
        letterSpacing = 0.4.sp
    ),
    labelLarge = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Medium,
        fontSize = 14.sp,
        lineHeight = 20.sp,
        letterSpacing = 0.1.sp
    ),
    titleLarge = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 22.sp,
        lineHeight = 28.sp,
        letterSpacing = 0.sp
    ),
    titleMedium = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.SemiBold,
        fontSize = 16.sp,
        lineHeight = 24.sp,
        letterSpacing = 0.15.sp
    ),
    titleSmall = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.SemiBold,
        fontSize = 14.sp,
        lineHeight = 20.sp,
        letterSpacing = 0.1.sp
    ),
    headlineMedium = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 28.sp,
        lineHeight = 36.sp,
        letterSpacing = 0.sp
    ),
    headlineLarge = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 32.sp,
        lineHeight = 40.sp,
        letterSpacing = 0.sp
    ),
    displayLarge = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 57.sp,
        lineHeight = 64.sp,
        letterSpacing = -0.25.sp
    ),
    displayMedium = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 45.sp,
        lineHeight = 52.sp,
        letterSpacing = 0.sp
    ),
    displaySmall = TextStyle(
        fontFamily = IranSansFontFamily,
        fontWeight = FontWeight.Bold,
        fontSize = 36.sp,
        lineHeight = 44.sp,
        letterSpacing = 0.sp
    )
)

@Composable
fun AppTheme(s: Settings, dark: Boolean, content: @Composable () -> Unit) {
    val ctx = LocalContext.current
    val dynamic = s.dynamicColor && Build.VERSION.SDK_INT >= 31
    val scheme = when {
        dynamic -> if (dark) dynamicDarkColorScheme(ctx) else dynamicLightColorScheme(ctx)
        dark -> Dark
        else -> Light
    }
    val extra = if (dynamic) Extra(scheme.primary, scheme.tertiary, scheme.tertiary)
    else Extra(scheme.secondary, scheme.primary, scheme.tertiary)
    CompositionLocalProvider(LocalExtra provides extra) {
        MaterialTheme(
            colorScheme = scheme,
            shapes = AppShapes,
            typography = AppTypography,
            content = content
        )
    }
}

@Composable
fun isDark(s: Settings): Boolean = when (s.theme) {
    "dark" -> true
    "light" -> false
    else -> isSystemInDarkTheme()
}