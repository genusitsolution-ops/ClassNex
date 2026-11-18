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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.Batch
import `in`.classnex.app.data.net.BranchDetail
import `in`.classnex.app.data.net.BranchFigures
import `in`.classnex.app.data.net.BranchSave
import `in`.classnex.app.data.net.BranchSummary
import `in`.classnex.app.data.net.BulkBranch
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.Quota
import `in`.classnex.app.data.net.StudentRow
import `in`.classnex.app.data.net.TeacherRow
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.KeyValue
import `in`.classnex.app.ui.common.Load
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.MiniStat
import `in`.classnex.app.ui.common.OptionPicker
import `in`.classnex.app.ui.common.SectionTitle
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.pct
import `in`.classnex.app.ui.common.prettyTime
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Red
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

/*
 * Branch management on Android. Same API and rules as the web panel:
 * branches are unlimited, share ONE subscription and ONE academy-wide student quota,
 * and moving a student between branches keeps the same profile and Student ID.
 */

// ================================================================ branch list
data class BranchesPage(val summary: BranchSummary, val quota: Quota?)

@HiltViewModel
class BranchesVm @Inject constructor(private val repo: Repository) : LoadVm<BranchesPage>() {
    init { refresh() }
    fun refresh() = load {
        val sum = call { repo.api.branchSummary() }.data!!
        val quota = runCatching { call { repo.api.dashboard() }.data?.student_quota }.getOrNull()
        BranchesPage(sum, quota)
    }

    fun save(id: Long?, body: BranchSave, done: () -> Unit) = viewModelScope.launch {
        try {
            val r = if (id == null) call { repo.api.createBranch(body) } else call { repo.api.updateBranch(id, body) }
            say(r.message); done(); refresh()
        } catch (e: Exception) { say(errorText(e)) }
    }
}

@Composable
fun BranchesScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: BranchesVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    var form by remember { mutableStateOf(false) }
    LoadContent(st, vm::refresh) { page ->
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Text("Add as many branches as you need. All branches share one subscription and one student limit.", color = Muted, fontSize = 13.sp)
                page.quota?.let { q ->
                    Spacer(Modifier.height(8.dp))
                    AppCard {
                        Column {
                            Text("${q.active} active students across all branches" + (q.limit?.let { " · package allows $it" } ?: " · no student limit"), fontWeight = FontWeight.Bold)
                            q.limit?.takeIf { it > 0 }?.let { lim ->
                                Spacer(Modifier.height(6.dp))
                                LinearProgressIndicator(progress = { (q.active.toFloat() / lim).coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth(), color = if (q.active >= lim) Red else Green)
                            }
                        }
                    }
                }
            }
            if (me.isClassAdmin && me.can("structure.manage")) item { Button(onClick = { form = true }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Outlined.Add, null); Text(" Add branch") } }
            if (page.summary.branches.isEmpty()) item { EmptyView("No branches yet. Your academy works as a single location until you add one.") }
            items(page.summary.branches) { b -> BranchFiguresCard(b, me) { b.branch_id?.let { nav("branch/$it") } } }
            if (page.summary.branches.isNotEmpty()) item {
                SectionTitle("Combined academy (this month)")
                BranchFiguresCard(page.summary.total.copy(name = "All branches"), me, null)
            }
        }
    }
    if (form) BranchFormDialog(null, onDismiss = { form = false }) { body -> vm.save(null, body) { form = false } }
}

