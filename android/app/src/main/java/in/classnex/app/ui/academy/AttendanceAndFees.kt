package `in`.classnex.app.ui.academy

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDecoration
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
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.util.UUID
import javax.inject.Inject

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
    /** The server allocates the amount to the chosen invoices (oldest first) – no calculation happens here. */
    fun pay(amount: String, method: String, reference: String, paidOn: String, notes: String, d: Dues) {
        busy.value = true
        viewModelScope.launch {
            try {
                val r = call { repo.api.pay(idem, PaymentRequest(studentId, amount, method, reference.ifBlank { null }, paidOn, d.invoices.filter { selected[it.id] == true }.map { it.id }, notes.ifBlank { null })) }
                result.value = r.data
                idem = UUID.randomUUID().toString()
            } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
        }
    }
    suspend fun receipt(r: PaymentResult) = repo.download("receipts/${r.receipt_id}/pdf?download=1", (r.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
    suspend fun whatsappLink(paymentId: Long) = call { repo.api.shareReceipt(paymentId, mapOf("channel" to "link")) }.data
}

val payMethods = listOf("cash" to "Cash", "upi" to "UPI", "bank_transfer" to "Bank Transfer", "card" to "Card", "online" to "Online", "cheque" to "Cheque", "other" to "Other")

@Composable
private fun StepTitle(n: Int, text: String) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 4.dp)) {
        Box(Modifier.size(26.dp).clip(androidx.compose.foundation.shape.CircleShape).background(Violet), contentAlignment = Alignment.Center) { Text("$n", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
        Text(text, fontWeight = FontWeight.Bold, color = Ink, fontSize = 16.sp, modifier = Modifier.padding(start = 10.dp))
    }
}

@Composable
fun CollectFeeScreen(initialStudent: Long, me: Me, snack: SnackbarHostState, nav: (String) -> Unit, vm: CollectVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    var studentId by rememberSaveable { mutableLongStateOf(initialStudent) }
    LaunchedEffect(studentId) { if (studentId > 0) vm.open(studentId) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, "Receipt")
    if (studentId == 0L) {
        Column {
            InfoBanner("Choose the student who is paying.", Violet, Icons.Outlined.PersonSearch, Modifier.padding(16.dp, 12.dp, 16.dp, 0.dp))
            StudentsScreen(me, nav, pickMode = true, onPick = { studentId = it.id })
        }
        return
    }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val result by vm.result.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var showDone by remember { mutableStateOf(false) }
    LaunchedEffect(result) { if (result != null) showDone = true }
    LoadContent(st, { vm.open(studentId) }) { d ->
        var amount by remember(d) { mutableStateOf(rupeesInput(vm.selectedTotal(d)).takeIf { it != "0" } ?: "") }
        var method by remember { mutableStateOf("cash") }
        var ref by remember { mutableStateOf("") }
        var notes by remember { mutableStateOf("") }
        var paidOn by remember { mutableStateOf(today()) }
        var moreOptions by remember { mutableStateOf(false) }
        var confirm by remember { mutableStateOf(false) }
        val paise = ((amount.toDoubleOrNull() ?: 0.0) * 100).toLong()
        Column(Modifier.fillMaxSize().imePadding()) {
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                CnxCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Avatar(null, d.student.name, 46)
                        Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                            Text(d.student.name, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, color = Ink)
                            Text("${d.student.student_code} · ${d.student.whatsapp}", color = Muted, fontSize = 12.sp)
                        }
                        if (initialStudent == 0L) TextButton(onClick = { studentId = 0 }) { Text("Change") }
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatTile("Pending Amount", money(d.balance.outstanding_paise), Icons.Outlined.HourglassBottom, Amber, Modifier.weight(1f))
                    StatTile("Overdue Amount", money(d.balance.overdue_paise), Icons.Outlined.ErrorOutline, Red, Modifier.weight(1f))
                    StatTile("Advance Paid", money(d.balance.advance_paise), Icons.Outlined.Savings, Green, Modifier.weight(1f))
                }

                StepTitle(1, "Select the fees")
                if (d.invoices.isEmpty()) InfoBanner("No pending fees. The amount you collect is kept as advance.", Green)
                d.invoices.forEach { i ->
                    val on = vm.selected[i.id] == true
                    CnxCard(onClick = { vm.selected[i.id] = !on; amount = rupeesInput(vm.selectedTotal(d)) }, padding = 12.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(on, { vm.selected[i.id] = it; amount = rupeesInput(vm.selectedTotal(d)) }, colors = CheckboxDefaults.colors(checkedColor = Violet))
                            Column(Modifier.weight(1f)) {
                                Text(i.title, fontWeight = FontWeight.Bold, color = Ink)
                                Text("Due ${prettyDate(i.due_date)}" + (if (i.status == "partial") " · partly paid" else "") + (if (i.days_overdue > 0) " · ${i.days_overdue} days late" else ""), color = if (i.days_overdue > 0) Red else Muted, fontSize = 12.sp)
                            }
                            Text(money(i.balance_paise), fontWeight = FontWeight.ExtraBold, color = Ink)
                        }
                    }
                }

                StepTitle(2, "Enter the amount")
                val sel = vm.selectedTotal(d)
                CnxField(amount, { amount = it.filter { c -> c.isDigit() || c == '.' } }, "Amount received", keyboard = KeyboardType.Decimal, prefix = "₹ ",
                    supporting = when { paise == 0L -> null; paise < sel -> "Part payment – ${money(sel - paise)} will stay pending"; paise > d.balance.outstanding_paise -> "${money(paise - d.balance.outstanding_paise)} extra will be kept as advance"; else -> "Full payment" })

                StepTitle(3, "How did they pay?")
                ChoiceChips(payMethods, method) { method = it }

                // optional details, folded away
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).pressable({ moreOptions = !moreOptions }).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(if (moreOptions) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, null, tint = Violet)
                    Text("More options (transaction no., date, note)", color = Violet, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(start = 6.dp))
                }
                androidx.compose.animation.AnimatedVisibility(moreOptions) {
                    CnxCard {
                        CnxField(ref, { ref = it }, if (method == "cheque") "Cheque number" else "Transaction / UPI reference number")
                        Spacer(Modifier.height(10.dp))
                        DateField("Payment date", paidOn) { it?.let { v -> if (v <= today()) paidOn = v } }
                        Spacer(Modifier.height(10.dp))
                        CnxField(notes, { notes = it }, "Note", singleLine = false, minLines = 2)
                    }
                }
            }
            Surface(color = Color.White, shadowElevation = 12.dp) {
                GradientButton(if (paise > 0) "Collect ${money(paise)}" else "Collect Fee", { confirm = true }, Modifier.fillMaxWidth().navigationBarsPadding().padding(16.dp),
                    enabled = paise > 0 && me.can("fees.collect"), loading = busy, icon = Icons.Outlined.ReceiptLong)
            }
        }
        if (confirm) AlertDialog(
            onDismissRequest = { confirm = false }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
            icon = { IconCircle(Icons.Outlined.CurrencyRupee, Violet) },
            title = { Text("Confirm payment") },
            text = {
                Column {
                    KeyRow("Student", d.student.name)
                    KeyRow("Amount", money(paise))
                    KeyRow("Paid by", payMethods.firstOrNull { it.first == method }?.second)
                    KeyRow("For", d.invoices.filter { vm.selected[it.id] == true }.joinToString(", ") { it.title }.ifBlank { "Advance" })
                    KeyRow("Date", prettyDate(paidOn))
                    Text("A receipt is created right after you confirm.", color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                }
            },
            confirmButton = { Button(onClick = { confirm = false; vm.pay(amount, method, ref, paidOn, notes, d) }, colors = ButtonDefaults.buttonColors(containerColor = Violet), shape = RoundedCornerShape(14.dp)) { Text("Confirm & Create Receipt") } },
            dismissButton = { TextButton(onClick = { confirm = false }) { Text("Back") } },
        )
    }
    val r = result
    if (r != null && showDone) {
        SuccessOverlay(true, "Payment saved", "${money(r.amount_paise)} · ${label(r.method)}") { showDone = false }
    }
    if (r != null && !showDone) {
        AlertDialog(onDismissRequest = { vm.result.value = null; vm.open(studentId) }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
            icon = { IconCircle(Icons.Outlined.CheckCircle, Green) },
            title = { Text("Receipt ${r.receipt_no ?: ""}") },
            text = {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(money(r.amount_paise), fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, color = Green)
                    Text("${r.student_name} · ${label(r.method)}", color = Ink)
                    Text("Balance after payment: ${money(r.balance_after_paise)}", color = Muted)
                    if (r.duplicate) InfoBanner("This payment was already saved – no duplicate was created.", Amber)
                    SoftButton("View / print PDF", { scope.launch { runCatching { openFile(ctx, vm.receipt(r)) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, Modifier.fillMaxWidth(), Icons.Outlined.PictureAsPdf)
                    SoftButton("Share PDF", { scope.launch { runCatching { shareFile(ctx, vm.receipt(r), "Receipt ${r.receipt_no} – ${money(r.amount_paise)}") }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, Modifier.fillMaxWidth(), Icons.Outlined.Share)
                    if (me.whatsapp_mode != "off") SoftButton("Send on WhatsApp", {
                        if (me.whatsappManualOnly) wa.prepare(ManualPrepare(purpose = "receipt", payment_id = r.id, student_id = vm.studentId))
                        else scope.launch { runCatching { vm.whatsappLink(r.id)?.whatsapp_link?.let { openUrl(ctx, it) } } }
                    }, Modifier.fillMaxWidth(), Icons.Outlined.Chat, Green)
                }
            },
            confirmButton = { TextButton(onClick = { vm.result.value = null; vm.open(studentId) }) { Text("Done") } })
    }
}

// ============================================================ fees lists
@HiltViewModel
class FeesVm @Inject constructor(private val repo: Repository) : LoadVm<List<Invoice>>() {
    var tab = "open"
    var q = ""
    private var job: Job? = null
    val summary = MutableStateFlow<JsonObject?>(null)
    fun start(t: Int) {
        tab = if (t == 1) "overdue" else "open"
        refresh()
        viewModelScope.launch { summary.value = runCatching { call { repo.api.getJson("fees/summary") }.data as? JsonObject }.getOrNull() }
    }
    fun refresh() = load { call { repo.api.invoices(tab, perPage = 100, q = q.ifBlank { null }) }.data ?: emptyList() }
    fun search(v: String) { q = v; job?.cancel(); job = viewModelScope.launch { delay(300); load(silent = true) { call { repo.api.invoices(tab, perPage = 100, q = q.ifBlank { null }) }.data ?: emptyList() } } }
    fun remindAll(onDone: (String) -> Unit) = viewModelScope.launch {
        onDone(try { call { repo.api.sendMessage(MessageRequest(target = "dues", overdue_only = 1, type = "fee_reminder", channels = listOf("whatsapp", "inapp", "push", "sms"), client_ref = UUID.randomUUID().toString())) }.message } catch (e: Exception) { errorText(e) })
    }
}

@Composable
fun PendingFeesScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit, initialTab: Int = 0, vm: FeesVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    var tab by rememberSaveable { mutableIntStateOf(initialTab) }
    LaunchedEffect(Unit) { vm.start(tab) }
    val st by vm.state.collectAsState()
    val sum by vm.summary.collectAsState()
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, "Overdue fee reminders")
    val scope = rememberCoroutineScope()
    var confirm by remember { mutableStateOf(false) }
    var q by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(16.dp, 12.dp, 16.dp, 4.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            sum?.let { s ->
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatTile("Pending", money(s.long("pending_paise")), Icons.Outlined.HourglassBottom, Amber, Modifier.weight(1f))
                    StatTile("Overdue", money(s.long("overdue_paise")), Icons.Outlined.ErrorOutline, Red, Modifier.weight(1f))
                    StatTile("Collected", money(s.long("collected_paise")), Icons.Outlined.Savings, Green, Modifier.weight(1f), "this month")
                }
            }
            SegmentedTabs(listOf("Pending", "Overdue"), tab) { tab = it; vm.tab = if (it == 1) "overdue" else "open"; vm.refresh() }
            SearchBox(q, { q = it; vm.search(it) }, "Search student")
            if (me.has("manual_reminders") && me.can("notifications.send")) SoftButton("Send reminder to everyone overdue", { confirm = true }, Modifier.fillMaxWidth(), Icons.Outlined.NotificationsActive)
        }
        LoadContent(st, vm::refresh) { list ->
            if (list.isEmpty()) EmptyState(if (tab == 1) "Nothing overdue 🎉" else "No pending fees 🎉", icon = Icons.Outlined.TaskAlt)
            else LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { Text("Total ${money(list.sumOf { it.balance_paise })} · ${list.size} fee(s) pending", fontWeight = FontWeight.Bold, color = Ink) }
                itemsIndexed(list, key = { _, i -> i.id }) { idx, i ->
                    Entrance(idx) {
                        PersonRow(i.student_name ?: "", "${i.title} · due ${prettyDate(i.due_date)}" + (if (i.days_overdue > 0) " · ${i.days_overdue} days overdue" else "") + (i.branch_name?.let { " · $it" } ?: ""),
                            onClick = { if (me.can("fees.collect")) nav("collect/${i.student_id}") },
                            trailing = {
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(money(i.balance_paise), fontWeight = FontWeight.ExtraBold, color = Ink)
                                    Pill(if (i.days_overdue > 0) "Overdue" else label(i.status), if (i.days_overdue > 0) Red else statusColor(i.status))
                                }
                            })
                    }
                }
            }
        }
    }
    if (confirm) ConfirmDialog("Send reminders?", "Every family with overdue fees gets a reminder with their own amount." + if (me.whatsappManualOnly) " WhatsApp messages are prepared for you to send." else "", "Send", onDismiss = { confirm = false }) {
        confirm = false
        vm.remindAll { m -> scope.launch { snack.showSnackbar(m) } }
        if (me.whatsappManualOnly) wa.prepare(ManualPrepare(purpose = "fee_reminder", target = "dues", overdue_only = 1))
    }
}

