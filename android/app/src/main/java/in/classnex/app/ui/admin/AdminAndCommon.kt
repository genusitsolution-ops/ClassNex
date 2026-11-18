package `in`.classnex.app.ui.admin

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
import androidx.compose.material.icons.outlined.Business
import androidx.compose.material.icons.outlined.CurrencyRupee
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Verified
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarHostState
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
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.AdminDashboard
import `in`.classnex.app.data.net.AppNotification
import `in`.classnex.app.data.net.ChangePassword
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.OwnerRow
import `in`.classnex.app.data.net.ReadRequest
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.common.EmptyView
import `in`.classnex.app.ui.common.KeyValue
import `in`.classnex.app.ui.common.LoadContent
import `in`.classnex.app.ui.common.LoadVm
import `in`.classnex.app.ui.common.StatCard
import `in`.classnex.app.ui.common.StatusChip
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.roleName
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.openUrl
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Orange
import `in`.classnex.app.ui.theme.Purple
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.launch
import javax.inject.Inject

data class AdminHome(val d: AdminDashboard, val owners: List<OwnerRow>)

@HiltViewModel
class AdminVm @Inject constructor(private val repo: Repository) : LoadVm<AdminHome>() {
    init { refresh() }
    fun refresh() = load { AdminHome(call { repo.api.adminDashboard() }.data!!, call { repo.api.owners() }.data ?: emptyList()) }
}

/** Super Admin on mobile: key numbers and academies. Full management stays on the web panel. */
@Composable
fun AdminDashboardScreen(vm: AdminVm = hiltViewModel()) {
    val st by vm.state.collectAsState()
    val ctx = LocalContext.current
    LoadContent(st, vm::refresh) { (d, owners) ->
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatCard("Academies", d.total_academies.toString(), Icons.Outlined.Business, Blue, Modifier.weight(1f))
                    StatCard("Students", d.total_students.toString(), Icons.Outlined.Group, Purple, Modifier.weight(1f))
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatCard("Active plans", d.active_subscriptions.toString(), Icons.Outlined.Verified, Green, Modifier.weight(1f), "${d.expired_subscriptions} expired")
                    StatCard("Revenue (month)", money(d.monthly_revenue_paise), Icons.Outlined.CurrencyRupee, Orange, Modifier.weight(1f))
                }
            }
            item { OutlinedButton(onClick = { openUrl(ctx, BuildConfig.API_BASE_URL.removeSuffix("api/v1/") + "admin/") }, modifier = Modifier.fillMaxWidth()) { Text("Open full admin panel") } }
            item { Text("Academies", fontWeight = FontWeight.ExtraBold, fontSize = 16.sp) }
            items(owners) { o ->
                AppCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(o.academy_name, fontWeight = FontWeight.Bold)
                            Text("Class Admin: ${o.owner_name ?: "–"} · ${o.mobile ?: ""}", color = Muted, fontSize = 12.sp)
                            Text("${o.package_name ?: "No plan"}${o.ends_on?.let { " · till ${prettyDate(it)}" } ?: ""} · ${o.students}${o.student_limit?.let { "/$it" } ?: ""} students · ${o.branches} branch(es)", color = Muted, fontSize = 12.sp)
                        }
                        o.sub_status?.let { StatusChip(it) }
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
        if (list.isEmpty()) EmptyView("No notifications yet")
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(list) { n -> AppCard { Column { Text(n.title, fontWeight = if (n.read_at == null) FontWeight.ExtraBold else FontWeight.SemiBold); Text(n.body, color = Muted, fontSize = 13.sp); Text(prettyDate(n.created_at), color = Muted, fontSize = 11.sp) } } }
        }
    }
}

// ---------------------------------------------------------------- profile
@HiltViewModel
class ProfileVm @Inject constructor(private val repo: Repository) : LoadVm<Unit>() {
    init { _state.value = `in`.classnex.app.ui.common.Load.Ready(Unit) }
    fun change(cur: String, new: String) = viewModelScope.launch {
        say(try { call { repo.api.changePassword(ChangePassword(cur, new)) }.message } catch (e: Exception) { errorText(e) })
    }
}

@Composable
fun ProfileScreen(me: Me, snack: SnackbarHostState, onLogout: () -> Unit, nav: (String) -> Unit = {}, vm: ProfileVm = hiltViewModel()) {
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    var cur by remember { mutableStateOf("") }
    var new by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        AppCard {
            Column {
                Text(me.name, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
                Text(roleName(me.role), color = Muted)
                Spacer(Modifier.height(8.dp))
                KeyValue("Mobile", "${me.country_code ?: ""} ${me.mobile ?: "–"}")
                KeyValue("Email", me.email ?: "–")
                me.academy?.let { KeyValue("Academy", it.name) }
                me.subscription?.let { KeyValue("Plan", "${it.package_name ?: "–"} (${label(it.status)})") }
                KeyValue("App version", BuildConfig.VERSION_NAME)
            }
        }
        if (me.isStaff) AppCard {
            // "More" menu for Class Admins and teachers – the same modules as the web panel
            Column {
                val links = buildList {
                    if (me.isClassAdmin) add("Branches" to "branches")
                    if (me.has("attendance")) add("Attendance history" to "history")
                    if (me.has("fees") && me.can("fees.view")) add("Payments & receipts" to "payments")
                    if (me.isClassAdmin && me.can("reports.view")) add("Combined branch report" to "report/branch_summary")
                    if (me.has("manual_reminders") && me.can("notifications.send")) add("Send message / reminder" to "message")
                    if (me.has("whatsapp_manual") && me.can("notifications.send")) add("WhatsApp history" to "waLog")
                }
                links.forEach { (t, r) -> androidx.compose.material3.TextButton(onClick = { nav(r) }, modifier = Modifier.fillMaxWidth()) { Text(t, modifier = Modifier.fillMaxWidth()) } }
            }
        }
        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Change password", fontWeight = FontWeight.Bold)
                OutlinedTextField(cur, { cur = it }, label = { Text("Current password") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                OutlinedTextField(new, { new = it }, label = { Text("New password") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(), supportingText = { Text("8+ characters with letters and numbers") })
                Button(onClick = { vm.change(cur, new); cur = ""; new = "" }, enabled = cur.isNotBlank() && new.length >= 8) { Text("Update password") }
            }
        }
        OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("Log out") }
    }
}
