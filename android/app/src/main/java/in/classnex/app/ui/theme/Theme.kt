package `in`.classnex.app.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import `in`.classnex.app.R

/*
 * ClassNex design system – colours, type and shapes.
 * Deep indigo app bars, a purple → indigo accent gradient for primary actions, a soft lavender page
 * background and white rounded cards. Status colours are used only for meaning (present / absent / dues).
 */

// brand
val Indigo900 = Color(0xFF1C1563)   // app bar, drawer header (darkest)
val Indigo800 = Color(0xFF261B85)
val Indigo600 = Color(0xFF3B26C4)
val Violet = Color(0xFF6C3CF0)       // primary accent
val VioletSoft = Color(0xFFEFEAFE)   // selected items, chips
val Lavender = Color(0xFFF6F4FD)     // page background
val Line = Color(0xFFE9E5F6)         // borders and dividers
val Ink = Color(0xFF1B1640)          // main text
val Muted = Color(0xFF6F6A8A)        // secondary text

// meaning
val Green = Color(0xFF1FA463)
val Red = Color(0xFFE5484D)
val Amber = Color(0xFFF5A524)
val Blue = Color(0xFF3B82F6)
val Teal = Color(0xFF0EA5A4)
val Pink = Color(0xFFE5488F)
val Orange = Color(0xFFF97316)

// names used by older screens (kept so every screen shares the same palette)
val Navy = Indigo900
val Purple = Violet
val Bg = Lavender

object Brand {
    val primary = Brush.horizontalGradient(listOf(Color(0xFF7041F3), Indigo600))
    val appBar = Brush.horizontalGradient(listOf(Indigo900, Color(0xFF2B1D93)))
    val hero = Brush.verticalGradient(listOf(Color(0xFFECE6FF), Lavender))
    val drawer = Brush.linearGradient(listOf(Color(0xFF7041F3), Color(0xFF3B26C4)))
}

private val Jakarta = FontFamily(
    Font(R.font.jakarta_regular, FontWeight.Normal),
    Font(R.font.jakarta_medium, FontWeight.Medium),
    Font(R.font.jakarta_semibold, FontWeight.SemiBold),
    Font(R.font.jakarta_bold, FontWeight.Bold),
    Font(R.font.jakarta_extrabold, FontWeight.ExtraBold),
)

private val base = Typography()
private fun TextStyle.j() = copy(fontFamily = Jakarta)
private val typography = Typography(
    displaySmall = base.displaySmall.j().copy(fontWeight = FontWeight.ExtraBold),
    headlineMedium = base.headlineMedium.j().copy(fontWeight = FontWeight.ExtraBold, fontSize = 26.sp),
    headlineSmall = base.headlineSmall.j().copy(fontWeight = FontWeight.ExtraBold, fontSize = 22.sp),
    titleLarge = base.titleLarge.j().copy(fontWeight = FontWeight.Bold, fontSize = 19.sp),
    titleMedium = base.titleMedium.j().copy(fontWeight = FontWeight.Bold, fontSize = 16.sp),
    titleSmall = base.titleSmall.j().copy(fontWeight = FontWeight.SemiBold),
    bodyLarge = base.bodyLarge.j().copy(fontSize = 15.sp),
    bodyMedium = base.bodyMedium.j().copy(fontSize = 14.sp),
    bodySmall = base.bodySmall.j().copy(fontSize = 12.sp),
    labelLarge = base.labelLarge.j().copy(fontWeight = FontWeight.SemiBold, fontSize = 14.sp),
    labelMedium = base.labelMedium.j().copy(fontWeight = FontWeight.SemiBold),
    labelSmall = base.labelSmall.j().copy(fontWeight = FontWeight.SemiBold),
)

private val scheme = lightColorScheme(
    primary = Violet, onPrimary = Color.White, primaryContainer = VioletSoft, onPrimaryContainer = Indigo800,
    secondary = Indigo600, onSecondary = Color.White, secondaryContainer = Color(0xFFE6E1FF), onSecondaryContainer = Indigo800,
    tertiary = Teal, background = Lavender, onBackground = Ink, surface = Color.White, onSurface = Ink, onSurfaceVariant = Muted,
    surfaceVariant = Color(0xFFF2EFFB), surfaceContainer = Color.White, surfaceContainerLow = Color.White, surfaceContainerHigh = Color(0xFFF7F5FE),
    outline = Color(0xFFD9D3EE), outlineVariant = Line, error = Red,
)

/** Corner radii: 12 inputs/chips, 16 cards, 22 sheets. */
object Radius {
    val small = RoundedCornerShape(10.dp)
    val input = RoundedCornerShape(14.dp)
    val card = RoundedCornerShape(18.dp)
    val sheet = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)
}

@Composable
fun ClassNexTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = scheme,
        typography = typography,
        shapes = Shapes(extraSmall = RoundedCornerShape(8.dp), small = RoundedCornerShape(12.dp), medium = RoundedCornerShape(16.dp), large = RoundedCornerShape(20.dp), extraLarge = RoundedCornerShape(28.dp)),
        content = content,
    )
}
