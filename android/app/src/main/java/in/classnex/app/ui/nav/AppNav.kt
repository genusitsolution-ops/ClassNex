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

/** One drawer entry. Only entries the user's package and permissions allow are built. */
data class MenuItem(val label: String, val route: String, val icon: ImageVector)
data class MenuSection(val title: String, val items: List<MenuItem>)

/** The navigation menu – the same modules and rules as the web panel for each role. */
fun menuFor(me: Me): List<MenuSection> {
    val owner = me.isClassAdmin
    val fees = me.has("fees") && me.can("fees.view")
    val att = me.has("attendance")
    fun sec(title: String, vararg items: MenuItem?) = MenuSection(title, items.filterNotNull())
    fun MenuItem.on(b: Boolean) = if (b) this else null
    val list = when {
        me.role == "super_admin" -> listOf(
            sec("Overview", MenuItem("Dashboard", "admin", Icons.Outlined.Dashboard), MenuItem("Notifications", "notifications", Icons.Outlined.Notifications)),
            sec("Platform", MenuItem("Registrations", "module/registrations", Icons.Outlined.HowToReg), MenuItem("Academies", "module/academies", Icons.Outlined.Apartment)),
            sec("Account", MenuItem("Settings", "profile", Icons.Outlined.Settings)),
        )
        me.isFamily -> listOf(
            sec("Overview", MenuItem("Home", "portal", Icons.Outlined.Home), MenuItem("Notifications", "notifications", Icons.Outlined.Notifications)),
            sec("My Child", MenuItem("Attendance", "portalAttendance", Icons.Outlined.HowToReg).on(att), MenuItem("Fees & Receipts", "portalFees", Icons.Outlined.ReceiptLong).on(me.has("fees"))),
            sec("Account", MenuItem("Help & Support", "module/support", Icons.Outlined.SupportAgent), MenuItem("Settings", "profile", Icons.Outlined.Settings)),
        )
        else -> listOf(
            sec("Overview", MenuItem("Dashboard", "dashboard", Icons.Outlined.Dashboard), MenuItem("Notifications", "notifications", Icons.Outlined.Notifications)),
            sec(
                "Academy Management",
                MenuItem("Branches", "branches", Icons.Outlined.Store).on(owner),
                MenuItem("Activities", "module/activities", Icons.Outlined.Interests),
                MenuItem("Batches", "module/batches", Icons.Outlined.Groups),
                MenuItem("Teachers", "module/teachers", Icons.Outlined.Badge).on(owner),
                MenuItem("Students", "students", Icons.Outlined.Group).on(me.can("students.view")),
                MenuItem("Admissions", "module/admissions", Icons.Outlined.AssignmentInd).on(me.has("online_admission") && me.can("admissions.manage")),
                MenuItem("Parents", "module/parents", Icons.Outlined.FamilyRestroom).on(owner || me.can("students.manage")),
                MenuItem("Holidays", "module/holidays", Icons.Outlined.BeachAccess).on(owner),
                MenuItem(if (owner) "Teacher Leaves" else "My Leaves", "module/leaves", Icons.Outlined.EventAvailable),
            ),
            sec(
                "Daily Operations",
                MenuItem("Attendance", "markAttendance?batch=0", Icons.Outlined.HowToReg).on(att && me.can("attendance.mark")),
                MenuItem("Attendance History", "history", Icons.Outlined.History).on(att),
                MenuItem("Timetable", "timetable", Icons.Outlined.CalendarViewWeek),
            ),
            sec(
                "Finance",
                MenuItem("Collect Fee", "collect/0", Icons.Outlined.CurrencyRupee).on(fees && me.can("fees.collect")),
                MenuItem("Fee Structure", "module/feePlans", Icons.Outlined.RequestQuote).on(fees),
                MenuItem("Discounts", "module/discounts", Icons.Outlined.Sell).on(fees && owner),
                MenuItem("Pending Fees", "fees", Icons.Outlined.HourglassBottom).on(fees),
                MenuItem("Overdue Fees", "fees?tab=1", Icons.Outlined.ErrorOutline).on(fees),
                MenuItem("Payments", "payments", Icons.Outlined.Payments).on(fees),
                MenuItem("Receipts", "receipts", Icons.Outlined.ReceiptLong).on(fees),
                MenuItem("Financial Reports", "reports?financial=1", Icons.Outlined.Insights).on(fees && me.has("reports") && me.can("reports.financial")),
            ),
            sec(
                "Communication",
                MenuItem("WhatsApp Reminders", "message", Icons.Outlined.Chat).on(me.has("manual_reminders") && me.can("notifications.send")),
                MenuItem("Announcements", "module/announcements", Icons.Outlined.Campaign).on(me.can("notifications.send")),
                MenuItem("Communication Centre", "communication", Icons.Outlined.Forum).on(me.can("notifications.send")),
                MenuItem("Notifications", "notifications", Icons.Outlined.NotificationsActive),
            ),
            sec(
                "Business & Account",
                MenuItem("Reports", "reports?financial=0", Icons.Outlined.Assessment).on(me.has("reports") && me.can("reports.view")),
                MenuItem("Subscription", "subscription", Icons.Outlined.WorkspacePremium).on(owner),
                MenuItem("Academy Profile", "academyProfile", Icons.Outlined.Storefront).on(owner),
                MenuItem("Settings", "profile", Icons.Outlined.Settings),
                MenuItem("Help & Support", "module/support", Icons.Outlined.SupportAgent),
            ),
        )
    }
    return list.filter { it.items.isNotEmpty() }
}

