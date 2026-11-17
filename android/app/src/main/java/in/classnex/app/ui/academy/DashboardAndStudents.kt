package `in`.classnex.app.ui.academy

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.BarChart
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Collections
import androidx.compose.material.icons.outlined.CurrencyRupee
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.HourglassBottom
import androidx.compose.material.icons.outlined.PersonAdd
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Send
import androidx.compose.material.icons.outlined.Warning
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
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
import `in`.classnex.app.data.net.AttMonth
import `in`.classnex.app.data.net.Dashboard
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.StudentDetail
import `in`.classnex.app.data.net.StudentRow
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.Avatar
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.KeyValue
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.QuickAction
import `in`.classnex.app.ui.common.SectionTitle
import `in`.classnex.app.ui.common.StatCard
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.openUrl
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.common.prettyTime
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Orange
import `in`.classnex.app.ui.theme.Purple
import `in`.classnex.app.ui.theme.Red
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.YearMonth
import javax.inject.Inject

// ================================================================ dashboard
@HiltViewModel
class DashboardVm @Inject constructor(private val repo: Repository) : LoadVm<Dashboard>() {
    init { refresh() }
    fun refresh() = load { call { repo.api.dashboard() }.data!! }
}

@Composable
fun DashboardScreen(me: Me, nav: (String) -> Unit, vm: DashboardVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    LoadContent(st, vm::refresh) { d ->
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Text("Hello, ${me.name.substringBefore(' ')} 👋", fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
                Text(me.academy?.name ?: "", color = Muted)
                me.subscription?.let { s -> if (s.status in listOf("expired", "grace", "suspended")) AppCard { Text(if (s.status == "grace") "Your plan expired on ${prettyDate(s.ends_on)}. Renew soon to avoid interruption." else "Your subscription is ${s.status}. Data is safe; changes are disabled until renewal.", color = Red) } }
            }
            if (d.role == "teacher") {
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        StatCard("Today's classes", d.todays_classes.size.toString(), Icons.Outlined.CalendarMonth, Green, Modifier.weight(1f), if (d.attendance_pending > 0) "${d.attendance_pending} not marked" else "All marked") { nav("attendance") }
                        StatCard("Students", d.total_students.toString(), Icons.Outlined.Group, Purple, Modifier.weight(1f)) { nav("students") }
                    }
                }
                item { SectionTitle("Today's classes") }
                if (d.todays_classes.isEmpty()) item { EmptyView("No classes scheduled today") }
                items(d.todays_classes) { c ->
                    AppCard(onClick = { nav("attendance/${c.id}") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) { Text(c.name, fontWeight = FontWeight.Bold); Text("${c.activity_name} · ${prettyTime(c.start_time)} – ${prettyTime(c.end_time)}", color = Muted, fontSize = 13.sp) }
                            if (c.marked) StatusChip("success", "Marked") else Button(onClick = { nav("attendance/${c.id}") }) { Text("Mark") }
                        }
                    }
                }
                return@LazyColumn
            }
            item {
                val acts = buildList {
                    if (me.can("students.manage")) add(Triple("Add Student", Icons.Outlined.PersonAdd, Blue) to "addStudent")
                    if (me.has("attendance")) add(Triple("Attendance", Icons.Outlined.CalendarMonth, Green) to "attendance")
                    if (me.has("fees") && me.can("fees.collect")) add(Triple("Collect Fee", Icons.Outlined.CurrencyRupee, Orange) to "collect/0")
                    if (me.has("fees")) add(Triple("Pending Fees", Icons.Outlined.HourglassBottom, Red) to "fees")
                    if (me.has("manual_reminders")) add(Triple("Send Reminder", Icons.Outlined.Send, Green) to "message")
                    add(Triple("Payments", Icons.Outlined.BarChart, Purple) to "payments")
                }
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    acts.chunked(3).forEach { row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            row.forEach { (a, route) -> QuickAction(a.first, a.second, a.third, Modifier.weight(1f)) { nav(route) } }
                            repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatCard("Active students", d.active_students.toString(), Icons.Outlined.Group, Blue, Modifier.weight(1f), "${d.new_admissions} new this month") { nav("students") }
                    StatCard("Present today", "${d.today_attendance.present}/${d.today_attendance.marked}", Icons.Outlined.CheckCircle, Green, Modifier.weight(1f), if (d.batches_not_marked > 0) "${d.batches_not_marked} batch(es) pending" else "${d.today_attendance.absent} absent") { nav("attendance") }
                }
            }
            if (me.has("fees")) {
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        StatCard("This month", money(d.month_collection_paise), Icons.Outlined.CurrencyRupee, Purple, Modifier.weight(1f), "Today ${money(d.today_collection_paise)}") { nav("payments") }
                        StatCard("Overdue", money(d.overdue_fees_paise), Icons.Outlined.Warning, Red, Modifier.weight(1f), "${d.overdue_students} students") { nav("fees") }
                    }
                }
                item { SectionTitle("Due in the next 7 days") }
                if (d.upcoming_dues.isEmpty()) item { AppCard { Text("No dues in the next 7 days 🎉", color = Muted) } }
                items(d.upcoming_dues) { u ->
                    AppCard(onClick = { nav("collect/${u.student_id}") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) { Text(u.student_name, fontWeight = FontWeight.Bold); Text("${u.title} · ${prettyDate(u.due_date)}", color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                            Text(money(u.balance_paise), fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
                item { SectionTitle("Recent payments") }
                items(d.recent_payments) { p ->
                    AppCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) { Text(p.student_name ?: "", fontWeight = FontWeight.Bold); Text("${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}", color = Muted, fontSize = 12.sp) }
                            Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, color = if (p.status == "reversed") Muted else Green)
                        }
                    }
                }
            }
        }
    }
}

