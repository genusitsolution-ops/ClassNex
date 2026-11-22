package `in`.classnex.app.data.net

import kotlinx.serialization.KSerializer
import kotlinx.serialization.Serializable
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.longOrNull

/** MySQL SUM()/DECIMAL values arrive as strings; counts as numbers. Accept both. */
object FlexLongSerializer : KSerializer<Long> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("FlexLong", PrimitiveKind.LONG)
    override fun serialize(encoder: Encoder, value: Long) = encoder.encodeLong(value)
    override fun deserialize(decoder: Decoder): Long {
        val el = (decoder as? JsonDecoder)?.decodeJsonElement() ?: return decoder.decodeLong()
        if (el is JsonNull) return 0
        val p = el as? JsonPrimitive ?: return 0
        return p.longOrNull ?: p.doubleOrNull?.toLong() ?: p.content.toDoubleOrNull()?.toLong() ?: 0
    }
}

/** PHP returns 0/1, true/false or "1"; accept all. */
object FlexBoolSerializer : KSerializer<Boolean> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("FlexBool", PrimitiveKind.BOOLEAN)
    override fun serialize(encoder: Encoder, value: Boolean) = encoder.encodeBoolean(value)
    override fun deserialize(decoder: Decoder): Boolean {
        val el = (decoder as? JsonDecoder)?.decodeJsonElement() ?: return decoder.decodeBoolean()
        val p = el as? JsonPrimitive ?: return false
        return p.booleanOrNull ?: (p.longOrNull?.let { it != 0L }) ?: (p.content == "1" || p.content.equals("true", true))
    }
}

/** Percentages arrive as numbers or (from SQL ROUND) as strings. */
object FlexDoubleSerializer : KSerializer<Double> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("FlexDouble", PrimitiveKind.DOUBLE)
    override fun serialize(encoder: Encoder, value: Double) = encoder.encodeDouble(value)
    override fun deserialize(decoder: Decoder): Double {
        val el = (decoder as? JsonDecoder)?.decodeJsonElement() ?: return decoder.decodeDouble()
        val p = el as? JsonPrimitive ?: return 0.0
        return p.doubleOrNull ?: p.content.toDoubleOrNull() ?: 0.0
    }
}

typealias FLong = @Serializable(with = FlexLongSerializer::class) Long
typealias FDouble = @Serializable(with = FlexDoubleSerializer::class) Double
typealias FBool = @Serializable(with = FlexBoolSerializer::class) Boolean

@Serializable
data class ApiResponse<T>(
    val success: Boolean = false,
    val message: String = "",
    val data: T? = null,
    val meta: JsonObject? = null,
    val errors: Map<String, String>? = null,
    val code: String? = null,
)

// ------------------------------------------------------------------ auth
@Serializable data class LoginRequest(val identifier: String, val password: String, val client: String = "android", val device_name: String? = null, val account_id: Long? = null)
@Serializable data class AccountChoice(val id: Long, val role: String, val name: String, val academy: String? = null)
@Serializable data class LoginData(val token: String? = null, val me: Me? = null, val choose_account: FBool = false, val accounts: List<AccountChoice> = emptyList())
@Serializable data class Academy(val id: Long = 0, val name: String = "", val code: String? = null, val slug: String? = null, val logo_url: String? = null, val mobile: String? = null)
@Serializable data class SubState(val status: String = "none", val writable: FBool = false, val package_name: String? = null, val ends_on: String? = null, val days_left: FLong? = null)
@Serializable data class Child(val id: Long, val name: String, val student_code: String = "", val photo_url: String? = null)
@Serializable data class Me(
    val id: Long, val name: String, val role: String, val email: String? = null, val mobile: String? = null, val country_code: String? = null,
    val permissions: List<String> = emptyList(), val academy: Academy? = null, val subscription: SubState? = null,
    val features: List<String> = emptyList(), val children: List<Child> = emptyList(),
    /** Package WhatsApp mode set by the Super Admin: off | manual (click-to-send) | api (official, automatic). */
    val whatsapp_mode: String = "off",
) {
    fun can(p: String) = role == "owner" || role == "super_admin" || permissions.contains(p)
    fun has(f: String) = features.contains(f)
    /** "owner" is the stored key of the Class Admin (Academy Admin) role. */
    val isClassAdmin get() = role == "owner"
    /** Manual WhatsApp: ClassNex prepares the message, the user sends it from WhatsApp. */
    val whatsappManualOnly get() = has("whatsapp_manual") && !has("whatsapp")
    val isStaff get() = role == "owner" || role == "teacher"
    val isFamily get() = role == "parent" || role == "student"
}

