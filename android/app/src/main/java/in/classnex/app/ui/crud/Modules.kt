package `in`.classnex.app.ui.crud

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.ui.common.label
import `in`.classnex.app.ui.common.money
import `in`.classnex.app.ui.common.prettyDate
import `in`.classnex.app.ui.common.prettyTime
import `in`.classnex.app.ui.common.today
import `in`.classnex.app.ui.theme.*
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive

/*
 * Module definitions: what each list shows, which actions it offers and which fields its form has.
 * Visibility follows the same rules as the web panel: package features (me.has) and permissions (me.can).
 */

private val srcBranches = OptionSource("branches", mapOf("status" to "active"))
private val srcTeachers = OptionSource("teachers", mapOf("status" to "active"))
private val srcActivities = OptionSource("activities", mapOf("status" to "active"))
private val srcBatches = OptionSource("batches", mapOf("status" to "active")) { "${it.s("name")} · ${it.s("activity_name") ?: ""}" + (it.s("branch_name")?.let { b -> " · $b" } ?: "") }
private val srcPermissions = OptionSource("permissions", valueKey = "perm_key") { it.s("name") ?: label(it.s("perm_key")) }
private val srcDiscounts = OptionSource("discounts", mapOf("status" to "active")) { d -> "${d.s("name")} (" + (if (d.s("value_type") == "percent") "${d.n("value") / 100.0}%" else money(d.n("value"))) + ")" }

private val activeInactive = listOf("active" to "Active", "inactive" to "Inactive")
private val statusTabs = listOf<Pair<String?, String>>(null to "All", "active" to "Active", "inactive" to "Inactive")
private fun time(r: kotlinx.serialization.json.JsonObject) = listOfNotNull(r.s("start_time")?.let(::prettyTime), r.s("end_time")?.let(::prettyTime)).joinToString(" – ")
private val dayShort = mapOf("1" to "Mon", "2" to "Tue", "3" to "Wed", "4" to "Thu", "5" to "Fri", "6" to "Sat", "7" to "Sun")

