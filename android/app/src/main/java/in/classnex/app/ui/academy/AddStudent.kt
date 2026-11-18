package `in`.classnex.app.ui.academy

import android.app.DatePickerDialog
import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CameraAlt
import androidx.compose.material.icons.outlined.DocumentScanner
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.data.ApiError
import `in`.classnex.app.data.ImageUtils
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.Activity
import `in`.classnex.app.data.net.Batch
import `in`.classnex.app.data.net.OcrResult
import `in`.classnex.app.data.net.OcrText
import `in`.classnex.app.di.AppJson
import `in`.classnex.app.ocr.DocumentOcr
import `in`.classnex.app.ui.common.AppCard
import `in`.classnex.app.ui.theme.Amber
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Red
import coil.compose.AsyncImage
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.time.LocalDate
import java.util.UUID
import javax.inject.Inject

data class StudentForm(
    val name: String = "", val whatsapp: String = "", val activityId: Long? = null, val batchId: Long? = null, val branchId: Long? = null,
    val minor: Boolean = true, val guardian: String = "", val dob: String = "", val gender: String = "", val email: String = "", val address: String = "",
    val consent: Boolean = true, val sendMessage: Boolean = true, val invite: Boolean = false,
)

@HiltViewModel
class AddStudentVm @Inject constructor(private val repo: Repository, @ApplicationContext private val ctx: Context) : ViewModel() {
    val form = MutableStateFlow(StudentForm())
    val activities = MutableStateFlow<List<Activity>>(emptyList())
    val batches = MutableStateFlow<List<Batch>>(emptyList())
    val branches = MutableStateFlow<List<`in`.classnex.app.data.net.Branch>>(emptyList())
    /** Possible duplicates returned by the server (name + WhatsApp + guardian + DOB). */
    val duplicates = MutableStateFlow<Duplicates?>(null)
    val photo = MutableStateFlow<File?>(null)
    val document = MutableStateFlow<File?>(null)
    val docType = MutableStateFlow("birth_certificate")
    val ocr = MutableStateFlow<OcrResult?>(null)
    val ocrFilled = MutableStateFlow<Set<String>>(emptySet())
    val busy = MutableStateFlow(false)
    val errors = MutableStateFlow<Map<String, String>>(emptyMap())
    val message = MutableSharedFlow<String>(extraBufferCapacity = 2)
    val saved = MutableSharedFlow<Long>(extraBufferCapacity = 1)

    init {
        viewModelScope.launch {
            runCatching {
                activities.value = call { repo.api.activities() }.data ?: emptyList()
                batches.value = call { repo.api.batches() }.data ?: emptyList()
                branches.value = runCatching { call { repo.api.branches("active") }.data }.getOrNull() ?: emptyList()
                if (activities.value.size == 1) update { it.copy(activityId = activities.value[0].id) }
            }.onFailure { message.tryEmit(it.message ?: "Could not load activities") }
        }
    }

    fun update(f: (StudentForm) -> StudentForm) {
        val before = form.value
        var next = f(before)
        if (next.activityId != before.activityId || next.branchId != before.branchId) {
            val list = batches.value.filter { it.activity_id == next.activityId && (next.branchId == null || it.branch_id == null || it.branch_id == next.branchId) }
            next = next.copy(batchId = list.singleOrNull()?.id)
        }
        form.value = next
    }

    fun newCameraUri(prefix: String): Uri {
        val dir = File(ctx.cacheDir, "camera").apply { mkdirs() }
        val f = File(dir, "$prefix-${System.currentTimeMillis()}.jpg")
        return FileProvider.getUriForFile(ctx, ctx.packageName + ".files", f)
    }

    fun setPhoto(uri: Uri) = viewModelScope.launch {
        runCatching { ImageUtils.compress(repo.copyToCache(uri, "photo-${System.currentTimeMillis()}.jpg"), 900, 85) }
            .onSuccess { photo.value = it }.onFailure { message.tryEmit("Could not read the photo") }
    }

