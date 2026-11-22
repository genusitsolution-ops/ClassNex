package `in`.classnex.app.ui.academy

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
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
import dagger.hilt.android.lifecycle.HiltViewModel
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.*
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.crud.FormBus
import `in`.classnex.app.ui.crud.extractList
import `in`.classnex.app.ui.crud.s
import `in`.classnex.app.ui.crud.n
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import javax.inject.Inject

// ================================================================ dashboard
@HiltViewModel
class DashboardVm @Inject constructor(private val repo: Repository) : LoadVm<Dashboard>() {
    val branches = MutableStateFlow<List<Branch>>(emptyList())
    var branch by mutableStateOf<Long?>(null)
    private var started = false
    fun start(me: Me) {
        if (started) return
        started = true
        if (me.isClassAdmin) viewModelScope.launch { branches.value = runCatching { call { repo.api.branches("active") }.data }.getOrNull() ?: emptyList() }
        refresh()
    }
    fun choose(b: Long?) { branch = b; refresh() }
    fun refresh(silent: Boolean = false) = load(silent) { call { repo.api.dashboard(branch) }.data!! }
}

private data class Act(val text: String, val icon: androidx.compose.ui.graphics.vector.ImageVector, val tint: Color, val route: String)

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun DashboardScreen(me: Me, nav: (String) -> Unit, vm: DashboardVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.start(me) }
    val st by vm.state.collectAsState()
    val branches by vm.branches.collectAsState()
    LoadContent(st, { vm.refresh() }) { d ->
        LazyColumn(contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // ---- greeting + branch selector
            item {
                Entrance(0) {
                    Column(Modifier.fillMaxWidth().clip(Radius.card).background(Brand.drawer).padding(18.dp)) {
                        Text("Hello, ${me.name.substringBefore(' ')} 👋", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                        Text(me.academy?.name ?: "", color = Color.White.copy(alpha = .85f), fontWeight = FontWeight.SemiBold)
                        Text(LocalDate.now().format(DateTimeFormatter.ofPattern("EEEE, dd MMM yyyy")), color = Color.White.copy(alpha = .7f), fontSize = 12.sp)
                        if (me.isClassAdmin && branches.size > 0) {
                            Spacer(Modifier.height(12.dp))
                            var open by remember { mutableStateOf(false) }
                            Box {
                                Row(Modifier.clip(RoundedCornerShape(50)).background(Color.White.copy(alpha = .18f)).clickable { open = true }.padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Outlined.Store, null, tint = Color.White, modifier = Modifier.size(18.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Text(branches.firstOrNull { it.id == vm.branch }?.name ?: "All branches", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                                    Icon(Icons.Outlined.KeyboardArrowDown, null, tint = Color.White)
                                }
                                DropdownMenu(open, { open = false }) {
                                    DropdownMenuItem(text = { Text("All branches") }, onClick = { open = false; vm.choose(null) })
                                    branches.forEach { b -> DropdownMenuItem(text = { Text(b.name) }, onClick = { open = false; vm.choose(b.id) }) }
                                }
                            }
                        }
                    }
                }
            }
            val sub = d.subscription ?: me.subscription
            if (sub != null && sub.status in listOf("expired", "grace", "suspended")) {
                item { InfoBanner(if (sub.status == "grace") "Your plan expired on ${prettyDate(sub.ends_on)}. Renew soon to avoid interruption." else "Your subscription is ${sub.status}. Your data is safe; changes are disabled until renewal.", Red, Icons.Outlined.WarningAmber) }
            } else if (sub != null && sub.status == "trial" && (sub.days_left ?: 99) <= 5) {
                item { InfoBanner("Trial ends in ${sub.days_left} day(s). Choose a plan in Subscription.", Amber, Icons.Outlined.HourglassTop) }
            }

            if (d.role == "teacher") {
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        StatTile("Today's classes", d.todays_classes.size.toString(), Icons.Outlined.CalendarMonth, Green, Modifier.weight(1f), if (d.attendance_pending > 0) "${d.attendance_pending} not marked" else "All marked") { nav("markAttendance?batch=0") }
                        StatTile("My students", d.total_students.toString(), Icons.Outlined.Group, Violet, Modifier.weight(1f)) { nav("students") }
                        StatTile("My batches", d.assigned_batches.toString(), Icons.Outlined.Groups, Blue, Modifier.weight(1f)) { nav("module/batches") }
                    }
                }
                item { SectionHeader("Today's classes", action = "Timetable") { nav("timetable") } }
                if (d.todays_classes.isEmpty()) item { EmptyState("No classes scheduled today", icon = Icons.Outlined.EventAvailable) }
                itemsIndexed(d.todays_classes) { i, c ->
                    Entrance(i) {
                        CnxCard(onClick = { nav("markAttendance?batch=${c.id}") }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconCircle(Icons.Outlined.Schedule, if (c.marked) Green else Amber)
                                Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                                    Text(c.name, fontWeight = FontWeight.Bold, color = Ink)
                                    Text("${c.activity_name} · ${prettyTime(c.start_time)} – ${prettyTime(c.end_time)}", color = Muted, fontSize = 12.sp)
                                }
                                if (c.marked) Pill("Marked", Green) else Pill("Mark now", Violet, filled = true)
                            }
                        }
                    }
                }
                return@LazyColumn
            }

            // ---- totals
            item {
                val tiles = buildList {
                    add(Act("Students", Icons.Outlined.Group, Violet, "students") to "${d.active_students}")
                    if (me.isClassAdmin) add(Act("Teachers", Icons.Outlined.Badge, Blue, "module/teachers") to "${d.total_teachers}")
                    if (me.isClassAdmin) add(Act("Branches", Icons.Outlined.Store, Teal, "branches") to "${d.total_branches}")
                    add(Act("Activities", Icons.Outlined.Interests, Pink, "module/activities") to "${d.total_activities}")
                    add(Act("Batches", Icons.Outlined.Groups, Orange, "module/batches") to "${d.total_batches}")
                    if (me.has("online_admission") && me.can("admissions.manage")) add(Act("Admissions", Icons.Outlined.AssignmentInd, Amber, "module/admissions") to "${d.pending_online_admissions}")
                }
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    tiles.chunked(3).forEachIndexed { ri, row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            row.forEachIndexed { ci, (a, v) ->
                                Box(Modifier.weight(1f)) {
                                    Entrance(ri * 3 + ci + 1) {
                                        StatTile(a.text, v, a.icon, a.tint, sub = when (a.text) { "Students" -> d.student_quota?.limit?.let { "of $it" } ?: "+${d.new_admissions} this month"; "Admissions" -> "pending"; else -> null }, onClick = { nav(a.route) })
                                    }
                                }
                            }
                            repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }

            // ---- today's attendance
            if (me.has("attendance")) item {
                val t = d.today_attendance
                val presentOnly = (t.present - t.late).coerceAtLeast(0)
                CnxCard(onClick = { nav("markAttendance?batch=0") }) {
                    SectionHeader("Today's Attendance", action = "Mark", onAction = { nav("markAttendance?batch=0") })
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        DonutChart(
                            listOf(presentOnly.toFloat() to Green, t.late.toFloat() to Amber, t.absent.toFloat() to Red, t.leave.toFloat() to Violet),
                            if (t.marked > 0) "${(t.present * 100 / t.marked)}%" else "–", if (t.marked > 0) "present" else "not marked",
                        )
                        Spacer(Modifier.width(18.dp))
                        Column(Modifier.weight(1f)) {
                            LegendDot("Present", Green, "$presentOnly")
                            LegendDot("Late", Amber, "${t.late}")
                            LegendDot("Absent", Red, "${t.absent}")
                            LegendDot("On leave", Violet, "${t.leave}")
                            if (d.batches_not_marked > 0) Text("${d.batches_not_marked} batch(es) not marked yet", color = Amber, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                        }
                    }
                }
            }

            // ---- fees
            if (me.has("fees") && me.can("fees.view")) item {
                CnxCard(onClick = { nav("payments") }) {
                    SectionHeader("Fee Collection – This Month", action = "Payments", onAction = { nav("payments") })
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(money(d.month_collection_paise), fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, color = Ink, modifier = Modifier.weight(1f, fill = false))
                        Spacer(Modifier.width(10.dp))
                        if (d.last_month_collection_paise > 0) {
                            val ch = ((d.month_collection_paise - d.last_month_collection_paise) * 100 / d.last_month_collection_paise)
                            Pill((if (ch >= 0) "▲ +" else "▼ ") + "$ch% vs last month", if (ch >= 0) Green else Red)
                        }
                    }
                    Text("Today ${money(d.today_collection_paise)}", color = Muted, fontSize = 12.sp)
                    if (d.collection_trend.isNotEmpty()) {
                        Spacer(Modifier.height(12.dp))
                        BarChart(d.collection_trend.map { it.total }, d.collection_trend.map { runCatching { YearMonth.parse(it.month).format(DateTimeFormatter.ofPattern("MMM")) }.getOrDefault(it.month) })
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        MiniFig("Pending", money(d.pending_fees_paise), Amber, Modifier.weight(1f)) { nav("fees") }
                        MiniFig("Overdue", money(d.overdue_fees_paise), Red, Modifier.weight(1f), "${d.overdue_students} students") { nav("fees?tab=1") }
                    }
                }
            }

            // ---- quick actions
            item {
                val acts = buildList {
                    if (me.can("students.manage")) add(Act("Add Student", Icons.Outlined.PersonAdd, Violet, "addStudent"))
                    if (me.has("attendance") && me.can("attendance.mark")) add(Act("Attendance", Icons.Outlined.HowToReg, Green, "markAttendance?batch=0"))
                    if (me.has("fees") && me.can("fees.collect")) add(Act("Collect Fee", Icons.Outlined.CurrencyRupee, Orange, "collect/0"))
                    if (me.has("manual_reminders") && me.can("notifications.send")) add(Act("Reminders", Icons.Outlined.NotificationsActive, Teal, "message"))
                    if (me.has("attendance")) add(Act("History", Icons.Outlined.History, Blue, "history"))
                    if (me.can("reports.view") && me.has("reports")) add(Act("Reports", Icons.Outlined.Assessment, Pink, "reports"))
                    if (me.can("notifications.send")) add(Act("Announce", Icons.Outlined.Campaign, Amber, "module/announcements"))
                    add(Act("Timetable", Icons.Outlined.CalendarViewWeek, Indigo600, "timetable"))
                }
                CnxCard {
                    SectionHeader("Quick Actions")
                    Spacer(Modifier.height(6.dp))
                    acts.chunked(4).forEach { row ->
                        Row {
                            row.forEach { a -> QuickAction(a.text, a.icon, a.tint, Modifier.weight(1f)) { nav(a.route) } }
                            repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }

            if (me.has("fees") && me.can("fees.view")) {
                item { SectionHeader("Upcoming dues · next 7 days", action = if (d.upcoming_dues.isNotEmpty()) "All" else null) { nav("fees") } }
                if (d.upcoming_dues.isEmpty()) item { CnxCard { Text("No dues in the next 7 days 🎉", color = Muted) } }
                items(d.upcoming_dues, key = { "due" + it.id }) { u ->
                    PersonRow(u.student_name, "${u.title} · due ${prettyDate(u.due_date)}", onClick = { if (me.can("fees.collect")) nav("collect/${u.student_id}") }, trailing = { Text(money(u.balance_paise), fontWeight = FontWeight.ExtraBold, color = Ink) })
                }
            }
            if (d.scheduled_messages.isNotEmpty()) {
                item { SectionHeader("Scheduled reminders") }
                items(d.scheduled_messages) { m ->
                    CnxCard(padding = 12.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            IconCircle(Icons.Outlined.Alarm, Teal, 38.dp, 18.dp)
                            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                Text(m.recipient_name ?: label(m.event_key), fontWeight = FontWeight.Bold, color = Ink, maxLines = 1)
                                Text("${label(m.channel)} · ${prettyDate(m.next_attempt_at)} ${prettyTime(m.next_attempt_at?.drop(11))}", color = Muted, fontSize = 12.sp)
                            }
                            Pill("Scheduled", Teal)
                        }
                    }
                }
            }
            if (me.has("fees") && me.can("fees.view") && d.recent_payments.isNotEmpty()) {
                item { SectionHeader("Recent payments", action = "View all") { nav("payments") } }
                items(d.recent_payments, key = { "pay" + it.id }) { p ->
                    PersonRow(p.student_name ?: "", "${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}", trailing = {
                        Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, color = if (p.status == "reversed") Muted else Green)
                    })
                }
            }
            if (d.recent_notifications.isNotEmpty()) {
                // latest outgoing messages; the queue has no ids here, so no item keys (avoids duplicate keys)
                item { SectionHeader("Recent messages", action = if (me.isClassAdmin) "Log" else null) { nav("module/messageLog") } }
                items(d.recent_notifications.take(6)) { n ->
                    CnxCard(padding = 12.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            IconCircle(Icons.Outlined.Forum, Teal, 38.dp, 18.dp)
                            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                Text(n.recipient_name ?: "–", fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text("${label(n.event_key)} · ${label(n.channel)} · ${prettyDate(n.created_at)}", color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                            Pill(when (n.status) { "pending" -> "Queued"; "processing" -> "Sending"; else -> label(n.status) }, statusColor(n.status))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MiniFig(title: String, value: String, color: Color, modifier: Modifier = Modifier, sub: String? = null, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = .08f)).clickable(onClick = onClick).padding(12.dp)) {
        Text(title, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Text(value, color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        if (sub != null) Text(sub, color = Muted, fontSize = 11.sp)
    }
}

// ================================================================= students
@HiltViewModel
class StudentsVm @Inject constructor(private val repo: Repository) : LoadVm<List<StudentRow>>() {
    private var job: Job? = null
    var query = ""
    private var branchFilter: String? = null
    val branches = MutableStateFlow<List<Branch>>(emptyList())
    init {
        viewModelScope.launch { branches.value = runCatching { call { repo.api.branches() }.data }.getOrNull() ?: emptyList() }
    }
    fun start(b: Long?) { branchFilter = b?.toString(); search(query) }
    fun chooseBranch(b: String?) { branchFilter = b; search(query) }
    fun search(q: String) {
        query = q
        job?.cancel()
        job = viewModelScope.launch {
            if (q.isNotEmpty()) delay(300)
            load(silent = q.isNotEmpty()) { call { repo.api.students(q.ifBlank { null }, perPage = 100, branchId = branchFilter) }.data ?: emptyList() }
        }
    }
}

@Composable
fun StudentsScreen(me: Me, nav: (String) -> Unit, pickMode: Boolean = false, onPick: ((StudentRow) -> Unit)? = null, branchId: Long? = null, vm: StudentsVm = hiltViewModel()) {
    LaunchedEffect(branchId) { vm.start(branchId) }
    val st by vm.state.collectAsState()
    val branches by vm.branches.collectAsState()
    var q by remember { mutableStateOf(vm.query) }
    var br by remember { mutableStateOf(branchId?.toString()) }
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.padding(16.dp, 12.dp, 16.dp, 6.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                SearchBox(q, { q = it; vm.search(it) }, "Search name, Student ID or phone")
                if (branches.isNotEmpty() && branchId == null) {
                    ChoiceChips(listOf("" to "All branches") + branches.map { it.id.toString() to it.name } + listOf("none" to "No branch"), br ?: "") { k -> br = k.ifEmpty { null }; vm.chooseBranch(br) }
                }
            }
            LoadContent(st, { vm.search(q) }) { rows ->
                if (rows.isEmpty()) EmptyState(if (q.isBlank()) "No students yet" else "No match for \"$q\"", if (q.isBlank() && me.can("students.manage")) "Add your first student – it takes less than a minute." else null, Icons.Outlined.Group,
                    if (q.isBlank() && !pickMode && me.can("students.manage")) "Add student" else null) { nav("addStudent") }
                else LazyColumn(contentPadding = PaddingValues(16.dp, 4.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    item { Text("${rows.size}${if (rows.size >= 100) "+" else ""} student(s)", color = Muted, fontSize = 12.sp) }
                    itemsIndexed(rows, key = { _, s -> s.id }) { i, s ->
                        Entrance(i) {
                            PersonRow(s.name, "${s.student_code} · ${s.enrolled_in ?: "Not enrolled"}${s.branch_name?.let { " · $it" } ?: ""}", s.photo_url,
                                onClick = { if (pickMode) onPick?.invoke(s) else nav("student/${s.id}") },
                                trailing = {
                                    Column(horizontalAlignment = Alignment.End) {
                                        if (s.status != "active") Pill(label(s.status), statusColor(s.status))
                                        if (s.pending_paise > 0 && me.can("fees.view")) Text(money(s.pending_paise), color = Red, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                                        else if (!pickMode) Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
                                    }
                                })
                        }
                    }
                }
            }
        }
        if (!pickMode && me.can("students.manage")) ExtendedFloatingActionButton(
            onClick = { nav("addStudent") }, icon = { Icon(Icons.Outlined.PersonAdd, null) }, text = { Text("Add student") },
            containerColor = Violet, contentColor = Color.White, shape = RoundedCornerShape(18.dp), modifier = Modifier.align(Alignment.BottomEnd).padding(18.dp),
        )
    }
}

// ----------------------------------------------------------- student detail
data class StudentPage(val s: StudentDetail, val dues: Dues?)

@HiltViewModel
class StudentDetailVm @Inject constructor(private val repo: Repository) : LoadVm<StudentPage>() {
    var sid = 0L
    val branches = MutableStateFlow<List<Branch>>(emptyList())
    val batches = MutableStateFlow<List<Batch>>(emptyList())
    val att = MutableStateFlow<AttMonth?>(null)
    var month by mutableStateOf(YearMonth.now())
    val docs = MutableStateFlow<List<JsonObject>?>(null)
    val busy = MutableStateFlow(false)

    fun loadLookups() = viewModelScope.launch {
        branches.value = runCatching { call { repo.api.branches("active") }.data }.getOrNull() ?: emptyList()
        batches.value = runCatching { call { repo.api.batches() }.data }.getOrNull() ?: emptyList()
    }
    fun changeBranch(branchId: Long, batchId: Long?) = viewModelScope.launch {
        try { val r = call { repo.api.changeBranch(sid, ChangeBranch(branchId, batchId)) }; say(r.message); open(sid, true) } catch (e: Exception) { say(errorText(e)) }
    }
    fun transfer(batchId: Long) = viewModelScope.launch {
        try {
            val r = call { repo.api.postJson("students/$sid/transfer", JsonObject(mapOf("batch_id" to kotlinx.serialization.json.JsonPrimitive(batchId)))) }
            say(r.message); open(sid, true)
        } catch (e: Exception) { say(errorText(e)) }
    }
    fun open(id: Long, silent: Boolean = false) = load(silent) {
        sid = id
        val s = call { repo.api.student(id) }.data!!
        val d = runCatching { call { repo.api.dues(id) }.data }.getOrNull()
        StudentPage(s, d)
    }
    fun loadAttendance() = viewModelScope.launch { att.value = runCatching { call { repo.api.studentAttendance(sid, month.toString()) }.data }.getOrNull() }
    fun shiftMonth(by: Long) { month = month.plusMonths(by); loadAttendance() }
    fun loadDocs() = viewModelScope.launch { docs.value = runCatching { extractList(call { repo.api.getJson("students/$sid/documents") }.data) }.getOrDefault(emptyList()) }
    suspend fun editRow(): JsonObject? = runCatching { call { repo.api.getJson("students/$sid") }.data as? JsonObject }.getOrNull()

    fun uploadPhoto(uri: Uri) = viewModelScope.launch {
        busy.value = true
        try {
            val f = `in`.classnex.app.data.ImageUtils.compress(repo.copyToCache(uri, "photo-$sid.jpg"))
            val part = MultipartBody.Part.createFormData("photo", f.name, f.asRequestBody("image/jpeg".toMediaType()))
            val r = call { repo.api.postMultipart("students/$sid", emptyMap(), part) }
            say(r.message.ifBlank { "Photo updated." }); open(sid, true)
        } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
    }
    fun uploadDoc(uri: Uri, type: String, title: String, mime: String?) = viewModelScope.launch {
        busy.value = true
        try {
            val ext = if (mime == "application/pdf") "pdf" else "jpg"
            val f = repo.copyToCache(uri, "doc-$sid.$ext")
            val part = MultipartBody.Part.createFormData("file", f.name, f.asRequestBody((mime ?: "image/jpeg").toMediaType()))
            val fields = mapOf("doc_type" to type.toRequestBody(), "title" to title.toRequestBody())
            val r = call { repo.api.uploadDocument(sid, fields, part) }
            say(r.message); loadDocs()
        } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
    }
    fun deleteDoc(id: Long) = viewModelScope.launch {
        try { val r = call { repo.api.deleteJson("documents/$id") }; say(r.message); loadDocs() } catch (e: Exception) { say(errorText(e)) }
    }
    suspend fun docFile(d: JsonObject) = repo.download("files/document/${d.n("id")}?download=1", "document-${d.n("id")}." + (d.s("original_name")?.substringAfterLast('.', "bin") ?: "bin"))
    suspend fun receipt(p: PaymentRow) = repo.download("receipts/${p.receipt_id}/pdf?download=1", (p.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun StudentDetailScreen(id: Long, me: Me, nav: (String) -> Unit, snack: SnackbarHostState? = null, vm: StudentDetailVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    LaunchedEffect(id) { vm.open(id); vm.loadLookups() }
    LaunchedEffect(Unit) { vm.messages.collect { snack?.showSnackbar(it) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack?.showSnackbar(it) } }
    val version by FormBus.version.collectAsState()
    LaunchedEffect(version) { if (version > 0 && vm.sid == id) vm.open(id, true) }
    val st by vm.state.collectAsState()
    val branches by vm.branches.collectAsState()
    val allBatches by vm.batches.collectAsState()
    val busy by vm.busy.collectAsState()
    var moveOpen by remember { mutableStateOf(false) }
    var transferOpen by remember { mutableStateOf(false) }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val photo = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let { vm.uploadPhoto(it) } }
    WhatsAppSendDialog(wa)
    val canEdit = me.can("students.manage")
    val tabs = buildList {
        add("Overview"); if (me.has("attendance")) add("Attendance"); if (me.has("fees") && me.can("fees.view")) add("Fees"); add("Batches")
        if (me.has("documents") && me.can("documents.manage")) add("Documents")
    }
    LoadContent(st, { vm.open(id) }) { (s, dues) ->
        LazyColumn(contentPadding = PaddingValues(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Column(Modifier.fillMaxWidth().background(Brand.hero).padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box {
                        Avatar(s.photo_url, s.name, 92)
                        if (canEdit) Box(Modifier.align(Alignment.BottomEnd).size(32.dp).clip(CircleShape).background(Violet).clickable { photo.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, contentAlignment = Alignment.Center) {
                            if (busy) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp) else Icon(Icons.Outlined.PhotoCamera, "Change photo", tint = Color.White, modifier = Modifier.size(16.dp))
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(s.name, fontSize = 21.sp, fontWeight = FontWeight.ExtraBold, color = Ink)
                    Text("ID ${s.student_code}" + (s.branch_name?.let { " · $it branch" } ?: ""), color = Muted)
                    Spacer(Modifier.height(6.dp))
                    Pill(label(s.status), statusColor(s.status))
                    Spacer(Modifier.height(14.dp))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                        QuickAction("Call", Icons.Outlined.Call, Green, Modifier.weight(1f)) { openUrl(ctx, "tel:${s.country_code}${s.whatsapp}") }
                        if (me.has("whatsapp_manual") && me.can("notifications.send")) QuickAction("WhatsApp", Icons.Outlined.Chat, Green, Modifier.weight(1f)) {
                            wa.prepare(ManualPrepare(purpose = if ((s.balance?.outstanding_paise ?: 0) > 0) "fee_reminder" else "custom", target = "students", student_ids = listOf(s.id), student_id = s.id))
                        } else if (me.whatsapp_mode != "off") QuickAction("WhatsApp", Icons.Outlined.Chat, Green, Modifier.weight(1f)) { openUrl(ctx, "https://wa.me/${s.country_code.trimStart('+')}${s.whatsapp}") }
                        if (me.has("fees") && me.can("fees.collect")) QuickAction("Collect", Icons.Outlined.CurrencyRupee, Orange, Modifier.weight(1f)) { nav("collect/${s.id}") }
                        if (canEdit) QuickAction("Edit", Icons.Outlined.Edit, Violet, Modifier.weight(1f)) {
                            scope.launch { vm.editRow()?.let { FormBus.row = it; nav("form/studentEdit?id=${s.id}") } ?: snack?.showSnackbar("Could not open the student") }
                        }
                        if (me.can("notifications.send")) QuickAction("Message", Icons.AutoMirrored.Outlined.Send, Blue, Modifier.weight(1f)) { nav("message?student=${s.id}") }
                    }
                }
            }
            item {
                ScrollableTabRow(tab.coerceAtMost(tabs.lastIndex), edgePadding = 16.dp, containerColor = Color.Transparent, contentColor = Violet, divider = {}) {
                    tabs.forEachIndexed { i, t -> Tab(tab == i, { tab = i }, text = { Text(t, fontWeight = if (tab == i) FontWeight.Bold else FontWeight.Medium) }) }
                }
            }
            when (tabs.getOrNull(tab)) {
                "Overview" -> {
                    s.balance?.takeIf { me.has("fees") && me.can("fees.view") }?.let { b ->
                        item { BalanceRow(b, Modifier.padding(horizontal = 16.dp)) }
                    }
                    item {
                        CnxCard(Modifier.padding(horizontal = 16.dp)) {
                            SectionHeader("Personal details")
                            KeyRow("WhatsApp", "${s.country_code} ${s.whatsapp}")
                            KeyRow("Guardian", s.guardian_name)
                            KeyRow("Date of birth", s.dob?.let(::prettyDate))
                            KeyRow("Gender", s.gender?.let(::label))
                            KeyRow("Email", s.email)
                            KeyRow("Address", s.address)
                            KeyRow("Admission date", s.admission_date?.let(::prettyDate))
                        }
                    }
                    item {
                        CnxCard(Modifier.padding(horizontal = 16.dp)) {
                            SectionHeader("Parents & guardians", action = if (canEdit) "Add" else null) { nav("form/studentParent?id=0&parent=${s.id}") }
                            if (s.parents.isEmpty()) Text("No parent added.", color = Muted, fontSize = 13.sp)
                            s.parents.forEach { p ->
                                Row(Modifier.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Avatar(null, p.name, 36)
                                    Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                        Text(p.name + (p.relation?.let { " ($it)" } ?: ""), fontWeight = FontWeight.SemiBold, color = Ink)
                                        Text("${p.country_code} ${p.whatsapp}", color = Muted, fontSize = 12.sp)
                                    }
                                    Pill(if (p.login_status == null) "No app login" else "App: " + label(p.login_status), statusColor(p.login_status))
                                }
                            }
                        }
                    }
                }
                "Attendance" -> {
                    item { LaunchedEffect(vm.month) { vm.loadAttendance() } }
                    item {
                        val a by vm.att.collectAsState()
                        CnxCard(Modifier.padding(horizontal = 16.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconButton(onClick = { vm.shiftMonth(-1) }) { Icon(Icons.AutoMirrored.Outlined.KeyboardArrowLeft, "Previous month") }
                                Text(vm.month.format(DateTimeFormatter.ofPattern("MMMM yyyy")), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), color = Ink)
                                IconButton(onClick = { vm.shiftMonth(1) }, enabled = vm.month < YearMonth.now()) { Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, "Next month") }
                            }
                            a?.let { m ->
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    DonutChart(listOf(m.counts.present.toFloat() to Green, m.counts.late.toFloat() to Amber, m.counts.absent.toFloat() to Red, m.counts.leave.toFloat() to Violet), m.percentage?.let { pct(it) } ?: "–", "attendance", 110.dp, 14.dp)
                                    Spacer(Modifier.width(16.dp))
                                    Column {
                                        LegendDot("Present", Green, "${m.counts.present}"); LegendDot("Late", Amber, "${m.counts.late}")
                                        LegendDot("Absent", Red, "${m.counts.absent}"); LegendDot("Leave", Violet, "${m.counts.leave}")
                                    }
                                }
                                Spacer(Modifier.height(10.dp))
                                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                    m.days.forEach { d -> Pill("${d.session_date.takeLast(2)} · ${label(d.status).take(1)}", statusColor(d.status)) }
                                }
                                if (m.days.isEmpty()) Text("No attendance marked this month.", color = Muted, fontSize = 13.sp)
                            } ?: Box(Modifier.fillMaxWidth().height(120.dp).clip(Radius.card).shimmer())
                            Spacer(Modifier.height(8.dp))
                            SoftButton("Full attendance history", { nav("history?student=${s.id}") }, icon = Icons.Outlined.History)
                        }
                    }
                }
                "Fees" -> {
                    s.balance?.let { b -> item { BalanceRow(b, Modifier.padding(horizontal = 16.dp)) } }
                    item {
                        Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            if (me.can("fees.collect")) GradientButton("Collect fee", { nav("collect/${s.id}") }, Modifier.weight(1f), icon = Icons.Outlined.CurrencyRupee)
                        }
                    }
                    if (me.isClassAdmin && me.can("fees.manage")) item {
                        Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            SoftButton("Assign fee", { nav("form/studentFee?id=0&parent=${s.id}") }, Modifier.weight(1f), Icons.Outlined.PlaylistAdd)
                            SoftButton("Add charge", { nav("form/studentCharge?id=0&parent=${s.id}") }, Modifier.weight(1f), Icons.Outlined.AddCard)
                        }
                    }
                    item { SectionHeader("Pending invoices", Modifier.padding(horizontal = 16.dp)) }
                    val inv = dues?.invoices.orEmpty()
                    if (inv.isEmpty()) item { CnxCard(Modifier.padding(horizontal = 16.dp)) { Text("No pending invoices.", color = Green) } }
                    items(inv, key = { "i" + it.id }) { i ->
                        CnxCard(Modifier.padding(horizontal = 16.dp), padding = 12.dp) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(i.title, fontWeight = FontWeight.Bold, color = Ink)
                                    Text("${i.invoice_no} · due ${prettyDate(i.due_date)}" + if (i.paid_paise > 0) " · paid ${money(i.paid_paise)}" else "", color = Muted, fontSize = 12.sp)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(money(i.balance_paise), fontWeight = FontWeight.ExtraBold, color = Ink)
                                    Pill(if (i.days_overdue > 0) "Overdue" else label(i.status), if (i.days_overdue > 0) Red else statusColor(i.status))
                                }
                            }
                        }
                    }
                    item { SectionHeader("Payment history", Modifier.padding(horizontal = 16.dp)) }
                    val pays = dues?.recent_payments.orEmpty()
                    if (pays.isEmpty()) item { CnxCard(Modifier.padding(horizontal = 16.dp)) { Text("No payments yet.", color = Muted) } }
                    items(pays, key = { "p" + it.id }) { p ->
                        CnxCard(Modifier.padding(horizontal = 16.dp), padding = 12.dp, onClick = if (p.receipt_id != null) ({ scope.launch { runCatching { openFile(ctx, vm.receipt(p)) }.onFailure { snack?.showSnackbar(it.message ?: "Download failed") } }; Unit }) else null) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconCircle(Icons.Outlined.ReceiptLong, Green, 38.dp, 18.dp)
                                Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                    Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, color = Ink)
                                    Text("${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}", color = Muted, fontSize = 12.sp)
                                }
                                if (p.status != "success") Pill(label(p.status), statusColor(p.status)) else Icon(Icons.Outlined.Download, null, tint = Muted)
                            }
                        }
                    }
                }
                "Batches" -> {
                    item {
                        CnxCard(Modifier.padding(horizontal = 16.dp)) {
                            SectionHeader("Activities & batches")
                            if (s.enrollments.isEmpty()) Text("Not enrolled in any batch.", color = Muted, fontSize = 13.sp)
                            s.enrollments.forEach { e ->
                                Row(Modifier.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                    IconCircle(Icons.Outlined.Groups, if (e.status == "active") Violet else Muted, 38.dp, 18.dp)
                                    Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                        Text(e.activity_name, fontWeight = FontWeight.Bold, color = Ink)
                                        Text(listOfNotNull(e.batch_name, e.branch_name, e.start_date?.let { "since ${prettyDate(it)}" }).joinToString(" · "), color = Muted, fontSize = 12.sp)
                                    }
                                    Pill(label(e.status), statusColor(e.status))
                                }
                            }
                        }
                    }
                    if (canEdit) item {
                        Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            SoftButton("Change batch", { transferOpen = true }, Modifier.weight(1f), Icons.Outlined.SwapHoriz)
                            if (me.isClassAdmin && branches.isNotEmpty()) SoftButton("Change branch", { moveOpen = true }, Modifier.weight(1f), Icons.Outlined.Store)
                        }
                    }
                }
                "Documents" -> {
                    item { LaunchedEffect(Unit) { vm.loadDocs() } }
                    item { DocumentsCard(vm, snack) }
                }
            }
        }
        if (moveOpen) ChangeBranchDialog(s.branch_id, branches, allBatches, onDismiss = { moveOpen = false }) { b, bt -> moveOpen = false; vm.changeBranch(b, bt) }
        if (transferOpen) {
            var b by remember { mutableStateOf<String?>(null) }
            AlertDialog(
                onDismissRequest = { transferOpen = false }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
                title = { Text("Change batch") },
                text = {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("The student moves to the new batch from today. Fees and history stay with the student.", color = Muted, fontSize = 13.sp)
                        SelectField("New batch", allBatches.filter { s.branch_id == null || it.branch_id == null || it.branch_id == s.branch_id }.map { it.id.toString() to "${it.name} · ${it.activity_name}" }, b) { b = it }
                    }
                },
                confirmButton = { Button(onClick = { b?.toLongOrNull()?.let { vm.transfer(it) }; transferOpen = false }, enabled = b != null, colors = ButtonDefaults.buttonColors(containerColor = Violet)) { Text("Move") } },
                dismissButton = { TextButton(onClick = { transferOpen = false }) { Text("Cancel") } },
            )
        }
    }
}

