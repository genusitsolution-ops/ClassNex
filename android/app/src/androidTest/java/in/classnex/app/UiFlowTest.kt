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
            if (compose.onAllNodesWithContentDescription("Menu").fetchSemanticsNodes().isNotEmpty() && !drawerShown()) return
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

    private fun drawerShown() = runCatching { compose.onNodeWithTag("drawerList").assertIsDisplayed() }.isSuccess

    private fun openDrawer() {
        hideKeyboard()
        compose.onNodeWithContentDescription("Menu").performClick()
        // the drawer content is always composed; wait until it is actually on screen
        compose.waitUntil(10_000) { drawerShown() }
        compose.waitForIdle()
    }

    /** Opens a screen from the navigation drawer (the same menu the user sees). */
    private fun menu(route: String) {
        openDrawer()
        compose.onNodeWithTag("drawerList").performScrollToNode(hasTestTag("menu:$route"))
        compose.onNodeWithTag("menu:$route").performClick()
        compose.waitForIdle()
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
        step("dashboard") { shot("03_dashboard"); swipeUp(); shot("04_dashboard_attendance_fees"); swipeUp(); shot("04b_dashboard_actions") }
        step("navigation drawer") {
            openDrawer(); shot("05_drawer")
            compose.onNodeWithTag("drawerList").performTouchInput { swipeUp() }; shot("05b_drawer_more")
            back()
        }
        step("students list") { tab("Students"); waitText("Dancer 1"); shot("06_students") }
        step("student profile") {
            click("Dancer 1"); waitText("Overview"); shot("07_student_profile")
            click("Fees"); waitText("Pending invoices"); shot("08_student_fees")
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
            tab("Fees"); waitText("Pending"); shot("12_fees_pending")
            click("Overdue"); shot("12b_fees_overdue")
        }
        step("collect fee") {
            menu("collect/0"); waitText("Choose the student"); click("Dancer 1"); waitText("Collect & Generate Receipt"); shot("13_collect_fee")
            compose.onNodeWithText("Collect & Generate Receipt").performClick()
            compose.waitUntil(25_000) { has("Payment saved") || has("Receipt RCPT") }
            shot("14_payment_saved", idle = false)
            compose.waitUntil(15_000) { has("Receipt RCPT") }
            shot("14b_receipt_actions")
            click("Done"); back()
        }
        step("payments and receipts") { menu("payments"); waitText("RCPT"); shot("15_payments"); click("RCPT", substring = true); shot("15b_payment_actions"); back() }
        step("branches") { menu("branches"); waitText("North"); shot("16_branches"); back() }
        step("activities") { menu("module/activities"); waitText("Kathak"); shot("17_activities"); back() }
        step("batches") { menu("module/batches"); waitText("North Kathak"); shot("18_batches"); click("North Kathak"); waitText("Cancel class"); shot("18b_batch_actions"); back() }
        step("teachers and teacher form") {
            menu("module/teachers"); waitText("Guru North"); shot("19_teachers")
            hideKeyboard(); compose.onNode(hasText("Add teacher") and hasClickAction()).performSemanticsAction(androidx.compose.ui.semantics.SemanticsActions.OnClick); waitText("Extra permissions"); shot("19b_teacher_form"); back(); back()
        }
        step("fee structure") { menu("module/feePlans"); waitText("Kathak monthly fee"); shot("20_fee_structure"); back() }
        step("discounts") { menu("module/discounts"); waitText("Sibling discount"); shot("20b_discounts"); back() }
        step("timetable") { menu("timetable"); waitText("Mon"); shot("21_timetable"); back() }
        step("attendance history") { menu("history"); waitText("Present"); shot("22_attendance_history"); back() }
        step("announcements") { menu("module/announcements"); waitText("Annual day rehearsal"); shot("23_announcements"); back() }
        step("whatsapp reminders") { menu("message"); waitText("Send via"); shot("24_whatsapp_reminders"); back() }
        step("communication centre") { menu("communication"); waitText("Message log"); shot("24b_communication"); back() }
        step("reports") { menu("reports?financial=0"); waitText("Branch-wise"); shot("25_reports"); click("Branch-wise", substring = true); shot("25b_report"); back(); back() }
        step("subscription") { menu("subscription"); waitText("Current plan"); shot("26_subscription"); back() }
        step("academy profile") { menu("academyProfile"); waitText("Academy name"); shot("27_academy_profile"); back() }
        step("settings") { menu("profile"); waitText("Change password"); shot("28_settings"); back() }
        step("help and support") { menu("module/support"); waitText("How do I print receipts"); shot("29_support"); click("How do I print receipts", substring = true); waitText("Write a reply"); shot("29b_ticket"); back(); back() }
        step("notifications") { compose.onNodeWithContentDescription("Notifications").performClick(); compose.waitForIdle(); shot("30_notifications"); back() }
        assertTrue("$failures Class Admin step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun c_teacherJourney() {
        step("login as teacher") { login("9845100001", "Teach@123"); waitText("Today's classes") }
        step("teacher dashboard") { shot("31_teacher_dashboard") }
        step("teacher menu shows only permitted modules") {
            openDrawer(); shot("32_teacher_drawer")
            assertTrue("teacher must not see Branches", compose.onAllNodesWithTag("menu:branches").fetchSemanticsNodes().isEmpty())
            assertTrue("teacher must not see Subscription", compose.onAllNodesWithTag("menu:subscription").fetchSemanticsNodes().isEmpty())
            back()
        }
        step("teacher attendance") { tab("Attendance"); compose.waitUntil(25_000) { has("Choose a batch") || has("Save Attendance") || has("Save Changes") }; shot("33_teacher_attendance") }
        assertTrue("$failures teacher step(s) failed – see CNXUI log", failures == 0)
    }

    @Test fun d_parentJourney() {
        step("login as parent") { login("9845200001", "Parent@123"); compose.waitUntil(25_000) { has("Meera") || has("Kabir") } }
        step("parent home") { shot("34_parent_home") }
        step("parent attendance") { tab("Attendance"); compose.waitUntil(25_000) { has("Present") }; shot("35_parent_attendance") }
        step("parent fees") { tab("Fees"); compose.waitUntil(25_000) { has("₹") }; shot("36_parent_fees") }
        step("parent menu") { openDrawer(); shot("37_parent_drawer"); back() }
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