    /** Stores the document, runs on-device OCR, and fills empty fields for the user to confirm. */
    fun setDocument(uri: Uri) = viewModelScope.launch {
        try {
            val raw = repo.copyToCache(uri, "doc-${System.currentTimeMillis()}.jpg")
            document.value = ImageUtils.compress(raw, 1800, 88)
            message.tryEmit("Reading document…")
            val text = DocumentOcr.readText(ctx, uri)
            val r = call { repo.api.ocrParse(OcrText(text)) }.data!!
            ocr.value = r
            if (r.document_type in listOf("aadhaar", "birth_certificate", "school_id")) docType.value = r.document_type!!
            val filled = mutableSetOf<String>()
            update { f ->
                var n = f
                if (n.name.isBlank() && !r.name.isNullOrBlank()) { n = n.copy(name = r.name); filled += "name" }
                if (n.dob.isBlank() && !r.dob.isNullOrBlank()) { n = n.copy(dob = r.dob); filled += "dob" }
                if (n.gender.isBlank() && !r.gender.isNullOrBlank()) { n = n.copy(gender = r.gender); filled += "gender" }
                if (n.address.isBlank() && !r.address.isNullOrBlank()) { n = n.copy(address = r.address); filled += "address" }
                if (n.guardian.isBlank() && !r.guardian_name.isNullOrBlank()) { n = n.copy(guardian = r.guardian_name); filled += "guardian" }
                n
            }
            ocrFilled.value = filled
            message.tryEmit(if (filled.isEmpty()) (r.message ?: "Could not read details – please type them") else "Filled ${filled.size} field(s). Please check them.")
        } catch (e: Exception) {
            message.tryEmit("Document attached. Auto-fill failed: ${e.message}")
        }
    }

    /** Link existing student: enrol the existing profile instead of creating a second one. */
    fun linkExisting(id: Long) = viewModelScope.launch {
        val f = form.value
        try {
            val r = call { repo.api.linkExisting(id, `in`.classnex.app.data.net.LinkExisting(f.activityId ?: 0, f.batchId)) }
            duplicates.value = null
            message.tryEmit(r.message)
            saved.tryEmit(id)
        } catch (e: Exception) { message.tryEmit((e as? ApiError)?.message ?: "Could not link") }
    }

