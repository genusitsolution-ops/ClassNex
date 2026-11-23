package `in`.classnex.app.ui.common

import androidx.compose.animation.*
import androidx.compose.animation.core.*
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import `in`.classnex.app.ui.theme.*
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset

/*
 * Reusable ClassNex components (design system). Every screen is built from these so the look stays
 * consistent: gradient app bar, gradient buttons with press feedback, stat tiles, charts, pills,
 * skeleton loaders, form fields, pickers, segmented tabs, success animation and entrance animation.
 */

// ------------------------------------------------------------------ press feedback
/** Slight shrink while pressed – used by tiles, buttons and cards. */
@Composable
fun Modifier.pressable(onClick: (() -> Unit)?, enabled: Boolean = true): Modifier {
    if (onClick == null) return this
    val src = remember { MutableInteractionSource() }
    val pressed by src.collectIsPressedAsState()
    val s by animateFloatAsState(if (pressed) .97f else 1f, spring(stiffness = Spring.StiffnessMediumLow), label = "press")
    return this.scale(s).clickable(interactionSource = src, indication = rippleInd(), enabled = enabled, onClick = onClick)
}

@Composable
private fun rippleInd() = androidx.compose.material3.ripple()

// ------------------------------------------------------------------ entrance animation
/** Cards fade and slide in, staggered by their position (capped so long lists stay quick). */
/** True when the user turned animations off in Android settings (accessibility / developer options). */
val LocalReduceMotion = staticCompositionLocalOf { false }

@Composable
fun rememberReduceMotion(): Boolean {
    val ctx = androidx.compose.ui.platform.LocalContext.current
    return remember { runCatching { android.provider.Settings.Global.getFloat(ctx.contentResolver, android.provider.Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f }.getOrDefault(false) }
}

@Composable
fun Entrance(index: Int = 0, content: @Composable () -> Unit) {
    if (LocalReduceMotion.current) { content(); return }
    val state = remember { MutableTransitionState(false).apply { targetState = true } }
    AnimatedVisibility(
        visibleState = state,
        enter = fadeIn(tween(260, delayMillis = (index.coerceAtMost(8)) * 45)) + slideInVertically(tween(300, delayMillis = (index.coerceAtMost(8)) * 45)) { it / 6 },
    ) { content() }
}

// ------------------------------------------------------------------ app bar
@Composable
fun GradientTopBar(
    title: String,
    subtitle: String? = null,
    navigation: (@Composable () -> Unit)? = null,
    actions: @Composable RowScope.() -> Unit = {},
) {
    Box(Modifier.fillMaxWidth().background(Brand.appBar).statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().height(60.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            if (navigation != null) navigation() else Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f).padding(horizontal = 6.dp)) {
                AnimatedContent(title, transitionSpec = { fadeIn(tween(180)) togetherWith fadeOut(tween(120)) }, label = "title") { t ->
                    Text(t, color = Color.White, style = MaterialTheme.typography.titleLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                if (subtitle != null) Text(subtitle, color = Color.White.copy(alpha = .72f), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            CompositionLocalProvider(LocalContentColor provides Color.White) { actions() }
        }
    }
}

@Composable
fun BarIcon(icon: ImageVector, desc: String, badge: Int = 0, onClick: () -> Unit) {
    IconButton(onClick = onClick) {
        BadgedBox(badge = { if (badge > 0) Badge(containerColor = Orange) { Text(if (badge > 99) "99+" else "$badge") } }) {
            Icon(icon, desc, tint = Color.White)
        }
    }
}

// ------------------------------------------------------------------ buttons
@Composable
fun GradientButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
    icon: ImageVector? = null,
    brush: Brush = Brand.primary,
) {
    val active = enabled && !loading
    Box(
        modifier.heightIn(min = 52.dp).shadow(if (active) 6.dp else 0.dp, RoundedCornerShape(16.dp), ambientColor = Violet, spotColor = Violet)
            .clip(RoundedCornerShape(16.dp))
            .background(if (active) brush else Brush.horizontalGradient(listOf(Color(0xFFC9C1EC), Color(0xFFBDB4E6))))
            .pressable(if (active) onClick else null),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.5.dp)
        else Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 18.dp)) {
            if (icon != null) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(8.dp)) }
            Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
