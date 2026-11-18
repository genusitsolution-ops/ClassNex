package `in`.classnex.app.ui.academy

import android.content.Context
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Download
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.AttEntry
import `in`.classnex.app.data.net.AttSave
import `in`.classnex.app.data.net.AttSheet
import `in`.classnex.app.data.net.Batch
import `in`.classnex.app.data.net.Dues
import `in`.classnex.app.data.net.Invoice
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.MessageRequest
import `in`.classnex.app.data.net.PaymentRequest
import `in`.classnex.app.data.net.PaymentResult
import `in`.classnex.app.data.net.PaymentRow
import `in`.classnex.app.data.net.TodayAtt
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.Avatar
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.openFile
import `in`.classnex.app.ui.common.openUrl
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.common.prettyTime
import `in`.classnex.app.ui.common.rupeesInput
import `in`.classnex.app.ui.common.shareFile
import `in`.classnex.app.ui.common.today
import `in`.classnex.app.ui.theme.Amber
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Purple
import `in`.classnex.app.ui.theme.Red
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import java.util.UUID
import javax.inject.Inject

// ============================================================== attendance
@HiltViewModel
class AttendanceListVm @Inject constructor(private val repo: Repository) : LoadVm<TodayAtt>() {
    init { refresh() }
    fun refresh() = load { call { repo.api.attendanceToday(today()) }.data!! }
}

@Composable
fun AttendanceListScreen(nav: (String) -> Unit, vm: AttendanceListVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    LoadContent(st, vm::refresh) { d ->
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("Today · ${prettyDate(d.date)}", fontWeight = FontWeight.ExtraBold, fontSize = 18.sp) }
            if (d.batches.isEmpty()) item { EmptyView("No batches yet") }
            items(d.batches) { b ->
                AppCard(onClick = { nav("attendance/${b.id}") }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(b.name, fontWeight = FontWeight.Bold)
                            Text("${b.activity_name}${b.start_time?.let { " · " + prettyTime(it) } ?: ""} · ${b.enrolled} students", color = Muted, fontSize = 13.sp)
                            if (b.session_id != null) Text("${b.present} present · ${b.absent} absent", color = Green, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                            else if (b.scheduled) Text("Not marked yet", color = Amber, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                        }
                        if (b.session_id != null) Icon(Icons.Outlined.CheckCircle, null, tint = Green)
                    }
                }
            }
        }
    }
}

@HiltViewModel
class AttendanceSheetVm @Inject constructor(private val repo: Repository) : LoadVm<AttSheet>() {
    val marks = mutableStateMapOf<Long, String>()
    /** Manual WhatsApp mode: after saving, offer click-to-send messages for absent / late students. */
    var manualWhatsApp = false
    val absentToNotify = MutableStateFlow(0)
    val saving = MutableStateFlow(false)
    var batchId = 0L
    fun open(id: Long) {
        batchId = id
        load {
            val s = call { repo.api.attendanceSheet(id, today()) }.data!!
            marks.clear()
            s.students.forEach { st -> st.status?.let { marks[st.id] = it } }
            s
        }
    }
    fun all(status: String) { (state.value as? `in`.classnex.app.ui.common.Load.Ready)?.data?.students?.forEach { marks[it.id] = status } }
    fun save(done: () -> Unit) {
        if (marks.isEmpty()) { say("Mark at least one student."); return }
        saving.value = true
        viewModelScope.launch {
            try {
                val r = call { repo.api.saveAttendance(AttSave(batchId, today(), marks.map { AttEntry(it.key, it.value) })) }
                say(r.message + if ((r.data?.notifications ?: 0) > 0) " Parents notified: ${r.data?.notifications}." else "")
                absentToNotify.value = marks.values.count { it == "absent" || it == "late" }
                if (absentToNotify.value == 0 || !manualWhatsApp) done()
            } catch (e: Exception) { say(errorText(e)) } finally { saving.value = false }
        }
    }
}