    fun save(confirmDuplicate: Boolean = false, reason: String? = null, onDuplicate: (String) -> Unit) {
        val f = form.value
        val errs = mutableMapOf<String, String>()
        if (f.name.isBlank()) errs["name"] = "Student name is required."
        if (f.whatsapp.filter(Char::isDigit).length < 10) errs["whatsapp"] = "Enter a valid 10-digit WhatsApp number."
        if (f.activityId == null) errs["activity_id"] = "Select an activity."
        if (f.minor && f.guardian.isBlank()) errs["guardian_name"] = "Guardian name is required for students under 18."
        errors.value = errs
        if (errs.isNotEmpty()) return
        busy.value = true
        viewModelScope.launch {
            try {
                val text = "text/plain".toMediaType()
                val fields = mutableMapOf<String, RequestBody>()
                fun put(k: String, v: String?) { if (!v.isNullOrBlank()) fields[k] = v.toRequestBody(text) }
                put("name", f.name.trim()); put("whatsapp", f.whatsapp); put("country_code", "+91")
                put("activity_id", f.activityId?.toString()); put("batch_id", f.batchId?.toString())
                put("is_minor", if (f.minor) "1" else "0"); put("guardian_name", f.guardian)
                put("dob", f.dob); put("gender", f.gender); put("email", f.email); put("address", f.address)
                put("consent_given", if (f.consent) "1" else "0"); put("send_message", if (f.sendMessage) "1" else "0"); put("invite_parent", if (f.invite) "1" else "0")
                put("branch_id", f.branchId?.toString())
                if (confirmDuplicate) { put("confirm_duplicate", "1"); put("duplicate_reason", reason) }
                val photoPart = photo.value?.let { MultipartBody.Part.createFormData("photo", "photo.jpg", it.asRequestBody("image/jpeg".toMediaType())) }
                val res = call { repo.api.createStudent(fields, photoPart) }.data!!
                val sid = res.student.id
                document.value?.let { doc ->
                    runCatching {
                        val docFields = mutableMapOf("doc_type" to docType.value.toRequestBody(text))
                        ocr.value?.let { docFields["ocr_fields"] = AppJson.encodeToString(OcrResult.serializer(), it).toRequestBody(text) }
                        call { repo.api.uploadDocument(sid, docFields, MultipartBody.Part.createFormData("file", "document.jpg", doc.asRequestBody("image/jpeg".toMediaType()))) }
                    }.onFailure { message.tryEmit("Student saved, but the document upload failed: ${it.message}") }
                }
                message.tryEmit("${res.student.name} registered · ID ${res.student.student_code}")
                saved.tryEmit(sid)
            } catch (e: ApiError) {
                if (e.code == "duplicate_student") {
                    val errs = e.body?.get("errors") as? kotlinx.serialization.json.JsonObject
                    val list = runCatching { AppJson.decodeFromJsonElement(kotlinx.serialization.builtins.ListSerializer(`in`.classnex.app.data.net.DuplicateCandidate.serializer()), errs!!["candidates"]!!) }.getOrDefault(emptyList())
                    val canOverride = (errs?.get("can_override") as? kotlinx.serialization.json.JsonPrimitive)?.content == "true"
                    duplicates.value = Duplicates(e.message ?: "", list, canOverride)
                    onDuplicate(e.message ?: "")
                } else {
                    errors.value = e.fieldErrors
                    message.tryEmit(e.fieldErrors.values.firstOrNull() ?: e.message ?: "Could not save")
                }
            } finally { busy.value = false }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddStudentScreen(snack: SnackbarHostState, onSaved: (Long) -> Unit, vm: AddStudentVm = hiltViewModel()) {
    val ctx = LocalContext.current
    val f by vm.form.collectAsState()
    val acts by vm.activities.collectAsState()
    val allBatches by vm.batches.collectAsState()
    val photo by vm.photo.collectAsState()
    val doc by vm.document.collectAsState()
    val errs by vm.errors.collectAsState()
    val busy by vm.busy.collectAsState()
    val branchList by vm.branches.collectAsState()
    val dups by vm.duplicates.collectAsState()
    val filled by vm.ocrFilled.collectAsState()
    val ocr by vm.ocr.collectAsState()
    var more by remember { mutableStateOf(false) }
    var dup by remember { mutableStateOf<String?>(null) }
    var pendingUri by remember { mutableStateOf<Uri?>(null) }
    var docUri by remember { mutableStateOf<Uri?>(null) }

    LaunchedEffect(Unit) { vm.message.collect { snack.showSnackbar(it) } }
    LaunchedEffect(Unit) { vm.saved.collect { onSaved(it) } }
    LaunchedEffect(filled) { if (filled.any { it in setOf("dob", "gender", "address") }) more = true }

    val takePhoto = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) pendingUri?.let(vm::setPhoto) }
    val pickPhoto = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { it?.let(vm::setPhoto) }
    val scanDoc = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) docUri?.let(vm::setDocument) }
    val pickDoc = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { it?.let(vm::setDocument) }

    val hl = { key: String -> if (key in filled) Modifier.background(Amber.copy(alpha = .10f)) else Modifier }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Only fields marked * are needed.", color = Muted)
        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(84.dp).clip(CircleShape).background(Color(0xFFEEF1F6)), contentAlignment = Alignment.Center) {
                        if (photo != null) AsyncImage(photo, null, contentScale = ContentScale.Crop, modifier = Modifier.size(84.dp).clip(CircleShape)) else Icon(Icons.Outlined.Person, null, tint = Muted, modifier = Modifier.size(40.dp))
                    }
                    Column(Modifier.padding(start = 12.dp)) {
                        Button(onClick = { vm.newCameraUri("photo").also { pendingUri = it; takePhoto.launch(it) } }) { Icon(Icons.Outlined.CameraAlt, null); Text(if (photo == null) " Take photo" else " Retake") }
                        Row {
                            TextButton(onClick = { pickPhoto.launch("image/*") }) { Icon(Icons.Outlined.Image, null); Text(" Gallery") }
                            if (photo != null) TextButton(onClick = { vm.photo.value = null }) { Text("Remove", color = Red) }
                        }
                    }
                }
                Field("Student name *", f.name, errs["name"], hl("name")) { v -> vm.update { it.copy(name = v) } }
                Field("WhatsApp number *", f.whatsapp, errs["whatsapp"], keyboard = KeyboardType.Phone, prefix = "+91 ", help = "Parent's number for students under 18") { v -> vm.update { it.copy(whatsapp = v.filter { c -> c.isDigit() }.take(10)) } }
                if (branchList.isNotEmpty()) Picker("Branch (optional – same as batch)", branchList.map { it.id to it.name }, f.branchId, null) { id -> vm.update { it.copy(branchId = id) } }
                Picker("Activity / Class *", acts.map { it.id to it.name }, f.activityId, errs["activity_id"]) { id -> vm.update { it.copy(activityId = id) } }
                val bs = allBatches.filter { it.activity_id == f.activityId && (f.branchId == null || it.branch_id == null || it.branch_id == f.branchId) }
                if (bs.isNotEmpty()) Picker("Batch", bs.map { it.id to (it.name + (it.start_time?.let { t -> " · ${t.take(5)}" } ?: "")) }, f.batchId, errs["batch_id"]) { id -> vm.update { it.copy(batchId = id) } }
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Student is under 18", Modifier.weight(1f)); Switch(f.minor, { v -> vm.update { it.copy(minor = v) } }) }
                if (f.minor) Field("Parent / Guardian name *", f.guardian, errs["guardian_name"], hl("guardian")) { v -> vm.update { it.copy(guardian = v) } }
                TextButton(onClick = { more = !more }) { Icon(if (more) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, null); Text(" More details (optional)") }
                AnimatedVisibility(more) {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        OutlinedButton(onClick = {
                            val d = runCatching { LocalDate.parse(f.dob) }.getOrDefault(LocalDate.now().minusYears(10))
                            DatePickerDialog(ctx, { _, y, m, day -> vm.update { it.copy(dob = LocalDate.of(y, m + 1, day).toString()) } }, d.year, d.monthValue - 1, d.dayOfMonth).apply { datePicker.maxDate = System.currentTimeMillis() }.show()
                        }, modifier = Modifier.fillMaxWidth().then(hl("dob"))) { Text(if (f.dob.isBlank()) "Date of birth" else "Date of birth: ${f.dob}") }
                        Picker("Gender", listOf(1L to "Male", 2L to "Female", 3L to "Other"), when (f.gender) { "male" -> 1L; "female" -> 2L; "other" -> 3L; else -> null }, null) { id -> vm.update { it.copy(gender = when (id) { 1L -> "male"; 2L -> "female"; else -> "other" }) } }
                        Field("Email", f.email, errs["email"], keyboard = KeyboardType.Email) { v -> vm.update { it.copy(email = v) } }
                        Field("Address", f.address, errs["address"], hl("address"), single = false) { v -> vm.update { it.copy(address = v) } }
                    }
                }
            }
        }
        AppCard {
            Column {
                Text("Document (optional)", fontWeight = FontWeight.Bold)
                Text("Scan a birth certificate or ID to auto-fill details. Aadhaar is never required and its number is not stored.", color = Muted, fontSize = 13.sp)
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { vm.newCameraUri("doc").also { docUri = it; scanDoc.launch(it) } }, modifier = Modifier.weight(1f)) { Icon(Icons.Outlined.DocumentScanner, null); Text(" Scan") }
                    OutlinedButton(onClick = { pickDoc.launch("image/*") }, modifier = Modifier.weight(1f)) { Icon(Icons.Outlined.Image, null); Text(" Upload") }
                }
                if (doc != null) Text("✓ Document attached" + (ocr?.id_last4?.let { " · ID $it" } ?: ""), color = Blue, modifier = Modifier.padding(top = 6.dp))
            }
        }
        AppCard {
            Column {
                CheckRow("Parent / guardian consent given", f.consent) { v -> vm.update { it.copy(consent = v) } }
                CheckRow("Send admission confirmation on WhatsApp", f.sendMessage) { v -> vm.update { it.copy(sendMessage = v) } }
                CheckRow("Invite parent to the ClassNex app", f.invite) { v -> vm.update { it.copy(invite = v) } }
            }
        }
        Button(onClick = { vm.save { dup = it } }, enabled = !busy, modifier = Modifier.fillMaxWidth().height(54.dp)) {
            if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp) else Text("Save Student", fontSize = 16.sp)
        }
        Spacer(Modifier.height(24.dp))
    }
    dups?.let { d -> DuplicateDialog(d, onDismiss = { dup = null; vm.duplicates.value = null }, onOpen = { id -> vm.duplicates.value = null; onSaved(id) }, onLink = vm::linkExisting) { reason -> vm.duplicates.value = null; vm.save(true, reason) {} } }
}

