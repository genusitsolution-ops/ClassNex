package `in`.classnex.app.ui.academy

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.viewModelScope
import coil.compose.AsyncImage
import dagger.hilt.android.lifecycle.HiltViewModel
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.ImageUtils
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.crud.*
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.time.LocalDate
import javax.inject.Inject

/** Generic loader for screens that read one JSON document. */
@HiltViewModel
class JsonVm @Inject constructor(val repo: Repository) : LoadVm<JsonElement>() {
    private var url: String? = null
    fun open(u: String, force: Boolean = false) { if (url == u && !force) return; url = u; load { call { repo.api.getJson(u) }.data ?: JsonNull } }
    fun reload() { url?.let { u -> load(silent = true) { call { repo.api.getJson(u) }.data ?: JsonNull } } }
    val busy = MutableStateFlow(false)
    fun post(u: String, body: JsonObject, then: () -> Unit = {}) = viewModelScope.launch {
        busy.value = true
        try { val r = call { repo.api.postJson(u, body) }; say(r.message.ifBlank { "Saved." }); then() } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
    }
    fun upload(u: String, field: String, uri: Uri) = viewModelScope.launch {
        busy.value = true
        try {
            val f = ImageUtils.compress(repo.copyToCache(uri, "$field.jpg"))
            val r = call { repo.api.postMultipart(u, emptyMap(), MultipartBody.Part.createFormData(field, f.name, f.asRequestBody("image/jpeg".toMediaType()))) }
            say(r.message.ifBlank { "Uploaded." }); reload()
        } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
    }
}

// ================================================================ timetable
private val weekdays = listOf(1 to "Mon", 2 to "Tue", 3 to "Wed", 4 to "Thu", 5 to "Fri", 6 to "Sat", 7 to "Sun")

@Composable
fun TimetableScreen(nav: (String) -> Unit, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.open("timetable") }
    val st by vm.state.collectAsState()
    var day by rememberSaveable { mutableIntStateOf(LocalDate.now().dayOfWeek.value) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(16.dp, 12.dp, 16.dp, 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            weekdays.forEach { (k, t) ->
                val on = k == day
                Column(Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(if (on) Violet else Color.White).pressable({ day = k }).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(t, color = if (on) Color.White else Ink, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                    if (k == LocalDate.now().dayOfWeek.value) Box(Modifier.padding(top = 3.dp).size(5.dp).clip(RoundedCornerShape(50)).background(if (on) Color.White else Violet))
                }
            }
        }
        LoadContent(st, { vm.open("timetable", true) }) { d ->
            val rows = extractList(d).filter { it.n("weekday") == day.toLong() }.sortedBy { it.s("start_time") ?: "" }
            if (rows.isEmpty()) EmptyState("No classes on this day", "Class days and timings come from each batch.", Icons.Outlined.EventAvailable)
            else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                itemsIndexed(rows) { i, r ->
                    Entrance(i) {
                        CnxCard(onClick = { nav("markAttendance?batch=${r.n("batch_id")}") }, padding = 12.dp) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.width(72.dp)) {
                                    Text(prettyTime(r.s("start_time")).ifBlank { "Any time" }, fontWeight = FontWeight.ExtraBold, color = Violet, fontSize = 13.sp)
                                    Text(prettyTime(r.s("end_time")), color = Muted, fontSize = 11.sp)
                                }
                                Box(Modifier.width(3.dp).height(42.dp).clip(RoundedCornerShape(50)).background(VioletSoft))
                                Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                                    Text(r.s("batch_name") ?: "", fontWeight = FontWeight.Bold, color = Ink)
                                    Text(listOfNotNull(r.s("activity_name"), r.s("teacher_name"), r.s("location")).joinToString(" · "), color = Muted, fontSize = 12.sp)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ================================================================ batch students
@Composable
fun BatchStudentsScreen(batchId: Long, nav: (String) -> Unit, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(batchId) { vm.open("batches/$batchId/students") }
    val st by vm.state.collectAsState()
    LoadContent(st, { vm.open("batches/$batchId/students", true) }) { d ->
        val rows = extractList(d)
        if (rows.isEmpty()) EmptyState("No students in this batch", icon = Icons.Outlined.Group)
        else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("${rows.size} student(s)", color = Muted, fontSize = 12.sp) }
            itemsIndexed(rows) { i, r ->
                Entrance(i) { PersonRow(r.s("name") ?: "", listOfNotNull(r.s("student_code"), r.s("start_date")?.let { "since ${prettyDate(it)}" }).joinToString(" · "), r.s("photo_url"), onClick = { nav("student/${r.id()}") }) }
            }
        }
    }
}

// ================================================================ reports
@Composable
fun ReportsListScreen(me: Me, financialOnly: Boolean, nav: (String) -> Unit, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.open("reports") }
    val st by vm.state.collectAsState()
    LoadContent(st, { vm.open("reports", true) }) { d ->
        val rows = extractList(d).filter { r -> (!r.b("financial") || me.can("reports.financial")) && (!financialOnly || r.b("financial")) }
        if (rows.isEmpty()) EmptyState("No reports available", if (!me.has("reports")) "Reports are not included in your plan." else null, Icons.Outlined.Assessment)
        else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            itemsIndexed(rows) { i, r ->
                Entrance(i) {
                    CnxCard(onClick = { nav("report/${r.s("key")}") }, padding = 14.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            IconCircle(if (r.b("financial")) Icons.Outlined.CurrencyRupee else Icons.Outlined.Assessment, if (r.b("financial")) Orange else Violet, 40.dp, 19.dp)
                            Text(r.s("title") ?: label(r.s("key")), fontWeight = FontWeight.Bold, color = Ink, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
                            Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
                        }
                    }
                }
            }
        }
    }
}