fun SoftButton(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, icon: ImageVector? = null, color: Color = Violet, enabled: Boolean = true) {
    Surface(
        modifier = modifier.heightIn(min = 44.dp).pressable(if (enabled) onClick else null),
        shape = RoundedCornerShape(14.dp), color = color.copy(alpha = if (enabled) .1f else .05f),
    ) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
            if (icon != null) { Icon(icon, null, tint = color, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)) }
            Text(text, color = color, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

// ------------------------------------------------------------------ cards and tiles
@Composable
fun CnxCard(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, padding: Dp = 16.dp, content: @Composable ColumnScope.() -> Unit) {
    Surface(
        modifier = modifier.fillMaxWidth().shadow(3.dp, Radius.card, ambientColor = Color(0x22311C8F), spotColor = Color(0x22311C8F)).pressable(onClick),
        shape = Radius.card, color = Color.White,
    ) { Column(Modifier.padding(padding), content = content) }
}

@Composable
fun IconCircle(icon: ImageVector, tint: Color, size: Dp = 44.dp, iconSize: Dp = 22.dp) {
    Box(Modifier.size(size).clip(CircleShape).background(tint.copy(alpha = .13f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(iconSize))
    }
}

/** Dashboard stat tile: tinted circle icon, big number, label. */
@Composable
fun StatTile(title: String, value: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, sub: String? = null, onClick: (() -> Unit)? = null) {
    CnxCard(modifier, onClick, padding = 14.dp) {
        IconCircle(icon, tint, 40.dp, 20.dp)
        Spacer(Modifier.height(10.dp))
        Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(title, color = Muted, fontSize = 12.sp, lineHeight = 14.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        if (sub != null) Text(sub, color = tint, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
fun SectionHeader(title: String, modifier: Modifier = Modifier, action: String? = null, onAction: (() -> Unit)? = null) {
    Row(modifier.fillMaxWidth().padding(top = 6.dp, bottom = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, style = MaterialTheme.typography.titleMedium, color = Ink, modifier = Modifier.weight(1f))
        if (action != null && onAction != null) Text(action, color = Violet, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onAction).padding(6.dp))
    }
}

@Composable
fun InfoBanner(text: String, color: Color = Green, icon: ImageVector = Icons.Outlined.Info, modifier: Modifier = Modifier) {
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = .1f)).border(1.dp, color.copy(alpha = .25f), RoundedCornerShape(14.dp)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = color, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Text(text, color = color, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun Pill(text: String, color: Color, modifier: Modifier = Modifier, filled: Boolean = false) {
    Surface(modifier = modifier, color = if (filled) color else color.copy(alpha = .12f), shape = RoundedCornerShape(50)) {
        Text(text, color = if (filled) Color.White else color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

fun statusColor(status: String?): Color = when (status) {
    "active", "paid", "success", "present", "sent", "delivered", "read", "approved", "completed", "resolved", "closed" -> Green
    "partial", "pending", "late", "grace", "trial", "prepared", "opened", "in_progress", "queued" -> Amber
    "unpaid", "failed", "absent", "expired", "reversed", "overdue", "suspended", "rejected", "cancelled", "left" -> Red
    "leave", "holiday", "refunded" -> Violet
    "open", "new" -> Blue
    else -> Muted
}

@Composable
fun KeyRow(k: String, v: String?, modifier: Modifier = Modifier) {
    if (v.isNullOrBlank()) return
    Row(modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(k, color = Muted, fontSize = 13.sp, modifier = Modifier.weight(.42f))
        Text(v, color = Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(.58f))
    }
}

// ------------------------------------------------------------------ charts
/** Animated donut: segments are (value, colour). */
@Composable
fun DonutChart(segments: List<Pair<Float, Color>>, center: String, centerSub: String? = null, size: Dp = 128.dp, stroke: Dp = 16.dp) {
    val total = segments.sumOf { it.first.toDouble() }.toFloat()
    val anim = remember { Animatable(0f) }
    val still = LocalReduceMotion.current
    LaunchedEffect(segments) { if (still) anim.snapTo(1f) else { anim.snapTo(0f); anim.animateTo(1f, tween(900, easing = FastOutSlowInEasing)) } }
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val sw = stroke.toPx()
            val box = Size(this.size.width - sw, this.size.height - sw)
            val tl = Offset(sw / 2, sw / 2)
            drawArc(Color(0xFFEDEAF7), 0f, 360f, false, tl, box, style = Stroke(sw))
            if (total > 0f) {
                var start = -90f
                segments.forEach { (v, c) ->
                    val sweep = 360f * (v / total) * anim.value
                    if (sweep > 0f) drawArc(c, start, (sweep - 1.5f).coerceAtLeast(.5f), false, tl, box, style = Stroke(sw, cap = StrokeCap.Butt))
                    start += 360f * (v / total) * anim.value
                }
            }
        }
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(center, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Ink)
            if (centerSub != null) Text(centerSub, fontSize = 11.sp, color = Muted)
        }
    }
}

@Composable
fun LegendDot(text: String, color: Color, value: String? = null) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 3.dp)) {
        Box(Modifier.size(10.dp).clip(CircleShape).background(color))
        Spacer(Modifier.width(8.dp))
        Text(text, fontSize = 13.sp, color = Muted, modifier = Modifier.weight(1f, fill = false))
        if (value != null) { Spacer(Modifier.width(8.dp)); Text(value, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Ink) }
    }
}

/** Simple animated bar chart (monthly collection trend). The last bar is highlighted. */
@Composable
fun BarChart(values: List<Long>, labels: List<String>, modifier: Modifier = Modifier, height: Dp = 110.dp) {
    val max = (values.maxOrNull() ?: 0L).coerceAtLeast(1L).toFloat()
    val anim = remember { Animatable(0f) }
    val still = LocalReduceMotion.current
    LaunchedEffect(values) { if (still) anim.snapTo(1f) else { anim.snapTo(0f); anim.animateTo(1f, tween(800, easing = FastOutSlowInEasing)) } }
    Column(modifier) {
        Canvas(Modifier.fillMaxWidth().height(height)) {
            val n = values.size.coerceAtLeast(1)
            val slot = this.size.width / n
            val bw = (slot * .5f).coerceAtMost(34.dp.toPx())
            values.forEachIndexed { i, v ->
                val h = (v / max) * this.size.height * anim.value
                val x = slot * i + (slot - bw) / 2
                drawRoundRect(
                    brush = if (i == values.lastIndex) Brush.verticalGradient(listOf(Color(0xFF7C4DFF), Color(0xFF3B26C4))) else Brush.verticalGradient(listOf(Color(0xFFD9D0FF), Color(0xFFC6B9FB))),
                    topLeft = Offset(x, this.size.height - h), size = Size(bw, h),
                    cornerRadius = androidx.compose.ui.geometry.CornerRadius(8.dp.toPx(), 8.dp.toPx()),
                )
            }
        }
        Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
            labels.forEach { Text(it, fontSize = 10.sp, color = Muted, textAlign = TextAlign.Center, modifier = Modifier.weight(1f), maxLines = 1) }
        }
    }
}

@Composable
fun ProgressLine(fraction: Float, color: Color, modifier: Modifier = Modifier) {
    val f by animateFloatAsState(fraction.coerceIn(0f, 1f), tween(700), label = "progress")
    Box(modifier.fillMaxWidth().height(8.dp).clip(RoundedCornerShape(50)).background(color.copy(alpha = .14f))) {
        Box(Modifier.fillMaxWidth(f).fillMaxHeight().clip(RoundedCornerShape(50)).background(color))
    }
}

// ------------------------------------------------------------------ skeleton loading
@Composable
fun Modifier.shimmer(): Modifier {
    val t = rememberInfiniteTransition(label = "shimmer")
    val x by t.animateFloat(-300f, 1200f, infiniteRepeatable(tween(1100, easing = LinearEasing)), label = "x")
    return this.background(Brush.linearGradient(listOf(Color(0xFFEDEAF6), Color(0xFFF8F6FD), Color(0xFFEDEAF6)), start = Offset(x, 0f), end = Offset(x + 300f, 120f)))
}

@Composable
fun SkeletonList(rows: Int = 6, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            repeat(2) { Box(Modifier.weight(1f).height(96.dp).clip(Radius.card).shimmer()) }
        }
        repeat(rows) {
            Row(Modifier.fillMaxWidth().clip(Radius.card).background(Color.White).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(CircleShape).shimmer())
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Box(Modifier.fillMaxWidth(.6f).height(12.dp).clip(RoundedCornerShape(6.dp)).shimmer())
                    Spacer(Modifier.height(8.dp))
                    Box(Modifier.fillMaxWidth(.35f).height(10.dp).clip(RoundedCornerShape(6.dp)).shimmer())
                }
            }
        }
    }
}