@Composable
fun AttendanceSheetScreen(batchId: Long, snack: SnackbarHostState, back: () -> Unit, me: Me? = null, vm: AttendanceSheetVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    vm.manualWhatsApp = me?.whatsappManualOnly == true && me.can("notifications.send")
    LaunchedEffect(batchId) { vm.open(batchId) }
    val absentN by vm.absentToNotify.collectAsState()
    val waItems by wa.items.collectAsState()
    var asked by remember { mutableStateOf(false) }
    WhatsAppSendDialog(wa, "Attendance alerts")
    LaunchedEffect(waItems, asked) { if (asked && waItems.isEmpty()) back() }
    if (vm.manualWhatsApp && absentN > 0 && !asked) AlertDialog(
        onDismissRequest = { back() },
        title = { Text("Message parents on WhatsApp?") },
        text = { Text("$absentN student(s) absent or late. Prepare WhatsApp messages for their parents?") },
        confirmButton = { Button(onClick = { asked = true; wa.prepare(`in`.classnex.app.data.net.ManualPrepare(purpose = "attendance", batch_id = batchId, date = today())) }) { Text("Prepare") } },
        dismissButton = { TextButton(onClick = { back() }) { Text("Skip") } },
    )
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val saving by vm.saving.collectAsState()
    LoadContent(st, { vm.open(batchId) }) { sheet ->
        Column(Modifier.fillMaxSize()) {
            if (sheet.already_marked) Text("Already marked today – changes are saved as corrections.", color = Green, modifier = Modifier.padding(16.dp, 8.dp), fontSize = 13.sp)
            sheet.holiday?.let { Text("Holiday: $it", color = Purple, modifier = Modifier.padding(horizontal = 16.dp)) }
            Row(Modifier.padding(16.dp, 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { vm.all("present") }, colors = ButtonDefaults.buttonColors(containerColor = Green)) { Text("All present") }
                OutlinedButton(onClick = { vm.all("absent") }) { Text("All absent", color = Red) }
            }
            if (sheet.students.isEmpty()) EmptyView("No students in this batch")
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(sheet.students, key = { it.id }) { s ->
                    AppCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Avatar(s.photo_url, s.name, 38)
                            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) { Text(s.name, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis); Text(s.student_code, color = Muted, fontSize = 12.sp) }
                            listOf("present" to "P", "absent" to "A", "late" to "L", "leave" to "Lv").forEach { (k, l) ->
                                val on = vm.marks[s.id] == k
                                val c = when (k) { "present" -> Green; "absent" -> Red; "late" -> Amber; else -> Purple }
                                Surface(onClick = { vm.marks[s.id] = k }, shape = RoundedCornerShape(10.dp), color = if (on) c else Color.White, border = BorderStroke(1.5.dp, if (on) c else Color(0xFFD9DEEA)), modifier = Modifier.padding(start = 4.dp).size(42.dp, 40.dp)) {
                                    Box(contentAlignment = Alignment.Center) { Text(l, fontWeight = FontWeight.ExtraBold, color = if (on) Color.White else Muted) }
                                }
                            }
                        }
                    }
                }
            }
            Surface(shadowElevation = 8.dp) {
                Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    val c = vm.marks.values.groupingBy { it }.eachCount()
                    Text("${c["present"] ?: 0} P · ${c["absent"] ?: 0} A · ${sheet.students.size - vm.marks.size} left", Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    Button(onClick = { vm.save(back) }, enabled = !saving, modifier = Modifier.height(50.dp)) { if (saving) CircularProgressIndicator(Modifier.size(20.dp), color = Color.White) else Text("Save", fontSize = 16.sp) }
                }
            }
        }
    }
}

