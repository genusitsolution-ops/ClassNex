package `in`.classnex.app.ui.academy

import android.content.Context
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.Activity
import `in`.classnex.app.data.net.Batch
import `in`.classnex.app.data.net.Branch
import `in`.classnex.app.data.net.HistoryRow
import `in`.classnex.app.data.net.HistorySummary
import `in`.classnex.app.data.net.ManualItem
import `in`.classnex.app.data.net.ManualLogRow
import `in`.classnex.app.data.net.ManualPrepare
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.ReportData
import `in`.classnex.app.data.net.str
import `in`.classnex.app.di.AppJson
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.MiniStat
import `in`.classnex.app.ui.common.OptionPicker
import `in`.classnex.app.ui.common.SectionTitle
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.mimeFor
import `in`.classnex.app.ui.common.openFile
import `in`.classnex.app.ui.common.openUrl
import `in`.classnex.app.ui.common.pct
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Red
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import java.net.URLEncoder
import java.time.LocalDate
import javax.inject.Inject

// ======================================================= attendance history
data class HistoryPage(val rows: List<HistoryRow>, val summary: HistorySummary, val byBranch: List<HistoryRow>, val total: Long)
data class HistoryFilter(
    val from: String = LocalDate.now().withDayOfMonth(1).toString(), val to: String = LocalDate.now().toString(),
    val branch: String? = null, val activity: String? = null, val batch: String? = null, val status: String? = null, val student: String? = null,
    val view: String = "sessions", val page: Int = 1,
) {
    fun params(): Map<String, String> = buildMap {
        put("from", from); put("to", to); put("view", view); put("page", page.toString()); put("per_page", "50")
        branch?.let { put("branch_id", it) }; activity?.let { put("activity_id", it) }; batch?.let { put("batch_id", it) }
        status?.let { put("status", it) }; student?.let { put("student_id", it) }
    }
}

@HiltViewModel
class AttendanceHistoryVm @Inject constructor(private val repo: Repository) : LoadVm<HistoryPage>() {
    val filter = MutableStateFlow(HistoryFilter())
    val branches = MutableStateFlow<List<Branch>>(emptyList())
    val activities = MutableStateFlow<List<Activity>>(emptyList())
    val batches = MutableStateFlow<List<Batch>>(emptyList())
    private var started = false

    fun start(branch: Long?, student: Long?) {
        if (started) return
        started = true
        filter.value = filter.value.copy(branch = branch?.toString(), student = student?.toString(), view = if (student != null) "students" else "sessions")
        viewModelScope.launch {
            branches.value = runCatching { call { repo.api.branches() }.data }.getOrNull() ?: emptyList()
            activities.value = runCatching { call { repo.api.activities() }.data }.getOrNull() ?: emptyList()
            batches.value = runCatching { call { repo.api.batches() }.data }.getOrNull() ?: emptyList()
        }
        refresh()
    }

    fun set(f: (HistoryFilter) -> HistoryFilter) { filter.value = f(filter.value).let { if (it.page == filter.value.page) it.copy(page = 1) else it }; refresh() }

    fun refresh() = load {
        val r = call { repo.api.attendanceHistory(filter.value.params()) }
        val meta = r.meta
        val sum = meta?.get("summary")?.let { AppJson.decodeFromJsonElement(HistorySummary.serializer(), it) } ?: HistorySummary()
        val by = meta?.get("by_branch")?.let { AppJson.decodeFromJsonElement(kotlinx.serialization.builtins.ListSerializer(HistoryRow.serializer()), it) } ?: emptyList()
        HistoryPage(r.data ?: emptyList(), sum, by, meta?.get("total").str()?.toLongOrNull() ?: 0)
    }

    /** Downloads the same filtered history as PDF / Excel / CSV and opens it. */
    fun export(ctx: Context, format: String) = viewModelScope.launch {
        try {
            val q = (filter.value.params() - "page" - "per_page" + ("format" to format)).entries.joinToString("&") { "${it.key}=${URLEncoder.encode(it.value, "UTF-8")}" }
            val f = repo.download("attendance/history?$q", "attendance-history.$format")
            openFile(ctx, f, mimeFor(format))
        } catch (e: Exception) { say(errorText(e)) }
    }
}