fun moduleFor(key: String, me: Me): ModuleSpec? {
    val owner = me.isClassAdmin
    return when (key) {
        "activities" -> ModuleSpec(
            key, "Activities", "activities",
            row = { r -> RowView(r.s("name") ?: "", "${r.n("batch_count")} batches · ${r.n("student_count")} students · ${r.s("branch_name") ?: "All branches"}" + (r.s("instructor_name")?.let { " · $it" } ?: ""), r.s("status"), trailing = r.n("monthly_fee_paise").takeIf { it > 0 }?.let { money(it) + "/mo" }, icon = Icons.Outlined.Interests) },
            form = formFor("activities", me), canCreate = owner && me.can("structure.manage"), canEdit = { owner && me.can("structure.manage") },
            filters = statusTabs, icon = Icons.Outlined.Interests, createLabel = "Add activity",
            emptyTitle = "No activities yet", emptyText = "Add the courses you teach, e.g. Kathak, Yoga, Maths.",
        )
        "batches" -> ModuleSpec(
            key, "Batches", "batches",
            row = { r ->
                RowView(r.s("name") ?: "", listOfNotNull(r.s("activity_name"), r.s("branch_name"), time(r).ifBlank { null }, r.s("teacher_name"), r.list("weekdays").mapNotNull { dayShort[it] }.joinToString(" ").ifBlank { null }).joinToString(" · "),
                    r.s("status"), trailing = "${r.n("student_count")}", note = if (r.b("marked_today")) "✓ Attendance marked today" else null, icon = Icons.Outlined.Groups)
            },
            form = formFor("batches", me), canCreate = owner && me.can("structure.manage"), canEdit = { owner && me.can("structure.manage") },
            filters = listOf<Pair<String?, String>>("active" to "Active", "inactive" to "Inactive", "completed" to "Completed", null to "All"),
            actions = listOf(
                RowAction("Students", Icons.Outlined.Group, route = { "batchStudents/${it.id()}" }),
                RowAction("Attendance", Icons.Outlined.HowToReg, route = { "markAttendance?batch=${it.id()}" }, visible = { me.has("attendance") && me.can("attendance.mark") && it.s("status") == "active" }),
                RowAction("Cancel class", Icons.Outlined.EventBusy, url = { "batches/${it.id()}/cancel-class" }, confirm = "Students of this batch are informed that today's class is cancelled.", prompt = "Message (optional)", promptKey = "message",
                    body = { mapOf("date" to JsonPrimitive(today())) }, visible = { me.can("attendance.mark") && it.s("status") == "active" }, danger = true),
            ),
            icon = Icons.Outlined.Groups, createLabel = "Add batch", emptyTitle = "No batches", emptyText = "A batch is a group of students with a timing, e.g. Morning Kathak 7–8 AM.",
        )
        "teachers" -> ModuleSpec(
            key, "Teachers", "teachers",
            row = { r -> RowView(r.s("name") ?: "", listOfNotNull(r.s("mobile"), r.s("specialization"), r.s("batch_names")).joinToString(" · "), r.s("status"), photo = r.s("photo_url"), note = r.list("permissions").takeIf { it.isNotEmpty() }?.let { "Extra permissions: " + it.joinToString(", ") { p -> label(p.replace('.', ' ')) } }) },
            form = formFor("teachers", me), canCreate = owner && me.can("teachers.manage"), canEdit = { owner && me.can("teachers.manage") },
            actions = listOf(RowAction("Send login invite", Icons.Outlined.Send, url = { "teachers/${it.id()}/invite" }, confirm = "The teacher receives their login details.", visible = { owner && me.can("teachers.manage") })),
            icon = Icons.Outlined.Badge, createLabel = "Add teacher", emptyTitle = "No teachers yet", emptyText = "Teachers mark attendance for their batches and see only what you allow.",
        )
        "holidays" -> ModuleSpec(
            key, "Holidays", "holidays",
            row = { r -> RowView(r.s("title") ?: "", prettyDate(r.s("holiday_date")) + " · " + (r.s("batch_name") ?: "Whole academy"), icon = Icons.Outlined.BeachAccess, tint = Pink) },
            form = formFor("holidays", me), canCreate = owner && me.can("structure.manage"),
            actions = listOf(RowAction("Delete", Icons.Outlined.Delete, url = { "holidays/${it.id()}" }, method = "DELETE", confirm = "Delete this holiday?", danger = true, visible = { owner })),
            icon = Icons.Outlined.BeachAccess, createLabel = "Add holiday", emptyTitle = "No upcoming holidays", emptyText = "Holidays stop attendance and fee reminders for that day.",
        )
        "announcements" -> ModuleSpec(
            key, "Announcements", "announcements",
            row = { r -> RowView(r.s("title") ?: "", prettyDate(r.s("publish_at")) + " · " + (if (r.s("audience") == "batch") r.s("batch_name") ?: "Batch" else label(r.s("audience"))), note = r.s("body"), icon = Icons.Outlined.Campaign, tint = Orange) },
            form = formFor("announcements", me), canCreate = me.can("notifications.send"),
            actions = listOf(RowAction("Delete", Icons.Outlined.Delete, url = { "announcements/${it.id()}" }, method = "DELETE", confirm = "Delete this announcement?", danger = true, visible = { me.can("notifications.send") })),
            icon = Icons.Outlined.Campaign, createLabel = "New announcement", emptyTitle = "No announcements", emptyText = "Share news with students, parents and teachers.",
        )
        "feePlans" -> ModuleSpec(
            key, "Fee Structure", "fee-structures",
            row = { r ->
                RowView(r.s("name") ?: "", listOfNotNull(label(r.s("fee_type")), r.n("frequency_months").takeIf { it > 0 }?.let { if (it == 1L) "Monthly" else "Every $it months" }, r.s("activity_name"), r.s("batch_name"), "Due day ${r.n("due_day")}").joinToString(" · "),
                    r.s("status"), trailing = money(r.n("amount_paise")), icon = Icons.Outlined.RequestQuote, tint = Teal)
            },
            form = formFor("feePlans", me), canCreate = owner && me.can("fees.manage"), canEdit = { owner && me.can("fees.manage") },
            actions = listOf(RowAction("Apply to students", Icons.Outlined.PlaylistAddCheck, url = { "fee-structures/${it.id()}/apply" }, confirm = "Assign this fee to every active student of its activity / batch who does not have it yet.", visible = { owner && me.can("fees.manage") && it.s("status") == "active" })),
            icon = Icons.Outlined.RequestQuote, createLabel = "Add fee plan", emptyTitle = "No fee plans", emptyText = "Fee plans are assigned to students automatically on admission.",
        )
        "discounts" -> ModuleSpec(
            key, "Discounts & Concessions", "discounts",
            row = { r -> RowView(r.s("name") ?: "", label(r.s("kind")), r.s("status"), trailing = if (r.s("value_type") == "percent") "${r.n("value") / 100.0}%" else money(r.n("value")), icon = Icons.Outlined.Sell, tint = Pink) },
            form = formFor("discounts", me), canCreate = owner && me.can("fees.manage"), canEdit = { owner && me.can("fees.manage") },
            icon = Icons.Outlined.Sell, createLabel = "Add discount", emptyTitle = "No discounts", emptyText = "Create discounts, scholarships and concessions to use while assigning fees.",
        )
        "parents" -> ModuleSpec(
            key, "Parents", "parents", query = mapOf("per_page" to "100"),
            row = { r -> RowView(r.s("name") ?: "", listOfNotNull(r.s("country_code")?.let { c -> c + " " + (r.s("whatsapp") ?: "") }, r.s("children")?.let { "Children: $it" }).joinToString(" · "), r.s("login_status") ?: "no_login", statusText = if (r.s("login_status") == null) "No login" else label(r.s("login_status"))) },
            form = formFor("parents", me), canEdit = { me.can("students.manage") }, searchable = true,
            icon = Icons.Outlined.FamilyRestroom, emptyTitle = "No parents yet", emptyText = "Parents are added with their child's admission.",
        )
        "leaves" -> ModuleSpec(
            key, if (owner) "Teacher Leaves" else "My Leaves", "leaves",
            row = { r -> RowView(if (owner) r.s("teacher_name") ?: "" else r.s("reason") ?: "Leave", prettyDate(r.s("from_date")) + " – " + prettyDate(r.s("to_date")) + (if (owner) r.s("reason")?.let { " · $it" } ?: "" else ""), r.s("status"), icon = Icons.Outlined.EventAvailable, tint = Blue) },
            form = formFor("leaves", me), canCreate = me.role == "teacher",
            actions = listOf(
                RowAction("Approve", Icons.Outlined.Check, url = { "leaves/${it.id()}" }, method = "PUT", body = { mapOf("status" to JsonPrimitive("approved")) }, visible = { owner && it.s("status") == "pending" }),
                RowAction("Reject", Icons.Outlined.Close, url = { "leaves/${it.id()}" }, method = "PUT", body = { mapOf("status" to JsonPrimitive("rejected")) }, confirm = "Reject this leave request?", danger = true, visible = { owner && it.s("status") == "pending" }),
            ),
            icon = Icons.Outlined.EventAvailable, createLabel = "Apply for leave", emptyTitle = "No leave requests",
        )
        "admissions" -> ModuleSpec(
            key, "Online Admissions", "admissions", itemsKey = "items",
            filters = listOf<Pair<String?, String>>("pending" to "Pending", "approved" to "Approved", "rejected" to "Rejected"),
            row = { r -> RowView(r.s("student_name") ?: "", listOfNotNull(r.s("parent_name"), r.s("country_code")?.let { it + " " + (r.s("whatsapp") ?: "") }, r.s("activity_name"), r.s("batch_name"), prettyDate(r.s("created_at"))).joinToString(" · "), r.s("status")) },
            actions = listOf(
                RowAction("Approve & admit", Icons.Outlined.HowToReg, url = { "admissions/${it.id()}/approve" }, confirm = "Create the student from this application? The student quota of your plan applies.", visible = { it.s("status") == "pending" || it.s("status") == "enquiry" }),
                RowAction("Reject", Icons.Outlined.Close, url = { "admissions/${it.id()}/reject" }, prompt = "Reason (optional)", danger = true, visible = { it.s("status") == "pending" || it.s("status") == "enquiry" }),
            ),
            icon = Icons.Outlined.AssignmentInd, emptyTitle = "No applications", emptyText = "Share your online admission link from Academy Profile.",
        )
        "support" -> ModuleSpec(
            key, "Help & Support", "support",
            row = { r -> RowView(r.s("subject") ?: "", "#${r.id()} · ${label(r.s("priority"))} priority · updated ${prettyDate(r.s("updated_at"))}" + (r.s("user_name")?.takeIf { owner }?.let { " · $it" } ?: ""), r.s("status"), icon = Icons.Outlined.SupportAgent, tint = Blue) },
            form = formFor("support", me), canCreate = true, onTap = { "ticket/${it.id()}" },
            icon = Icons.Outlined.SupportAgent, createLabel = "New ticket", emptyTitle = "No tickets", emptyText = if (owner) "Ask the ClassNex team anything. We reply here." else "Ask your academy a question. They reply here.",
        )
        "messageLog" -> ModuleSpec(
            key, "Message Log", "messages/log", query = mapOf("per_page" to "100"),
            filters = listOf<Pair<String?, String>>(null to "All", "sent" to "Sent", "pending" to "Queued", "failed" to "Failed"),
            row = { r ->
                // honest statuses: "Queued" until the provider accepts it, "Delivered/Read" only when the provider reports it
                val st = r.s("status")
                RowView(r.s("recipient_name") ?: r.s("recipient") ?: "", "${label(r.s("channel"))} · ${label(r.s("event_key"))} · ${prettyDate(r.s("created_at"))}", st,
                    statusText = when (st) { "pending" -> "Queued"; "processing" -> "Sending"; else -> label(st) }, note = r.s("body") + (r.s("last_error")?.let { "\n⚠ $it" } ?: ""), icon = Icons.Outlined.Forum, tint = Teal)
            },
            actions = listOf(RowAction("Retry", Icons.Outlined.Replay, url = { "messages/retry" }, body = { mapOf("ids" to JsonArray(listOf(JsonPrimitive(it.id())))) }, visible = { it.s("status") == "failed" && me.can("notifications.send") })),
            icon = Icons.Outlined.Forum, emptyTitle = "No messages yet",
        )
        "registrations" -> ModuleSpec(
            key, "Academy Registrations", "admin/registrations",
            filters = listOf<Pair<String?, String>>("pending" to "Pending", "rejected" to "Rejected"),
            row = { r -> RowView(r.s("academy_name") ?: "", listOfNotNull(r.s("owner_name"), r.s("mobile"), r.s("email"), r.s("city"), r.s("package_name")?.let { "Plan: $it" }, prettyDate(r.s("created_at"))).joinToString(" · "), r.s("status"), note = r.s("rejection_reason")?.let { "Reason: $it" }, icon = Icons.Outlined.Business) },
            actions = listOf(
                RowAction("Approve", Icons.Outlined.Check, url = { "admin/academies/${it.n("academy_id")}/approve" }, confirm = "Approve this academy? The Class Admin can log in right away.", visible = { it.s("status") == "pending" }),
                RowAction("Reject", Icons.Outlined.Close, url = { "admin/academies/${it.n("academy_id")}/reject" }, prompt = "Reason", promptRequired = true, danger = true, visible = { it.s("status") == "pending" }),
            ),
            icon = Icons.Outlined.Business, emptyTitle = "No registrations waiting",
        )
        "academies" -> ModuleSpec(
            key, "Academies", "admin/owners", query = mapOf("per_page" to "200"), searchable = true,
            row = { r -> RowView(r.s("academy_name") ?: "", listOfNotNull(r.s("owner_name"), r.s("mobile"), r.s("package_name"), "${r.n("students")}${r.s("student_limit")?.let { "/$it" } ?: ""} students", "${r.n("branches")} branches", r.s("ends_on")?.let { "ends ${prettyDate(it)}" }).joinToString(" · "), r.s("sub_status"), icon = Icons.Outlined.Apartment) },
            icon = Icons.Outlined.Apartment, emptyTitle = "No academies",
        )
        else -> null
    }
}