// ================================================================= students
@HiltViewModel
class StudentsVm @Inject constructor(private val repo: Repository) : LoadVm<List<StudentRow>>() {
    private var job: Job? = null
    var query = ""
    init { search("") }
    fun search(q: String) {
        query = q
        job?.cancel()
        job = viewModelScope.launch {
            if (q.isNotEmpty()) delay(300)
            load(silent = q.isNotEmpty()) { call { repo.api.students(q.ifBlank { null }) }.data ?: emptyList() }
        }
    }
}

@Composable
fun StudentsScreen(me: Me, nav: (String) -> Unit, pickMode: Boolean = false, onPick: ((StudentRow) -> Unit)? = null, vm: StudentsVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    var q by remember { mutableStateOf(vm.query) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.padding(16.dp, 12.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(q, { q = it; vm.search(it) }, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text("Name, Student ID or phone") }, singleLine = true, modifier = Modifier.weight(1f))
            if (!pickMode && me.can("students.manage")) { Spacer(Modifier.width(8.dp)); Button(onClick = { nav("addStudent") }) { Icon(Icons.Outlined.PersonAdd, null) } }
        }
        LoadContent(st, { vm.search(q) }) { rows ->
            if (rows.isEmpty()) EmptyView(if (q.isBlank()) "No students yet. Tap + to add one." else "No match for \"$q\"")
            LazyColumn(contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows, key = { it.id }) { s ->
                    AppCard(onClick = { if (pickMode) onPick?.invoke(s) else nav("student/${s.id}") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Avatar(s.photo_url, s.name)
                            Column(Modifier.weight(1f).padding(start = 12.dp)) {
                                Text(s.name, fontWeight = FontWeight.Bold)
                                Text("${s.student_code} · ${s.enrolled_in ?: "Not enrolled"}", color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                            if (s.pending_paise > 0 && me.can("fees.view")) Text(money(s.pending_paise), color = Red, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }
    }
}

// ----------------------------------------------------------- student detail
data class StudentPage(val s: StudentDetail, val att: AttMonth?)

@HiltViewModel
class StudentDetailVm @Inject constructor(private val repo: Repository) : LoadVm<StudentPage>() {
    fun open(id: Long) = load {
        val s = call { repo.api.student(id) }.data!!
        val a = runCatching { call { repo.api.studentAttendance(id, YearMonth.now().toString()) }.data }.getOrNull()
        StudentPage(s, a)
    }
}

@Composable
fun StudentDetailScreen(id: Long, me: Me, nav: (String) -> Unit, vm: StudentDetailVm = hiltViewModel()) {
    LaunchedEffect(id) { vm.open(id) }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, { vm.open(id) }) { (s, att) ->
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                AppCard {
                    Column {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Avatar(s.photo_url, s.name, 64)
                            Column(Modifier.padding(start = 14.dp).weight(1f)) {
                                Text(s.name, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
                                Text("ID ${s.student_code}", color = Muted)
                                if (s.status != "active") StatusChip(s.status)
                            }
                        }
                        Spacer(Modifier.height(10.dp))
                        s.enrollments.filter { it.status == "active" }.forEach { Text("• ${it.activity_name}${it.batch_name?.let { b -> " – $b" } ?: ""}", fontSize = 14.sp) }
                        Spacer(Modifier.height(10.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            AssistChip(onClick = { openUrl(ctx, "https://wa.me/${s.country_code.trimStart('+')}${s.whatsapp}") }, label = { Text("WhatsApp") })
                            AssistChip(onClick = { openUrl(ctx, "tel:${s.country_code}${s.whatsapp}") }, label = { Text("Call") })
                        }
                    }
                }
            }
            s.balance?.let { b ->
                item {
                    AppCard {
                        Column {
                            Row {
                                Column(Modifier.weight(1f)) { Text("Pending", color = Muted, fontSize = 12.sp); Text(money(b.outstanding_paise), color = if (b.outstanding_paise > 0) Red else Green, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp) }
                                Column(Modifier.weight(1f)) { Text("Overdue", color = Muted, fontSize = 12.sp); Text(money(b.overdue_paise), fontWeight = FontWeight.Bold, fontSize = 18.sp) }
                                Column(Modifier.weight(1f)) { Text("Advance", color = Muted, fontSize = 12.sp); Text(money(b.advance_paise), color = Green, fontWeight = FontWeight.Bold, fontSize = 18.sp) }
                            }
                            if (me.can("fees.collect")) { Spacer(Modifier.height(10.dp)); Button(onClick = { nav("collect/${s.id}") }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Outlined.CurrencyRupee, null); Text(" Collect fee") } }
                        }
                    }
                }
            }
            att?.let { a ->
                item {
                    AppCard {
                        Column {
                            Text("Attendance this month", fontWeight = FontWeight.Bold)
                            Text(a.percentage?.let { "$it%" } ?: "No classes marked yet", fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Blue)
                            Text("Present ${a.counts.present} · Absent ${a.counts.absent} · Late ${a.counts.late} · Leave ${a.counts.leave}", color = Muted, fontSize = 13.sp)
                        }
                    }
                }
            }
            item {
                AppCard {
                    Column {
                        Text("Details", fontWeight = FontWeight.Bold)
                        HorizontalDivider(Modifier.padding(vertical = 6.dp))
                        KeyValue("WhatsApp", "${s.country_code} ${s.whatsapp}")
                        KeyValue("Guardian", s.guardian_name ?: "–")
                        KeyValue("Date of birth", prettyDate(s.dob))
                        KeyValue("Email", s.email ?: "–")
                        KeyValue("Address", s.address ?: "–")
                        KeyValue("Joined", prettyDate(s.admission_date))
                        s.parents.forEach { p -> KeyValue("Parent", "${p.name} (${p.whatsapp})${p.login_status?.let { " · app: $it" } ?: ""}") }
                    }
                }
            }
            item { OutlinedButton(onClick = { nav("message?student=${s.id}") }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Outlined.Send, null); Text(" Send message") } }
        }
    }
}

@Suppress("unused") private val unusedIcon = Icons.Outlined.Collections
