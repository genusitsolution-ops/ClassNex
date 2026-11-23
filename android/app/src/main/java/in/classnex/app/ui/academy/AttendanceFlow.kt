package `in`.classnex.app.ui.academy

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.ui.platform.testTag
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import `in`.classnex.app.data.ApiError
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.*
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import java.time.LocalDate
import javax.inject.Inject

/*
 * Mark Attendance: DATE (today by default) → BRANCH → ACTIVITY → BATCH, then the register.
 * A new register starts with everyone Present; the user taps only the exceptions and presses Save.
 * Nothing is saved until Save is pressed. An existing register for that date is loaded for editing.
 * One register per batch per day (the database enforces it); several daily classes are separate batches.
 */
@HiltViewModel
class MarkVm @Inject constructor(private val repo: Repository) : ViewModel() {
    var date by mutableStateOf(LocalDate.now().toString())
    var branch by mutableStateOf<Long?>(null)
    var activity by mutableStateOf<Long?>(null)
    var batch by mutableStateOf<Long?>(null)
    val branches = MutableStateFlow<List<Branch>>(emptyList())
    val activities = MutableStateFlow<List<Activity>>(emptyList())
    val batches = MutableStateFlow<List<Batch>>(emptyList())
    val sheet = MutableStateFlow<Load<AttSheet>?>(null)
    val marks = mutableStateMapOf<Long, String>()
    private var original: Map<Long, String> = emptyMap()
    val saving = MutableStateFlow(false)
    val saved = MutableStateFlow<String?>(null)
    val absentToNotify = MutableStateFlow(0)
    val messages = MutableSharedFlow<String>(extraBufferCapacity = 4)
    var reason by mutableStateOf("")
    private var started = false

    fun start(me: Me, initialBatch: Long) {
        if (started) return
        started = true
        viewModelScope.launch {
            if (me.isClassAdmin) branches.value = runCatching { call { repo.api.branches("active") }.data }.getOrNull() ?: emptyList()
            activities.value = runCatching { call { repo.api.activities() }.data }.getOrNull() ?: emptyList()
            batches.value = runCatching { call { repo.api.batches() }.data }.getOrNull() ?: emptyList()
            if (initialBatch > 0) batches.value.firstOrNull { it.id == initialBatch }?.let { b -> branch = b.branch_id; choose(b.id) }
            else if (batches.value.size == 1) choose(batches.value.first().id)
        }
    }

    fun visibleBatches() = batches.value.filter { (branch == null || it.branch_id == branch) && (activity == null || it.activity_id == activity) }

    fun pickDate(d: String) {
        if (d > LocalDate.now().toString()) { messages.tryEmit("Attendance cannot be marked for a future date."); return }
        date = d
        batch?.let { choose(it) }
    }
    fun pickBranch(b: Long?) { branch = b; if (batch != null && visibleBatches().none { it.id == batch }) clearBatch() }
    fun pickActivity(a: Long?) { activity = a; if (batch != null && visibleBatches().none { it.id == batch }) clearBatch() }
    private fun clearBatch() { batch = null; sheet.value = null; marks.clear() }

    fun choose(id: Long) {
        batch = id
        sheet.value = Load.Loading
        reason = ""
        viewModelScope.launch {
            sheet.value = try {
                val s = call { repo.api.attendanceSheet(id, date) }.data!!
                marks.clear()
                s.students.forEach { st -> st.status?.let { marks[st.id] = it } }
                original = marks.toMap()
                // present by default: a new register starts with everyone present
                if (!s.already_marked && s.holiday == null) s.students.forEach { st -> if (st.id !in marks) marks[st.id] = "present" }
                Load.Ready(s)
            } catch (e: Exception) { Load.Failed(e.message ?: "Could not load the students") }
        }
    }

    fun all(status: String) { (sheet.value as? Load.Ready)?.data?.students?.forEach { marks[it.id] = status } }
    fun changedCount() = marks.count { (k, v) -> original[k] != null && original[k] != v }

