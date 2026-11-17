package `in`.classnex.app.ui.portal

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.CurrencyRupee
import androidx.compose.material.icons.outlined.Receipt
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.AttMonth
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.PaymentRow
import `in`.classnex.app.data.net.PortalDashboard
import `in`.classnex.app.data.net.PortalFees
import `in`.classnex.app.ui.academy.PaymentList
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.Avatar
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.SectionTitle
import `in`.classnex.app.ui.common.StatCard
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.openFile
import `in`.classnex.app.ui.common.openUrl
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.common.prettyTime
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Purple
import `in`.classnex.app.ui.theme.Red
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import java.time.YearMonth
import javax.inject.Inject

/** Shared selected-child state (persisted), so parents switch children without logging out. */
@HiltViewModel
class ChildVm @Inject constructor(private val repo: Repository) : androidx.lifecycle.ViewModel() {
    val child = MutableStateFlow(repo.session.selectedChild)
    fun ensure(me: Me) { if (me.children.none { it.id == child.value }) select(me.children.firstOrNull()?.id ?: 0) }
    fun select(id: Long) { repo.session.selectedChild = id; child.value = id }
}

@Composable
fun ChildSwitcher(me: Me, selected: Long, onSelect: (Long) -> Unit) {
    if (me.children.size < 2) return
    LazyRow(contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(me.children) { c -> FilterChip(c.id == selected, { onSelect(c.id) }, label = { Text(c.name.substringBefore(' ')) }, leadingIcon = { Avatar(c.photo_url, c.name, 24) }) }
    }
}

@HiltViewModel
class PortalHomeVm @Inject constructor(private val repo: Repository) : LoadVm<PortalDashboard>() {
    fun open(id: Long) = load { call { repo.api.portalDashboard(id) }.data!! }
    suspend fun receipt(id: Long, no: String) = repo.download("receipts/$id/pdf?download=1", no.replace("/", "-") + ".pdf")
}

@Composable
fun PortalHomeScreen(me: Me, snack: SnackbarHostState, nav: (String) -> Unit, childVm: ChildVm = hiltViewModel(), vm: PortalHomeVm = hiltViewModel()) {
    LaunchedEffect(me) { childVm.ensure(me) }
    val child by childVm.child.collectAsState()
    LaunchedEffect(child) { if (child > 0) vm.open(child) }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    if (me.children.isEmpty()) { EmptyView("No student is linked to your account yet. Please contact the academy."); return }
    Column {
        ChildSwitcher(me, child) { childVm.select(it) }
        LoadContent(st, { vm.open(child) }) { d ->
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    AppCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Avatar(d.student.photo_url, d.student.name, 60)
                            Column(Modifier.padding(start = 12.dp)) {
                                Text(d.student.name, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
                                Text("ID ${d.student.student_code}", color = Muted)
                                d.enrollments.forEach { Text("${it.activity_name}${it.batch_name?.let { b -> " · $b" } ?: ""}", fontSize = 13.sp) }
                            }
                        }
                    }
                }
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        StatCard("Today", d.today_attendance?.status?.let(::label) ?: "Not marked", Icons.Outlined.CheckCircle, if (d.today_attendance?.status == "absent") Red else Green, Modifier.weight(1f)) { nav("portalAttendance") }
                        StatCard("This month", d.month_attendance.percentage?.let { "$it%" } ?: "–", Icons.Outlined.CalendarMonth, Blue, Modifier.weight(1f)) { nav("portalAttendance") }
                    }
                }
                d.fees?.let { f ->
                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            StatCard("Pending fees", money(f.outstanding_paise), Icons.Outlined.CurrencyRupee, if (f.outstanding_paise > 0) Red else Green, Modifier.weight(1f)) { nav("portalFees") }
                            StatCard("Last payment", d.last_payment?.let { money(it.amount_paise) } ?: "–", Icons.Outlined.Receipt, Purple, Modifier.weight(1f), d.last_payment?.let { prettyDate(it.paid_on) }) { nav("portalFees") }
                        }
                    }
                }
                d.next_class?.let { n -> item { AppCard { Column { Text("Next class", color = Muted, fontSize = 12.sp); Text("${n.activity_name} · ${n.batch_name}", fontWeight = FontWeight.Bold); Text("${prettyDate(n.date)} at ${prettyTime(n.start_time)}${n.location?.let { " · $it" } ?: ""}", color = Muted) } } } }
                d.next_due?.let { n ->
                    item {
                        AppCard {
                            Column {
                                Text("Next due", color = Muted, fontSize = 12.sp)
                                Text(n.title, fontWeight = FontWeight.Bold)
                                Text("${money(n.balance_paise)} · due ${prettyDate(n.due_date)}", color = Red, fontWeight = FontWeight.Bold)
                                if (d.online_payment) { Spacer(Modifier.height(8.dp)); Button(onClick = { openUrl(ctx, BuildConfig.API_BASE_URL.removeSuffix("api/v1/") + "student/#/fees") }) { Text("Pay online") } }
                            }
                        }
                    }
                }
                d.last_payment?.receipt_id?.let { rid ->
                    item { OutlinedButton(onClick = { scope.launch { runCatching { openFile(ctx, vm.receipt(rid, d.last_payment.receipt_no ?: "receipt")) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }, modifier = Modifier.fillMaxWidth()) { Text("Download last receipt ${d.last_payment.receipt_no ?: ""}") } }
                }
                item { SectionTitle("Recent updates") }
                if (d.notifications.isEmpty()) item { Text("No updates yet.", color = Muted) }
                items(d.notifications) { n -> AppCard { Column { Text(n.title, fontWeight = FontWeight.Bold); Text(n.body, color = Muted, fontSize = 13.sp); Text(prettyDate(n.created_at), color = Muted, fontSize = 11.sp) } } }
            }
        }
    }
}

