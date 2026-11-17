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
import androidx.compose.runtime.Composable
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
import `in`.classnex.app.ui.theme.Amber
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Purple
import `in`.classnex.app.ui.theme.Red
import coil.compose.AsyncImage
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
    when (state) {
        Load.Loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        is Load.Failed -> ErrorView(state.message, onRetry)
        is Load.Ready -> content(state.data)
    }
}

@Composable
fun ErrorView(message: String, onRetry: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Icon(Icons.Outlined.CloudOff, null, Modifier.size(56.dp), tint = Muted)
        Spacer(Modifier.height(12.dp))
        Text(message, textAlign = TextAlign.Center, color = Muted)
        Spacer(Modifier.height(16.dp))
        Button(onClick = onRetry) { Text("Try again") }
    }
}

@Composable
fun EmptyView(text: String, icon: ImageVector = Icons.Outlined.Inbox) {
    Column(Modifier.fillMaxWidth().padding(40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(icon, null, Modifier.size(48.dp), tint = Color(0xFFC3C9D9))
        Spacer(Modifier.height(8.dp))
        Text(text, color = Muted, textAlign = TextAlign.Center)
    }
}

@Composable
fun AppCard(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, content: @Composable () -> Unit) {
    val m = modifier.fillMaxWidth()
    if (onClick != null) {
        Card(onClick = onClick, modifier = m, colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(1.dp), shape = RoundedCornerShape(16.dp)) { Box(Modifier.padding(16.dp)) { content() } }
    } else {
        Card(modifier = m, colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(1.dp), shape = RoundedCornerShape(16.dp)) { Box(Modifier.padding(16.dp)) { content() } }
    }
}

@Composable
fun StatCard(title: String, value: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, sub: String? = null, onClick: (() -> Unit)? = null) {
    AppCard(modifier, onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = .12f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = tint) }
            Spacer(Modifier.padding(start = 12.dp))
            Column {
                Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp, maxLines = 1)
                Text(title, color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (sub != null) Text(sub, fontSize = 11.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

@Composable
fun QuickAction(text: String, icon: ImageVector, tint: Color, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Card(onClick = onClick, modifier = modifier, colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp), elevation = CardDefaults.cardElevation(1.dp)) {
        Column(Modifier.fillMaxWidth().padding(vertical = 14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(46.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = .12f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = tint) }
            Spacer(Modifier.height(6.dp))
            Text(text, fontWeight = FontWeight.Bold, fontSize = 13.sp, textAlign = TextAlign.Center, maxLines = 1)
        }
    }
}

@Composable
fun StatusChip(status: String, text: String? = null) {
    val c = when (status) {
        "active", "paid", "success", "present", "sent", "delivered", "read", "approved" -> Green
        "partial", "pending", "late", "grace", "trial" -> Amber
        "unpaid", "failed", "absent", "expired", "reversed", "overdue", "suspended" -> Red
        "leave", "holiday" -> Purple
        else -> Muted
    }
    Surface(color = c.copy(alpha = .12f), shape = RoundedCornerShape(8.dp)) {
        Text(text ?: label(status), color = c, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp))
    }
}

@Composable
fun Avatar(url: String?, name: String, size: Int = 42) {
    if (url != null) {
        AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(size.dp).clip(CircleShape).background(Color(0xFFEEF1F6)))
    } else {
        Box(Modifier.size(size.dp).clip(CircleShape).background(Blue), contentAlignment = Alignment.Center) {
            Text(name.take(1).uppercase(), color = Color.White, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(text, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp, modifier = modifier.padding(top = 8.dp, bottom = 6.dp))
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

