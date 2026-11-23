package `in`.classnex.app.ui.nav

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.*
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import coil.compose.AsyncImage
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.R
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.ui.academy.*
import `in`.classnex.app.ui.admin.AdminDashboardScreen
import `in`.classnex.app.ui.admin.NotificationsScreen
import `in`.classnex.app.ui.admin.ProfileScreen
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.crud.*
import `in`.classnex.app.ui.portal.PortalAttendanceScreen
import `in`.classnex.app.ui.portal.PortalFeesScreen
import `in`.classnex.app.ui.portal.PortalHomeScreen
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.launch

/** One entry of the More screen. Only entries the user's package and permissions allow are built. */
data class MenuItem(val label: String, val route: String, val icon: ImageVector, val tint: Color = Violet, val hint: String? = null)
data class MenuSection(val title: String, val items: List<MenuItem>)

/**
 * The More screen: the main sections as large tiles, then the other tools in a short list.
 * Same modules and permission rules as the web panel for each role.
 */
fun moreFor(me: Me): List<MenuSection> {
    val owner = me.isClassAdmin
    val fees = me.has("fees") && me.can("fees.view")
    val att = me.has("attendance")
    fun sec(title: String, vararg items: MenuItem?) = MenuSection(title, items.filterNotNull())
    fun MenuItem.on(b: Boolean) = if (b) this else null
    val list = when {
        me.role == "super_admin" -> listOf(
            sec("Main", MenuItem("Registrations", "module/registrations", Icons.Outlined.HowToReg, Amber, "Approve new academies"), MenuItem("Academies", "module/academies", Icons.Outlined.Apartment, Violet, "All academies and plans"),
                MenuItem("Notifications", "notifications", Icons.Outlined.Notifications, Blue), MenuItem("Settings", "profile", Icons.Outlined.Settings, Muted, "Password and log out")),
        )
        me.isFamily -> listOf(
            sec("Main", MenuItem("Attendance", "portalAttendance", Icons.Outlined.HowToReg, Green).on(att), MenuItem("Fees & Receipts", "portalFees", Icons.Outlined.ReceiptLong, Orange).on(me.has("fees")),
                MenuItem("Notifications", "notifications", Icons.Outlined.Notifications, Blue), MenuItem("Help & Support", "module/support", Icons.Outlined.SupportAgent, Pink, "Ask the academy"),
                MenuItem("Settings", "profile", Icons.Outlined.Settings, Muted, "Password and log out")),
        )
        else -> listOf(
            sec(
                "Main",
                MenuItem("Branches", "branches", Icons.Outlined.Store, Teal, "Your centres").on(owner),
                MenuItem("Teachers", "module/teachers", Icons.Outlined.Badge, Blue, "Add teachers, set access").on(owner),
                MenuItem("Activities & Batches", "structure", Icons.Outlined.Groups, Orange, "Classes, timings, batches"),
                MenuItem("Reports", "reports?financial=0", Icons.Outlined.Assessment, Pink, "Attendance, fees, students").on(me.has("reports") && me.can("reports.view")),
                MenuItem("Reminders & WhatsApp", "reminders", Icons.Outlined.NotificationsActive, Green, "Fee reminders, messages").on(me.can("notifications.send") || (owner && me.can("settings.manage"))),
                MenuItem("Announcements", "module/announcements", Icons.Outlined.Campaign, Amber, "News for everyone").on(me.can("notifications.send")),
                MenuItem("Subscription", "subscription", Icons.Outlined.WorkspacePremium, Violet, "Your plan and usage").on(owner),
                MenuItem("Settings", "profile", Icons.Outlined.Settings, Muted, "Profile, password, log out"),
                MenuItem("Help & Support", "module/support", Icons.Outlined.SupportAgent, Pink, "We are here to help"),
            ),
            sec(
                "Other tools",
                MenuItem("Payments & Receipts", "payments", Icons.Outlined.ReceiptLong, Green).on(fees),
                MenuItem("Fee Plans", "module/feePlans", Icons.Outlined.RequestQuote, Teal).on(fees),
                MenuItem("Discounts", "module/discounts", Icons.Outlined.Sell, Pink).on(fees && owner),
                MenuItem("Fee Reports", "reports?financial=1", Icons.Outlined.Insights, Orange).on(fees && me.has("reports") && me.can("reports.financial")),
                MenuItem("Attendance History", "history", Icons.Outlined.History, Blue).on(att),
                MenuItem("Timetable", "timetable", Icons.Outlined.CalendarViewWeek, Indigo600),
                MenuItem("Admission Requests", "module/admissions", Icons.Outlined.AssignmentInd, Amber).on(me.has("online_admission") && me.can("admissions.manage")),
                MenuItem("Parents", "module/parents", Icons.Outlined.FamilyRestroom, Violet).on(owner || me.can("students.manage")),
                MenuItem("Holidays", "module/holidays", Icons.Outlined.BeachAccess, Pink).on(owner),
                MenuItem(if (owner) "Teacher Leaves" else "My Leaves", "module/leaves", Icons.Outlined.EventAvailable, Blue),
                MenuItem("Academy Profile", "academyProfile", Icons.Outlined.Storefront, Violet).on(owner),
                MenuItem("Notifications", "notifications", Icons.Outlined.Notifications, Blue),
            ),
        )
    }
    return list.filter { it.items.isNotEmpty() }
}