@Composable
private fun BalanceRow(b: Balance, modifier: Modifier = Modifier) {
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        MiniFig("Pending", money(b.outstanding_paise), if (b.outstanding_paise > 0) Red else Green, Modifier.weight(1f)) {}
        MiniFig("Overdue", money(b.overdue_paise), Amber, Modifier.weight(1f)) {}
        MiniFig("Advance", money(b.advance_paise), Green, Modifier.weight(1f)) {}
    }
}

private val docTypes = listOf("birth_certificate" to "Birth certificate", "photo_id" to "Photo ID", "school_id" to "School ID", "medical" to "Medical", "other" to "Other")

@Composable
private fun DocumentsCard(vm: StudentDetailVm, snack: SnackbarHostState?) {
    val docs by vm.docs.collectAsState()
    val busy by vm.busy.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf<String?>("other") }
    var title by remember { mutableStateOf("") }
    var del by remember { mutableStateOf<JsonObject?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri -> uri?.let { vm.uploadDoc(it, type ?: "other", title, ctx.contentResolver.getType(it)); title = "" } }
    CnxCard(Modifier.padding(horizontal = 16.dp)) {
        SectionHeader("Documents")
        Text("Stored securely. ID numbers are never saved; Aadhaar is not required.", color = Muted, fontSize = 12.sp)
        Spacer(Modifier.height(10.dp))
        SelectField("Document type", docTypes.map { it.first to it.second }, type) { type = it }
        Spacer(Modifier.height(8.dp))
        CnxField(title, { title = it }, "Title (optional)")
        Spacer(Modifier.height(10.dp))
        GradientButton("Upload photo or PDF", { pick.launch("*/*") }, Modifier.fillMaxWidth(), loading = busy, icon = Icons.Outlined.UploadFile)
        Spacer(Modifier.height(12.dp))
        when (val list = docs) {
            null -> Box(Modifier.fillMaxWidth().height(60.dp).clip(Radius.card).shimmer())
            else -> {
                if (list.isEmpty()) Text("No documents uploaded.", color = Muted, fontSize = 13.sp)
                list.forEach { d ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        IconCircle(if (d.s("mime") == "application/pdf") Icons.Outlined.PictureAsPdf else Icons.Outlined.Image, Blue, 38.dp, 18.dp)
                        Column(Modifier.weight(1f).padding(horizontal = 10.dp).clickable { scope.launch { runCatching { openFile(ctx, vm.docFile(d), d.s("mime") ?: "*/*") }.onFailure { snack?.showSnackbar(it.message ?: "Could not open") } } }) {
                            Text(d.s("title") ?: label(d.s("doc_type")), fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text("${label(d.s("doc_type"))} · ${prettyDate(d.s("created_at"))}", color = Muted, fontSize = 12.sp)
                        }
                        IconButton(onClick = { del = d }) { Icon(Icons.Outlined.Delete, "Delete", tint = Red) }
                    }
                }
            }
        }
    }
    del?.let { d -> ConfirmDialog("Delete document?", "The file is removed permanently.", "Delete", true, { del = null }) { vm.deleteDoc(d.n("id")); del = null } }
}

/** Move one student to another branch (optionally into a batch there). Same profile, same Student ID. */
@Composable
fun ChangeBranchDialog(current: Long?, branches: List<Branch>, batches: List<Batch>, onDismiss: () -> Unit, onMove: (Long, Long?) -> Unit) {
    var b by remember { mutableStateOf(branches.firstOrNull { it.id != current }?.id?.toString()) }
    var bt by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = onDismiss, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
        title = { Text("Change branch") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Fees, attendance history and the Student ID stay with the student. Your student count does not change.", color = Muted, fontSize = 12.sp)
                SelectField("New branch", branches.filter { it.id != current }.map { it.id.toString() to it.name }, b) { b = it; bt = null }
                SelectField("Batch", listOf<Pair<String?, String>>(null to "Keep current batch") + batches.filter { it.branch_id?.toString() == b }.map { it.id.toString() to "${it.name} (${it.activity_name})" }, bt) { bt = it }
            }
        },
        confirmButton = { Button(enabled = b != null, onClick = { b?.toLongOrNull()?.let { onMove(it, bt?.toLongOrNull()) } }, colors = ButtonDefaults.buttonColors(containerColor = Violet)) { Text("Move") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