// -------------------------------------------------------------- academy
@Serializable data class Activity(val id: Long, val name: String, val monthly_fee_paise: FLong = 0, val status: String = "active")
@Serializable data class Batch(val id: Long, val activity_id: Long, val name: String, val activity_name: String = "", val start_time: String? = null, val end_time: String? = null, val student_count: FLong = 0, val teacher_name: String? = null, val status: String = "active", val branch_id: Long? = null, val branch_name: String? = null)
@Serializable data class StudentRow(
    val id: Long, val student_code: String, val name: String, val guardian_name: String? = null, val country_code: String = "+91", val whatsapp: String = "",
    val status: String = "active", val enrolled_in: String? = null, val pending_paise: FLong = 0, val photo_url: String? = null,
    val branch_id: Long? = null, val branch_name: String? = null,
)
@Serializable data class Balance(val outstanding_paise: FLong = 0, val overdue_paise: FLong = 0, val advance_paise: FLong = 0)
@Serializable data class Enrollment(val activity_name: String, val batch_name: String? = null, val status: String = "active", val start_date: String? = null, val activity_id: Long? = null, val batch_id: Long? = null, val branch_name: String? = null)
@Serializable data class ParentInfo(val id: Long, val name: String, val country_code: String = "+91", val whatsapp: String = "", val relation: String? = null, val login_status: String? = null)
@Serializable data class StudentDetail(
    val id: Long, val student_code: String, val name: String, val country_code: String = "+91", val whatsapp: String = "", val guardian_name: String? = null,
    val email: String? = null, val dob: String? = null, val gender: String? = null, val address: String? = null, val status: String = "active", val admission_date: String? = null,
    val photo_url: String? = null, val enrollments: List<Enrollment> = emptyList(), val parents: List<ParentInfo> = emptyList(), val balance: Balance? = null,
    val branch_id: Long? = null, val branch_name: String? = null,
)
@Serializable data class CreateStudentData(val student: StudentDetail)
@Serializable data class OcrResult(
    val name: String? = null, val dob: String? = null, val gender: String? = null, val address: String? = null, val guardian_name: String? = null,
    val document_type: String? = null, val id_last4: String? = null, val confidence: String? = null, val message: String? = null,
)
@Serializable data class OcrText(val text: String)

@Serializable data class AttStudent(val id: Long, val student_code: String, val name: String, val status: String? = null, val photo_url: String? = null)
@Serializable data class AttSheet(val date: String, val holiday: String? = null, val students: List<AttStudent> = emptyList(), val already_marked: FBool = false)
@Serializable data class AttEntry(val student_id: Long, val status: String)
@Serializable data class AttSave(val batch_id: Long, val date: String, val entries: List<AttEntry>, val reason: String? = null)
@Serializable data class AttResult(val new: FLong = 0, val changed: FLong = 0, val unchanged: FLong = 0, val notifications: FLong = 0)
@Serializable data class TodayBatch(val id: Long, val name: String, val activity_name: String = "", val start_time: String? = null, val session_id: Long? = null, val present: FLong = 0, val absent: FLong = 0, val enrolled: FLong = 0, val scheduled: FBool = true)
@Serializable data class TodayAtt(val date: String, val batches: List<TodayBatch> = emptyList())
@Serializable data class AttDay(val session_date: String, val status: String, val batch_name: String? = null)
@Serializable data class AttCounts(val present: FLong = 0, val absent: FLong = 0, val late: FLong = 0, val leave: FLong = 0, val holiday: FLong = 0)
@Serializable data class AttMonth(val month: String, val days: List<AttDay> = emptyList(), val counts: AttCounts = AttCounts(), val percentage: Double? = null)