private fun bottomFor(me: Me): List<BottomItem> = when {
    me.role == "super_admin" -> listOf(BottomItem("admin", "Home", Icons.Outlined.Dashboard), BottomItem("module/registrations", "Approvals", Icons.Outlined.HowToReg), BottomItem("module/academies", "Academies", Icons.Outlined.Apartment), BottomItem("more", "More", Icons.Outlined.GridView))
    me.isFamily -> buildList {
        add(BottomItem("portal", "Home", Icons.Outlined.Home))
        if (me.has("attendance")) add(BottomItem("portalAttendance", "Attendance", Icons.Outlined.HowToReg))
        if (me.has("fees")) add(BottomItem("portalFees", "Fees", Icons.Outlined.ReceiptLong))
        add(BottomItem("notifications", "Alerts", Icons.Outlined.Notifications))
        add(BottomItem("more", "More", Icons.Outlined.GridView))
    }
    else -> buildList {
        add(BottomItem("dashboard", "Home", Icons.Outlined.Home))
        if (me.can("students.view")) add(BottomItem("students", "Students", Icons.Outlined.Group))
        if (me.has("attendance") && me.can("attendance.mark")) add(BottomItem("markAttendance?batch={batch}", "Attendance", Icons.Outlined.HowToReg))
        if (me.has("fees") && me.can("fees.view")) add(BottomItem("fees?tab={tab}", "Fees", Icons.Outlined.CurrencyRupee))
        add(BottomItem("more", "More", Icons.Outlined.GridView))
    }
}


/** Turns a route pattern into a real destination ("fees?tab={tab}" → "fees"). */
private fun target(route: String) = route.substringBefore('?').let { base -> if (route.contains("{")) base else route }