// ================================================================ subscription
@Composable
fun SubscriptionScreen(me: Me, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.open("subscription") }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, { vm.open("subscription", true) }) { d ->
        val o = d as? JsonObject ?: JsonObject(emptyMap())
        val cur = o.obj("current")
        val usage = o.obj("usage")?.obj("usage")
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Column(Modifier.fillMaxWidth().clip(Radius.card).background(Brand.drawer).padding(18.dp)) {
                    Text("Current plan", color = Color.White.copy(alpha = .8f), fontSize = 12.sp)
                    Text(cur?.s("package_name") ?: "No plan", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                    Spacer(Modifier.height(6.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Pill(label(cur?.s("status") ?: "none"), Color.White)
                        Spacer(Modifier.width(8.dp))
                        Text(cur?.s("ends_on")?.let { "till ${prettyDate(it)}" + (cur?.s("days_left")?.let { dl -> " · $dl days left" } ?: "") } ?: "", color = Color.White.copy(alpha = .85f), fontSize = 13.sp)
                    }
                }
            }
            usage?.let { u ->
                item {
                    CnxCard {
                        SectionHeader("Usage")
                        listOf("students" to "Students (all branches)", "teachers" to "Teachers", "branches" to "Branches", "batches" to "Batches", "activities" to "Activities", "whatsapp_month" to "WhatsApp this month", "storage_mb" to "Storage (MB)").forEach { (k, t) ->
                            u.obj(k)?.let { x ->
                                val used = x.n("used"); val lim = x.s("limit")?.toLongOrNull()
                                Column(Modifier.padding(vertical = 6.dp)) {
                                    Row { Text(t, color = Ink, fontSize = 13.sp, modifier = Modifier.weight(1f)); Text("$used / ${lim ?: "Unlimited"}", color = Muted, fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
                                    Spacer(Modifier.height(4.dp))
                                    ProgressLine(if (lim != null && lim > 0) used.toFloat() / lim else 0.05f, if (lim != null && used >= lim) Red else Violet)
                                }
                            }
                        }
                    }
                }
            }
            val labels = o.obj("feature_labels")
            o.obj("usage")?.list("features")?.takeIf { it.isNotEmpty() }?.let { feats ->
                item {
                    CnxCard {
                        SectionHeader("Included features")
                        feats.forEach { f -> Row(Modifier.padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Outlined.CheckCircle, null, tint = Green, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)); Text(labels?.s(f) ?: label(f), color = Ink, fontSize = 13.sp) } }
                    }
                }
            }
            item { SectionHeader("Plans") }
            items(o.arr("packages")) { p ->
                CnxCard(padding = 14.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(p.s("name") ?: "", fontWeight = FontWeight.Bold, color = Ink)
                            Text(listOfNotNull(p.s("student_limit")?.let { "Up to $it students" }, when (p.s("whatsapp_mode")) { "api" -> "Automatic WhatsApp"; "manual" -> "Click-to-send WhatsApp"; else -> null }, p.s("description")).joinToString(" · "), color = Muted, fontSize = 12.sp)
                        }
                        Text(money(p.n("price_paise")) + "/" + (if (p.s("billing_cycle") == "yearly") "yr" else "mo"), fontWeight = FontWeight.ExtraBold, color = Violet)
                    }
                    if (p.n("id") == cur?.n("package_id")) Pill("Current plan", Green, Modifier.padding(top = 8.dp))
                }
            }
            if (me.isClassAdmin) item {
                GradientButton("Renew or upgrade online", { openUrl(ctx, BuildConfig.API_BASE_URL.removeSuffix("api/v1/") + "class-admin/#/subscription") }, Modifier.fillMaxWidth(), icon = Icons.AutoMirrored.Outlined.OpenInNew)
                Text("Payment opens securely in your browser (log in with the same account).", color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
}

// ================================================================ academy profile
private val academyForm = FormSpec(
    "Academy profile", listOf(
        FieldSpec("name", "Academy name", required = true),
        FieldSpec("contact_person", "Contact person"),
        FieldSpec("mobile", "Mobile", FType.Phone),
        FieldSpec("whatsapp", "WhatsApp number", FType.Phone),
        FieldSpec("email", "Email", FType.Email),
        FieldSpec("website", "Website"),
        FieldSpec("address", "Address", FType.Area),
        FieldSpec("city", "City"),
        FieldSpec("state", "State"),
        FieldSpec("pincode", "PIN code", FType.Number),
        FieldSpec("billing", "Receipts", FType.Section),
        FieldSpec("gstin", "GSTIN"),
        FieldSpec("business_details", "Business details on receipts", FType.Area),
        FieldSpec("receipt_footer", "Receipt footer", FType.Area),
        FieldSpec("online_admission_enabled", "Online admission form", FType.Switch, help = "Parents can apply using your admission link."),
    ), null, { "academy" }, updatePut = false,
)

@Composable
fun AcademyProfileScreen(me: Me, snack: SnackbarHostState, back: () -> Unit, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.open("academy") }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val logo = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let { vm.upload("academy", "logo", it) } }
    LoadContent(st, { vm.open("academy", true) }) { d ->
        val a = d as? JsonObject ?: return@LoadContent
        Column(Modifier.fillMaxSize()) {
            Row(Modifier.fillMaxWidth().background(Brand.hero).padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(64.dp).clip(RoundedCornerShape(18.dp)).background(Color.White), contentAlignment = Alignment.Center) {
                    a.s("logo_url")?.let { AsyncImage(it, null, Modifier.fillMaxSize()) } ?: Icon(Icons.Outlined.Storefront, null, tint = Violet)
                }
                Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                    Text(a.s("name") ?: "", fontWeight = FontWeight.ExtraBold, color = Ink)
                    Text("Code ${a.s("code") ?: ""}", color = Muted, fontSize = 12.sp)
                }
                if (me.can("settings.manage")) SoftButton(if (busy) "Uploading…" else "Logo", { logo.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, icon = Icons.Outlined.Image)
            }
            a.s("admission_link")?.let { link ->
                Row(Modifier.fillMaxWidth().padding(16.dp, 10.dp, 16.dp, 0.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    SoftButton("Share admission link", { ctx.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, "Apply for admission at ${a.s("name")}: $link"), "Share link")) }, Modifier.weight(1f), Icons.Outlined.Share)
                    SoftButton("Open", { openUrl(ctx, link) }, icon = Icons.AutoMirrored.Outlined.OpenInNew)
                }
            }
            if (me.can("settings.manage")) Box(Modifier.weight(1f)) {
                FormScreen("academy", academyForm, a, snack, onDone = { m -> scope.launch { snack.showSnackbar(m) }; vm.open("academy", true) })
            } else Text("Only the Class Admin can change these details.", color = Muted, modifier = Modifier.padding(16.dp))
        }
    }
}