    fun save(manualWhatsApp: Boolean) {
        val b = batch ?: return
        if (marks.isEmpty()) { messages.tryEmit("Mark at least one student."); return }
        saving.value = true
        viewModelScope.launch {
            try {
                val r = call { repo.api.saveAttendance(AttSave(b, date, marks.map { AttEntry(it.key, it.value) }, reason.ifBlank { null })) }
                val d = r.data
                absentToNotify.value = if (manualWhatsApp) marks.values.count { it == "absent" || it == "late" } else 0
                saved.value = buildString {
                    append(r.message.ifBlank { "Attendance saved." })
                    if (d != null && d.changed > 0) append(" ${d.changed} change(s).")
                    if ((d?.notifications ?: 0) > 0) append(" Parents notified: ${d?.notifications}.")
                }
                original = marks.toMap()
            } catch (e: Exception) {
                messages.tryEmit((e as? ApiError)?.let { it.fieldErrors.values.firstOrNull() ?: it.message } ?: (e.message ?: "Could not save"))
            } finally { saving.value = false }
        }
    }
}

private val statuses = listOf(Triple("present", "Present", Green), Triple("absent", "Absent", Red), Triple("late", "Late", Amber), Triple("leave", "Leave", Violet))

@Composable
fun MarkAttendanceScreen(initialBatch: Long, me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: MarkVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.start(me, initialBatch) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    val branches by vm.branches.collectAsState()
    val activities by vm.activities.collectAsState()
    val batchesAll by vm.batches.collectAsState()
    val sheet by vm.sheet.collectAsState()
    val saving by vm.saving.collectAsState()
    val saved by vm.saved.collectAsState()
    val absentN by vm.absentToNotify.collectAsState()
    val manualWa = me.whatsappManualOnly && me.can("notifications.send")
    var askWa by remember { mutableStateOf(false) }
    WhatsAppSendDialog(wa, "Attendance alerts")

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    // once the students are on screen, the choices fold into one line to leave room for the list
                    var editing by remember { mutableStateOf(false) }
                    val chosen = batchesAll.firstOrNull { it.id == vm.batch }
                    if (chosen != null && sheet is Load.Ready && !editing) {
                        CnxCard(onClick = { editing = true }, padding = 12.dp) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconCircle(Icons.Outlined.Groups, Violet, 38.dp, 18.dp)
                                Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                    Text(chosen.name, fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(prettyDate(vm.date) + (chosen.branch_name?.let { " · $it" } ?: ""), color = Muted, fontSize = 12.sp)
                                }
                                Text("Change", color = Violet, fontWeight = FontWeight.Bold)
                            }
                        }
                    } else {
                        LaunchedEffect(vm.batch, vm.date) { if (editing && sheet is Load.Ready) editing = false }
                    CnxCard {
                        DateField("Date", vm.date) { it?.let(vm::pickDate) }
                        if (branches.isNotEmpty()) {
                            Spacer(Modifier.height(10.dp))
                            SelectField("Branch", listOf<Pair<String?, String>>(null to "All branches") + branches.map { it.id.toString() to it.name }, vm.branch?.toString(), icon = Icons.Outlined.Store) { vm.pickBranch(it?.toLongOrNull()) }
                        }
                        Spacer(Modifier.height(10.dp))
                        val vis = remember(batchesAll, vm.branch, vm.activity) { vm.visibleBatches() }
                        SelectField("Batch", vis.map { it.id.toString() to "${it.name} · ${it.activity_name}" + (it.start_time?.let { t -> " · " + prettyTime(t) } ?: "") }, vm.batch?.toString(), icon = Icons.Outlined.Groups) { it?.toLongOrNull()?.let(vm::choose) }
                        if (batchesAll.isNotEmpty() && vis.isEmpty()) Text("No batch in this branch.", color = Amber, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    }
                    }
                }
                when (val s = sheet) {
                    null -> item { EmptyState("Choose a batch", "Pick the date, branch and batch to see the students.", Icons.Outlined.HowToReg) }
                    Load.Loading -> item { SkeletonList(4, Modifier.height(360.dp)) }
                    is Load.Failed -> item { ErrorView(s.message) { vm.batch?.let(vm::choose) } }
                    is Load.Ready -> {
                        val sh = s.data
                        item {
                            when {
                                sh.holiday != null -> InfoBanner("Holiday: ${sh.holiday}. You can still save if the class took place.", Violet, Icons.Outlined.BeachAccess)
                                sh.already_marked -> InfoBanner("Attendance for ${prettyDate(sh.date)} is already saved. Tap a status to change it and save again.", Blue, Icons.Outlined.EditCalendar)
                                else -> InfoBanner("All students are marked Present. Tap a student's status to change it: Absent → Late → Leave.", Green, Icons.Outlined.Bolt)
                            }
                        }
                        // live counts
                        item {
                            val c = vm.marks.values.groupingBy { it }.eachCount()
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                statuses.forEach { (k, l, col) -> CountTile(c[k] ?: 0, l, col, Modifier.weight(1f)) }
                            }
                        }
                        item {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text("Students (${sh.students.size})", style = MaterialTheme.typography.titleMedium, color = Ink, modifier = Modifier.weight(1f))
                                TextButton(onClick = { vm.all("present") }) { Icon(Icons.Outlined.DoneAll, null, tint = Green, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(4.dp)); Text("All Present", color = Green, fontWeight = FontWeight.Bold) }
                                TextButton(onClick = { vm.all("absent") }) { Text("All Absent", color = Red, fontWeight = FontWeight.Bold) }
                            }
                        }
                        if (sh.students.isEmpty()) item { EmptyState("No students in this batch on this date", icon = Icons.Outlined.Group) }
                        item {
                            if (sh.students.isNotEmpty()) GroupCard(null) {
                                sh.students.forEachIndexed { idx, st ->
                                    if (idx > 0) HorizontalDivider(color = Line, modifier = Modifier.padding(start = 52.dp))
                                    // a student added after the register was saved has no status yet: shown as "Not marked"
                                    val cur = vm.marks[st.id]
                                    val (_, word, col) = statuses.firstOrNull { it.first == cur } ?: Triple("", "Not marked", Muted)
                                    val bg by animateColorAsState(col.copy(alpha = .14f), tween(220), label = "st")
                                    val fg by animateColorAsState(col, tween(220), label = "fg")
                                    val next = { vm.marks[st.id] = if (cur == null) "present" else statuses[(statuses.indexOfFirst { it.first == cur } + 1) % statuses.size].first }
                                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = next).padding(vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Avatar(st.photo_url, st.name, 42)
                                        Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                            Text(st.name, fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                            Text(st.student_code, color = Muted, fontSize = 12.sp, maxLines = 1)
                                        }
                                        Row(Modifier.testTag("status:" + st.id).clip(RoundedCornerShape(50)).background(bg).clickable(onClick = next).padding(horizontal = 12.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
                                            Icon(when (cur) { "present" -> Icons.Outlined.CheckCircle; "absent" -> Icons.Outlined.Cancel; "late" -> Icons.Outlined.Schedule; "leave" -> Icons.Outlined.EventBusy; else -> Icons.Outlined.RadioButtonUnchecked }, null, tint = fg, modifier = Modifier.size(17.dp))
                                            Spacer(Modifier.width(5.dp))
                                            AnimatedContent(word, transitionSpec = { fadeIn(tween(150)) togetherWith fadeOut(tween(100)) }, label = "w") { w -> Text(w, color = fg, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
                                        }
                                    }
                                }
                            }
                        }
                        if (sh.already_marked) item { CnxField(vm.reason, { vm.reason = it }, "Reason for the change (optional)") }
                    }
                }
            }
            val ready = (sheet as? Load.Ready)?.data
            if (ready != null && ready.students.isNotEmpty()) {
                Surface(color = Lavender) {
                    Column(Modifier.fillMaxWidth().padding(16.dp, 8.dp)) {
                        GradientButton(if (ready.already_marked) "Save Changes" else "Save Attendance", { vm.save(manualWa) }, Modifier.fillMaxWidth(), loading = saving, icon = Icons.Outlined.Save,
                            enabled = me.can("attendance.mark"))
                    }
                }
            }
        }
        SuccessOverlay(saved != null, "Attendance saved", saved) {
            vm.saved.value = null
            if (absentN > 0) askWa = true
        }
    }
    if (askWa) AlertDialog(
        onDismissRequest = { askWa = false }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
        icon = { IconCircle(Icons.Outlined.Chat, Green) },
        title = { Text("Message parents on WhatsApp?") },
        text = { Text("$absentN student(s) are absent or late. Prepare WhatsApp messages for their parents? You send each one from WhatsApp.", color = Muted) },
        confirmButton = { Button(onClick = { askWa = false; vm.batch?.let { wa.prepare(ManualPrepare(purpose = "attendance", batch_id = it, date = vm.date)) } }, colors = ButtonDefaults.buttonColors(containerColor = Green)) { Text("Prepare") } },
        dismissButton = { TextButton(onClick = { askWa = false }) { Text("Skip") } },
    )
}

@Composable
private fun CountBox(label: String, n: Int, color: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text("$n", fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = color)
        Text(" $label", fontSize = 12.sp, color = Muted)
    }
}