@Composable
fun Field(label: String, value: String, error: String?, modifier: Modifier = Modifier, keyboard: KeyboardType = KeyboardType.Text, prefix: String? = null, help: String? = null, single: Boolean = true, onChange: (String) -> Unit) {
    OutlinedTextField(value, onChange, label = { Text(label) }, isError = error != null, singleLine = single, minLines = if (single) 1 else 2,
        prefix = prefix?.let { { Text(it) } }, keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        supportingText = (error ?: help)?.let { { Text(it, color = if (error != null) Red else Muted) } }, modifier = modifier.fillMaxWidth())
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun Picker(label: String, options: List<Pair<Long, String>>, selected: Long?, error: String?, onSelect: (Long) -> Unit) {
    var open by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(open, { open = it }) {
        OutlinedTextField(options.firstOrNull { it.first == selected }?.second ?: "", {}, readOnly = true, label = { Text(label) }, isError = error != null,
            supportingText = error?.let { { Text(it, color = Red) } }, trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) }, modifier = Modifier.menuAnchor().fillMaxWidth())
        ExposedDropdownMenu(open, { open = false }) {
            options.forEach { (id, name) -> DropdownMenuItem(text = { Text(name) }, onClick = { onSelect(id); open = false }) }
        }
    }
}

@Composable
fun CheckRow(text: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) { Checkbox(checked, onChange); Text(text, fontSize = 14.sp) }
}