@Serializable data class Invoice(val id: Long, val branch_name: String? = null, val invoice_no: String = "", val title: String, val due_date: String, val total_paise: FLong = 0, val paid_paise: FLong = 0, val balance_paise: FLong = 0, val status: String = "unpaid", val student_id: Long = 0, val student_name: String? = null, val student_code: String? = null, val days_overdue: FLong = 0)
@Serializable data class PaymentRow(val id: Long, val branch_name: String? = null, val amount_paise: FLong, val method: String, val paid_on: String, val status: String = "success", val receipt_no: String? = null, val receipt_id: Long? = null, val student_name: String? = null, val reference: String? = null)
@Serializable data class StudentBrief(val id: Long, val name: String, val student_code: String, val whatsapp: String = "")
@Serializable data class Dues(val student: StudentBrief, val invoices: List<Invoice> = emptyList(), val balance: Balance = Balance(), val recent_payments: List<PaymentRow> = emptyList())
@Serializable data class PaymentRequest(val student_id: Long, val amount: String, val method: String, val reference: String? = null, val paid_on: String? = null, val invoice_ids: List<Long> = emptyList(), val notes: String? = null)
@Serializable data class PaymentResult(val id: Long, val amount_paise: FLong, val method: String, val receipt_no: String? = null, val receipt_id: Long? = null, val receipt_url: String? = null, val balance_after_paise: FLong = 0, val student_name: String = "", val duplicate: FBool = false)
@Serializable data class ShareLink(val whatsapp_link: String? = null, val url: String? = null)

@Serializable data class UpcomingDue(val id: Long, val student_id: Long, val student_name: String, val title: String, val due_date: String, val balance_paise: FLong)
@Serializable data class TrendPoint(val month: String, val total: FLong)
@Serializable data class TodayCounts(val present: FLong = 0, val absent: FLong = 0, val marked: FLong = 0, val late: FLong = 0, val leave: FLong = 0)
@Serializable data class ScheduledMessage(val event_key: String? = null, val channel: String? = null, val recipient_name: String? = null, val subject: String? = null, val body: String? = null, val next_attempt_at: String? = null)
@Serializable data class DashNotification(val id: Long = 0, val title: String = "", val body: String? = null, val created_at: String? = null)
@Serializable data class TeacherClass(val id: Long, val name: String, val activity_name: String = "", val start_time: String? = null, val end_time: String? = null, val marked: FBool = false)
@Serializable data class Dashboard(
    val role: String = "owner",
    val active_students: FLong = 0, val new_admissions: FLong = 0, val total_teachers: FLong = 0, val total_batches: FLong = 0,
    val today_attendance: TodayCounts = TodayCounts(), val batches_not_marked: FLong = 0, val pending_online_admissions: FLong = 0,
    val month_collection_paise: FLong = 0, val today_collection_paise: FLong = 0, val pending_fees_paise: FLong = 0, val overdue_fees_paise: FLong = 0, val overdue_students: FLong = 0,
    val upcoming_dues: List<UpcomingDue> = emptyList(), val recent_payments: List<PaymentRow> = emptyList(), val collection_trend: List<TrendPoint> = emptyList(),
    // teacher
    val todays_classes: List<TeacherClass> = emptyList(), val assigned_batches: FLong = 0, val total_students: FLong = 0, val attendance_pending: FLong = 0,
    // academy-wide student quota (all branches) and this month's branch figures
    val student_quota: Quota? = null, val branches: List<BranchFigures> = emptyList(),
    // redesign: academy totals, branch filter, last month for the trend badge, scheduled reminders
    val total_branches: FLong = 0, val total_activities: FLong = 0, val branch_id: Long? = null, val last_month_collection_paise: FLong = 0,
    val scheduled_messages: List<ScheduledMessage> = emptyList(), val recent_notifications: List<DashNotification> = emptyList(), val subscription: SubState? = null,
)
@Serializable data class Quota(val active: FLong = 0, val limit: FLong? = null)
@Serializable data class MessageRequest(val target: String, val batch_id: Long? = null, val student_ids: List<Long> = emptyList(), val message: String? = null, val title: String? = null, val type: String = "message", val channels: List<String>, val overdue_only: Int = 0, val client_ref: String, val send_at: String? = null)

