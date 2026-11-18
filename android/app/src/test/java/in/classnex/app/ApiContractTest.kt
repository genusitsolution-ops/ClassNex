package `in`.classnex.app

import `in`.classnex.app.data.net.ApiResponse
import `in`.classnex.app.data.net.Branch
import `in`.classnex.app.data.net.BranchDetail
import `in`.classnex.app.data.net.BranchSummary
import `in`.classnex.app.data.net.BulkBranchResult
import `in`.classnex.app.data.net.Dashboard
import `in`.classnex.app.data.net.DuplicateCandidate
import `in`.classnex.app.data.net.HistoryRow
import `in`.classnex.app.data.net.HistorySummary
import `in`.classnex.app.data.net.Invoice
import `in`.classnex.app.data.net.ManualBatch
import `in`.classnex.app.data.net.ManualLogRow
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.data.net.PaymentRow
import `in`.classnex.app.data.net.ReportData
import `in`.classnex.app.data.net.StudentDetail
import `in`.classnex.app.data.net.StudentRow
import `in`.classnex.app.data.net.TeacherRow
import `in`.classnex.app.data.net.Batch
import `in`.classnex.app.di.AppJson
import kotlinx.serialization.KSerializer
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.JsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Contract tests: every fixture is a real response captured from the ClassNex API
 * (tests/android_fixtures.php). Parsing them with the app's own models proves the Android app
 * and the web panel read the same data from the same endpoints.
 */
class ApiContractTest {
    private fun raw(name: String): String = javaClass.classLoader!!.getResource("fixtures/$name.json")!!.readText()
    private fun <T> parse(name: String, ser: KSerializer<T>): ApiResponse<T> = AppJson.decodeFromString(ApiResponse.serializer(ser), raw(name))
    private fun <T> list(name: String, ser: KSerializer<T>): ApiResponse<List<T>> = parse(name, ListSerializer(ser))

    @Test fun me_has_role_features_and_whatsapp_mode() {
        val me = parse("me", Me.serializer()).data!!
        assertEquals("owner", me.role)
        assertTrue(me.isClassAdmin)
        assertEquals("api", me.whatsapp_mode)
        assertTrue(me.has("whatsapp"))
        val manual = parse("me_manual_whatsapp", Me.serializer()).data!!
        assertEquals("manual", manual.whatsapp_mode)
        assertTrue(manual.whatsappManualOnly)
    }

    @Test fun dashboard_has_academy_wide_quota() {
        val d = parse("dashboard", Dashboard.serializer()).data!!
        assertNotNull(d.student_quota)
        assertEquals(100L, d.student_quota!!.limit)
        assertTrue(d.branches.isNotEmpty())
    }

    @Test fun branches_summary_and_detail() {
        val list = list("branches", Branch.serializer()).data!!
        assertTrue(list.size >= 6)
        val sum = parse("branch_summary", BranchSummary.serializer()).data!!
        assertEquals(sum.total.students, sum.branches.sumOf { it.students })
        val d = parse("branch_detail", BranchDetail.serializer()).data!!
        assertEquals("Vijay Nagar", d.name)
        assertEquals("Coach VN", d.manager_teacher_name)
        assertTrue(d.batches.isNotEmpty())
    }

    @Test fun students_and_profile_carry_branch() {
        val rows = list("students_branch", StudentRow.serializer())
        assertTrue(rows.data!!.all { it.branch_name == "Vijay Nagar" })
        assertTrue((rows.meta!!["total"].toString().toInt()) > 0)
        assertNotNull(parse("student_detail", StudentDetail.serializer()).data!!.branch_name)
        assertTrue(list("batches", Batch.serializer()).data!!.any { it.branch_id != null })
        assertTrue(list("teachers", TeacherRow.serializer()).data!!.isNotEmpty())
    }

    @Test fun attendance_history_views_and_summary() {
        for (v in listOf("history_sessions", "history_students", "history_branches")) {
            val r = list(v, HistoryRow.serializer())
            assertTrue(v, r.data!!.isNotEmpty())
            val s = AppJson.decodeFromJsonElement(HistorySummary.serializer(), (r.meta as JsonObject)["summary"]!!)
            assertTrue(v, s.total_students > 0)
        }
        assertNotNull(list("history_students", HistoryRow.serializer()).data!!.first().status)
    }

    @Test fun reports_bulk_and_duplicates() {
        val rep = parse("report_branch_summary", ReportData.serializer()).data!!
        assertEquals("Branch", rep.columns.first())
        assertTrue(rep.rows.isNotEmpty())
        parse("report_fee_collection", ReportData.serializer()).data!!
        val bulk = parse("bulk_preview", BulkBranchResult.serializer()).data!!
        assertTrue(bulk.preview)
        assertEquals(1L, bulk.count)
        val dup = AppJson.parseToJsonElement(raw("duplicate_error")) as JsonObject
        val errs = dup["errors"] as JsonObject
        val cands = AppJson.decodeFromJsonElement(ListSerializer(DuplicateCandidate.serializer()), errs["candidates"]!!)
        assertTrue(cands.first().reasons.contains("same WhatsApp number"))
        assertFalse(cands.isEmpty())
    }

    @Test fun whatsapp_click_to_send_and_fees() {
        val prep = parse("whatsapp_prepare", ManualBatch.serializer()).data!!
        assertTrue(prep.items.first().link.startsWith("https://wa.me/"))
        list("whatsapp_log", ManualLogRow.serializer())
        list("invoices", Invoice.serializer()).data!!
        list("payments", PaymentRow.serializer()).data!!
    }
}