// ================================================================ collect
@HiltViewModel
class CollectVm @Inject constructor(private val repo: Repository) : LoadVm<Dues>() {
    val selected = mutableStateMapOf<Long, Boolean>()
    val busy = MutableStateFlow(false)
    val result = MutableStateFlow<PaymentResult?>(null)
    private var idem = UUID.randomUUID().toString()
    var studentId = 0L
    fun open(id: Long) {
        studentId = id
        idem = UUID.randomUUID().toString()
        load { call { repo.api.dues(id) }.data!!.also { d -> selected.clear(); d.invoices.forEach { selected[it.id] = true } } }
    }
    fun selectedTotal(d: Dues) = d.invoices.filter { selected[it.id] == true }.sumOf { it.balance_paise }
    fun pay(amount: String, method: String, reference: String, d: Dues) {
        busy.value = true
        viewModelScope.launch {
            try {
                val r = call { repo.api.pay(idem, PaymentRequest(studentId, amount, method, reference.ifBlank { null }, today(), d.invoices.filter { selected[it.id] == true }.map { it.id })) }
                result.value = r.data
                idem = UUID.randomUUID().toString()
            } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
        }
    }
    suspend fun receipt(r: PaymentResult) = repo.download("receipts/${r.receipt_id}/pdf?download=1", (r.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
    suspend fun whatsappLink(paymentId: Long) = call { repo.api.shareReceipt(paymentId, mapOf("channel" to "link")) }.data
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CollectFeeScreen(initialStudent: Long, me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: CollectVm = hiltViewModel()) {
    var studentId by remember { mutableStateOf(initialStudent) }
    LaunchedEffect(studentId) { if (studentId > 0) vm.open(studentId) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    if (studentId == 0L) {
        Column { Text("Choose a student", Modifier.padding(16.dp, 12.dp, 16.dp, 0.dp), fontWeight = FontWeight.Bold); StudentsScreen(me, nav, pickMode = true, onPick = { studentId = it.id }) }
        return
    }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val result by vm.result.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    LoadContent(st, { vm.open(studentId) }) { d ->
        var amount by remember(d) { mutableStateOf(rupeesInput(vm.selectedTotal(d)).takeIf { it != "0" } ?: "") }
        var method by remember { mutableStateOf("cash") }
        var ref by remember { mutableStateOf("") }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            AppCard {
                Column {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) { Text(d.student.name, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp); Text("${d.student.student_code} · ${d.student.whatsapp}", color = Muted, fontSize = 13.sp) }
                        TextButton(onClick = { studentId = 0 }) { Text("Change") }
                    }
                    Row(Modifier.padding(top = 8.dp)) {
                        Column(Modifier.weight(1f)) { Text("Pending", color = Muted, fontSize = 12.sp); Text(money(d.balance.outstanding_paise), color = Red, fontWeight = FontWeight.ExtraBold) }
                        Column(Modifier.weight(1f)) { Text("Overdue", color = Muted, fontSize = 12.sp); Text(money(d.balance.overdue_paise), fontWeight = FontWeight.Bold) }
                        Column(Modifier.weight(1f)) { Text("Advance", color = Muted, fontSize = 12.sp); Text(money(d.balance.advance_paise), color = Green, fontWeight = FontWeight.Bold) }
                    }
                }
            }
            if (d.invoices.isEmpty()) AppCard { Text("No pending dues. Any amount collected is kept as advance.", color = Green) }
            d.invoices.forEach { i ->
                AppCard(onClick = { vm.selected[i.id] = !(vm.selected[i.id] ?: false); amount = rupeesInput(vm.selectedTotal(d)) }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(vm.selected[i.id] == true, { vm.selected[i.id] = it; amount = rupeesInput(vm.selectedTotal(d)) })
                        Column(Modifier.weight(1f)) { Text(i.title, fontWeight = FontWeight.Bold); Text("Due ${prettyDate(i.due_date)}${if (i.status == "partial") " · partly paid" else ""}", color = Muted, fontSize = 12.sp) }
                        Text(money(i.balance_paise), fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
            OutlinedTextField(amount, { amount = it.filter { c -> c.isDigit() || c == '.' } }, label = { Text("Amount received *") }, prefix = { Text("₹ ") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
                supportingText = {
                    val a = ((amount.toDoubleOrNull() ?: 0.0) * 100).toLong()
                    val sel = vm.selectedTotal(d)
                    Text(when { a == 0L -> ""; a < sel -> "Partial payment · ${money(sel - a)} stays pending"; a > d.balance.outstanding_paise -> "${money(a - d.balance.outstanding_paise)} kept as advance"; else -> "Full payment" })
                })
            Text("Payment method", fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("cash" to "Cash", "upi" to "UPI", "bank_transfer" to "Bank", "cheque" to "Cheque", "card" to "Card", "other" to "Other").forEach { (k, l) ->
                    FilterChip(selected = method == k, onClick = { method = k }, label = { Text(l) })
                }
            }
            if (method != "cash") OutlinedTextField(ref, { ref = it }, label = { Text("Transaction / cheque no.") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            Button(onClick = { vm.pay(amount, method, ref, d) }, enabled = !busy && (amount.toDoubleOrNull() ?: 0.0) > 0, modifier = Modifier.fillMaxWidth().height(54.dp)) {
                if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White) else Text("Save & generate receipt", fontSize = 16.sp)
            }
        }
    }
    result?.let { r ->
        AlertDialog(onDismissRequest = { vm.result.value = null; vm.open(studentId) }, title = { Text("Payment saved ✅") },
            text = {
                Column {
                    Text(money(r.amount_paise), fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, color = Green)
                    Text("${r.student_name} · ${label(r.method)}")
                    Text("Receipt ${r.receipt_no}", fontWeight = FontWeight.Bold)
                    Text("Balance: ${money(r.balance_after_paise)}", color = Muted)
                    if (r.duplicate) Text("This payment was already saved — no duplicate created.", color = Amber)
                    Spacer(Modifier.height(12.dp))
                    OutlinedButton(onClick = { scope.launch { runCatching { openFile(ctx, vm.receipt(r)) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Outlined.Download, null); Text(" View / print PDF") }
                    OutlinedButton(onClick = { scope.launch { runCatching { shareFile(ctx, vm.receipt(r), "Receipt ${r.receipt_no} – ${money(r.amount_paise)}") } } }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Outlined.Share, null); Text(" Share PDF") }
                    Button(onClick = { scope.launch { runCatching { vm.whatsappLink(r.id)?.whatsapp_link?.let { openUrl(ctx, it) } } } }, colors = ButtonDefaults.buttonColors(containerColor = Green), modifier = Modifier.fillMaxWidth()) { Text("Send on WhatsApp") }
                }
            },
            confirmButton = { TextButton(onClick = { vm.result.value = null; vm.open(studentId) }) { Text("Done") } })
    }
}

// ============================================================ fees lists
@HiltViewModel
class FeesVm @Inject constructor(private val repo: Repository) : LoadVm<List<Invoice>>() {
    var tab = "open"
    init { refresh() }
    fun refresh() = load { call { repo.api.invoices(tab) }.data ?: emptyList() }
    fun remindAll(onDone: (String) -> Unit) = viewModelScope.launch {
        onDone(try { call { repo.api.sendMessage(MessageRequest(target = "dues", overdue_only = 1, type = "fee_reminder", channels = listOf("whatsapp", "inapp", "push", "sms"), client_ref = UUID.randomUUID().toString())) }.message } catch (e: Exception) { errorText(e) })
    }
}

@Composable
fun PendingFeesScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: FeesVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, "Overdue fee reminders")
    var tab by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    var confirm by remember { mutableStateOf(false) }
    Column {
        TabRow(tab) { listOf("Pending" to "open", "Overdue" to "overdue").forEachIndexed { i, (l, k) -> Tab(tab == i, { tab = i; vm.tab = k; vm.refresh() }, text = { Text(l) }) } }
        if (me.has("manual_reminders") && me.can("notifications.send")) TextButton(onClick = { confirm = true }, modifier = Modifier.padding(horizontal = 8.dp)) { Text("Send reminder to all overdue") }
        LoadContent(st, vm::refresh) { list ->
            if (list.isEmpty()) EmptyView("No pending fees 🎉")
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item { Text("Total: ${money(list.sumOf { it.balance_paise })} · ${list.size} invoice(s)", fontWeight = FontWeight.Bold) }
                items(list) { i ->
                    AppCard(onClick = { if (me.can("fees.collect")) nav("collect/${i.student_id}") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(i.student_name ?: "", fontWeight = FontWeight.Bold)
                                Text(i.title, color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text("Due ${prettyDate(i.due_date)}" + if (i.days_overdue > 0) " · ${i.days_overdue} days overdue" else "", color = if (i.days_overdue > 0) Red else Muted, fontSize = 12.sp)
                            }
                            Text(money(i.balance_paise), fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
            }
        }
    }
    if (confirm) AlertDialog(onDismissRequest = { confirm = false }, title = { Text("Send reminders?") }, text = { Text("Every family with overdue fees gets a reminder with their own amount.") },
        confirmButton = { Button(onClick = {
            confirm = false
            vm.remindAll { m -> scope.launch { snack.showSnackbar(m) } }
            // manual mode: one click-to-send WhatsApp message per family (bulk reminder preparation)
            if (me.whatsappManualOnly) wa.prepare(`in`.classnex.app.data.net.ManualPrepare(purpose = "fee_reminder", target = "dues", overdue_only = 1))
        }) { Text("Send") } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } })
}

@HiltViewModel
class PaymentsVm @Inject constructor(private val repo: Repository) : LoadVm<List<PaymentRow>>() {
    init { refresh() }
    fun refresh() = load { call { repo.api.payments() }.data ?: emptyList() }
    suspend fun receipt(p: PaymentRow) = repo.download("receipts/${p.receipt_id}/pdf?download=1", (p.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
}

@Composable
fun PaymentsScreen(snack: SnackbarHostState, vm: PaymentsVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    LoadContent(st, vm::refresh) { list -> PaymentList(list, ctx, onOpen = { p -> scope.launch { runCatching { openFile(ctx, vm.receipt(p)) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }) }
}

@Composable
fun PaymentList(list: List<PaymentRow>, ctx: Context, onOpen: (PaymentRow) -> Unit) {
    if (list.isEmpty()) EmptyView("No payments yet")
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items(list) { p ->
            AppCard(onClick = { if (p.receipt_id != null) onOpen(p) }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.student_name ?: prettyDate(p.paid_on), fontWeight = FontWeight.Bold)
                        Text("${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}", color = Muted, fontSize = 12.sp)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, textDecoration = if (p.status == "reversed") TextDecoration.LineThrough else null)
                        if (p.status != "success") StatusChip(p.status) else if (p.receipt_id != null) Icon(Icons.Outlined.Download, null, tint = Muted, modifier = Modifier.size(18.dp))
                    }
                }
            }
        }
    }
}

// ================================================================ message
@HiltViewModel
class MessageVm @Inject constructor(private val repo: Repository) : LoadVm<List<Batch>>() {
    val busy = MutableStateFlow(false)
    init { load { call { repo.api.batches() }.data ?: emptyList() } }
    fun send(req: MessageRequest, done: () -> Unit) {
        busy.value = true
        viewModelScope.launch {
            try { val r = call { repo.api.sendMessage(req) }; say(r.message); done() } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun MessageScreen(me: Me, studentId: Long?, snack: SnackbarHostState, back: () -> Unit, vm: MessageVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    var target by remember { mutableStateOf(if (studentId != null) "students" else "batch") }
    var batch by remember { mutableStateOf<Long?>(null) }
    var type by remember { mutableStateOf("message") }
    var text by remember { mutableStateOf("") }
    val channels = remember { mutableStateMapOf<String, Boolean>().apply { listOf("whatsapp", "inapp", "push").filter { me.has(it) }.forEach { put(it, true) }; if (me.has("whatsapp_manual")) put("wa_manual", me.whatsappManualOnly) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, if (type == "fee_reminder") "Fee reminders" else "Message")
    LoadContent(st, {}) { batches ->
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (studentId == null) {
                Text("Send to", fontWeight = FontWeight.Bold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    buildList { add("batch" to "A batch"); if (me.role == "owner") add("all" to "Everyone"); if (me.has("fees")) add("dues" to "With pending fees") }.forEach { (k, l) -> FilterChip(target == k, { target = k }, label = { Text(l) }) }
                }
                if (target == "batch") Picker("Batch", batches.map { it.id to "${it.name} · ${it.activity_name}" }, batch, null) { batch = it }
            }
            if (me.has("fees")) FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(type == "message", { type = "message" }, label = { Text("Message") })
                FilterChip(type == "fee_reminder", { type = "fee_reminder" }, label = { Text("Fee reminder") })
            }
            if (type == "message") OutlinedTextField(text, { if (it.length <= 1000) text = it }, label = { Text("Message") }, minLines = 4, modifier = Modifier.fillMaxWidth(), supportingText = { Text("${text.length}/1000") })
            else Text("Each family receives a reminder with their own pending amount.", color = Muted)
            Text("Send via", fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) { channels.keys.toList().forEach { c -> FilterChip(channels[c] == true, { channels[c] = !(channels[c] ?: false) }, label = { Text(when (c) { "inapp" -> "App inbox"; "whatsapp" -> "WhatsApp (automatic)"; "wa_manual" -> "WhatsApp – click to send"; else -> label(c) }) }) } }
            Button(onClick = {
                val chosen = channels.filterValues { it }.keys.toList()
                val auto = chosen - "wa_manual"
                if ("wa_manual" in chosen) wa.prepare(`in`.classnex.app.data.net.ManualPrepare(purpose = if (type == "fee_reminder") "fee_reminder" else "custom", target = target, batch_id = batch, student_ids = listOfNotNull(studentId), message = text))
                if (auto.isNotEmpty()) vm.send(MessageRequest(target = target, batch_id = batch, student_ids = listOfNotNull(studentId), message = text, type = type, channels = auto, client_ref = UUID.randomUUID().toString())) { if ("wa_manual" !in chosen) back() }
            }, enabled = !busy && (type == "fee_reminder" || text.length >= 2), modifier = Modifier.fillMaxWidth().height(52.dp)) { Text("Send") }
        }
    }
}