@HiltViewModel
class PortalAttendanceVm @Inject constructor(private val repo: Repository) : LoadVm<AttMonth>() {
    var month: YearMonth = YearMonth.now()
    fun open(id: Long) = load { call { repo.api.studentAttendance(id, month.toString()) }.data!! }
}

@Composable
fun PortalAttendanceScreen(me: Me, childVm: ChildVm = hiltViewModel(), vm: PortalAttendanceVm = hiltViewModel()) {
    LaunchedEffect(me) { childVm.ensure(me) }
    val child by childVm.child.collectAsState()
    LaunchedEffect(child) { if (child > 0) vm.open(child) }
    val st by vm.state.collectAsState()
    Column {
        ChildSwitcher(me, child) { childVm.select(it) }
        Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedButton(onClick = { vm.month = vm.month.minusMonths(1); vm.open(child) }) { Text("‹") }
            Text(vm.month.month.name.lowercase().replaceFirstChar(Char::uppercase) + " " + vm.month.year, Modifier.weight(1f), fontWeight = FontWeight.Bold, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            OutlinedButton(onClick = { if (vm.month < YearMonth.now()) { vm.month = vm.month.plusMonths(1); vm.open(child) } }) { Text("›") }
        }
        LoadContent(st, { vm.open(child) }) { a ->
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item {
                    AppCard {
                        Column {
                            Text(a.percentage?.let { "$it%" } ?: "No classes marked", fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, color = Blue)
                            Text("Present ${a.counts.present} · Absent ${a.counts.absent} · Late ${a.counts.late} · Leave ${a.counts.leave}", color = Muted)
                        }
                    }
                }
                items(a.days) { d -> AppCard { Row(verticalAlignment = Alignment.CenterVertically) { Column(Modifier.weight(1f)) { Text(prettyDate(d.session_date), fontWeight = FontWeight.Bold); d.batch_name?.let { Text(it, color = Muted, fontSize = 12.sp) } }; StatusChip(d.status) } } }
            }
        }
    }
}

@HiltViewModel
class PortalFeesVm @Inject constructor(private val repo: Repository) : LoadVm<PortalFees>() {
    fun open(id: Long) = load { call { repo.api.portalFees(id) }.data!! }
    suspend fun receipt(p: PaymentRow) = repo.download("receipts/${p.receipt_id}/pdf?download=1", (p.receipt_no ?: "receipt").replace("/", "-") + ".pdf")
}

@Composable
fun PortalFeesScreen(me: Me, snack: SnackbarHostState, childVm: ChildVm = hiltViewModel(), vm: PortalFeesVm = hiltViewModel()) {
    LaunchedEffect(me) { childVm.ensure(me) }
    val child by childVm.child.collectAsState()
    LaunchedEffect(child) { if (child > 0) vm.open(child) }
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    Column {
        ChildSwitcher(me, child) { childVm.select(it) }
        LoadContent(st, { vm.open(child) }) { f ->
            Column {
                AppCard(Modifier.padding(16.dp)) {
                    Column {
                        Row {
                            Column(Modifier.weight(1f)) { Text("Pending", color = Muted, fontSize = 12.sp); Text(money(f.balance.outstanding_paise), color = Red, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp) }
                            Column(Modifier.weight(1f)) { Text("Overdue", color = Muted, fontSize = 12.sp); Text(money(f.balance.overdue_paise), fontWeight = FontWeight.Bold) }
                            Column(Modifier.weight(1f)) { Text("Advance", color = Muted, fontSize = 12.sp); Text(money(f.balance.advance_paise), color = Green, fontWeight = FontWeight.Bold) }
                        }
                        f.pending.forEach { i -> Text("• ${i.title}: ${money(i.balance_paise)} (due ${prettyDate(i.due_date)})", fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                        if (f.online_payment && f.pending.isNotEmpty()) { Spacer(Modifier.height(8.dp)); Button(onClick = { openUrl(ctx, BuildConfig.API_BASE_URL.removeSuffix("api/v1/") + "student/#/fees") }) { Text("Pay online") } }
                    }
                }
                SectionTitle("Payments & receipts", Modifier.padding(horizontal = 16.dp))
                PaymentList(f.payments, ctx) { p -> scope.launch { runCatching { openFile(ctx, vm.receipt(p)) }.onFailure { snack.showSnackbar(it.message ?: "Download failed") } } }
            }
        }
    }
}