private fun bottomFor(me: Me): List<BottomItem> = when {
    me.role == "super_admin" -> listOf(BottomItem("admin", "Home", Icons.Outlined.Dashboard), BottomItem("module/registrations", "Approvals", Icons.Outlined.HowToReg), BottomItem("module/academies", "Academies", Icons.Outlined.Apartment), BottomItem(MORE, "More", Icons.Outlined.Menu))
    me.isFamily -> buildList {
        add(BottomItem("portal", "Home", Icons.Outlined.Home))
        if (me.has("attendance")) add(BottomItem("portalAttendance", "Attendance", Icons.Outlined.HowToReg))
        if (me.has("fees")) add(BottomItem("portalFees", "Fees", Icons.Outlined.ReceiptLong))
        add(BottomItem("notifications", "Alerts", Icons.Outlined.Notifications))
        add(BottomItem(MORE, "More", Icons.Outlined.Menu))
    }
    else -> buildList {
        add(BottomItem("dashboard", "Home", Icons.Outlined.Home))
        if (me.can("students.view")) add(BottomItem("students", "Students", Icons.Outlined.Group))
        if (me.has("attendance") && me.can("attendance.mark")) add(BottomItem("markAttendance?batch={batch}", "Attendance", Icons.Outlined.HowToReg))
        if (me.has("fees") && me.can("fees.view")) add(BottomItem("fees?tab={tab}", "Fees", Icons.Outlined.CurrencyRupee))
        add(BottomItem(MORE, "More", Icons.Outlined.Menu))
    }
}

private const val MORE = "__more__"

/** Turns a route pattern into a real destination ("fees?tab={tab}" → "fees"). */
private fun target(route: String) = route.substringBefore('?').let { base -> if (route.contains("{")) base else route }

