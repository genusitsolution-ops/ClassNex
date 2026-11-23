package `in`.classnex.app

import android.util.Log
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.test.core.app.ActivityScenario
import androidx.test.espresso.Espresso
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.After
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.FixMethodOrder
import org.junit.Test
import org.junit.runners.MethodSorters
import org.junit.rules.Timeout
import dagger.hilt.android.EntryPointAccessors
import `in`.classnex.app.di.SessionEntryPoint
import java.util.concurrent.TimeUnit
import org.junit.runner.RunWith
import java.io.FileInputStream

/**
 * End-to-end UI journeys on an emulator against a real ClassNex test server (seeded by
 * tests/e2e_final.php + tests/android_ui_seed.php). Every step is logged as PASS/FAIL under the
 * "CNXUI" logcat tag and a screenshot of each redesigned screen is saved to /data/local/tmp/cnx.
 * A test fails if any of its steps failed.
 */
@RunWith(AndroidJUnit4::class)
@FixMethodOrder(MethodSorters.NAME_ASCENDING)
class UiFlowTest {
    @get:Rule(order = 0) val compose = createEmptyComposeRule()
    /** A stuck journey fails after 9 minutes instead of blocking the whole run. */
    @get:Rule(order = 1) val timeout: Timeout = Timeout(9, TimeUnit.MINUTES)

    private var scenario: ActivityScenario<MainActivity>? = null
    private val inst get() = InstrumentationRegistry.getInstrumentation()
    private var failures = 0

    @Before fun reset() {
        // log out through the app's own session store (the singleton keeps prefs in memory)
        EntryPointAccessors.fromApplication(inst.targetContext.applicationContext, SessionEntryPoint::class.java).store().clear()
        shell("mkdir -p /data/local/tmp/cnx")
        failures = 0
    }

    @After fun close() { scenario?.close() }

    // ------------------------------------------------------------------ helpers
    private fun shell(cmd: String) {
        inst.uiAutomation.executeShellCommand(cmd).use { pfd -> FileInputStream(pfd.fileDescriptor).use { it.readBytes() } }
    }

    private fun shot(name: String, idle: Boolean = true) {
        if (idle) runCatching { compose.waitForIdle() }
        Thread.sleep(600)
        shell("screencap -p /data/local/tmp/cnx/$name.png")
    }

    /** After a failed step, go back to a main (tab) screen so the next step starts from a known place. */
    private var recover = false
    private fun toMainScreen() {
        repeat(5) {
            if (compose.onAllNodesWithTag("tab:More").fetchSemanticsNodes().isNotEmpty()) return
            runCatching { back() }
        }
    }

    private fun step(name: String, block: () -> Unit) {
        if (recover) { runCatching { toMainScreen() }; recover = false }
        val t0 = System.currentTimeMillis()
        Log.i("CNXUI", "START $name")
        try {
            block()
            Log.i("CNXUI", "PASS $name (${System.currentTimeMillis() - t0} ms)")
        } catch (t: Throwable) {
            failures++
            recover = true
            Log.i("CNXUI", "FAIL $name (${System.currentTimeMillis() - t0} ms): ${t.javaClass.simpleName}: ${t.message?.lineSequence()?.firstOrNull()?.take(200)}")
            runCatching { shot("FAIL_" + name.replace(Regex("[^A-Za-z0-9]+"), "_"), idle = false) }
        }
    }