/** Forms. [parent] is the student id for student sub-forms. */
fun formFor(key: String, me: Me, parent: Long = 0): FormSpec? = when (key) {
    "activities" -> FormSpec(
        "Activity", listOf(
            FieldSpec("name", "Activity name", required = true),
            FieldSpec("description", "Description", FType.Area),
            FieldSpec("branch_id", "Branch", FType.Select, source = srcBranches, noneLabel = "All branches"),
            FieldSpec("instructor_id", "Main instructor", FType.Select, source = srcTeachers, noneLabel = "Not set"),
            FieldSpec("fees", "Fees", FType.Section),
            FieldSpec("monthly_fee", "Monthly fee", FType.Money, readKey = "monthly_fee_paise"),
            FieldSpec("registration_fee", "Registration fee", FType.Money, readKey = "registration_fee_paise"),
            FieldSpec("one_time_fee", "One-time fee (kit, uniform…)", FType.Money, readKey = "one_time_fee_paise"),
            FieldSpec("duration", "Course duration", help = "e.g. 6 months, ongoing"),
            FieldSpec("status", "Status", FType.Select, options = activeInactive, default = "active"),
        ), "activities", { "activities/$it" },
    )
    "batches" -> FormSpec(
        "Batch", listOf(
            FieldSpec("name", "Batch name", required = true, help = "e.g. Morning Kathak"),
            FieldSpec("activity_id", "Activity", FType.Select, required = true, source = srcActivities),
            FieldSpec("branch_id", "Branch", FType.Select, source = srcBranches, noneLabel = "Main / not set"),
            FieldSpec("teacher_id", "Teacher", FType.Select, source = srcTeachers, noneLabel = "Not assigned"),
            FieldSpec("extra_teacher_ids", "Other teachers who can mark attendance", FType.MultiSelect, source = srcTeachers),
            FieldSpec("timing", "Timing", FType.Section),
            FieldSpec("weekdays", "Class days", FType.Weekdays, help = "Leave empty if the batch has no fixed days."),
            FieldSpec("start_time", "Starts at", FType.Time),
            FieldSpec("end_time", "Ends at", FType.Time),
            FieldSpec("start_date", "Start date", FType.Date),
            FieldSpec("end_date", "End date", FType.Date),
            FieldSpec("more", "More", FType.Section),
            FieldSpec("capacity", "Capacity", FType.Number),
            FieldSpec("location", "Room / location"),
            FieldSpec("monthly_fee", "Monthly fee (if different from the activity)", FType.Money, readKey = "monthly_fee_paise"),
            FieldSpec("status", "Status", FType.Select, options = listOf("active" to "Active", "inactive" to "Inactive", "completed" to "Completed"), default = "active"),
        ), "batches", { "batches/$it" },
    )
    "teachers" -> FormSpec(
        "Teacher", listOf(
            FieldSpec("name", "Full name", required = true),
            FieldSpec("mobile", "Mobile number", FType.Phone, required = true, help = "Used to log in to the app"),
            FieldSpec("email", "Email", FType.Email),
            FieldSpec("branch_id", "Branch", FType.Select, source = srcBranches, noneLabel = "All / not set"),
            FieldSpec("specialization", "Specialisation"),
            FieldSpec("joining_date", "Joining date", FType.Date),
            FieldSpec("address", "Address", FType.Area),
            FieldSpec("salary_type", "Salary type", FType.Select, options = listOf("none" to "Not tracked", "monthly" to "Monthly", "per_class" to "Per class", "per_student" to "Per student", "other" to "Other"), default = "none"),
            FieldSpec("salary", "Salary amount", FType.Money, readKey = "salary_paise", visibleWhen = { (it["salary_type"] ?: "none") != "none" }),
            FieldSpec("status", "Status", FType.Select, options = activeInactive, default = "active"),
            FieldSpec("access", "Access", FType.Section),
            FieldSpec("batch_ids", "Batches this teacher handles", FType.MultiSelect, source = srcBatches),
            FieldSpec("permissions", "Extra permissions", FType.MultiSelect, source = srcPermissions, help = "Teachers can always view their dashboard and students and mark attendance. Tick anything else they may do."),
            FieldSpec("password", "Set a password (optional)", FType.Password, help = "Leave blank to send an invite or keep the current password."),
            FieldSpec("send_invite", "Send login invite now", FType.Switch, default = "1", createOnly = true),
        ), "teachers", { "teachers/$it" },
    )
    "holidays" -> FormSpec(
        "Holiday", listOf(
            FieldSpec("holiday_date", "Date", FType.Date, required = true, default = today()),
            FieldSpec("title", "Title", required = true, help = "e.g. Diwali"),
            FieldSpec("batch_id", "Only for batch", FType.Select, source = srcBatches, noneLabel = "Whole academy"),
        ), "holidays", null,
    )
    "announcements" -> FormSpec(
        "Announcement", listOf(
            FieldSpec("title", "Title", required = true),
            FieldSpec("body", "Message", FType.Area, required = true),
            FieldSpec("audience", "Show to", FType.Select, options = listOf("all" to "Everyone", "students" to "Students & parents", "teachers" to "Teachers", "batch" to "One batch"), default = "all"),
            FieldSpec("batch_id", "Batch", FType.Select, source = srcBatches, required = true, visibleWhen = { it["audience"] == "batch" }),
            FieldSpec("notify", "Also send a notification", FType.Switch, default = "1"),
        ), "announcements", null, submitText = "Publish",
    )
    "feePlans" -> FormSpec(
        "Fee plan", listOf(
            FieldSpec("name", "Name", required = true, help = "e.g. Kathak monthly fee"),
            FieldSpec("fee_type", "Type", FType.Select, required = true, options = listOf("recurring" to "Recurring (monthly / quarterly…)", "admission" to "Admission", "registration" to "Registration", "one_time" to "One-time", "additional" to "Additional"), default = "recurring"),
            FieldSpec("frequency_months", "Repeats every", FType.Select, options = listOf("1" to "1 month", "2" to "2 months", "3" to "3 months", "6" to "6 months", "12" to "12 months"), default = "1", visibleWhen = { it["fee_type"] == "recurring" }),
            FieldSpec("amount", "Amount", FType.Money, required = true, readKey = "amount_paise"),
            FieldSpec("due_day", "Due on day of month", FType.Number, default = "5"),
            FieldSpec("activity_id", "For activity", FType.Select, source = srcActivities, noneLabel = "Any activity"),
            FieldSpec("batch_id", "For batch", FType.Select, source = srcBatches, noneLabel = "Any batch"),
            FieldSpec("late_fee", "Late fee", FType.Money, readKey = "late_fee_paise"),
            FieldSpec("late_fee_after_days", "Late fee after (days)", FType.Number),
            FieldSpec("auto_assign", "Assign automatically on admission", FType.Switch, default = "1"),
            FieldSpec("status", "Status", FType.Select, options = activeInactive, default = "active"),
        ), "fee-structures", { "fee-structures/$it" },
    )
    "discounts" -> FormSpec(
        "Discount", listOf(
            FieldSpec("name", "Name", required = true),
            FieldSpec("kind", "Kind", FType.Select, options = listOf("discount" to "Discount", "scholarship" to "Scholarship", "concession" to "Concession"), default = "discount"),
            FieldSpec("value_type", "Value type", FType.Select, required = true, options = listOf("percent" to "Percentage", "flat" to "Fixed amount"), default = "percent"),
            FieldSpec("value", "Value", FType.Money, required = true, hundredths = true, help = "Percentage (e.g. 10) or rupees"),
            FieldSpec("status", "Status", FType.Select, options = activeInactive, default = "active"),
        ), "discounts", { "discounts/$it" },
    )
    "parents" -> FormSpec(
        "Parent", listOf(
            FieldSpec("name", "Name", required = true),
            FieldSpec("whatsapp", "WhatsApp number", FType.Phone, required = true),
            FieldSpec("email", "Email", FType.Email),
            FieldSpec("occupation", "Occupation"),
            FieldSpec("address", "Address", FType.Area),
        ), null, { "parents/$it" },
    )
    "studentParent" -> FormSpec(
        "Add parent / guardian", listOf(
            FieldSpec("name", "Name", required = true),
            FieldSpec("relation", "Relation", FType.Select, options = listOf("Mother" to "Mother", "Father" to "Father", "Guardian" to "Guardian", "Other" to "Other"), default = "Mother"),
            FieldSpec("whatsapp", "WhatsApp number", FType.Phone, required = true),
            FieldSpec("email", "Email", FType.Email),
            FieldSpec("occupation", "Occupation"),
        ), "students/$parent/parents", null,
    )
    "leaves" -> FormSpec(
        "Leave request", listOf(
            FieldSpec("from_date", "From", FType.Date, required = true, default = today()),
            FieldSpec("to_date", "To", FType.Date, required = true, default = today()),
            FieldSpec("reason", "Reason", FType.Area),
        ), "leaves", null, submitText = "Send request",
    )
    "support" -> FormSpec(
        "New ticket", listOf(
            FieldSpec("subject", "Subject", required = true),
            FieldSpec("priority", "Priority", FType.Select, options = listOf("low" to "Low", "normal" to "Normal", "high" to "High"), default = "normal"),
            FieldSpec("message", "Describe the issue", FType.Area, required = true),
        ), "support", null, submitText = "Submit",
    )
    "studentEdit" -> FormSpec(
        "Edit student", listOf(
            FieldSpec("name", "Full name", required = true),
            FieldSpec("whatsapp", "WhatsApp number", FType.Phone, required = true),
            FieldSpec("email", "Email", FType.Email),
            FieldSpec("dob", "Date of birth", FType.Date),
            FieldSpec("gender", "Gender", FType.Select, options = listOf("male" to "Male", "female" to "Female", "other" to "Other"), noneLabel = "Not set"),
            FieldSpec("guardian_name", "Parent / guardian name"),
            FieldSpec("guardian_relation", "Relation"),
            FieldSpec("alt_mobile", "Alternate mobile", FType.Phone),
            FieldSpec("emergency_contact", "Emergency contact", FType.Phone),
            FieldSpec("address", "Address", FType.Area),
            FieldSpec("school_name", "School / college"),
            FieldSpec("blood_group", "Blood group"),
            FieldSpec("medical_notes", "Medical notes", FType.Area),
            FieldSpec("admission_date", "Admission date", FType.Date),
            FieldSpec("status", "Status", FType.Select, options = listOf("active" to "Active", "inactive" to "Inactive", "left" to "Left"), default = "active"),
            FieldSpec("whatsapp_opt_in", "WhatsApp messages allowed", FType.Switch, default = "1"),
            FieldSpec("notes", "Notes", FType.Area),
        ), null, { "students/$it" }, updatePut = false,
    )
    "studentFee" -> FormSpec(
        "Assign fee", listOf(
            FieldSpec("name", "Fee name", required = true, help = "e.g. Monthly fee"),
            FieldSpec("fee_type", "Type", FType.Select, required = true, options = listOf("recurring" to "Recurring", "admission" to "Admission", "registration" to "Registration", "one_time" to "One-time", "additional" to "Additional"), default = "recurring"),
            FieldSpec("frequency_months", "Repeats every", FType.Select, options = listOf("1" to "1 month", "3" to "3 months", "6" to "6 months", "12" to "12 months"), default = "1", visibleWhen = { it["fee_type"] == "recurring" }),
            FieldSpec("amount", "Amount", FType.Money, required = true),
            FieldSpec("discount_id", "Discount / scholarship", FType.Select, source = srcDiscounts, noneLabel = "None"),
            FieldSpec("discount", "Or a fixed discount", FType.Money),
            FieldSpec("due_day", "Due on day of month", FType.Number, default = "5", visibleWhen = { it["fee_type"] == "recurring" }),
            FieldSpec("start_date", "Start date", FType.Date, default = today()),
            FieldSpec("end_date", "End date", FType.Date),
        ), "students/$parent/fees", null, submitText = "Assign fee",
    )
    "studentCharge" -> FormSpec(
        "Add charge", listOf(
            FieldSpec("title", "Charge for", required = true, help = "e.g. Costume, exam fee"),
            FieldSpec("amount", "Amount", FType.Money, required = true),
            FieldSpec("discount", "Discount", FType.Money),
            FieldSpec("due_date", "Due date", FType.Date, default = today()),
        ), "students/$parent/charges", null, submitText = "Add charge",
    )
    else -> null
}