@Composable
fun HomeScaffold(me: Me, onLogout: () -> Unit) {
    val nav = rememberNavController()
    val snack = remember { SnackbarHostState() }
    val entry by nav.currentBackStackEntryAsState()
    val pattern = entry?.destination?.route ?: ""
    val drawer = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()

    if (Build.VERSION.SDK_INT >= 33) {
        val perm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
        LaunchedEffect(Unit) { perm.launch(Manifest.permission.POST_NOTIFICATIONS) }
    }

    val menu = remember(me) { menuFor(me) }
    val tabs = remember(me) { bottomFor(me) }
    val start = target(tabs.first().route)
    val isTab = tabs.any { it.route == pattern }
    val go: (String) -> Unit = { r -> nav.navigate(r) { launchSingleTop = true } }
    val goTab: (String) -> Unit = { r ->
        nav.navigate(r) { popUpTo(nav.graph.findStartDestination().id) { saveState = true }; launchSingleTop = true; restoreState = true }
    }
    val currentReal = currentRoute(entry)
    // close the keyboard when the user arrives (after login) and whenever the screen changes
    val keyboard = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current
    LaunchedEffect(pattern) { keyboard?.hide() }
    // system back closes the drawer first
    androidx.activity.compose.BackHandler(enabled = drawer.isOpen) { scope.launch { drawer.close() } }

    ModalNavigationDrawer(
        drawerState = drawer,
        gesturesEnabled = isTab || drawer.isOpen,
        drawerContent = {
            AppDrawer(me, menu, currentReal, onLogout = { scope.launch { drawer.close() }; onLogout() }) { item ->
                scope.launch { drawer.close() }
                if (tabs.any { t -> target(t.route) == item.route.substringBefore('?') }) goTab(item.route) else go(item.route)
            }
        },
    ) {
        Scaffold(
            containerColor = Lavender,
            snackbarHost = { SnackbarHost(snack) { d -> Snackbar(d, shape = RoundedCornerShape(14.dp), containerColor = Indigo900, contentColor = Color.White) } },
            topBar = {
                GradientTopBar(
                    title = titleFor(pattern, entry, me),
                    subtitle = if (isTab && pattern != "dashboard") me.academy?.name else null,
                    navigation = {
                        if (isTab) BarIcon(Icons.Outlined.Menu, "Menu") { scope.launch { drawer.open() } }
                        else BackIcon { nav.popBackStack() }
                    },
                    actions = {
                        if (pattern != "notifications") BarIcon(Icons.Outlined.Notifications, "Notifications") { go("notifications") }
                        if (pattern == "students" && me.can("students.manage")) BarIcon(Icons.Outlined.PersonAdd, "Add student") { go("addStudent") }
                    },
                )
            },
            bottomBar = {
                AnimatedVisibility(isTab, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                    CnxBottomBar(tabs, pattern) { t ->
                        if (t.route == MORE) scope.launch { drawer.open() } else goTab(target(t.route))
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
                composable("message?student={student}", listOf(navArgument("student") { type = NavType.LongType; defaultValue = 0L })) {
                    MessageScreen(me, it.arguments!!.getLong("student").takeIf { s -> s > 0 }, snack, back = { nav.popBackStack() })
                }
                composable("module/{key}", listOf(navArgument("key") { type = NavType.StringType })) {
                    val spec = remember(me) { moduleFor(it.arguments!!.getString("key") ?: "", me) }
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
                composable("notificationSettings") { NotificationSettingsScreen(me, snack) }
                composable("communication") { CommunicationScreen(me, go) }
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
    "subscription" to "Subscription", "academyProfile" to "Academy Profile", "notificationSettings" to "Notification Settings", "communication" to "Communication",
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
        pattern.startsWith("reports") -> if ((a?.getInt("financial") ?: 0) == 1) "Financial Reports" else "Reports"
        pattern.startsWith("markAttendance") || pattern.startsWith("attendance/") -> "Mark Attendance"
        pattern.startsWith("history") -> "Attendance History"
        pattern.startsWith("student/") -> "Student Profile"
        pattern.startsWith("branchStudents") -> "Branch Students"
        pattern.startsWith("collect/") -> "Collect Fee"
        pattern.startsWith("message") -> "WhatsApp & Reminders"
        pattern.startsWith("branch/") -> "Branch"
        pattern.startsWith("assign/") -> "Assign Students"
        pattern.startsWith("report/") -> "Report"
        pattern.startsWith("batchStudents") -> "Batch Students"
        pattern.startsWith("ticket/") -> "Support Ticket"
        else -> "ClassNex"
    }
}

@Composable
private fun AppDrawer(me: Me, menu: List<MenuSection>, current: String, onLogout: () -> Unit, onSelect: (MenuItem) -> Unit) {
    ModalDrawerSheet(drawerContainerColor = Color.White, drawerShape = RoundedCornerShape(topEnd = 28.dp, bottomEnd = 28.dp), modifier = Modifier.widthIn(max = 320.dp)) {
        Column(Modifier.fillMaxWidth().background(Brand.drawer).statusBarsPadding().padding(20.dp)) {
            Box(Modifier.size(58.dp).clip(RoundedCornerShape(18.dp)).background(Color.White), contentAlignment = Alignment.Center) {
                val logo = me.academy?.logo_url
                if (logo != null) AsyncImage(logo, null, Modifier.fillMaxSize().padding(4.dp)) else Image(painterResource(R.drawable.classnex_icon), null, Modifier.size(42.dp))
            }
            Spacer(Modifier.height(12.dp))
            Text(me.academy?.name ?: "ClassNex", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text("${me.name} · ${roleName(me.role)}", color = Color.White.copy(alpha = .8f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            me.subscription?.package_name?.let { Pill("$it · ${label(me.subscription?.status)}", Color.White, Modifier.padding(top = 8.dp)) }
        }
        LazyColumn(Modifier.weight(1f).testTag("drawerList"), contentPadding = PaddingValues(12.dp, 8.dp, 12.dp, 8.dp)) {
            menu.forEach { sec ->
                item { Text(sec.title.uppercase(), color = Muted, fontSize = 11.sp, fontWeight = FontWeight.Bold, letterSpacing = 1.sp, modifier = Modifier.padding(start = 12.dp, top = 14.dp, bottom = 4.dp)) }
                sec.items.forEach { mi ->
                    item {
                        val on = mi.route == current
                        NavigationDrawerItem(
                            label = { Text(mi.label, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                            icon = { Icon(mi.icon, null) }, selected = on, onClick = { onSelect(mi) },
                            shape = RoundedCornerShape(14.dp), modifier = Modifier.height(48.dp).testTag("menu:" + mi.route),
                            colors = NavigationDrawerItemDefaults.colors(selectedContainerColor = VioletSoft, selectedTextColor = Indigo800, selectedIconColor = Violet, unselectedIconColor = Muted, unselectedTextColor = Ink),
                        )
                    }
                }
            }
        }
        HorizontalDivider(color = Line)
        Row(Modifier.fillMaxWidth().navigationBarsPadding().clickable(onClick = onLogout).padding(20.dp, 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.AutoMirrored.Outlined.Logout, null, tint = Red)
            Text("Log out", color = Red, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f).padding(start = 12.dp))
            Text("v${BuildConfig.VERSION_NAME}", color = Muted, fontSize = 11.sp)
        }
    }
}