@Serializable data class AppNotification(val id: Long, val title: String, val body: String, val created_at: String, val read_at: String? = null)
@Serializable data class ReadRequest(val ids: List<Long> = emptyList())
@Serializable data class DeviceRequest(val fcm_token: String)
@Serializable data class ForgotRequest(val identifier: String)
@Serializable data class RegisterAcademy(
    val owner_name: String, val mobile: String, val academy_name: String, val password: String, val city: String? = null, val email: String? = null,
    val country_code: String = "+91", val accept_terms: Int = 1, val client: String = "android",
)
@Serializable data class RegStatusRequest(val identifier: String, val password: String)
@Serializable data class RegStatus(val status: String = "", val academy_name: String = "", val registered_on: String? = null, val approved_on: String? = null, val reason: String? = null)
@Serializable data class RegisterResult(val pending: FBool = false, val token: String? = null)
@Serializable data class ChangePassword(val current_password: String, val new_password: String)

// --------------------------------------------------------------- portal
@Serializable data class NextClass(val activity_name: String = "", val batch_name: String = "", val date: String = "", val start_time: String? = null, val location: String? = null)
@Serializable data class TodayMark(val status: String, val batch_name: String? = null)
@Serializable data class MonthAtt(val percentage: Double? = null, val counts: AttCounts = AttCounts())
@Serializable data class NextDue(val id: Long, val title: String, val due_date: String, val balance_paise: FLong)
@Serializable data class LastPayment(val id: Long, val amount_paise: FLong, val paid_on: String, val method: String = "", val receipt_no: String? = null, val receipt_id: Long? = null)
@Serializable data class PortalStudent(val id: Long, val name: String, val student_code: String, val photo_url: String? = null)
@Serializable data class PortalDashboard(
    val student: PortalStudent, val enrollments: List<Enrollment> = emptyList(), val today_attendance: TodayMark? = null, val month_attendance: MonthAtt = MonthAtt(),
    val next_class: NextClass? = null, val fees: Balance? = null, val next_due: NextDue? = null, val last_payment: LastPayment? = null, val online_payment: FBool = false,
    val notifications: List<AppNotification> = emptyList(),
)
@Serializable data class PortalFees(val balance: Balance = Balance(), val pending: List<Invoice> = emptyList(), val payments: List<PaymentRow> = emptyList(), val online_payment: FBool = false)

// ---------------------------------------------------------------- admin
@Serializable data class AdminDashboard(
    val total_academies: FLong = 0, val total_students: FLong = 0, val active_subscriptions: FLong = 0, val trial_subscriptions: FLong = 0,
    val expired_subscriptions: FLong = 0, val monthly_revenue_paise: FLong = 0, val whatsapp_month: FLong = 0, val new_enquiries: FLong = 0, val open_tickets: FLong = 0,
    val pending_registrations: FLong = 0, val total_owners: FLong = 0, val pending_subscription_payments: FLong = 0, val revenue_trend: List<TrendPoint> = emptyList(),
)
@Serializable data class OwnerRow(val academy_id: Long, val academy_name: String, val owner_name: String? = null, val mobile: String? = null, val package_name: String? = null, val sub_status: String? = null, val ends_on: String? = null, val students: FLong = 0, val student_limit: FLong? = null, val branches: FLong = 0)

fun JsonObject?.long(key: String): Long = (this?.get(key) as? JsonPrimitive)?.let { it.longOrNull ?: it.content.toDoubleOrNull()?.toLong() } ?: 0
fun JsonElement?.str(): String? = (this as? JsonPrimitive)?.content