@Composable
fun AttendanceHistoryScreen(branch: Long?, student: Long?, snack: SnackbarHostState, vm: AttendanceHistoryVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.start(branch, student); vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val f by vm.filter.collectAsState()
    val branches by vm.branches.collectAsState()
    val acts by vm.activities.collectAsState()
    val batches by vm.batches.collectAsState()
    val ctx = LocalContext.current
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                val now = LocalDate.now()
                listOf(
                    "Today" to (now to now), "7 days" to (now.minusDays(6) to now),
                    "This month" to (now.withDayOfMonth(1) to now), "Last month" to (now.minusMonths(1).withDayOfMonth(1) to now.withDayOfMonth(1).minusDays(1)),
                ).forEach { (t, r) ->
                    FilterChip(selected = f.from == r.first.toString() && f.to == r.second.toString(), onClick = { vm.set { it.copy(from = r.first.toString(), to = r.second.toString()) } }, label = { Text(t) })
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (branches.isNotEmpty()) OptionPicker("All branches", listOf<Pair<String?, String>>(null to "All branches (combined)") + branches.map { it.id.toString() to it.name }, f.branch, Modifier.weight(1f)) { v -> vm.set { it.copy(branch = v) } }
                OptionPicker("All batches", listOf<Pair<String?, String>>(null to "All batches") + batches.filter { b -> f.branch == null || b.branch_id?.toString() == f.branch }.map { it.id.toString() to it.name }, f.batch, Modifier.weight(1f)) { v -> vm.set { it.copy(batch = v) } }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                OptionPicker("All activities", listOf<Pair<String?, String>>(null to "All activities") + acts.map { it.id.toString() to it.name }, f.activity, Modifier.weight(1f)) { v -> vm.set { it.copy(activity = v) } }
                OptionPicker("Any status", listOf<Pair<String?, String>>(null to "Any status", "present" to "Present", "absent" to "Absent", "late" to "Late", "leave" to "Leave"), f.status, Modifier.weight(1f)) { v -> vm.set { it.copy(status = v) } }
            }
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                listOf("sessions" to "By class", "students" to "By student", "branches" to "By branch").forEach { (k, t) ->
                    if (k != "branches" || branches.isNotEmpty()) FilterChip(selected = f.view == k, onClick = { vm.set { it.copy(view = k) } }, label = { Text(t) })
                }
                Spacer(Modifier.width(6.dp))
                listOf("pdf" to "PDF", "xlsx" to "Excel", "csv" to "CSV").forEach { (k, t) -> TextButton(onClick = { vm.export(ctx, k) }) { Text(t) } }
            }
            if (f.student != null) Row(verticalAlignment = Alignment.CenterVertically) { Text("One student", color = Muted, fontSize = 12.sp, modifier = Modifier.weight(1f)); TextButton(onClick = { vm.set { it.copy(student = null) } }) { Text("Show all") } }
        }
        LoadContent(st, vm::refresh) { p ->
            val s = p.summary
            LazyColumn(contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item {
                    Text("${prettyDate(s.from)} – ${prettyDate(s.to)} · ${s.sessions} classes", color = Muted, fontSize = 12.sp)
                    Spacer(Modifier.height(4.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        MiniStat("Students", s.total_students.toString(), Modifier.weight(1f))
                        MiniStat("Present", s.present.toString(), Modifier.weight(1f), Green)
                        MiniStat("Absent", s.absent.toString(), Modifier.weight(1f), Red)
                    }
                    Spacer(Modifier.height(6.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        MiniStat("Late", s.late.toString(), Modifier.weight(1f))
                        MiniStat("Leave", s.leave.toString(), Modifier.weight(1f))
                        MiniStat("Attendance", pct(s.attendance_pct), Modifier.weight(1f))
                    }
                }
                if (p.byBranch.size > 1 && f.view != "branches") {
                    item { SectionTitle("Branch-wise (combined academy)") }
                    items(p.byBranch) { b -> AppCard { Row { Text(b.branch_name ?: "Main", Modifier.weight(1f), fontWeight = FontWeight.Bold); Text("${b.present + b.late}/${b.present + b.late + b.absent} · ${pct(b.attendance_pct)}") } } }
                    item { SectionTitle(if (f.view == "students") "Students" else "Classes") }
                }
                if (p.rows.isEmpty()) item { EmptyView("No attendance for these filters.") }
                items(p.rows) { r ->
                    AppCard {
                        when (f.view) {
                            "students" -> Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(r.student_name ?: "", fontWeight = FontWeight.Bold)
                                    Text("${prettyDate(r.session_date)} · ${r.batch_name ?: ""} · ${r.branch_name ?: "Main"}", color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                }
                                StatusChip(r.status ?: "")
                            }
                            "branches" -> Column {
                                Row { Text(r.branch_name ?: "Main", Modifier.weight(1f), fontWeight = FontWeight.Bold); Text(pct(r.attendance_pct), fontWeight = FontWeight.ExtraBold) }
                                Text("${r.sessions} classes · ${r.total_students} students · P ${r.present} · A ${r.absent} · L ${r.late} · Lv ${r.leave}", color = Muted, fontSize = 12.sp)
                            }
                            else -> Column {
                                Row { Text(prettyDate(r.session_date), Modifier.weight(1f), fontWeight = FontWeight.Bold); Text(pct(r.attendance_pct), fontWeight = FontWeight.ExtraBold) }
                                Text("${r.batch_name ?: ""} · ${r.activity_name ?: ""}${if (branches.isNotEmpty()) " · " + (r.branch_name ?: "Main") else ""}", color = Muted, fontSize = 12.sp)
                                Text("P ${r.present} · A ${r.absent} · L ${r.late} · Lv ${r.leave} of ${r.total_students}", fontSize = 13.sp)
                            }
                        }
                    }
                }
                if (p.total > f.page * 50L) item { OutlinedButton(onClick = { vm.set { it.copy(page = it.page + 1) } }, modifier = Modifier.fillMaxWidth()) { Text("Next page") } }
                if (f.page > 1) item { TextButton(onClick = { vm.set { it.copy(page = it.page - 1) } }, modifier = Modifier.fillMaxWidth()) { Text("Previous page") } }
            }
        }
    }
}

