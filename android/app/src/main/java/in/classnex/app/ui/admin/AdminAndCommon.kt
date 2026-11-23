package `in`.classnex.app.ui.admin

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
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
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.*
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.launch
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import javax.inject.Inject

data class AdminHome(val d: AdminDashboard, val owners: List<OwnerRow>)

@HiltViewModel
class AdminVm @Inject constructor(private val repo: Repository) : LoadVm<AdminHome>() {
    init { refresh() }
    fun refresh() = load { AdminHome(call { repo.api.adminDashboard() }.data!!, call { repo.api.owners(perPage = 6) }.data ?: emptyList()) }
}

/** Super Admin on mobile: platform figures, registration approvals and academies. Full configuration stays on the web panel. */
@Composable
fun AdminDashboardScreen(me: Me? = null, nav: (String) -> Unit = {}, vm: AdminVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, vm::refresh) { (d, owners) ->
        LazyColumn(contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Column(Modifier.fillMaxWidth().clip(Radius.card).background(Brand.drawer).padding(18.dp)) {
                    Text("Hello, ${me?.name?.substringBefore(' ') ?: "Admin"} 👋", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                    Text("ClassNex platform overview", color = Color.White.copy(alpha = .8f))
                }
            }
            if (d.pending_registrations > 0) item {
                CnxCard(onClick = { nav("module/registrations") }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        IconCircle(Icons.Outlined.PendingActions, Amber)
                        Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                            Text("${d.pending_registrations} registration(s) waiting", fontWeight = FontWeight.Bold, color = Ink)
                            Text("Review and approve new academies", color = Muted, fontSize = 12.sp)
                        }
                        Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
                    }
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatTile("Academies", "${d.total_academies}", Icons.Outlined.Business, Violet, Modifier.weight(1f)) { nav("module/academies") }
                    StatTile("Students", "${d.total_students}", Icons.Outlined.Group, Blue, Modifier.weight(1f))
                    StatTile("Open tickets", "${d.open_tickets}", Icons.Outlined.SupportAgent, Pink, Modifier.weight(1f))
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatTile("Active plans", "${d.active_subscriptions}", Icons.Outlined.Verified, Green, Modifier.weight(1f), "${d.trial_subscriptions} on trial")
                    StatTile("Expired", "${d.expired_subscriptions}", Icons.Outlined.EventBusy, Red, Modifier.weight(1f))
                    StatTile("WhatsApp", "${d.whatsapp_month}", Icons.Outlined.Forum, Teal, Modifier.weight(1f), "this month")
                }
            }
            item {
                CnxCard {
                    SectionHeader("Revenue this month")
                    Text(money(d.monthly_revenue_paise), fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, color = Ink)
                    if (d.pending_subscription_payments > 0) Text("${d.pending_subscription_payments} payment(s) awaiting confirmation", color = Amber, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    if (d.revenue_trend.isNotEmpty()) {
                        Spacer(Modifier.height(12.dp))
                        BarChart(d.revenue_trend.map { it.total }, d.revenue_trend.map { runCatching { YearMonth.parse(it.month).format(DateTimeFormatter.ofPattern("MMM")) }.getOrDefault(it.month) })
                    }
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    SoftButton("Registrations", { nav("module/registrations") }, Modifier.weight(1f), Icons.Outlined.HowToReg)
                    SoftButton("Web admin panel", { openUrl(ctx, BuildConfig.API_BASE_URL.removeSuffix("api/v1/") + "admin/") }, Modifier.weight(1f), Icons.AutoMirrored.Outlined.OpenInNew)
                }
            }
            item { SectionHeader("Latest academies", action = "All") { nav("module/academies") } }
            itemsIndexed(owners) { i, o ->
                Entrance(i) {
                    CnxCard(padding = 12.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            IconCircle(Icons.Outlined.Apartment, Violet, 40.dp, 19.dp)
                            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                Text(o.academy_name, fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text("${o.owner_name ?: "–"} · ${o.package_name ?: "No plan"} · ${o.students}${o.student_limit?.let { "/$it" } ?: ""} students", color = Muted, fontSize = 12.sp, maxLines = 2)
                            }
                            o.sub_status?.let { Pill(label(it), statusColor(it)) }
                        }
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------- notifications
@HiltViewModel
class NotificationsVm @Inject constructor(private val repo: Repository) : LoadVm<List<AppNotification>>() {
    init { refresh() }
    fun refresh() = load { call { repo.api.notifications() }.data.orEmpty().also { viewModelScope.launch { runCatching { call { repo.api.markRead(ReadRequest()) } } } } }
}

@Composable
fun NotificationsScreen(vm: NotificationsVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    LoadContent(st, vm::refresh) { list ->
        if (list.isEmpty()) EmptyState("You're all caught up", "Notifications about attendance, fees and announcements appear here.", Icons.Outlined.NotificationsNone)
        else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            itemsIndexed(list, key = { _, n -> n.id }) { i, n ->
                Entrance(i) {
                    CnxCard(padding = 14.dp) {
                        Row {
                            IconCircle(Icons.Outlined.Notifications, if (n.read_at == null) Violet else Muted, 38.dp, 18.dp)
                            Column(Modifier.weight(1f).padding(start = 12.dp)) {
                                Text(n.title, fontWeight = if (n.read_at == null) FontWeight.ExtraBold else FontWeight.SemiBold, color = Ink)
                                Text(n.body, color = Muted, fontSize = 13.sp)
                                Text(prettyDate(n.created_at) + " " + prettyTime(n.created_at.drop(11)), color = Muted, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
                            }
                            if (n.read_at == null) Box(Modifier.size(9.dp).clip(androidx.compose.foundation.shape.CircleShape).background(Orange))
                        }
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- profile / settings
@HiltViewModel
class ProfileVm @Inject constructor(private val repo: Repository) : LoadVm<Unit>() {
    init { _state.value = Load.Ready(Unit) }
    val busy = kotlinx.coroutines.flow.MutableStateFlow(false)
    fun change(cur: String, new: String, done: () -> Unit) = viewModelScope.launch {
        busy.value = true
        try { say(call { repo.api.changePassword(ChangePassword(cur, new)) }.message); done() } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
    }
}

@Composable
fun ProfileScreen(me: Me, snack: SnackbarHostState, onLogout: () -> Unit, nav: (String) -> Unit = {}, vm: ProfileVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val busy by vm.busy.collectAsState()
    var cur by remember { mutableStateOf("") }
    var new by remember { mutableStateOf("") }
    var logout by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        CnxCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Avatar(null, me.name, 60)
                Column(Modifier.weight(1f).padding(horizontal = 14.dp)) {
                    Text(me.name, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, color = Ink)
                    Pill(roleName(me.role), Violet)
                }
            }
            Spacer(Modifier.height(10.dp))
            KeyRow("Mobile", "${me.country_code ?: ""} ${me.mobile ?: "–"}")
            KeyRow("Email", me.email)
            me.academy?.let { KeyRow("Academy", it.name) }
            me.subscription?.let { KeyRow("Plan", "${it.package_name ?: "–"} (${label(it.status)})") }
            KeyRow("WhatsApp mode", when (me.whatsapp_mode) { "api" -> "Automatic (official API)"; "manual" -> "Click-to-send"; else -> "Off" }.takeIf { me.isStaff })
        }
        if (me.isClassAdmin) CnxCard {
            SectionHeader("Academy settings")
            SettingLink("Academy profile & receipts", Icons.Outlined.Storefront) { nav("academyProfile") }
            if (me.can("settings.manage")) SettingLink("Reminders & WhatsApp", Icons.Outlined.NotificationsActive) { nav("reminders") }
            SettingLink("Subscription", Icons.Outlined.WorkspacePremium) { nav("subscription") }
            if (me.can("settings.manage")) {
                SettingLink("Receipts & IDs", Icons.Outlined.Receipt) { nav("academySettings/receipts") }
                SettingLink("Online admission", Icons.Outlined.AssignmentInd) { nav("academySettings/admission") }
                if (me.has("online_payments")) SettingLink("Online payments (Razorpay)", Icons.Outlined.CreditCard) { nav("academySettings/payments") }
                SettingLink("Activity log", Icons.Outlined.History) { nav("module/auditLog") }
            }
        }
        CnxCard {
            SectionHeader("Change password")
            CnxField(cur, { cur = it }, "Current password", password = true, icon = Icons.Outlined.Lock)
            Spacer(Modifier.height(8.dp))
            CnxField(new, { new = it }, "New password", password = true, icon = Icons.Outlined.Key, supporting = "8+ characters with letters and numbers")
            Spacer(Modifier.height(10.dp))
            GradientButton("Update password", { vm.change(cur, new) { cur = ""; new = "" } }, Modifier.fillMaxWidth(), enabled = cur.isNotBlank() && new.length >= 8, loading = busy)
        }
        CnxCard {
            SettingLink("Help & Support", Icons.Outlined.SupportAgent) { nav("module/support") }
            SettingLink("Log out", Icons.AutoMirrored.Outlined.Logout, Red) { logout = true }
        }
        Text("ClassNex ${BuildConfig.VERSION_NAME}", color = Muted, fontSize = 12.sp, modifier = Modifier.align(Alignment.CenterHorizontally))
    }
    if (logout) ConfirmDialog("Log out?", "You will need your password to log in again.", "Log out", true, { logout = false }) { logout = false; onLogout() }
}

@Composable
fun SettingLink(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color = Violet, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(Radius.small).pressable(onClick).padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        IconCircle(icon, tint, 38.dp, 18.dp)
        Text(text, color = if (tint == Red) Red else Ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
        Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
    }
}
