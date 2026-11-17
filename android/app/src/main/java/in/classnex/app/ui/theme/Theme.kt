package `in`.classnex.app.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp

val Navy = Color(0xFF1E2A78)
val Blue = Color(0xFF2563EB)
val Purple = Color(0xFF6D28D9)
val Orange = Color(0xFFF97316)
val Green = Color(0xFF16A34A)
val Red = Color(0xFFDC2626)
val Amber = Color(0xFFF59E0B)
val Bg = Color(0xFFF4F6FB)
val Muted = Color(0xFF6B7280)

private val scheme = lightColorScheme(
    primary = Blue, onPrimary = Color.White, primaryContainer = Color(0xFFE8EFFF), onPrimaryContainer = Navy,
    secondary = Purple, onSecondary = Color.White, secondaryContainer = Color(0xFFF1EAFF), onSecondaryContainer = Purple,
    tertiary = Orange, background = Bg, surface = Color.White, onSurface = Color(0xFF111827), onSurfaceVariant = Muted,
    surfaceVariant = Color(0xFFF1F3F9), outline = Color(0xFFD9DEEA), error = Red,
)

@Composable
fun ClassNexTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = scheme,
        shapes = Shapes(small = RoundedCornerShape(10.dp), medium = RoundedCornerShape(14.dp), large = RoundedCornerShape(18.dp)),
        content = content,
    )
}