// ================================================================== reports
@HiltViewModel
class ReportVm @Inject constructor(private val repo: Repository) : LoadVm<ReportData>() {
    var name = ""
    var filters: Map<String, String> = emptyMap()
    fun open(report: String, branch: Long?) {
        name = report
        filters = buildMap { branch?.let { put("branch_id", it.toString()) } }
        load { call { repo.api.report(report, filters) }.data!! }
    }
    fun export(ctx: Context, format: String) = viewModelScope.launch {
        try {
            val q = (filters + ("format" to format)).entries.joinToString("&") { "${it.key}=${URLEncoder.encode(it.value, "UTF-8")}" }
            openFile(ctx, repo.download("reports/$name?$q", "$name.$format"), mimeFor(format))
        } catch (e: Exception) { say(errorText(e)) }
    }
}

/** Any report from the shared report engine (branch-wise or combined). */
@Composable
fun ReportScreen(report: String, branch: Long?, snack: SnackbarHostState, vm: ReportVm = hiltViewModel()) {
    LaunchedEffect(report, branch) { vm.open(report, branch) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, { vm.open(report, branch) }) { r ->
        LazyColumn(contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            item {
                Text(r.title, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                Row { listOf("pdf" to "PDF", "xlsx" to "Excel", "csv" to "CSV").forEach { (k, t) -> TextButton(onClick = { vm.export(ctx, k) }) { Text(t) } } }
            }
            if (r.rows.isEmpty()) item { EmptyView("Nothing to show for this period.") }
            items(r.rows.take(300)) { row ->
                AppCard {
                    Column {
                        r.columns.forEachIndexed { i, c ->
                            val v = row.getOrNull(i).str() ?: ""
                            if (v.isNotBlank()) Row { Text(c, color = Muted, fontSize = 12.sp, modifier = Modifier.weight(.4f)); Text(v, fontSize = 13.sp, modifier = Modifier.weight(.6f)) }
                        }
                    }
                }
            }
            if (r.rows.size > 300) item { Text("Showing 300 of ${r.rows.size} rows. Download the PDF / Excel for all rows.", color = Muted, fontSize = 12.sp) }
            (r.totals as? kotlinx.serialization.json.JsonObject)?.let { t ->
                item { AppCard { Column { t.forEach { (k, v) -> Row { Text(k, Modifier.weight(1f), fontWeight = FontWeight.Bold); Text(v.str() ?: "") } } } } }
            }
        }
    }
}

// ===================================================== WhatsApp click-to-send
/**
 * Manual WhatsApp mode: the server prepares one message per family and keeps the history;
 * "Send" opens WhatsApp / WhatsApp Business with the text ready and the user presses send there.
 * Nothing is sent automatically from the phone (no unofficial automation).
 */
@HiltViewModel
class WhatsAppVm @Inject constructor(private val repo: Repository) : LoadVm<List<ManualLogRow>>() {
    val items = MutableStateFlow<List<ManualItem>>(emptyList())
    fun prepare(body: ManualPrepare) = viewModelScope.launch {
        try { items.value = call { repo.api.waPrepare(body) }.data?.items ?: emptyList(); if (items.value.isEmpty()) say("Nobody to message.") } catch (e: Exception) { say(errorText(e)) }
    }
    fun opened(ctx: Context, item: ManualItem) {
        openUrl(ctx, item.link)
        viewModelScope.launch { runCatching { call { repo.api.waOpened(item.id) } } }
    }
    fun clear() { items.value = emptyList() }
    fun history() = load { call { repo.api.waLog() }.data ?: emptyList() }
}

@Composable
fun WhatsAppSendDialog(vm: WhatsAppVm, title: String = "WhatsApp") {
    val items by vm.items.collectAsState()
    if (items.isEmpty()) return
    val ctx = LocalContext.current
    val sent = remember(items) { mutableStateListOf<Long>() }
    AlertDialog(
        onDismissRequest = { vm.clear() },
        title = { Text("$title – ${items.size} message(s)") },
        text = {
            LazyColumn(Modifier.heightIn(max = 460.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item { Text("Tap Send to open WhatsApp with the message ready, then press send in WhatsApp. ${sent.size} of ${items.size} opened.", color = Muted, fontSize = 12.sp) }
                items(items) { m ->
                    Column {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(m.student_name, fontWeight = FontWeight.Bold)
                                Text("${m.name ?: ""} ${m.phone}", color = Muted, fontSize = 12.sp)
                            }
                            Button(onClick = { vm.opened(ctx, m); if (m.id !in sent) sent.add(m.id) }) { Text(if (m.id in sent) "Sent ✓" else "Send") }
                        }
                        Text(m.text, fontSize = 12.sp, maxLines = 4, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = { vm.clear() }) { Text("Done") } },
    )
}

@Composable
fun WhatsAppHistoryScreen(snack: SnackbarHostState, vm: WhatsAppVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.history(); vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, vm::history) { rows ->
        LazyColumn(contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            item { Text("Messages prepared for click-to-send. \"Opened\" means Send was tapped and WhatsApp opened.", color = Muted, fontSize = 12.sp) }
            if (rows.isEmpty()) item { EmptyView("No WhatsApp messages in the last 30 days.") }
            items(rows) { m ->
                AppCard {
                    Column {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(m.student_name ?: m.recipient_name ?: m.phone, fontWeight = FontWeight.Bold)
                                Text("${label(m.purpose)} · ${prettyDate(m.created_at)}${m.branch_name?.let { " · $it" } ?: ""}", color = Muted, fontSize = 12.sp)
                            }
                            StatusChip(if (m.status == "opened") "sent" else "pending", if (m.status == "opened") "Opened" else "Prepared")
                        }
                        Text(m.message, fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
                        TextButton(onClick = { openUrl(ctx, m.link) }) { Text("Send again") }
                    }
                }
            }
        }
    }
}
