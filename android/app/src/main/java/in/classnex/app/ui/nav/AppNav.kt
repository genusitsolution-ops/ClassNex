package `in`.classnex.app.ui.nav

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.CurrencyRupee
import androidx.compose.material.icons.outlined.Dashboard
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Receipt
import androidx.compose.material3.CenterAlignedTopAppBar
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.ui.academy.AddStudentScreen
import `in`.classnex.app.ui.academy.AttendanceListScreen
import `in`.classnex.app.ui.academy.AttendanceSheetScreen
import `in`.classnex.app.ui.academy.CollectFeeScreen
import `in`.classnex.app.ui.academy.DashboardScreen
import `in`.classnex.app.ui.academy.MessageScreen
import `in`.classnex.app.ui.academy.PaymentsScreen
import `in`.classnex.app.ui.academy.PendingFeesScreen
import `in`.classnex.app.ui.academy.StudentDetailScreen
import `in`.classnex.app.ui.academy.StudentsScreen
import `in`.classnex.app.ui.admin.AdminDashboardScreen
import `in`.classnex.app.ui.admin.NotificationsScreen
import `in`.classnex.app.ui.admin.ProfileScreen
import `in`.classnex.app.ui.portal.PortalAttendanceScreen
import `in`.classnex.app.ui.portal.PortalFeesScreen
import `in`.classnex.app.ui.portal.PortalHomeScreen
import `in`.classnex.app.ui.theme.Navy

private data class Tab(val route: String, val label: String, val icon: ImageVector)

private val titles = mapOf(
    "dashboard" to "Dashboard", "students" to "Students", "addStudent" to "Add Student", "attendance" to "Attendance",
    "fees" to "Pending Fees", "payments" to "Payments", "notifications" to "Notifications", "profile" to "Profile",
    "portal" to "Home", "portalAttendance" to "Attendance", "portalFees" to "Fees & Receipts", "admin" to "ClassNex Admin",
)

/** One app for every role: the menu, start screen and permissions come from the logged-in user. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScaffold(me: Me, onLogout: () -> Unit) {
    val nav = rememberNavController()
    val snack = remember { SnackbarHostState() }
    val entry by nav.currentBackStackEntryAsState()
    val route = entry?.destination?.route ?: ""

    if (Build.VERSION.SDK_INT >= 33) {
        val perm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
        LaunchedEffect(Unit) { perm.launch(Manifest.permission.POST_NOTIFICATIONS) }
    }

    val tabs = when {
        me.role == "super_admin" -> listOf(Tab("admin", "Home", Icons.Outlined.Dashboard), Tab("notifications", "Alerts", Icons.Outlined.Notifications), Tab("profile", "Profile", Icons.Outlined.AccountCircle))
        me.isFamily -> buildList {
            add(Tab("portal", "Home", Icons.Outlined.Home))
            if (me.has("attendance")) add(Tab("portalAttendance", "Attendance", Icons.Outlined.CalendarMonth))
            if (me.has("fees")) add(Tab("portalFees", "Fees", Icons.Outlined.Receipt))
            add(Tab("notifications", "Alerts", Icons.Outlined.Notifications))
            add(Tab("profile", "Profile", Icons.Outlined.AccountCircle))
        }
        else -> buildList {
            add(Tab("dashboard", "Home", Icons.Outlined.Dashboard))
            add(Tab("students", "Students", Icons.Outlined.Group))
            if (me.has("attendance")) add(Tab("attendance", "Attendance", Icons.Outlined.CalendarMonth))
            if (me.has("fees") && me.can("fees.view")) add(Tab("fees", "Fees", Icons.Outlined.CurrencyRupee))
            add(Tab("profile", "More", Icons.Outlined.AccountCircle))
        }
    }
    val start = tabs.first().route
    val isTab = tabs.any { it.route == route }
    val go: (String) -> Unit = { r -> nav.navigate(r) }

    Scaffold(
        snackbarHost = { SnackbarHost(snack) },
        topBar = {
            CenterAlignedTopAppBar(
                title = { Text(titles[route.substringBefore('/').substringBefore('?')] ?: titleFor(route), fontWeight = FontWeight.Bold) },
                navigationIcon = { if (!isTab) IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", tint = Color.White) } },
                actions = { if (route != "notifications" && !me.isFamily && me.role != "super_admin") IconButton(onClick = { go("notifications") }) { Icon(Icons.Outlined.Notifications, "Notifications", tint = Color.White) } },
                colors = TopAppBarDefaults.centerAlignedTopAppBarColors(containerColor = Navy, titleContentColor = Color.White),
            )
        },
        bottomBar = {
            if (isTab) NavigationBar {
                tabs.forEach { t ->
                    NavigationBarItem(selected = route == t.route, onClick = {
                        nav.navigate(t.route) { popUpTo(nav.graph.findStartDestination().id) { saveState = true }; launchSingleTop = true; restoreState = true }
                    }, icon = { Icon(t.icon, null) }, label = { Text(t.label) })
                }
            }
        },
    ) { pad ->
        NavHost(nav, startDestination = start, modifier = Modifier.padding(pad)) {
            composable("dashboard") { DashboardScreen(me, go) }
            composable("students") { StudentsScreen(me, go) }
            composable("student/{id}", listOf(navArgument("id") { type = NavType.LongType })) { StudentDetailScreen(it.arguments!!.getLong("id"), me, go) }
            composable("addStudent") { AddStudentScreen(snack, onSaved = { id -> nav.popBackStack(); nav.navigate("student/$id") }) }
            composable("attendance") { AttendanceListScreen(go) }
            composable("attendance/{batch}", listOf(navArgument("batch") { type = NavType.LongType })) { AttendanceSheetScreen(it.arguments!!.getLong("batch"), snack, back = { nav.popBackStack() }) }
            composable("collect/{student}", listOf(navArgument("student") { type = NavType.LongType })) { CollectFeeScreen(it.arguments!!.getLong("student"), me, snack, go) }
            composable("fees") { PendingFeesScreen(me, snack, go) }
            composable("payments") { PaymentsScreen(snack) }
            composable("message?student={student}", listOf(navArgument("student") { type = NavType.LongType; defaultValue = 0L })) {
                val sid = it.arguments!!.getLong("student")
                MessageScreen(me, sid.takeIf { s -> s > 0 }, snack, back = { nav.popBackStack() })
            }
            composable("portal") { PortalHomeScreen(me, snack, go) }
            composable("portalAttendance") { PortalAttendanceScreen(me) }
            composable("portalFees") { PortalFeesScreen(me, snack) }
            composable("admin") { AdminDashboardScreen() }
            composable("notifications") { NotificationsScreen() }
            composable("profile") { ProfileScreen(me, snack, onLogout) }
        }
    }
}

private fun titleFor(route: String) = when {
    route.startsWith("student/") -> "Student"
    route.startsWith("attendance/") -> "Mark Attendance"
    route.startsWith("collect/") -> "Collect Fee"
    route.startsWith("message") -> "Send Message"
    else -> "ClassNex"
}
