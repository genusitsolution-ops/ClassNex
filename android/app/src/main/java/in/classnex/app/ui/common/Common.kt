package `in`.classnex.app.ui.common

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.data.ApiError
import coil.compose.AsyncImage
import `in`.classnex.app.ui.theme.*
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.widthIn
import androidx.compose.ui.graphics.Brush
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.KeyboardArrowDown
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import java.io.File
import java.text.NumberFormat
import java.time.LocalDate
import java.time.LocalTime
import java.time.format.DateTimeFormatter
import java.util.Locale

// ------------------------------------------------------------ formatting
private val inr: NumberFormat = NumberFormat.getNumberInstance(Locale("en", "IN")).apply { maximumFractionDigits = 2; minimumFractionDigits = 0 }
fun money(paise: Long): String = "₹" + inr.format(paise / 100.0)
fun rupeesInput(paise: Long): String = if (paise % 100 == 0L) (paise / 100).toString() else "%.2f".format(paise / 100.0)
fun prettyDate(s: String?): String = runCatching { LocalDate.parse(s!!.take(10)).format(DateTimeFormatter.ofPattern("dd MMM yyyy")) }.getOrDefault(s ?: "–")
fun prettyTime(s: String?): String = runCatching { LocalTime.parse(s!!.take(5)).format(DateTimeFormatter.ofPattern("h:mm a")) }.getOrDefault("")
fun today(): String = LocalDate.now().toString()
fun label(s: String?): String = (s ?: "").replace('_', ' ').split(' ').joinToString(" ") { it.replaceFirstChar(Char::uppercase) }

// ---------------------------------------------------------------- state
sealed interface Load<out T> {
    data object Loading : Load<Nothing>
    data class Ready<T>(val data: T) : Load<T>
    data class Failed(val message: String) : Load<Nothing>
}

/** Base ViewModel: loads data into a StateFlow and publishes one-off messages (snackbars). */
abstract class LoadVm<T> : ViewModel() {
    protected val _state = MutableStateFlow<Load<T>>(Load.Loading)
    val state: StateFlow<Load<T>> = _state
    val messages = MutableSharedFlow<String>(extraBufferCapacity = 4)

    fun load(silent: Boolean = false, block: suspend () -> T) {
        if (!silent) _state.value = Load.Loading
        viewModelScope.launch {
            _state.value = try { Load.Ready(block()) } catch (e: Exception) { Load.Failed(e.message ?: "Could not load") }
        }
    }
    fun say(msg: String) { messages.tryEmit(msg) }
    fun errorText(e: Throwable): String = (e as? ApiError)?.let { err -> err.fieldErrors.values.firstOrNull() ?: err.message } ?: (e.message ?: "Something went wrong")
}

@Composable
fun <T> LoadContent(state: Load<T>, onRetry: () -> Unit, content: @Composable (T) -> Unit) {
    // skeleton while loading, friendly error with retry, then the content fades in
    androidx.compose.animation.Crossfade(state is Load.Loading, label = "load") { loading ->
        if (loading) SkeletonList()
        else when (state) {
            is Load.Failed -> ErrorView(state.message, onRetry)
            is Load.Ready -> content(state.data)
            else -> SkeletonList()
        }
    }
}

@Composable
fun ErrorView(message: String, onRetry: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        IconCircle(Icons.Outlined.CloudOff, Red, 72.dp, 34.dp)
        Spacer(Modifier.height(14.dp))
        Text(message, textAlign = TextAlign.Center, color = Muted)
        Spacer(Modifier.height(18.dp))
        GradientButton("Try again", onRetry, Modifier.widthIn(min = 180.dp), icon = Icons.Outlined.Refresh)
    }
}

@Composable
fun EmptyView(text: String, icon: ImageVector = Icons.Outlined.Inbox) = EmptyState(text, icon = icon)

@Composable
fun AppCard(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, content: @Composable () -> Unit) {
    CnxCard(modifier, onClick) { content() }
}

@Composable
fun StatCard(title: String, value: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, sub: String? = null, onClick: (() -> Unit)? = null) {
    StatTile(title, value, icon, tint, modifier, sub, onClick)
}