// ================================================================ reminders & WhatsApp
/**
 * Reminders & WhatsApp – one simple page: send a reminder now, automatic fee reminders ON / OFF
 * (ClassNex default timings), how WhatsApp messages are sent, and the advanced options folded away.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun RemindersScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit, onSaved: () -> Unit = {}, vm: JsonVm = hiltViewModel()) {
    val canSettings = me.isClassAdmin && me.can("settings.manage")
    LaunchedEffect(Unit) { if (canSettings) vm.open("notification-settings") }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    Column(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (me.can("notifications.send")) item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    if (me.has("manual_reminders")) Box(Modifier.weight(1f)) { BigAction("Send fee reminder", "To families with pending fees", Icons.Outlined.NotificationsActive, Orange) { nav("message?type=fee_reminder") } }
                    Box(Modifier.weight(1f)) { BigAction("Send a message", "To a batch or everyone", Icons.AutoMirrored.Outlined.Send, Blue) { nav("message") } }
                }
            }
            if (canSettings) {
                when (val s = st) {
                    is Load.Ready -> (s.data as? JsonObject)?.let { o -> item { SettingsCards(o, me, vm, busy, onSaved) } }
                    is Load.Failed -> item { ErrorView(s.message) { vm.open("notification-settings", true) } }
                    else -> item { Box(Modifier.fillMaxWidth().height(220.dp).clip(Radius.card).shimmer()) }
                }
            }
            item { SectionHeader("History") }
            item {
                CnxCard(padding = 6.dp) {
                    if (me.isClassAdmin && me.can("notifications.send")) LinkRow("Sent messages", Icons.Outlined.Forum) { nav("module/messageLog") }
                    if (me.has("whatsapp_manual") && me.can("notifications.send")) LinkRow("WhatsApp messages you opened", Icons.Outlined.Chat) { nav("waLog") }
                    if (me.can("notifications.send")) LinkRow("Announcements", Icons.Outlined.Campaign) { nav("module/announcements") }
                    LinkRow("My notifications", Icons.Outlined.Notifications) { nav("notifications") }
                }
            }
        }
    }
}

@Composable
private fun BigAction(title: String, sub: String, icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, onClick: () -> Unit) {
    CnxCard(Modifier.heightIn(min = 118.dp), onClick = onClick, padding = 14.dp) {
        IconCircle(icon, tint, 42.dp, 21.dp)
        Spacer(Modifier.height(10.dp))
        Text(title, fontWeight = FontWeight.Bold, color = Ink)
        Text(sub, color = Muted, fontSize = 12.sp)
    }
}

@Composable
fun LinkRow(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).pressable(onClick).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
        IconCircle(icon, Violet, 36.dp, 18.dp)
        Text(text, color = Ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
        Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
    }
}

private fun dayText(off: Int) = when { off < 0 -> "${-off} day${if (off == -1) "" else "s"} before"; off == 0 -> "On the due date"; else -> "$off day${if (off == 1) "" else "s"} after" }

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SettingsCards(o: JsonObject, me: Me, vm: JsonVm, busy: Boolean, onSaved: () -> Unit) {
    val autoAllowed = o.b("auto_reminders")
    val mode0 = o.s("reminder_mode") ?: "default"
    var remOn by remember(o) { mutableStateOf(mode0 != "off") }
    val defaults = o.list("default_reminder_rules").mapNotNull { it.toIntOrNull() }
    val rules = remember(o) { mutableStateListOf<Int>().apply { addAll(o.list("reminder_rules").mapNotNull { it.toIntOrNull() }.ifEmpty { defaults }) } }
    var rulesEdited by remember(o) { mutableStateOf(false) }
    val wa = o.obj("whatsapp")
    var waChoice by remember(o) { mutableStateOf(wa?.s("current") ?: "off") }
    var advanced by remember { mutableStateOf(false) }
    val events = o.obj("events") ?: JsonObject(emptyMap())
    val available = o.obj("available")?.filterValues { (it as? JsonPrimitive)?.booleanOrNull == true }?.keys?.toList() ?: emptyList()
    val matrix = remember(o) { mutableStateMapOf<String, Boolean>().apply { o.obj("matrix")?.forEach { (ev, chs) -> (chs as? JsonObject)?.forEach { (ch, v) -> put("$ev|$ch", (v as? JsonPrimitive)?.booleanOrNull == true) } } } }
    var matrixEdited by remember(o) { mutableStateOf(false) }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // ---- fee reminders: one switch
        CnxCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconCircle(Icons.Outlined.Alarm, Green, 40.dp, 20.dp)
                Text("Fee Reminders", style = MaterialTheme.typography.titleMedium, color = Ink, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
            }
            Spacer(Modifier.height(8.dp))
            if (!autoAllowed) Text("Automatic reminders are not in your plan. You can still send reminders yourself with the button above.", color = Muted, fontSize = 13.sp)
            else {
                Text("Would you like ClassNex to remind parents about pending fees automatically?", color = Ink)
                Row(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).background(if (remOn) Green.copy(alpha = .08f) else Color(0xFFF4F3F8)).pressable({ remOn = !remOn }).padding(12.dp, 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(if (remOn) "Automatic Reminders ON" else "Automatic Reminders OFF", fontWeight = FontWeight.Bold, color = if (remOn) Green else Muted, modifier = Modifier.weight(1f))
                    Switch(remOn, { remOn = it }, colors = SwitchDefaults.colors(checkedTrackColor = Green))
                }
                Text(if (remOn) "ClassNex will automatically remind parents before and after the fee due date." else "Parents will not get automatic fee reminders. You can still send them yourself.", color = Muted, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
                if (remOn && mode0 == "custom" && !rulesEdited) Text("You are using your own reminder days (see Advanced settings).", color = Violet, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
            }
        }
        // ---- WhatsApp: three simple choices
        if (wa != null) CnxCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconCircle(Icons.Outlined.Chat, Green, 40.dp, 20.dp)
                Text("WhatsApp Messages", style = MaterialTheme.typography.titleMedium, color = Ink, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
            }
            Text("How would you like to send WhatsApp messages?", color = Ink, modifier = Modifier.padding(top = 8.dp, bottom = 6.dp))
            wa.arr("options").forEach { op ->
                val key = op.s("key") ?: ""
                val ok = op.b("available")
                val on = waChoice == key
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp)).background(if (on) VioletSoft else Color.White)
                    .border(1.dp, if (on) Violet else Line, RoundedCornerShape(14.dp)).pressable(if (ok) ({ waChoice = key }) else null).padding(10.dp), verticalAlignment = Alignment.Top) {
                    RadioButton(on, { if (ok) waChoice = key }, enabled = ok, colors = RadioButtonDefaults.colors(selectedColor = Violet))
                    Column(Modifier.weight(1f).padding(top = 10.dp)) {
                        Text(op.s("title") ?: "", fontWeight = FontWeight.Bold, color = if (ok) Ink else Muted)
                        Text(op.s("text") ?: "", color = Muted, fontSize = 13.sp)
                        op.s("reason")?.let { Text(it, color = Amber, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 2.dp)) }
                    }
                }
            }
            wa.s("note")?.let { InfoBanner(it, Amber, Icons.Outlined.Info, Modifier.padding(top = 6.dp)) }
        }
        // ---- advanced, folded away
        CnxCard(padding = 6.dp) {
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).pressable({ advanced = !advanced }).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                IconCircle(Icons.Outlined.Tune, Muted, 36.dp, 18.dp)
                Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                    Text("Advanced settings", fontWeight = FontWeight.Bold, color = Ink)
                    Text("Reminder days and which messages go where", color = Muted, fontSize = 12.sp)
                }
                Icon(if (advanced) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, null, tint = Muted)
            }
            androidx.compose.animation.AnimatedVisibility(advanced) {
                Column(Modifier.padding(10.dp)) {
                    if (autoAllowed && remOn) {
                        Text("Reminder days", fontWeight = FontWeight.Bold, color = Ink)
                        Text("ClassNex default: " + defaults.joinToString(", ") { dayText(it) } + ".", color = Muted, fontSize = 12.sp)
                        Spacer(Modifier.height(8.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            listOf(-7, -5, -3, -2, -1, 0, 1, 3, 5, 7, 10, 15).forEach { off ->
                                val on = off in rules
                                FilterChip(on, { if (on) rules.remove(off) else rules.add(off); rulesEdited = true }, label = { Text(dayText(off)) },
                                    colors = FilterChipDefaults.filterChipColors(selectedContainerColor = VioletSoft, selectedLabelColor = Indigo800))
                            }
                        }
                        TextButton(onClick = { rules.clear(); rules.addAll(defaults); rulesEdited = true }) { Text("Use ClassNex default days") }
                        Spacer(Modifier.height(8.dp))
                    }
                    Text("Which messages go where", fontWeight = FontWeight.Bold, color = Ink)
                    events.entries.forEach { (ev, title) ->
                        Text((title as? JsonPrimitive)?.content ?: label(ev), fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp))
                        Row(Modifier.fillMaxWidth()) {
                            available.forEach { ch ->
                                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                                    Switch(matrix["$ev|$ch"] == true, { matrix["$ev|$ch"] = it; matrixEdited = true }, colors = SwitchDefaults.colors(checkedTrackColor = Violet))
                                    Text(when (ch) { "inapp" -> "App inbox"; "whatsapp" -> "WhatsApp"; "push" -> "Phone alert"; else -> label(ch) }, fontSize = 10.sp, color = Muted, maxLines = 1)
                                }
                            }
                        }
                    }
                }
            }
        }
        GradientButton("Save", {
            val body = buildMap<String, JsonElement> {
                if (wa != null && waChoice != wa.s("current")) put("whatsapp_preference", JsonPrimitive(waChoice))
                if (autoAllowed) {
                    when {
                        !remOn -> put("reminder_mode", JsonPrimitive("off"))
                        rulesEdited && rules.sorted() == defaults.sorted() -> put("reminder_mode", JsonPrimitive("default"))
                        rulesEdited -> put("reminder_rules", JsonArray(rules.sorted().map { JsonPrimitive(it) }))
                        mode0 == "off" -> put("reminder_mode", JsonPrimitive("default"))
                    }
                }
                if (matrixEdited) put("matrix", buildJsonObject { events.keys.forEach { ev -> put(ev, buildJsonObject { available.forEach { ch -> put(ch, matrix["$ev|$ch"] == true) } }) } })
            }
            if (body.isEmpty()) vm.say("Nothing changed.") else vm.post("notification-settings", JsonObject(body)) { vm.open("notification-settings", true); onSaved() }
        }, Modifier.fillMaxWidth(), loading = busy, icon = Icons.Outlined.Save)
    }
}

// ================================================================ support ticket
@Composable
fun TicketScreen(id: Long, me: Me, snack: SnackbarHostState, vm: JsonVm = hiltViewModel()) {
    LaunchedEffect(id) { vm.open("support/$id") }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    var text by remember { mutableStateOf("") }
    LoadContent(st, { vm.open("support/$id", true) }) { d ->
        val t = d as? JsonObject ?: return@LoadContent
        Column(Modifier.fillMaxSize().imePadding()) {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    CnxCard {
                        Text(t.s("subject") ?: "", fontWeight = FontWeight.ExtraBold, color = Ink, fontSize = 17.sp)
                        Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) { Pill(label(t.s("status")), statusColor(t.s("status"))); Pill(label(t.s("priority")) + " priority", Muted) }
                    }
                }
                items(t.arr("replies")) { r ->
                    val mine = r.s("name") == me.name
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
                        Column(Modifier.fillMaxWidth(.85f).clip(RoundedCornerShape(18.dp)).background(if (mine) VioletSoft else Color.White).padding(12.dp)) {
                            Text("${r.s("name") ?: ""} · ${roleName(r.s("role"))}", fontSize = 11.sp, color = Violet, fontWeight = FontWeight.Bold)
                            Text(r.s("message") ?: "", color = Ink, fontSize = 14.sp)
                            Text(prettyDate(r.s("created_at")) + " " + prettyTime(r.s("created_at")?.drop(11)), fontSize = 10.sp, color = Muted)
                        }
                    }
                }
            }
            if (t.s("status") != "closed") Surface(color = Color.White, shadowElevation = 12.dp) {
                Row(Modifier.fillMaxWidth().navigationBarsPadding().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    CnxField(text, { text = it }, "Write a reply", Modifier.weight(1f), singleLine = false)
                    Spacer(Modifier.width(8.dp))
                    FilledIconButton(onClick = { vm.post("support/$id/reply", JsonObject(mapOf("message" to JsonPrimitive(text)))) { text = ""; vm.reload() } }, enabled = text.isNotBlank() && !busy,
                        colors = IconButtonDefaults.filledIconButtonColors(containerColor = Violet)) { Icon(Icons.AutoMirrored.Outlined.Send, "Send") }
                }
            }
        }
    }
}

// ================================================================ activities & batches
@Composable
fun StructureScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit) {
    var tab by rememberSaveable { mutableIntStateOf(1) }
    Column(Modifier.fillMaxSize()) {
        SegmentedTabs(listOf("Activities", "Batches"), tab, Modifier.padding(16.dp, 12.dp, 16.dp, 0.dp)) { tab = it }
        val spec = remember(tab, me) { moduleFor(if (tab == 0) "activities" else "batches", me) }
        Box(Modifier.weight(1f)) { spec?.let { CrudListScreen(it, snack, nav) } }
    }
}