@Composable
fun HomeScaffold(me: Me, onLogout: () -> Unit, onRefreshMe: () -> Unit = {}) {
    val nav = rememberNavController()
    val snack = remember { SnackbarHostState() }
    val entry by nav.currentBackStackEntryAsState()
    val pattern = entry?.destination?.route ?: ""
    val scope = rememberCoroutineScope()

    if (Build.VERSION.SDK_INT >= 33) {
        val perm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
        LaunchedEffect(Unit) { perm.launch(Manifest.permission.POST_NOTIFICATIONS) }
    }

    val more = remember(me) { moreFor(me) }
    val tabs = remember(me) { bottomFor(me) }
    val start = target(tabs.first().route)
    val go: (String) -> Unit = { r -> nav.navigate(r) { launchSingleTop = true } }
    val goTab: (String) -> Unit = { r ->
        // module tabs share one destination ("module/{key}"), so their state is not saved / restored per tab
        val module = r.startsWith("module/")
        nav.navigate(r) { popUpTo(nav.graph.findStartDestination().id) { saveState = !module }; launchSingleTop = !module; restoreState = !module }
    }
    val currentReal = currentRoute(entry)
    // a tab is matched by its route pattern, or by the real route for module tabs (module/registrations)
    val currentTab = tabs.firstOrNull { it.route == pattern || it.route == currentReal }?.route
    val isTab = currentTab != null
    // close the keyboard when the user arrives (after login) and whenever the screen changes
    val keyboard = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current
    LaunchedEffect(pattern) { keyboard?.hide() }
    run {
        Scaffold(
            containerColor = Lavender,
            snackbarHost = { SnackbarHost(snack) { d -> Snackbar(d, shape = RoundedCornerShape(14.dp), containerColor = Indigo900, contentColor = Color.White) } },
            topBar = {
                GradientTopBar(
                    title = titleFor(pattern, entry, me),
                    subtitle = if (isTab && pattern != "dashboard") me.academy?.name else null,
                    navigation = if (isTab) null else ({ BackIcon { nav.popBackStack() } }),
                    actions = {
                        if (pattern != "notifications") BarIcon(Icons.Outlined.Notifications, "Notifications") { go("notifications") }
                        if (pattern == "students" && me.can("students.manage")) BarIcon(Icons.Outlined.PersonAdd, "Add student") { go("addStudent") }
                    },
                )
            },
            bottomBar = {
                AnimatedVisibility(isTab, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                    CnxBottomBar(tabs, currentTab) { t ->
                        goTab(target(t.route))
                    }
                }
            },
        ) { pad ->
            NavHost(
                nav, startDestination = start, modifier = Modifier.padding(pad).consumeWindowInsets(pad),
                enterTransition = { fadeIn(tween(220)) + slideInHorizontally(tween(260)) { it / 12 } },
                exitTransition = { fadeOut(tween(160)) },
                popEnterTransition = { fadeIn(tween(220)) + slideInHorizontally(tween(260)) { -it / 12 } },
                popExitTransition = { fadeOut(tween(160)) },
            ) {
                val showMsg: (String) -> Unit = { m -> scope.launch { snack.showSnackbar(m) } }
                composable("dashboard") { DashboardScreen(me, go) }
                composable("more") { MoreScreen(me, more, go, onLogout) }
                composable("structure") { StructureScreen(me, snack, go) }
                composable("reminders") { RemindersScreen(me, snack, go, onRefreshMe) }
                composable("students") { StudentsScreen(me, go) }
                composable("student/{id}", listOf(navArgument("id") { type = NavType.LongType })) { StudentDetailScreen(it.arguments!!.getLong("id"), me, go, snack) }
                composable("branchStudents/{branch}", listOf(navArgument("branch") { type = NavType.LongType })) { StudentsScreen(me, go, branchId = it.arguments!!.getLong("branch")) }
                composable("branches") { BranchesScreen(me, snack, go) }
                composable("branch/{id}", listOf(navArgument("id") { type = NavType.LongType })) { BranchDetailScreen(it.arguments!!.getLong("id"), me, snack, go) }
                composable("assign/{branch}", listOf(navArgument("branch") { type = NavType.LongType })) { AssignStudentsScreen(it.arguments!!.getLong("branch"), snack, back = { nav.popBackStack() }) }
                composable("history?branch={branch}&student={student}", listOf(navArgument("branch") { type = NavType.LongType; defaultValue = 0L }, navArgument("student") { type = NavType.LongType; defaultValue = 0L })) {
                    AttendanceHistoryScreen(it.arguments!!.getLong("branch").takeIf { b -> b > 0 }, it.arguments!!.getLong("student").takeIf { s -> s > 0 }, snack)
                }
                composable("report/{name}?branch={branch}", listOf(navArgument("name") { type = NavType.StringType }, navArgument("branch") { type = NavType.LongType; defaultValue = 0L })) {
                    ReportScreen(it.arguments!!.getString("name") ?: "branch_summary", it.arguments!!.getLong("branch").takeIf { b -> b > 0 }, snack)
                }
                composable("reports?financial={financial}", listOf(navArgument("financial") { type = NavType.IntType; defaultValue = 0 })) { ReportsListScreen(me, it.arguments!!.getInt("financial") == 1, go) }
                composable("waLog") { WhatsAppHistoryScreen(snack) }
                composable("addStudent") { AddStudentScreen(snack, onSaved = { id -> nav.popBackStack(); go("student/$id") }) }
                composable("markAttendance?batch={batch}", listOf(navArgument("batch") { type = NavType.LongType; defaultValue = 0L })) { MarkAttendanceScreen(it.arguments!!.getLong("batch"), me, snack, go) }
                composable("attendance/{batch}", listOf(navArgument("batch") { type = NavType.LongType })) { MarkAttendanceScreen(it.arguments!!.getLong("batch"), me, snack, go) }
                composable("collect/{student}", listOf(navArgument("student") { type = NavType.LongType })) { CollectFeeScreen(it.arguments!!.getLong("student"), me, snack, go) }
                composable("fees?tab={tab}", listOf(navArgument("tab") { type = NavType.IntType; defaultValue = 0 })) { PendingFeesScreen(me, snack, go, it.arguments!!.getInt("tab")) }
                composable("payments") { PaymentsScreen(snack, me) }
                composable("receipts") { PaymentsScreen(snack, me) }
                composable("message?student={student}&type={type}", listOf(navArgument("student") { type = NavType.LongType; defaultValue = 0L }, navArgument("type") { type = NavType.StringType; defaultValue = "message" })) {
                    MessageScreen(me, it.arguments!!.getLong("student").takeIf { s -> s > 0 }, snack, back = { nav.popBackStack() }, initialType = it.arguments!!.getString("type") ?: "message")
                }
                composable("module/{key}", listOf(navArgument("key") { type = NavType.StringType })) {
                    // keyed by the module: a single-top navigation between modules reuses this entry with new arguments
                    val key = it.arguments!!.getString("key") ?: ""
                    val spec = remember(key, me) { moduleFor(key, me) }
                    if (spec != null) CrudListScreen(spec, snack, go) else EmptyState("This section is not available for your account.", icon = Icons.Outlined.Lock)
                }
                composable("form/{key}?id={id}&parent={parent}", listOf(navArgument("key") { type = NavType.StringType }, navArgument("id") { type = NavType.LongType; defaultValue = 0L }, navArgument("parent") { type = NavType.LongType; defaultValue = 0L })) {
                    val key = it.arguments!!.getString("key") ?: ""
                    val id = it.arguments!!.getLong("id")
                    val spec = remember(key) { moduleFor(key, me)?.form ?: formFor(key, me, it.arguments!!.getLong("parent")) }
                    val row = remember(key, id) { if (id > 0) FormBus.row?.takeIf { r -> r.id() == id } else null }
                    if (spec == null || (id > 0 && row == null)) LaunchedEffect(Unit) { nav.popBackStack() }
                    else FormScreen(key, spec, row, snack, onDone = { m -> nav.popBackStack(); showMsg(m) })
                }
                composable("batchStudents/{id}", listOf(navArgument("id") { type = NavType.LongType })) { BatchStudentsScreen(it.arguments!!.getLong("id"), go) }
                composable("ticket/{id}", listOf(navArgument("id") { type = NavType.LongType })) { TicketScreen(it.arguments!!.getLong("id"), me, snack) }
                composable("timetable") { TimetableScreen(go) }
                composable("subscription") { SubscriptionScreen(me) }
                composable("academyProfile") { AcademyProfileScreen(me, snack, back = { nav.popBackStack() }) }
                composable("notificationSettings") { RemindersScreen(me, snack, go, onRefreshMe) }
                composable("communication") { RemindersScreen(me, snack, go, onRefreshMe) }
                composable("portal") { PortalHomeScreen(me, snack, go) }
                composable("portalAttendance") { PortalAttendanceScreen(me) }
                composable("portalFees") { PortalFeesScreen(me, snack) }
                composable("admin") { AdminDashboardScreen(me, go) }
                composable("notifications") { NotificationsScreen() }
                composable("profile") { ProfileScreen(me, snack, onLogout, go) }
            }
        }
    }
}