// ------------------------------------------------------------------ empty state
@Composable
fun EmptyState(title: String, message: String? = null, icon: ImageVector = Icons.Outlined.Inbox, action: String? = null, onAction: (() -> Unit)? = null) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 32.dp, vertical = 40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(84.dp).clip(CircleShape).background(Brand.hero), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Violet, modifier = Modifier.size(38.dp)) }
        Spacer(Modifier.height(14.dp))
        Text(title, fontWeight = FontWeight.Bold, color = Ink, textAlign = TextAlign.Center)
        if (message != null) Text(message, color = Muted, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 4.dp))
        if (action != null && onAction != null) { Spacer(Modifier.height(14.dp)); SoftButton(action, onAction, icon = Icons.Outlined.Add) }
    }
}

// ------------------------------------------------------------------ success animation
/** Full-screen success tick (after saving attendance or collecting a fee). */
@Composable
fun SuccessOverlay(visible: Boolean, title: String, sub: String? = null, onDone: () -> Unit) {
    if (!visible) return
    val scale = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        scale.animateTo(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessLow))
        kotlinx.coroutines.delay(1100)
        onDone()
    }
    Box(Modifier.fillMaxSize().background(Color(0x99130E3A)).clickable(onClick = onDone), contentAlignment = Alignment.Center) {
        Column(Modifier.padding(32.dp).clip(RoundedCornerShape(28.dp)).background(Color.White).padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(86.dp).scale(scale.value).clip(CircleShape).background(Brush.linearGradient(listOf(Color(0xFF34D399), Green))), contentAlignment = Alignment.Center) {
                Icon(Icons.Outlined.Check, null, tint = Color.White, modifier = Modifier.size(48.dp))
            }
            Spacer(Modifier.height(16.dp))
            Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Ink, textAlign = TextAlign.Center)
            if (sub != null) Text(sub, color = Muted, textAlign = TextAlign.Center, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

// ------------------------------------------------------------------ inputs
@Composable
fun CnxField(
    value: String,
    onChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    keyboard: KeyboardType = KeyboardType.Text,
    error: String? = null,
    singleLine: Boolean = true,
    minLines: Int = 1,
    password: Boolean = false,
    prefix: String? = null,
    supporting: String? = null,
    enabled: Boolean = true,
) {
    var show by remember { mutableStateOf(false) }
    OutlinedTextField(
        value = value, onValueChange = onChange, label = { Text(label) }, modifier = modifier.fillMaxWidth(), enabled = enabled,
        leadingIcon = icon?.let { { Icon(it, null, tint = Violet) } },
        trailingIcon = if (password) { { IconButton(onClick = { show = !show }) { Icon(if (show) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, "Show password") } } } else null,
        visualTransformation = if (password && !show) PasswordVisualTransformation() else VisualTransformation.None,
        prefix = prefix?.let { { Text(it) } },
        isError = error != null, supportingText = (error ?: supporting)?.let { { Text(it) } },
        singleLine = singleLine && minLines == 1, minLines = minLines,
        keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        shape = Radius.input,
        colors = OutlinedTextFieldDefaults.colors(unfocusedBorderColor = Line, focusedBorderColor = Violet, unfocusedContainerColor = Color.White, focusedContainerColor = Color.White, disabledContainerColor = Color(0xFFF7F6FB)),
    )
}

/** Read-only field that opens a dropdown of (value, text) options. A null value means "All / None". */
@Composable
fun SelectField(label: String, options: List<Pair<String?, String>>, selected: String?, modifier: Modifier = Modifier, icon: ImageVector? = null, error: String? = null, enabled: Boolean = true, onSelect: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val text = options.firstOrNull { it.first == selected }?.second ?: ""
    Box(modifier) {
        OutlinedTextField(
            value = text, onValueChange = {}, readOnly = true, label = { Text(label) }, modifier = Modifier.fillMaxWidth(), enabled = enabled,
            leadingIcon = icon?.let { { Icon(it, null, tint = Violet) } },
            trailingIcon = { Icon(Icons.Outlined.KeyboardArrowDown, null) }, singleLine = true, shape = Radius.input,
            isError = error != null, supportingText = error?.let { { Text(it) } },
            colors = OutlinedTextFieldDefaults.colors(unfocusedBorderColor = Line, focusedBorderColor = Violet, unfocusedContainerColor = Color.White, focusedContainerColor = Color.White),
        )
        // transparent layer on top so the whole field opens the menu
        Box(Modifier.matchParentSize().padding(top = 8.dp).clip(Radius.input).clickable(enabled = enabled) { open = true })
        DropdownMenu(expanded = open, onDismissRequest = { open = false }, modifier = Modifier.heightIn(max = 360.dp)) {
            if (options.isEmpty()) DropdownMenuItem(text = { Text("Nothing to choose yet", color = Muted) }, onClick = { open = false })
            options.forEach { (v, t) ->
                DropdownMenuItem(text = { Text(t, fontWeight = if (v == selected) FontWeight.Bold else FontWeight.Normal) }, onClick = { open = false; onSelect(v) },
                    trailingIcon = if (v == selected) { { Icon(Icons.Outlined.Check, null, tint = Violet) } } else null)
            }
        }
    }
}

/** Date field with the Material date picker; value is yyyy-MM-dd. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DateField(label: String, value: String?, modifier: Modifier = Modifier, allowClear: Boolean = false, error: String? = null, onChange: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box(modifier) {
        OutlinedTextField(
            value = value?.let { prettyDate(it) } ?: "", onValueChange = {}, readOnly = true, label = { Text(label) }, modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Outlined.CalendarMonth, null, tint = Violet) }, singleLine = true, shape = Radius.input,
            trailingIcon = if (allowClear && value != null) { { IconButton(onClick = { onChange(null) }) { Icon(Icons.Outlined.Close, "Clear") } } } else null,
            isError = error != null, supportingText = error?.let { { Text(it) } },
            colors = OutlinedTextFieldDefaults.colors(unfocusedBorderColor = Line, focusedBorderColor = Violet, unfocusedContainerColor = Color.White, focusedContainerColor = Color.White),
        )
        Box(Modifier.matchParentSize().padding(top = 8.dp, end = if (allowClear && value != null) 48.dp else 0.dp).clip(Radius.input).clickable { open = true })
    }
    if (open) {
        val initial = runCatching { LocalDate.parse(value!!).atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli() }.getOrNull() ?: System.currentTimeMillis()
        val st = rememberDatePickerState(initialSelectedDateMillis = initial)
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = { TextButton(onClick = { st.selectedDateMillis?.let { onChange(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString()) }; open = false }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { open = false }) { Text("Cancel") } },
        ) { DatePicker(st) }
    }
}

/** Time field (HH:mm) with the Material time picker. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TimeField(label: String, value: String?, modifier: Modifier = Modifier, onChange: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box(modifier) {
        OutlinedTextField(
            value = value?.let { prettyTime(it) } ?: "", onValueChange = {}, readOnly = true, label = { Text(label) }, modifier = Modifier.fillMaxWidth(),
            leadingIcon = { Icon(Icons.Outlined.Schedule, null, tint = Violet) }, singleLine = true, shape = Radius.input,
            colors = OutlinedTextFieldDefaults.colors(unfocusedBorderColor = Line, focusedBorderColor = Violet, unfocusedContainerColor = Color.White, focusedContainerColor = Color.White),
        )
        Box(Modifier.matchParentSize().padding(top = 8.dp).clip(Radius.input).clickable { open = true })
    }
    if (open) {
        val h = value?.take(2)?.toIntOrNull() ?: 9
        val m = value?.drop(3)?.take(2)?.toIntOrNull() ?: 0
        val st = rememberTimePickerState(h, m, false)
        AlertDialog(
            onDismissRequest = { open = false },
            confirmButton = { TextButton(onClick = { onChange("%02d:%02d".format(st.hour, st.minute)); open = false }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { open = false }) { Text("Cancel") } },
            text = { TimePicker(st) },
        )
    }
}

/** Pill segmented control (tabs inside a screen). */
@Composable
fun SegmentedTabs(options: List<String>, selected: Int, modifier: Modifier = Modifier, onSelect: (Int) -> Unit) {
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(50)).background(Color.White).border(1.dp, Line, RoundedCornerShape(50)).padding(4.dp)) {
        options.forEachIndexed { i, o ->
            val on = i == selected
            val bg by animateColorAsState(if (on) Violet else Color.Transparent, tween(220), label = "seg")
            Box(Modifier.weight(1f).clip(RoundedCornerShape(50)).background(bg).clickable { onSelect(i) }.padding(vertical = 9.dp), contentAlignment = Alignment.Center) {
                Text(o, color = if (on) Color.White else Muted, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/** Horizontally scrolling chips (filters, payment methods). */
@Composable
fun ChoiceChips(options: List<Pair<String, String>>, selected: String?, modifier: Modifier = Modifier, onSelect: (String) -> Unit) {
    androidx.compose.foundation.lazy.LazyRow(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(options.size) { i ->
            val (k, l) = options[i]
            val on = k == selected
            Surface(
                shape = RoundedCornerShape(50), color = if (on) Violet else Color.White,
                border = BorderStroke(1.dp, if (on) Violet else Line),
                modifier = Modifier.clip(RoundedCornerShape(50)).clickable { onSelect(k) },
            ) { Text(l, color = if (on) Color.White else Ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) }
        }
    }
}

/** Search box used at the top of lists. */
@Composable
fun SearchBox(value: String, onChange: (String) -> Unit, hint: String, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value, onChange, modifier = modifier.fillMaxWidth(), placeholder = { Text(hint) }, singleLine = true,
        leadingIcon = { Icon(Icons.Outlined.Search, null, tint = Muted) },
        trailingIcon = if (value.isNotEmpty()) { { IconButton(onClick = { onChange("") }) { Icon(Icons.Outlined.Close, "Clear") } } } else null,
        shape = RoundedCornerShape(50),
        colors = OutlinedTextFieldDefaults.colors(unfocusedBorderColor = Line, focusedBorderColor = Violet, unfocusedContainerColor = Color.White, focusedContainerColor = Color.White),
    )
}

// ------------------------------------------------------------------ list rows
@Composable
fun PersonRow(
    name: String, sub: String?, photo: String? = null, modifier: Modifier = Modifier,
    trailing: (@Composable () -> Unit)? = null, onClick: (() -> Unit)? = null,
) {
    CnxCard(modifier, onClick, padding = 12.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Avatar(photo, name, 44)
            Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                Text(name, fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (!sub.isNullOrBlank()) Text(sub, color = Muted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            trailing?.invoke()
        }
    }
}

// ------------------------------------------------------------------ bottom navigation
data class BottomItem(val route: String, val label: String, val icon: ImageVector)

/**
 * Bottom navigation (v1.0.22): the selected item gets a filled purple pill with a white icon that
 * grows in smoothly; labels never clip (one line, ellipsis on very small screens).
 */
@Composable
fun CnxBottomBar(items: List<BottomItem>, selected: String?, onSelect: (BottomItem) -> Unit) {
    Surface(color = Color.White, shadowElevation = 16.dp, shape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)) {
        Row(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 4.dp, vertical = 8.dp), horizontalArrangement = Arrangement.SpaceAround) {
            items.forEach { item ->
                val on = item.route == selected
                val pill by animateDpAsState(if (on) 54.dp else 40.dp, tween(260), label = "pill")
                val bg by animateColorAsState(if (on) Violet else Color.Transparent, tween(260), label = "bn")
                val tint by animateColorAsState(if (on) Color.White else Muted, tween(260), label = "bt")
                val text by animateColorAsState(if (on) Violet else Muted, tween(260), label = "bl")
                Column(
                    Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).clickable { onSelect(item) }.testTag("tab:" + item.label).padding(vertical = 2.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Box(Modifier.width(pill).height(34.dp).shadow(if (on) 6.dp else 0.dp, RoundedCornerShape(50), spotColor = Violet).clip(RoundedCornerShape(50)).background(if (on) Brand.primary else Brush.linearGradient(listOf(bg, bg))), contentAlignment = Alignment.Center) {
                        Icon(item.icon, item.label, tint = tint, modifier = Modifier.size(21.dp))
                    }
                    Text(item.label, color = text, fontSize = 11.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 3.dp))
                }
            }
        }
    }
}

// ------------------------------------------------------------------ v1.0.22 building blocks
/** Light app bar (white over lavender): dark title, optional back / custom leading content and actions. */
@Composable
fun LightTopBar(
    title: String,
    subtitle: String? = null,
    navigation: (@Composable () -> Unit)? = null,
    titleContent: (@Composable () -> Unit)? = null,
    actions: @Composable RowScope.() -> Unit = {},
) {
    Box(Modifier.fillMaxWidth().background(Lavender).statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().height(62.dp).padding(horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            if (navigation != null) navigation() else Spacer(Modifier.width(12.dp))
            Box(Modifier.weight(1f).padding(horizontal = 6.dp)) {
                if (titleContent != null) titleContent()
                else Column {
                    AnimatedContent(title, transitionSpec = { fadeIn(tween(180)) togetherWith fadeOut(tween(120)) }, label = "title") { t ->
                        Text(t, color = Ink, style = MaterialTheme.typography.titleLarge, fontSize = 21.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    if (subtitle != null) Text(subtitle, color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
            CompositionLocalProvider(LocalContentColor provides Ink) { actions() }
        }
    }
}

@Composable
fun RoundIconButton(icon: ImageVector, desc: String, tint: Color = Ink, badge: Boolean = false, onClick: () -> Unit) {
    Box(Modifier.padding(4.dp).size(42.dp).clip(CircleShape).background(Color.White).border(1.dp, Line, CircleShape).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, desc, tint = tint, modifier = Modifier.size(21.dp))
        if (badge) Box(Modifier.align(Alignment.TopEnd).padding(9.dp).size(8.dp).clip(CircleShape).background(Red))
    }
}

/** Big purple pill used for the main action in a top bar ("+ Add"). */
@Composable
fun PillAction(text: String, icon: ImageVector, onClick: () -> Unit) {
    Row(Modifier.padding(end = 6.dp).height(42.dp).shadow(6.dp, RoundedCornerShape(50), spotColor = Violet).clip(RoundedCornerShape(50)).background(Brand.primary).pressable(onClick).padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Color.White, fontWeight = FontWeight.Bold)
    }
}

/** Rounded-square tinted icon with a label underneath – compact navigation tile. */
@Composable
fun IconTile(label: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).pressable(onClick).padding(vertical = 8.dp, horizontal = 2.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(54.dp).clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(tint.copy(alpha = .16f), tint.copy(alpha = .08f)))), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(26.dp))
        }
        Spacer(Modifier.height(7.dp))
        Text(label, color = Ink, fontWeight = FontWeight.SemiBold, fontSize = 12.sp, lineHeight = 15.sp, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

/** A white rounded group with a heading – used to group related modules instead of many separate cards. */
@Composable
fun GroupCard(title: String?, modifier: Modifier = Modifier, action: String? = null, onAction: (() -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    Surface(modifier.fillMaxWidth().shadow(2.dp, Radius.card, ambientColor = Color(0x1A311C8F), spotColor = Color(0x1A311C8F)), shape = Radius.card, color = Color.White) {
        Column(Modifier.padding(14.dp)) {
            if (title != null) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(start = 2.dp, bottom = 6.dp)) {
                    Text(title, style = MaterialTheme.typography.titleMedium, color = Ink, modifier = Modifier.weight(1f))
                    if (action != null && onAction != null) Text(action, color = Violet, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onAction).padding(6.dp))
                }
            }
            content()
        }
    }
}