    private fun has(text: String, substring: Boolean = true) =
        compose.onAllNodesWithText(text, substring = substring, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun waitText(text: String, timeout: Long = 25_000, substring: Boolean = true) {
        compose.waitUntil(timeout) { has(text, substring) }
    }

    private fun click(text: String, substring: Boolean = false) {
        waitText(text, substring = substring)
        compose.onAllNodesWithText(text, substring = substring)[0].performClick()
    }

    private fun type(label: String, value: String) {
        compose.onNode(hasText(label) and hasSetTextAction()).performTextReplacement(value)
    }

    private fun pressButton(text: String) { hideKeyboard(); click(text) }

    private fun swipeUp() = compose.onAllNodes(isRoot())[0].performTouchInput { swipeUp(startY = bottom * .8f, endY = top + bottom * .25f) }

    private fun launch() {
        scenario = ActivityScenario.launch(MainActivity::class.java)
    }

    private fun login(id: String, pw: String) {
        launch()
        compose.waitUntil(15_000) { has("Skip") || has("Log In") }
        if (has("Skip")) click("Skip")
        waitText("Log In")
        type("Mobile number or email", id)
        type("Password", pw)
        hideKeyboard()
        compose.onNodeWithText("Log In").performClick()
    }

    private fun hideKeyboard() { runCatching { Espresso.closeSoftKeyboard() } }

    /** Opens a screen from the More tab (the same place the user finds it). */
    private fun menu(route: String) {
        hideKeyboard()
        tab("More")
        compose.waitUntil(10_000) { compose.onAllNodesWithTag("moreList").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithTag("moreList").performScrollToNode(hasTestTag("more:$route"))
        compose.onNodeWithTag("more:$route").performClick()
        compose.waitForIdle()
    }

    private fun home() { tab("Home") }

    /** Taps one of the big quick-action cards at the top of the dashboard. */
    private fun quick(text: String) {
        home()
        compose.waitUntil(15_000) { compose.onAllNodesWithTag("dashList").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithTag("dashList").performScrollToNode(hasTestTag("quick:$text"))
        compose.onNodeWithTag("quick:$text").performClick(); compose.waitForIdle()
    }

    private fun back() { hideKeyboard(); Espresso.pressBack(); compose.waitForIdle() }

    private fun tab(label: String) { compose.onNodeWithTag("tab:$label").performClick(); compose.waitForIdle() }

    // ------------------------------------------------------------------ journeys
    @Test fun a_authScreens() {
        EntryPointAccessors.fromApplication(inst.targetContext.applicationContext, SessionEntryPoint::class.java).store().onboarded = false
        launch()
        step("onboarding pages") {
            waitText("Add students in seconds"); shot("01_onboarding_1")
            click("Next"); waitText("One-tap attendance"); shot("01_onboarding_2")
            click("Next"); waitText("Fees without follow-ups"); shot("01_onboarding_3")
            click("Get started")
        }
        step("login page") { waitText("Welcome Back"); shot("02_login") }
        step("wrong password shows a message") {
            type("Mobile number or email", "9845000001"); type("Password", "wrong-password")
            hideKeyboard(); compose.onNodeWithText("Log In").performClick()
            compose.waitUntil(20_000) { has("password", true) && (has("incorrect") || has("Incorrect") || has("invalid") || has("Invalid") || has("wrong")) }
            shot("02b_login_error")
        }
        step("register page") {
            pressButton("Register Your Academy"); waitText("Free trial after approval"); shot("02c_register")
            back()
        }
        step("track registration status") {
            pressButton("Track Registration Status"); waitText("Track Registration")
            type("Mobile number or email", "9845000001"); type("Password", "Asha@1234")
            pressButton("Check Status"); waitText("Approved", 20_000); shot("02d_track_status")
            back()
        }
        assertTrue("$failures auth step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun b_classAdminJourney() {
        step("login as Class Admin") { login("9845000001", "Asha@1234"); waitText("Hello, Asha") }
        step("dashboard: quick actions first") {
            waitText("Today at a Glance"); shot("03_dashboard")
            for (q in listOf("Add Student", "Mark Attendance", "Collect Fee", "Pending Fees", "Students", "Send Reminder"))
                assertTrue("quick action $q", compose.onAllNodesWithTag("quick:$q").fetchSemanticsNodes().isNotEmpty())
            swipeUp(); shot("04_dashboard_glance")
        }
        step("more tab") { tab("More"); waitText("Academy Management"); shot("05_more"); swipeUp(); shot("05b_more_lower") }
        step("add student form") { quick("Add Student"); waitText("Student name"); shot("05c_add_student"); click("More details (optional)"); waitText("Date of birth"); shot("05d_add_student_more"); back() }
        step("students list") {
            tab("Students"); waitText("Dancer 1"); compose.waitUntil(15_000) { has("All (") }; shot("06_students")
            compose.onNodeWithContentDescription("Filters").performClick(); waitText("Filter students"); shot("06b_student_filters"); back()
        }
        step("student profile") {
            click("Dancer 1"); waitText("Overview"); shot("07_student_profile")
            click("Fees"); waitText("Pending fees"); shot("08_student_fees")
            click("Batches"); waitText("Activities & batches"); shot("08b_student_batches")
            back()
        }
        step("mark attendance") {
            tab("Attendance"); waitText("Choose a batch"); shot("09_attendance_select")
            compose.onAllNodesWithText("Batch")[0].performClick()
            click("North Kathak ·", substring = true)
            compose.waitUntil(25_000) { has("Save Attendance") || has("Save Changes") }
            shot("10_attendance_register")
            click("All Present")
            compose.onNode(hasText("Save Attendance") or hasText("Save Changes")).performClick()
            compose.waitUntil(25_000) { has("Attendance saved") }
            shot("11_attendance_saved", idle = false)
        }
        step("fees – pending and overdue") {
            compose.waitForIdle()
            tab("Fees"); waitText("Recent Payments"); compose.waitUntil(20_000) { has("This month") }; shot("12_fees_collect_tab")
            compose.onAllNodes(hasSetTextAction())[0].performTextReplacement("Dancer 1"); waitText("RDS0001", 20_000); click("Dancer 1"); waitText("Advance"); shot("12a_fees_student_balance")
            click("Pending"); waitText("fee(s) pending"); shot("12b_fees_pending")
            click("Overdue"); compose.waitUntil(25_000) { has("fee(s) pending") || has("Nothing overdue") }; shot("12c_fees_overdue")
        }
        step("collect fee in simple steps") {
            quick("Collect Fee"); waitText("Choose the student"); click("Dancer 1"); waitText("Select the fees"); shot("13_collect_fee")
            compose.onNode(hasText("Collect ₹", substring = true) and hasClickAction()).performClick()
            waitText("Confirm payment"); shot("13b_confirm_payment")
            click("Confirm & Create Receipt")
            compose.waitUntil(25_000) { has("Payment saved") || has("Receipt RCPT") }
            shot("14_payment_saved", idle = false)
            compose.waitUntil(15_000) { has("Receipt RCPT") }
            shot("14b_receipt_actions")
            click("Done"); back()
        }
        step("send reminder") { quick("Send Reminder"); waitText("Send via"); shot("24_send_reminder"); back() }
        step("reminders and whatsapp settings") {
            menu("reminders"); waitText("Fee Reminders"); shot("24b_reminders_whatsapp"); swipeUp(); shot("24c_whatsapp_choice")
            click("Advanced settings"); waitText("Which messages go where"); shot("24d_advanced_settings"); back()
        }
        step("payments and receipts") { menu("payments"); waitText("RCPT"); shot("15_payments"); click("RCPT", substring = true); shot("15b_payment_actions"); back() }
        step("branches") { menu("branches"); waitText("North"); shot("16_branches"); back() }
        step("activities and batches") {
            menu("module/activities"); waitText("Yoga"); shot("17_activities"); back()
            menu("module/batches"); waitText("North Kathak"); shot("18_batches"); click("North Kathak"); click("Edit"); waitText("Class days"); shot("18c_batch_form")
            click("More options (optional)"); waitText("Capacity"); shot("18d_batch_form_more"); back(); back()
        }
        step("teachers and teacher form") {
            menu("module/teachers"); waitText("Guru North"); shot("19_teachers")
            click("Guru North"); click("Edit"); waitText("What else this teacher may do"); shot("19b_teacher_form"); back(); back()
        }
        step("fee plans") { menu("module/feePlans"); waitText("Kathak monthly fee"); shot("20_fee_plans"); back() }
        step("discounts") { menu("module/discounts"); waitText("Sibling discount"); shot("20b_discounts"); back() }
        step("timetable") { menu("timetable"); compose.waitUntil(25_000) { has("North Kathak") || has("No classes on this day") }; shot("21_timetable"); back() }
        step("attendance history") { menu("history"); waitText("Present"); shot("22_attendance_history"); back() }
        step("announcements") { menu("module/announcements"); waitText("Annual day rehearsal"); shot("23_announcements"); back() }
        step("reports") { menu("reports?financial=0"); waitText("Branch-wise"); shot("25_reports"); click("Branch-wise", substring = true); compose.waitUntil(25_000) { has("North") }; shot("25b_report"); back(); back() }
        step("subscription") { menu("subscription"); waitText("Current plan"); shot("26_subscription"); back() }
        step("academy profile") { menu("academyProfile"); waitText("Academy name"); shot("27_academy_profile"); back() }
        step("settings and academy settings") {
            menu("profile"); waitText("Change password"); shot("28_settings")
            click("Receipts & IDs"); waitText("Receipt number prefix"); shot("28b_receipts_ids"); back()
            click("Activity log"); compose.waitUntil(20_000) { has("System") || has("No activity yet") || has("Asha") }; shot("28c_activity_log"); back(); back()
        }
        step("help and support") { menu("module/support"); waitText("How do I print receipts"); shot("29_support"); click("How do I print receipts", substring = true); waitText("Write a reply"); shot("29b_ticket"); back(); back() }
        step("notifications") { compose.onNodeWithContentDescription("Notifications").performClick(); compose.waitForIdle(); shot("30_notifications"); back() }
        assertTrue("$failures Class Admin step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun c_teacherJourney() {
        step("login as teacher") { login("9845100001", "Teach@123"); waitText("Today's classes") }
        step("teacher dashboard") { shot("31_teacher_dashboard") }
        step("teacher More shows only permitted sections") {
            tab("More"); waitText("Academy Management"); shot("32_teacher_more")
            assertTrue("teacher must not see Branches", compose.onAllNodesWithTag("more:branches").fetchSemanticsNodes().isEmpty())
            assertTrue("teacher must not see Subscription", compose.onAllNodesWithTag("more:subscription").fetchSemanticsNodes().isEmpty())
            assertTrue("teacher must not see Teachers", compose.onAllNodesWithTag("more:module/teachers").fetchSemanticsNodes().isEmpty())
        }
        step("teacher attendance") { tab("Attendance"); compose.waitUntil(25_000) { has("Choose a batch") || has("Save Attendance") || has("Save Changes") }; shot("33_teacher_attendance") }
        assertTrue("$failures teacher step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun d_parentJourney() {
        step("login as parent") { login("9845200001", "Parent@123"); compose.waitUntil(25_000) { has("Meera") || has("Kabir") } }
        step("parent home") { shot("34_parent_home") }
        step("parent attendance") { tab("Attendance"); compose.waitUntil(25_000) { has("Present") }; shot("35_parent_attendance") }
        step("parent fees") { tab("Fees"); compose.waitUntil(25_000) { has("₹") }; shot("36_parent_fees") }
        step("parent More") { tab("More"); waitText("Help & Support"); shot("37_parent_more") }
        assertTrue("$failures parent step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun e_superAdminJourney() {
        step("login as Super Admin") { login("qa-admin@classnex.test", "Admin@12345"); waitText("platform overview") }
        step("super admin dashboard") { shot("38_superadmin_dashboard") }
        step("registrations") { tab("Approvals"); waitText("Pending"); shot("39_registrations"); click("Rejected"); waitText("Spam Academy"); shot("39b_registrations_rejected") }
        step("academies") { tab("Academies"); waitText("Rao Dance Studio"); shot("40_academies") }
        assertTrue("$failures super admin step(s) failed – see CNXUI log", failures == 0)
    }
}