/** The real route of the current screen (pattern with its arguments filled in), used to highlight the drawer. */
private fun currentRoute(e: NavBackStackEntry?): String {
    val p = e?.destination?.route ?: return ""
    val a = e.arguments
    return when {
        p.startsWith("module/") -> "module/" + (a?.getString("key") ?: "")
        p.startsWith("fees") -> if ((a?.getInt("tab") ?: 0) == 1) "fees?tab=1" else "fees"
        p.startsWith("reports") -> "reports?financial=" + (a?.getInt("financial") ?: 0)
        p.startsWith("markAttendance") -> "markAttendance?batch=0"
        p.startsWith("history") -> "history"
        else -> p
    }
}

private val titles = mapOf(
    "dashboard" to "Dashboard", "students" to "Students", "addStudent" to "Add Student", "notifications" to "Notifications", "profile" to "Settings",
    "portal" to "Home", "portalAttendance" to "Attendance", "portalFees" to "Fees & Receipts", "admin" to "Super Admin",
    "branches" to "Branches", "waLog" to "WhatsApp History", "payments" to "Payments", "receipts" to "Receipts", "timetable" to "Timetable",
    "subscription" to "Subscription", "academyProfile" to "Academy Profile", "notificationSettings" to "Fee Reminder Settings", "communication" to "Reminders & WhatsApp", "more" to "More", "structure" to "Activities & Batches", "reminders" to "Reminders & WhatsApp",
)