/** Number that counts up when it first appears (instant when reduce-motion is on). */
@Composable
fun CountUpText(value: Long, format: (Long) -> String, style: androidx.compose.ui.text.TextStyle = MaterialTheme.typography.headlineSmall, color: Color = Ink, modifier: Modifier = Modifier) {
    val still = LocalReduceMotion.current
    val anim = remember { Animatable(if (still) value.toFloat() else 0f) }
    LaunchedEffect(value) { if (still) anim.snapTo(value.toFloat()) else anim.animateTo(value.toFloat(), tween(750, easing = FastOutSlowInEasing)) }
    Text(format(anim.value.toLong()), style = style, color = color, modifier = modifier, maxLines = 1, overflow = TextOverflow.Ellipsis)
}

/** Coloured count box (attendance: Present / Absent / Late / Leave). */
@Composable
fun CountTile(value: Int, label: String, color: Color, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = .1f)).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        AnimatedContent(value, transitionSpec = { (slideInVertically { -it / 2 } + fadeIn()) togetherWith (slideOutVertically { it / 2 } + fadeOut()) }, label = "count") { v ->
            Text("$v", color = color, fontWeight = FontWeight.ExtraBold, fontSize = 21.sp)
        }
        Text(label, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** Pull-to-refresh wrapper used by the main lists and the dashboard. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun Refreshable(refreshing: Boolean, onRefresh: () -> Unit, modifier: Modifier = Modifier, content: @Composable BoxScope.() -> Unit) {
    androidx.compose.material3.pulltorefresh.PullToRefreshBox(isRefreshing = refreshing, onRefresh = onRefresh, modifier = modifier.fillMaxSize(), content = content)
}

/** Simple trophy illustration for the welcome card (drawn, no image files). */
@Composable
fun TrophyArt(modifier: Modifier = Modifier) {
    val t = rememberInfiniteTransition(label = "glow")
    val still = LocalReduceMotion.current
    val glow by t.animateFloat(.55f, .85f, infiniteRepeatable(tween(1800), RepeatMode.Reverse), label = "g")
    Box(modifier.size(96.dp), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            drawCircle(Brush.radialGradient(listOf(Color(0xFFFFE08A).copy(alpha = if (still) .7f else glow), Color.Transparent)), radius = size.minDimension / 2)
            listOf(Offset(.15f, .25f), Offset(.85f, .2f), Offset(.9f, .7f), Offset(.12f, .75f)).forEach { o ->
                drawCircle(Color.White.copy(alpha = .8f), radius = 2.5.dp.toPx(), center = Offset(size.width * o.x, size.height * o.y))
            }
        }
        Icon(Icons.Outlined.EmojiEvents, null, tint = Color(0xFFFFC53D), modifier = Modifier.size(62.dp))
    }
}

// ------------------------------------------------------------------ dialogs
@Composable
fun ConfirmDialog(title: String, text: String, confirm: String = "Confirm", danger: Boolean = false, onDismiss: () -> Unit, onConfirm: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss, shape = RoundedCornerShape(24.dp), containerColor = Color.White,
        icon = { IconCircle(if (danger) Icons.Outlined.WarningAmber else Icons.AutoMirrored.Outlined.HelpOutline, if (danger) Red else Violet) },
        title = { Text(title, textAlign = TextAlign.Center) }, text = { Text(text, color = Muted, textAlign = TextAlign.Center) },
        confirmButton = { Button(onClick = onConfirm, colors = ButtonDefaults.buttonColors(containerColor = if (danger) Red else Violet), shape = RoundedCornerShape(14.dp)) { Text(confirm) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

/** A back arrow for GradientTopBar. */
@Composable
fun BackIcon(onBack: () -> Unit) {
    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", tint = Color.White) }
}