@Composable
fun QuickAction(text: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).pressable(onClick).padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(54.dp).clip(RoundedCornerShape(18.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(18.dp)), contentAlignment = Alignment.Center) {
            IconCircle(icon, tint, 40.dp, 21.dp)
        }
        Spacer(Modifier.height(6.dp))
        Text(text, fontWeight = FontWeight.SemiBold, fontSize = 12.sp, textAlign = TextAlign.Center, maxLines = 2, color = Ink, lineHeight = 14.sp)
    }
}

@Composable
fun StatusChip(status: String, text: String? = null) = Pill(text ?: label(status), statusColor(status))

@Composable
fun Avatar(url: String?, name: String, size: Int = 42) {
    if (url != null) {
        AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(size.dp).clip(CircleShape).background(VioletSoft))
    } else {
        val palette = listOf(listOf(Color(0xFF7C4DFF), Color(0xFF4F2BD8)), listOf(Color(0xFF3B82F6), Color(0xFF2563EB)), listOf(Color(0xFFEC4899), Color(0xFFBE185D)), listOf(Color(0xFF14B8A6), Color(0xFF0F766E)), listOf(Color(0xFFF59E0B), Color(0xFFD97706)))
        val g = palette[(name.firstOrNull()?.code ?: 0) % palette.size]
        Box(Modifier.size(size.dp).clip(CircleShape).background(Brush.linearGradient(g)), contentAlignment = Alignment.Center) {
            Text(name.split(' ').filter { it.isNotBlank() }.take(2).joinToString("") { it.take(1) }.uppercase().ifBlank { "?" }, color = Color.White, fontWeight = FontWeight.Bold, fontSize = (size / 2.8).sp)
        }
    }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(text, style = MaterialTheme.typography.titleMedium, color = Ink, modifier = modifier.padding(top = 8.dp, bottom = 6.dp))
}

@Composable
fun KeyValue(k: String, v: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(k, color = Muted, modifier = Modifier.weight(.4f), fontSize = 14.sp)
        Text(v, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(.6f), fontSize = 14.sp)
    }
}

fun Modifier.clickableIf(on: Boolean, f: () -> Unit) = if (on) this.clickable(onClick = f) else this

// --------------------------------------------------------------- intents
fun openFile(context: Context, file: File, mime: String = "application/pdf") {
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val i = Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    context.startActivity(Intent.createChooser(i, "Open receipt"))
}

fun shareFile(context: Context, file: File, text: String, mime: String = "application/pdf") {
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val i = Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri).putExtra(Intent.EXTRA_TEXT, text).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    context.startActivity(Intent.createChooser(i, "Share receipt"))
}

fun openUrl(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
}

// ------------------------------------------------------------- shared bits
/** Business names of the roles (keys stay as stored: "owner" = Class Admin). */
fun roleName(role: String?): String = when (role) {
    "owner" -> "Class Admin"
    "super_admin" -> "ClassNex Super Admin"
    else -> label(role)
}

fun pct(v: Double?): String = if (v == null) "–" else if (v % 1.0 == 0.0) "${v.toInt()}%" else "$v%"

fun mimeFor(format: String): String = when (format) {
    "pdf" -> "application/pdf"
    "xlsx" -> "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    else -> "text/csv"
}

/** Simple dropdown: options are (value, text); a null value means "all" / "none". */
@Composable
fun OptionPicker(label: String, options: List<Pair<String?, String>>, selected: String?, modifier: Modifier = Modifier, onSelect: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box(modifier) {
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp), border = androidx.compose.foundation.BorderStroke(1.dp, Line)) {
            Text(options.firstOrNull { it.first == selected }?.second ?: label, maxLines = 1, overflow = TextOverflow.Ellipsis, color = Ink, modifier = Modifier.weight(1f))
            Icon(Icons.Outlined.KeyboardArrowDown, null, tint = Muted)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { (v, t) -> DropdownMenuItem(text = { Text(t) }, onClick = { open = false; onSelect(v) }) }
        }
    }
}

@Composable
fun MiniStat(title: String, value: String, modifier: Modifier = Modifier, color: Color = Color.Unspecified) {
    AppCard(modifier) {
        Column {
            Text(title, color = Muted, fontSize = 11.sp, maxLines = 1)
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, color = color, maxLines = 1)
        }
    }
}