@Suppress("unused") private fun uid() = UUID.randomUUID().toString()

data class Duplicates(val message: String, val candidates: List<`in`.classnex.app.data.net.DuplicateCandidate>, val canOverride: Boolean)

/**
 * Possible duplicate: open the existing profile, link it (no new profile), or – Class Admin only –
 * create a separate student after checking, with a reason that goes to the audit log. Never merges automatically.
 */
@Composable
fun DuplicateDialog(d: Duplicates, onDismiss: () -> Unit, onOpen: (Long) -> Unit, onLink: (Long) -> Unit, onSeparate: (String) -> Unit) {
    var reason by remember { mutableStateOf("") }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Possible duplicate student") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Brothers and sisters can share a parent's WhatsApp number – check the name, guardian and date of birth.", color = Muted, fontSize = 12.sp)
                d.candidates.forEach { c ->
                    AppCard {
                        Column {
                            Text("${c.name} · ${c.student_code}", fontWeight = FontWeight.Bold)
                            Text((if (c.level == "high") "Likely the same student · " else "Possible match · ") + c.reasons.joinToString(", "), color = if (c.level == "high") Red else Muted, fontSize = 12.sp)
                            Text("Guardian: ${c.guardian_name ?: "–"} · DOB: ${c.dob ?: "–"} · ${c.branch_name ?: "Main"} · ${c.status}", color = Muted, fontSize = 12.sp)
                            Row {
                                TextButton(onClick = { onOpen(c.id) }) { Text("Open") }
                                TextButton(onClick = { onLink(c.id) }) { Text("Link this student") }
                            }
                        }
                    }
                }
                if (d.canOverride) OutlinedTextField(reason, { reason = it }, label = { Text("Reason (if this is a different student)") }, modifier = Modifier.fillMaxWidth())
                else Text("Only the Class Admin can create a separate profile when a possible duplicate exists.", color = Muted, fontSize = 12.sp)
            }
        },
        confirmButton = { if (d.canOverride) Button(enabled = reason.trim().length >= 5, onClick = { onSeparate(reason.trim()) }) { Text("Create separate") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