// -------------------------------------------------------------- branches
// Branches are unlimited and share the academy's one subscription and student quota.
@Serializable data class Branch(
    val id: Long, val name: String, val address: String? = null, val contact_number: String? = null, val email: String? = null,
    val manager_name: String? = null, val manager_teacher_id: Long? = null, val manager_teacher_name: String? = null, val status: String = "active",
    val students: FLong = 0, val teachers: FLong = 0, val batches: FLong = 0,
)
@Serializable data class BranchFigures(
    val branch_id: Long? = null, val name: String = "", val status: String = "active",
    val students: FLong = 0, val new_admissions: FLong = 0, val teachers: FLong = 0, val batches: FLong = 0,
    val collected_paise: FLong = 0, val payments: FLong = 0, val pending_paise: FLong = 0, val overdue_paise: FLong = 0, val attendance_pct: FDouble? = null,
    val branch_count: FLong = 0,
)
@Serializable data class BranchSummary(val branches: List<BranchFigures> = emptyList(), val total: BranchFigures = BranchFigures(), val from: String = "", val to: String = "")
@Serializable data class BranchBatch(val id: Long, val name: String, val activity_name: String = "", val start_time: String? = null, val teacher_name: String? = null, val students: FLong = 0, val attendance_pct: FDouble? = null)
@Serializable data class BranchTeacher(val id: Long, val name: String, val mobile: String? = null, val status: String = "active")
@Serializable data class BranchDetail(
    val id: Long, val name: String, val address: String? = null, val contact_number: String? = null, val email: String? = null,
    val manager_name: String? = null, val manager_teacher_id: Long? = null, val manager_teacher_name: String? = null, val status: String = "active",
    val figures: BranchFigures = BranchFigures(), val batches: List<BranchBatch> = emptyList(), val teachers: List<BranchTeacher> = emptyList(),
)
@Serializable data class BranchSave(val name: String, val address: String? = null, val contact_number: String? = null, val email: String? = null, val manager_teacher_id: Long? = null, val manager_name: String? = null, val status: String? = null)
@Serializable data class TeacherRow(val id: Long, val name: String, val mobile: String? = null, val status: String = "active", val branch_id: Long? = null)
@Serializable data class ChangeBranch(val branch_id: Long, val batch_id: Long? = null)
@Serializable data class BulkBranch(val branch_id: Long, val batch_id: Long? = null, val student_ids: List<Long> = emptyList(), val preview: Int = 0)
@Serializable data class BulkBranchResult(val preview: FBool = false, val count: FLong = 0, val moved: FLong = 0, val branch_name: String = "")
@Serializable data class SavedId(val id: Long = 0)

// ---------------------------------------------------- attendance history
/** One row of /attendance/history; which fields are filled depends on the view (sessions | students | branches). */
@Serializable data class HistoryRow(
    val id: Long? = null, val session_date: String? = null, val branch_id: Long? = null, val branch_name: String? = null, val batch_name: String? = null, val activity_name: String? = null,
    val marked_by: String? = null, val total_students: FLong = 0, val present: FLong = 0, val absent: FLong = 0, val late: FLong = 0, val leave: FLong = 0, val attendance_pct: FDouble? = null,
    val sessions: FLong = 0, val student_id: Long? = null, val student_name: String? = null, val student_code: String? = null, val status: String? = null, val remarks: String? = null,
)
@Serializable data class HistorySummary(val total_students: FLong = 0, val present: FLong = 0, val absent: FLong = 0, val late: FLong = 0, val leave: FLong = 0, val attendance_pct: FDouble? = null, val sessions: FLong = 0, val from: String = "", val to: String = "")

// ------------------------------------------------------------ duplicates
@Serializable data class DuplicateCandidate(
    val id: Long, val student_code: String = "", val name: String = "", val status: String = "active", val branch_name: String? = null,
    val guardian_name: String? = null, val dob: String? = null, val whatsapp_last4: String? = null, val reasons: List<String> = emptyList(), val level: String = "medium",
)
@Serializable data class LinkExisting(val activity_id: Long, val batch_id: Long? = null)

// ------------------------------------------------- WhatsApp click-to-send
@Serializable data class ManualPrepare(
    val purpose: String, val target: String? = null, val student_ids: List<Long> = emptyList(), val student_id: Long? = null, val batch_id: Long? = null,
    val date: String? = null, val payment_id: Long? = null, val message: String? = null, val overdue_only: Int = 0, val branch_id: Long? = null,
)
@Serializable data class ManualItem(val id: Long, val student_id: Long = 0, val student_name: String = "", val name: String? = null, val phone: String = "", val text: String = "", val link: String = "")
@Serializable data class ManualBatch(val batch_ref: String = "", val purpose: String = "", val items: List<ManualItem> = emptyList())
@Serializable data class ManualLogRow(val id: Long, val purpose: String, val phone: String = "", val recipient_name: String? = null, val student_name: String? = null, val branch_name: String? = null, val message: String = "", val status: String = "prepared", val created_at: String = "", val opened_at: String? = null, val link: String = "")

// --------------------------------------------------------------- reports
@Serializable data class ReportData(val title: String = "", val columns: List<String> = emptyList(), val rows: List<List<JsonElement>> = emptyList(), val totals: JsonElement? = null)