@HiltViewModel
class PaymentsVm @Inject constructor(private val repo: Repository) : LoadVm<List<PaymentRow>>() {
    var q = ""
    private var job: Job? = null
    init { refresh() }
    fun refresh(silent: Boolean = false) = load(silent) { call { repo.api.payments(perPage = 100, q = q.ifBlank { null }) }.data ?: emptyList() }
    fun search(v: String) { q = v; job?.cancel(); job = viewModelScope.launch { delay(300); refresh(true) } }
    suspend fun receipt(p: PaymentRow) = repo.download("receipts/${p.receipt_id}/pdf?download=1", (p.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
    suspend fun whatsappLink(paymentId: Long) = call { repo.api.shareReceipt(paymentId, mapOf("channel" to "link")) }.data
    fun reverse(p: PaymentRow, reason: String) = viewModelScope.launch {
        try { val r = call { repo.api.postJson("payments/${p.id}/reverse", JsonObject(mapOf("reason" to JsonPrimitive(reason)))) }; say(r.message); refresh(true) } catch (e: Exception) { say(errorText(e)) }
    }
}

@Composable
fun PaymentsScreen(snack: SnackbarHostState, me: Me? = null, vm: PaymentsVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var q by remember { mutableStateOf(vm.q) }
    var open by remember { mutableStateOf<Long?>(null) }
    var reverse by remember { mutableStateOf<PaymentRow?>(null) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, "Receipt")
    Column(Modifier.fillMaxSize()) {
        SearchBox(q, { q = it; vm.search(it) }, "Search receipt no. or student", Modifier.padding(16.dp, 12.dp, 16.dp, 4.dp))
        LoadContent(st, { vm.refresh() }) { list ->
            if (list.isEmpty()) EmptyState("No payments yet", "Payments you collect appear here with their receipts.", Icons.Outlined.ReceiptLong)
            else LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                itemsIndexed(list, key = { _, p -> p.id }) { i, p ->
                    Entrance(i) {
                        CnxCard(Modifier.animateContentSize(), onClick = { open = if (open == p.id) null else p.id }, padding = 12.dp) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconCircle(Icons.Outlined.ReceiptLong, if (p.status == "success") Green else Red, 40.dp, 19.dp)
                                Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                    Text(p.student_name ?: prettyDate(p.paid_on), fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text("${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}" + (p.branch_name?.let { " · $it" } ?: ""), color = Muted, fontSize = 12.sp, maxLines = 2)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, color = Ink, textDecoration = if (p.status == "reversed") TextDecoration.LineThrough else null)
                                    if (p.status != "success") Pill(label(p.status), statusColor(p.status))
                                }
                            }
                            if (open == p.id) {
                                HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Line)
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    if (p.receipt_id != null) {
                                        SoftButton("PDF", { scope.launch { runCatching { openFile(ctx, vm.receipt(p)) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, Modifier.weight(1f), Icons.Outlined.PictureAsPdf)
                                        SoftButton("Share", { scope.launch { runCatching { shareFile(ctx, vm.receipt(p), "Receipt ${p.receipt_no} – ${money(p.amount_paise)}") }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, Modifier.weight(1f), Icons.Outlined.Share)
                                    }
                                    if (me?.whatsapp_mode != "off" && p.status == "success") SoftButton("WhatsApp", {
                                        if (me?.whatsappManualOnly == true) wa.prepare(ManualPrepare(purpose = "receipt", payment_id = p.id))
                                        else scope.launch { runCatching { vm.whatsappLink(p.id)?.whatsapp_link?.let { openUrl(ctx, it) } }.onFailure { snack.showSnackbar(it.message ?: "Could not share") } }
                                    }, Modifier.weight(1f), Icons.Outlined.Chat, Green)
                                }
                                if (me != null && me.can("payments.reverse") && me.isClassAdmin && p.status == "success") {
                                    Spacer(Modifier.height(8.dp))
                                    SoftButton("Cancel this payment", { reverse = p }, Modifier.fillMaxWidth(), Icons.Outlined.Undo, Red)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    reverse?.let { p ->
        var reason by remember { mutableStateOf("") }
        AlertDialog(onDismissRequest = { reverse = null }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
            icon = { IconCircle(Icons.Outlined.WarningAmber, Red) },
            title = { Text("Cancel payment of ${money(p.amount_paise)}?") },
            text = { Column { Text("The receipt is cancelled and the fee becomes pending again. This is recorded in the activity log.", color = Muted); Spacer(Modifier.height(10.dp)); CnxField(reason, { reason = it }, "Reason *", singleLine = false, minLines = 2) } },
            confirmButton = { Button(onClick = { vm.reverse(p, reason); reverse = null }, enabled = reason.isNotBlank(), colors = ButtonDefaults.buttonColors(containerColor = Red)) { Text("Cancel payment") } },
            dismissButton = { TextButton(onClick = { reverse = null }) { Text("Cancel") } })
    }
}

/** Kept for screens that only need a read-only list of payments (portal). */
@Composable
fun PaymentList(list: List<PaymentRow>, ctx: android.content.Context, onOpen: (PaymentRow) -> Unit) {
    if (list.isEmpty()) EmptyState("No payments yet", icon = Icons.Outlined.ReceiptLong)
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        itemsIndexed(list, key = { _, p -> p.id }) { i, p ->
            Entrance(i) {
                PersonRow(p.student_name ?: prettyDate(p.paid_on), "${p.receipt_no ?: ""} · ${label(p.method)} · ${prettyDate(p.paid_on)}", onClick = { if (p.receipt_id != null) onOpen(p) }, trailing = {
                    Column(horizontalAlignment = Alignment.End) {
                        Text(money(p.amount_paise), fontWeight = FontWeight.ExtraBold, color = Ink, textDecoration = if (p.status == "reversed") TextDecoration.LineThrough else null)
                        if (p.status != "success") Pill(label(p.status), statusColor(p.status)) else if (p.receipt_id != null) Icon(Icons.Outlined.Download, null, tint = Muted, modifier = Modifier.size(18.dp))
                    }
                })
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
fun MessageScreen(me: Me, studentId: Long?, snack: SnackbarHostState, back: () -> Unit, initialType: String = "message", vm: MessageVm = hiltViewModel(), wa: WhatsAppVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    var target by remember { mutableStateOf(if (studentId != null) "students" else if (initialType == "fee_reminder") "dues" else "batch") }
    var batch by remember { mutableStateOf<String?>(null) }
    var type by remember { mutableStateOf(if (initialType == "fee_reminder" && me.has("fees")) "fee_reminder" else "message") }
    var text by remember { mutableStateOf("") }
    val channels = remember { mutableStateMapOf<String, Boolean>().apply { listOf("whatsapp", "inapp", "push").filter { me.has(it) }.forEach { put(it, true) }; if (me.has("whatsapp_manual")) put("wa_manual", me.whatsappManualOnly) } }
    LaunchedEffect(Unit) { wa.messages.collect { snack.showSnackbar(it) } }
    WhatsAppSendDialog(wa, if (type == "fee_reminder") "Fee reminders" else "Message")
    LoadContent(st, {}) { batches ->
        Column(Modifier.fillMaxSize().imePadding()) {
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                when (me.whatsapp_mode) {
                    "api" -> InfoBanner("WhatsApp is automatic (official WhatsApp Business API). Delivery status appears in the Message Log.", Green, Icons.Outlined.Verified)
                    "manual" -> InfoBanner("WhatsApp is click-to-send: ClassNex prepares each message and you press send in WhatsApp.", Amber, Icons.Outlined.TouchApp)
                    else -> InfoBanner("WhatsApp is not included in your plan. Messages go to the app inbox and notifications.", Muted, Icons.Outlined.Info)
                }
                CnxCard {
                    if (studentId == null) {
                        Text("Send to", fontWeight = FontWeight.Bold, color = Ink)
                        Spacer(Modifier.height(6.dp))
                        ChoiceChips(buildList { add("batch" to "A batch"); if (me.isClassAdmin) add("all" to "Everyone"); if (me.has("fees")) add("dues" to "With pending fees") }, target) { target = it }
                        if (target == "batch") { Spacer(Modifier.height(10.dp)); SelectField("Batch", batches.map { it.id.toString() to "${it.name} · ${it.activity_name}" }, batch) { batch = it } }
                        Spacer(Modifier.height(12.dp))
                    }
                    if (me.has("fees")) {
                        SegmentedTabs(listOf("Message", "Fee reminder"), if (type == "message") 0 else 1) { type = if (it == 0) "message" else "fee_reminder" }
                        Spacer(Modifier.height(12.dp))
                    }
                    if (type == "message") CnxField(text, { if (it.length <= 1000) text = it }, "Message", singleLine = false, minLines = 4, supporting = "${text.length}/1000")
                    else Text("Each family receives a reminder with their own pending amount.", color = Muted)
                }
                CnxCard {
                    Text("Send via", fontWeight = FontWeight.Bold, color = Ink)
                    channels.keys.toList().forEach { c ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(when (c) { "inapp" -> "App inbox"; "whatsapp" -> "WhatsApp (automatic)"; "wa_manual" -> "WhatsApp – click to send"; "push" -> "Push notification"; else -> label(c) }, modifier = Modifier.weight(1f), color = Ink)
                            Switch(channels[c] == true, { channels[c] = it }, colors = SwitchDefaults.colors(checkedTrackColor = Violet))
                        }
                    }
                }
            }
            Surface(color = Color.White, shadowElevation = 12.dp) {
                GradientButton("Send", {
                    val chosen = channels.filterValues { it }.keys.toList()
                    val auto = chosen - "wa_manual"
                    val bid = batch?.toLongOrNull()
                    if ("wa_manual" in chosen) wa.prepare(ManualPrepare(purpose = if (type == "fee_reminder") "fee_reminder" else "custom", target = target, batch_id = bid, student_ids = listOfNotNull(studentId), message = text))
                    if (auto.isNotEmpty()) vm.send(MessageRequest(target = target, batch_id = bid, student_ids = listOfNotNull(studentId), message = text, type = type, channels = auto, client_ref = UUID.randomUUID().toString())) { if ("wa_manual" !in chosen) back() }
                }, Modifier.fillMaxWidth().navigationBarsPadding().padding(16.dp), enabled = (type == "fee_reminder" || text.length >= 2) && (target != "batch" || studentId != null || batch != null) && channels.values.any { it },
                    loading = busy, icon = Icons.AutoMirrored.Outlined.Send)
            }
        }
    }
}