private fun titleFor(pattern: String, e: NavBackStackEntry?, me: Me): String {
    val a = e?.arguments
    titles[pattern]?.let { return it }
    return when {
        pattern.startsWith("module/") -> moduleFor(a?.getString("key") ?: "", me)?.title ?: "ClassNex"
        pattern.startsWith("form/") -> {
            val key = a?.getString("key") ?: ""
            val t = (moduleFor(key, me)?.form ?: formFor(key, me))?.title ?: "Form"
            if (t.startsWith("Edit") || t.startsWith("Add") || t.startsWith("New") || t.startsWith("Assign") || t.contains("profile")) t
            else (if ((a?.getLong("id") ?: 0L) > 0) "Edit " else "New ") + t.replaceFirstChar { it.lowercase() }
        }
        pattern.startsWith("fees") -> if ((a?.getInt("tab") ?: 0) == 1) "Overdue Fees" else "Pending Fees"
        pattern.startsWith("reports") -> if ((a?.getInt("financial") ?: 0) == 1) "Fee Reports" else "Reports"
        pattern.startsWith("markAttendance") || pattern.startsWith("attendance/") -> "Attendance"
        pattern.startsWith("history") -> "Attendance History"
        pattern.startsWith("student/") -> "Student Profile"
        pattern.startsWith("branchStudents") -> "Branch Students"
        pattern.startsWith("collect/") -> "Collect Fee"
        pattern.startsWith("message") -> "Send Reminder"
        pattern.startsWith("branch/") -> "Branch"
        pattern.startsWith("assign/") -> "Assign Students"
        pattern.startsWith("report/") -> "Report"
        pattern.startsWith("batchStudents") -> "Batch Students"
        pattern.startsWith("ticket/") -> "Support Ticket"
        else -> "ClassNex"
    }
}

/** The More tab: main sections as large tiles, other tools as a short list. */
@Composable
private fun MoreScreen(me: Me, sections: List<MenuSection>, go: (String) -> Unit, onLogout: () -> Unit) {
    var logout by remember { mutableStateOf(false) }
    LazyColumn(Modifier.fillMaxSize().testTag("moreList"), contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Row(Modifier.fillMaxWidth().clip(Radius.card).background(Brand.drawer).padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(50.dp).clip(RoundedCornerShape(16.dp)).background(Color.White), contentAlignment = Alignment.Center) {
                    val logo = me.academy?.logo_url
                    if (logo != null) AsyncImage(logo, null, Modifier.fillMaxSize().padding(4.dp)) else Image(painterResource(R.drawable.classnex_icon), null, Modifier.size(36.dp))
                }
                Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                    Text(me.academy?.name ?: "ClassNex", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text("${me.name} · ${roleName(me.role)}", color = Color.White.copy(alpha = .8f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        sections.forEachIndexed { si, sec ->
            if (si == 0) {
                // main sections: two large tiles per row
                sec.items.chunked(2).forEachIndexed { ri, row ->
                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            row.forEachIndexed { ci, mi ->
                                Box(Modifier.weight(1f)) {
                                    Entrance(ri * 2 + ci) {
                                        CnxCard(Modifier.testTag("more:" + mi.route).heightIn(min = 112.dp), onClick = { go(mi.route) }, padding = 14.dp) {
                                            IconCircle(mi.icon, mi.tint, 42.dp, 21.dp)
                                            Spacer(Modifier.height(10.dp))
                                            Text(mi.label, fontWeight = FontWeight.Bold, color = Ink, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                            mi.hint?.let { Text(it, color = Muted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                                        }
                                    }
                                }
                            }
                            if (row.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                }
            } else {
                item { SectionHeader(sec.title) }
                item {
                    CnxCard(padding = 6.dp) {
                        sec.items.forEach { mi ->
                            Row(Modifier.fillMaxWidth().testTag("more:" + mi.route).clip(RoundedCornerShape(12.dp)).clickable { go(mi.route) }.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                                IconCircle(mi.icon, mi.tint, 36.dp, 18.dp)
                                Text(mi.label, color = Ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f).padding(horizontal = 12.dp))
                                Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, null, tint = Muted)
                            }
                        }
                    }
                }
            }
        }
        item {
            SoftButton("Log out", { logout = true }, Modifier.fillMaxWidth(), Icons.AutoMirrored.Outlined.Logout, Red)
            Text("ClassNex v${BuildConfig.VERSION_NAME}", color = Muted, fontSize = 12.sp, modifier = Modifier.fillMaxWidth().padding(top = 10.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        }
    }
    if (logout) ConfirmDialog("Log out?", "You will need your password to log in again.", "Log out", true, { logout = false }) { logout = false; onLogout() }
}