@Composable
fun BranchFiguresCard(b: BranchFigures, me: Me, onClick: (() -> Unit)?) {
    AppCard(onClick = onClick) {
        Column {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(b.name, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (b.status == "inactive") StatusChip("inactive")
            }
            Text("${b.students} students · ${b.teachers} teachers · ${b.batches} batches · attendance ${pct(b.attendance_pct)}", color = Muted, fontSize = 12.sp)
            if (me.has("fees") && me.can("fees.view")) Text("Collected ${money(b.collected_paise)} · Pending ${money(b.pending_paise)}", fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

// ============================================================== branch form
@HiltViewModel
class TeachersVm @Inject constructor(private val repo: Repository) : LoadVm<List<TeacherRow>>() {
    init { load { runCatching { call { repo.api.teachers() }.data }.getOrNull() ?: emptyList() } }
}

@Composable
fun BranchFormDialog(b: BranchDetail?, onDismiss: () -> Unit, tvm: TeachersVm = hiltViewModel(), onSave: (BranchSave) -> Unit) {
    var name by remember { mutableStateOf(b?.name ?: "") }
    var address by remember { mutableStateOf(b?.address ?: "") }
    var phone by remember { mutableStateOf(b?.contact_number ?: "") }
    var email by remember { mutableStateOf(b?.email ?: "") }
    var manager by remember { mutableStateOf(b?.manager_teacher_id?.toString()) }
    var status by remember { mutableStateOf(b?.status ?: "active") }
    val tst by tvm.state.collectAsState()
    val teachers: List<TeacherRow> = when (val t = tst) { is Load.Ready -> t.data; else -> emptyList() }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (b == null) "Add branch" else "Edit branch") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (b == null) Text("Branches are free and unlimited. They do not change your subscription.", color = Muted, fontSize = 12.sp)
                OutlinedTextField(name, { name = it }, label = { Text("Branch name *") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(address, { address = it }, label = { Text("Address") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(phone, { phone = it }, label = { Text("Contact number") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(email, { email = it }, label = { Text("Email") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OptionPicker("Branch manager (teacher)", listOf<Pair<String?, String>>(null to "No manager") + teachers.map { it.id.toString() to it.name }, manager, Modifier.fillMaxWidth()) { manager = it }
                if (b != null) OptionPicker("Status", listOf<Pair<String?, String>>("active" to "Active", "inactive" to "Inactive (history kept)"), status, Modifier.fillMaxWidth()) { status = it ?: "active" }
            }
        },
        confirmButton = { Button(enabled = name.isNotBlank(), onClick = { onSave(BranchSave(name.trim(), address.ifBlank { null }, phone.ifBlank { null }, email.ifBlank { null }, manager?.toLongOrNull(), null, if (b != null) status else null)) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

// ============================================================ branch detail
@HiltViewModel
class BranchDetailVm @Inject constructor(private val repo: Repository) : LoadVm<BranchDetail>() {
    var id = 0L
    fun open(branchId: Long) { id = branchId; load { call { repo.api.branch(branchId) }.data!! } }
    fun save(body: BranchSave, done: () -> Unit) = viewModelScope.launch {
        try { val r = call { repo.api.updateBranch(id, body) }; say(r.message); done(); open(id) } catch (e: Exception) { say(errorText(e)) }
    }
}

@Composable
fun BranchDetailScreen(id: Long, me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: BranchDetailVm = hiltViewModel()) {
    LaunchedEffect(id) { vm.open(id) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    var edit by remember { mutableStateOf(false) }
    var confirmStatus by remember { mutableStateOf(false) }
    val manage = me.isClassAdmin && me.can("structure.manage")
    LoadContent(st, { vm.open(id) }) { b ->
        val f = b.figures
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                AppCard {
                    Column {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(b.name, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                            StatusChip(b.status)
                        }
                        b.address?.let { Text(it, color = Muted, fontSize = 13.sp) }
                        (b.manager_teacher_name ?: b.manager_name)?.let { KeyValue("Manager", it) }
                        b.contact_number?.let { KeyValue("Contact", it) }
                        b.email?.let { KeyValue("Email", it) }
                    }
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    MiniStat("Students", f.students.toString(), Modifier.weight(1f))
                    MiniStat("Teachers", f.teachers.toString(), Modifier.weight(1f))
                    MiniStat("Attendance", pct(f.attendance_pct), Modifier.weight(1f))
                }
            }
            if (me.has("fees") && me.can("fees.view")) item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    MiniStat("Collected (month)", money(f.collected_paise), Modifier.weight(1f), Green)
                    MiniStat("Pending", money(f.pending_paise), Modifier.weight(1f), if (f.pending_paise > 0) Red else Green)
                }
            }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(onClick = { nav("branchStudents/${b.id}") }, modifier = Modifier.weight(1f)) { Text("Students") }
                        OutlinedButton(onClick = { nav("history?branch=${b.id}") }, modifier = Modifier.weight(1f)) { Text("Attendance") }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (me.has("fees") && me.can("fees.view")) OutlinedButton(onClick = { nav("report/fee_collection?branch=${b.id}") }, modifier = Modifier.weight(1f)) { Text("Fee collection") }
                        if (me.has("fees") && me.can("fees.view")) OutlinedButton(onClick = { nav("report/pending_fees?branch=${b.id}") }, modifier = Modifier.weight(1f)) { Text("Pending fees") }
                    }
                    if (manage) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(onClick = { nav("assign/${b.id}") }, modifier = Modifier.weight(1f)) { Text("Assign students") }
                        OutlinedButton(onClick = { edit = true }, modifier = Modifier.weight(1f)) { Text("Edit") }
                    }
                    if (manage) TextButton(onClick = { confirmStatus = true }, modifier = Modifier.fillMaxWidth()) {
                        Text(if (b.status == "active") "Deactivate branch" else "Activate branch", color = if (b.status == "active") Red else Green)
                    }
                }
            }
            item { SectionTitle("Batches") }
            if (b.batches.isEmpty()) item { EmptyView("No batches at this branch yet.") }
            items(b.batches) { x ->
                AppCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(x.name, fontWeight = FontWeight.Bold)
                            Text("${x.activity_name}${x.start_time?.let { " · " + prettyTime(it) } ?: ""} · ${x.teacher_name ?: "No teacher"}", color = Muted, fontSize = 12.sp)
                        }
                        Column(horizontalAlignment = Alignment.End) { Text("${x.students} students", fontSize = 12.sp); Text(pct(x.attendance_pct), fontWeight = FontWeight.Bold) }
                    }
                }
            }
            item { SectionTitle("Teachers") }
            if (b.teachers.isEmpty()) item { EmptyView("No teachers assigned to this branch.") }
            items(b.teachers) { t -> AppCard { Row { Text(t.name, Modifier.weight(1f)); StatusChip(t.status) } } }
        }
        if (edit) BranchFormDialog(b, onDismiss = { edit = false }) { body -> vm.save(body) { edit = false } }
        if (confirmStatus) AlertDialog(
            onDismissRequest = { confirmStatus = false },
            title = { Text(if (b.status == "active") "Deactivate ${b.name}?" else "Activate ${b.name}?") },
            text = { Text(if (b.status == "active") "No new students can be added to an inactive branch. All history, fees and attendance are kept." else "The branch becomes available again.") },
            confirmButton = { Button(onClick = { confirmStatus = false; vm.save(BranchSave(b.name, b.address, b.contact_number, b.email, b.manager_teacher_id, b.manager_name, if (b.status == "active") "inactive" else "active")) {} }) { Text("Yes") } },
            dismissButton = { TextButton(onClick = { confirmStatus = false }) { Text("Cancel") } },
        )
    }
}

// ===================================================== assign students (bulk)
/** Server-side search + paging, so it works for academies with thousands of students. */
@HiltViewModel
class AssignStudentsVm @Inject constructor(private val repo: Repository) : LoadVm<List<StudentRow>>() {
    private var job: Job? = null
    private var page = 1
    var branchId = 0L
    var query = ""
    val rows = MutableStateFlow<List<StudentRow>>(emptyList())
    val hasMore = MutableStateFlow(false)
    val batches = MutableStateFlow<List<Batch>>(emptyList())
    val done = MutableStateFlow(false)

    fun start(branch: Long) {
        if (branchId == branch) return
        branchId = branch
        viewModelScope.launch { batches.value = runCatching { call { repo.api.batches() }.data }.getOrNull()?.filter { it.branch_id == branch } ?: emptyList() }
        search("")
    }

    fun search(q: String) {
        query = q
        job?.cancel()
        job = viewModelScope.launch {
            if (q.isNotEmpty()) delay(300)
            page = 1
            load(silent = true) { fetch() }
        }
    }

    fun more() = viewModelScope.launch { page++; runCatching { fetch() }.onFailure { say(errorText(it)) } }

    private suspend fun fetch(): List<StudentRow> {
        val r = call { repo.api.students(query.ifBlank { null }, page, 30, notBranchId = branchId) }
        val list = r.data ?: emptyList()
        rows.value = if (page == 1) list else rows.value + list
        hasMore.value = page < (r.meta?.get("pages")?.toString()?.toIntOrNull() ?: 1)
        return rows.value
    }

    fun move(ids: List<Long>, batchId: Long?, onPreview: (Long) -> Unit, confirmed: Boolean) = viewModelScope.launch {
        try {
            if (!confirmed) {
                val p = call { repo.api.bulkBranch(BulkBranch(branchId, batchId, ids, preview = 1)) }.data!!
                onPreview(p.count)
            } else {
                val r = call { repo.api.bulkBranch(BulkBranch(branchId, batchId, ids)) }
                say(r.message); done.value = true
            }
        } catch (e: Exception) { say(errorText(e)) }
    }
}

@Composable
fun AssignStudentsScreen(branchId: Long, snack: SnackbarHostState, back: () -> Unit, vm: AssignStudentsVm = hiltViewModel()) {
    LaunchedEffect(branchId) { vm.start(branchId) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val rows by vm.rows.collectAsState()
    val more by vm.hasMore.collectAsState()
    val batches by vm.batches.collectAsState()
    val done by vm.done.collectAsState()
    LaunchedEffect(done) { if (done) back() }
    var q by remember { mutableStateOf("") }
    val selected = remember { mutableStateListOf<Long>() }
    var batch by remember { mutableStateOf<String?>(null) }
    var confirmCount by remember { mutableStateOf<Long?>(null) }
    Column(Modifier.fillMaxSize()) {
        OutlinedTextField(q, { q = it; vm.search(it) }, leadingIcon = { Icon(Icons.Outlined.Search, null) }, placeholder = { Text("Name, Student ID or WhatsApp") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(16.dp, 8.dp))
        LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (rows.isEmpty()) item { EmptyView("No students to assign.") }
            items(rows, key = { it.id }) { s ->
                AppCard(onClick = { if (s.id in selected) selected.remove(s.id) else selected.add(s.id) }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(checked = s.id in selected, onCheckedChange = { c -> if (c) selected.add(s.id) else selected.remove(s.id) })
                        Column(Modifier.weight(1f)) {
                            Text(s.name, fontWeight = FontWeight.Bold)
                            Text("${s.student_code} · ${s.branch_name ?: "Main"} · ${s.whatsapp}", color = Muted, fontSize = 12.sp)
                        }
                    }
                }
            }
            if (more) item { TextButton(onClick = { vm.more() }, modifier = Modifier.fillMaxWidth()) { Text("Load more") } }
        }
        HorizontalDivider()
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (batches.isNotEmpty()) OptionPicker("Keep current batch", listOf<Pair<String?, String>>(null to "Keep current batch") + batches.map { it.id.toString() to "${it.name} (${it.activity_name})" }, batch, Modifier.fillMaxWidth()) { batch = it }
            Button(enabled = selected.isNotEmpty(), onClick = { vm.move(selected.toList(), batch?.toLongOrNull(), { confirmCount = it }, false) }, modifier = Modifier.fillMaxWidth().height(50.dp)) {
                Text("Assign ${selected.size} selected")
            }
        }
    }
    confirmCount?.let { n ->
        AlertDialog(
            onDismissRequest = { confirmCount = null },
            title = { Text("Move $n student(s)?") },
            text = { Text("They keep the same Student ID, fees and attendance history. Your student count does not change.") },
            confirmButton = { Button(onClick = { confirmCount = null; vm.move(selected.toList(), batch?.toLongOrNull(), {}, true) }) { Text("Move") } },
            dismissButton = { TextButton(onClick = { confirmCount = null }) { Text("Cancel") } },
        )
    }
}
