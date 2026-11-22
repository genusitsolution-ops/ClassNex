package `in`.classnex.app.ui.crud

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import `in`.classnex.app.data.ApiError
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import javax.inject.Inject

/*
 * A small configurable engine for the many "list + add/edit form" modules (activities, batches,
 * teachers, holidays, announcements, fee plans, discounts, parents, leaves, admissions, support…).
 * Each module is described once (ModuleSpec / FormSpec in Modules.kt) and talks to the same REST
 * endpoints as the web panels, so the app and the website always show the same data.
 */

// --------------------------------------------------------------------------- JSON helpers
fun JsonObject.s(key: String): String? = (this[key] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content?.takeIf { it.isNotEmpty() }
fun JsonObject.n(key: String): Long = (this[key] as? JsonPrimitive)?.let { it.longOrNull ?: it.content.toDoubleOrNull()?.toLong() } ?: 0L
fun JsonObject.id(): Long = n("id")
fun JsonObject.b(key: String): Boolean = (this[key] as? JsonPrimitive)?.let { it.booleanOrNull ?: (it.longOrNull?.let { v -> v != 0L }) } ?: false
fun JsonObject.list(key: String): List<String> = (this[key] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content } ?: emptyList()
fun JsonObject.obj(key: String): JsonObject? = this[key] as? JsonObject
fun JsonObject.arr(key: String): List<JsonObject> = (this[key] as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()

/** Lists come as an array, or inside {items: [...]} for a few endpoints. */
fun extractList(d: JsonElement?, itemsKey: String? = null): List<JsonObject> = when (d) {
    is JsonArray -> d.mapNotNull { it as? JsonObject }
    is JsonObject -> (d[itemsKey ?: "items"] as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()
    else -> emptyList()
}

// --------------------------------------------------------------------------- specs
enum class FType { Text, Number, Money, Phone, Email, Date, Time, Select, MultiSelect, Switch, Area, Weekdays, Password, Section }

data class OptionSource(val url: String, val query: Map<String, String> = emptyMap(), val valueKey: String = "id", val itemsKey: String? = null, val text: (JsonObject) -> String = { it.s("name") ?: "" })

data class FieldSpec(
    val key: String,
    val label: String,
    val type: FType = FType.Text,
    val required: Boolean = false,
    val options: List<Pair<String, String>> = emptyList(),
    val source: OptionSource? = null,
    val default: String? = null,
    val help: String? = null,
    /** Field of the row to prefill from when it differs (e.g. monthly_fee_paise). */
    val readKey: String? = null,
    /** Stored ×100 (paise / basis points) – shown divided by 100. */
    val hundredths: Boolean = false,
    val createOnly: Boolean = false,
    val noneLabel: String? = null,
    val visibleWhen: (Map<String, String>) -> Boolean = { true },
)

data class FormSpec(
    val title: String,
    val fields: List<FieldSpec>,
    val createUrl: String?,
    val updateUrl: ((Long) -> String)?,
    val updatePut: Boolean = true,
    val submitText: String = "Save",
    val extra: (JsonObject?) -> Map<String, JsonElement> = { emptyMap() },
)

data class RowView(val title: String, val sub: String? = null, val status: String? = null, val statusText: String? = null, val trailing: String? = null, val photo: String? = null, val note: String? = null, val icon: ImageVector? = null, val tint: Color = Violet)

data class RowAction(
    val label: String,
    val icon: ImageVector,
    val url: (JsonObject) -> String = { "" },
    val method: String = "POST",
    val confirm: String? = null,
    val prompt: String? = null,
    val promptKey: String = "reason",
    val promptRequired: Boolean = false,
    val danger: Boolean = false,
    val body: (JsonObject) -> Map<String, JsonElement> = { emptyMap() },
    val route: ((JsonObject) -> String)? = null,
    val visible: (JsonObject) -> Boolean = { true },
)

data class ModuleSpec(
    val key: String,
    val title: String,
    val listUrl: String,
    val row: (JsonObject) -> RowView,
    val form: FormSpec? = null,
    val canCreate: Boolean = false,
    val canEdit: (JsonObject) -> Boolean = { false },
    val actions: List<RowAction> = emptyList(),
    val filters: List<Pair<String?, String>> = emptyList(),
    val filterKey: String = "status",
    val query: Map<String, String> = emptyMap(),
    val itemsKey: String? = null,
    val searchable: Boolean = false,
    val emptyTitle: String = "Nothing here yet",
    val emptyText: String? = null,
    val icon: ImageVector = Icons.Outlined.Inbox,
    val createLabel: String = "Add",
    val onTap: ((JsonObject) -> String)? = null,
    val header: String? = null,
)

/** Hands the row being edited to the form screen and tells lists to reload after a save. */
object FormBus {
    var row: JsonObject? = null
    val version = MutableStateFlow(0)
    fun saved() { version.value++ }
}

// --------------------------------------------------------------------------- list
@HiltViewModel
class CrudVm @Inject constructor(private val repo: Repository) : LoadVm<List<JsonObject>>() {
    private var spec: ModuleSpec? = null
    var filter by mutableStateOf<String?>(null)
    var q by mutableStateOf("")
    val busy = MutableStateFlow(false)

    fun start(s: ModuleSpec) {
        if (spec?.key == s.key) return
        spec = s
        filter = s.filters.firstOrNull()?.first
        refresh()
    }

    fun refresh(silent: Boolean = false) {
        val s = spec ?: return
        load(silent) {
            val query = buildMap {
                putAll(s.query)
                filter?.let { put(s.filterKey, it) }
                if (q.isNotBlank()) put("q", q.trim())
            }
            extractList(call { repo.api.getJson(s.listUrl, query) }.data, s.itemsKey)
        }
    }

    fun act(a: RowAction, row: JsonObject, input: String?) {
        busy.value = true
        viewModelScope.launch {
            try {
                val body = JsonObject(a.body(row) + (if (a.prompt != null && !input.isNullOrBlank()) mapOf(a.promptKey to JsonPrimitive(input)) else emptyMap()))
                val url = a.url(row)
                val r = when (a.method) {
                    "DELETE" -> call { repo.api.deleteJson(url) }
                    "PUT" -> call { repo.api.putJson(url, body) }
                    else -> call { repo.api.postJson(url, body) }
                }
                say(r.message.ifBlank { "Done." })
                refresh(silent = true)
            } catch (e: Exception) { say(errorText(e)) } finally { busy.value = false }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CrudListScreen(spec: ModuleSpec, snack: SnackbarHostState, nav: (String) -> Unit, vm: CrudVm = hiltViewModel()) {
    LaunchedEffect(spec.key) { vm.start(spec) }
    val version by FormBus.version.collectAsState()
    LaunchedEffect(version) { if (version > 0) vm.refresh(silent = true) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val st by vm.state.collectAsState()
    var pending by remember { mutableStateOf<Pair<RowAction, JsonObject>?>(null) }
    var expanded by remember { mutableStateOf<Long?>(null) }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            if (spec.filters.isNotEmpty() || spec.searchable || spec.header != null) Column(Modifier.padding(16.dp, 12.dp, 16.dp, 4.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                spec.header?.let { InfoBanner(it, Violet) }
                if (spec.filters.isNotEmpty()) {
                    if (spec.filters.size <= 4) SegmentedTabs(spec.filters.map { it.second }, spec.filters.indexOfFirst { it.first == vm.filter }.coerceAtLeast(0)) { vm.filter = spec.filters[it].first; vm.refresh() }
                    else ChoiceChips(spec.filters.map { (it.first ?: "") to it.second }, vm.filter ?: "") { k -> vm.filter = k.ifEmpty { null }; vm.refresh() }
                }
                if (spec.searchable) {
                    SearchBox(vm.q, { vm.q = it; if (it.isEmpty() || it.length >= 2) vm.refresh(silent = true) }, "Search")
                }
            }
            LoadContent(st, { vm.refresh() }) { rows ->
                if (rows.isEmpty()) EmptyState(spec.emptyTitle, spec.emptyText, spec.icon, if (spec.canCreate && spec.form != null) spec.createLabel else null) { FormBus.row = null; nav("form/${spec.key}?id=0") }
                else LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    itemsIndexed(rows, key = { i, r -> r.id().takeIf { it > 0 } ?: (i.toLong() + 1_000_000) }) { i, r ->
                        val v = spec.row(r)
                        val acts = spec.actions.filter { it.visible(r) }
                        val editable = spec.form != null && spec.canEdit(r)
                        Entrance(i) {
                            CnxCard(Modifier.animateContentSize(), onClick = {
                                when {
                                    spec.onTap != null -> nav(spec.onTap.invoke(r))
                                    acts.isNotEmpty() || editable -> expanded = if (expanded == r.id()) null else r.id()
                                }
                            }, padding = 14.dp) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    if (v.photo != null || v.icon == null) Avatar(v.photo, v.title, 44) else IconCircle(v.icon, v.tint, 44.dp)
                                    Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                                        Text(v.title, fontWeight = FontWeight.Bold, color = Ink, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                        v.sub?.let { Text(it, color = Muted, fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis) }
                                    }
                                    Column(horizontalAlignment = Alignment.End) {
                                        v.trailing?.let { Text(it, fontWeight = FontWeight.ExtraBold, color = Ink) }
                                        v.status?.let { Spacer(Modifier.height(4.dp)); Pill(v.statusText ?: label(it), statusColor(it)) }
                                    }
                                }
                                v.note?.let { Text(it, color = Ink.copy(alpha = .8f), fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
                                if (expanded == r.id() && (acts.isNotEmpty() || editable)) {
                                    HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Line)
                                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                        if (editable) SoftButton("Edit", { FormBus.row = r; nav("form/${spec.key}?id=${r.id()}") }, icon = Icons.Outlined.Edit)
                                        acts.forEach { a ->
                                            SoftButton(a.label, {
                                                when {
                                                    a.route != null -> nav(a.route.invoke(r))
                                                    a.confirm != null || a.prompt != null -> pending = a to r
                                                    else -> vm.act(a, r, null)
                                                }
                                            }, icon = a.icon, color = if (a.danger) Red else Violet)
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if (spec.canCreate && spec.form != null) {
            ExtendedFloatingActionButton(
                onClick = { FormBus.row = null; nav("form/${spec.key}?id=0") },
                icon = { Icon(Icons.Outlined.Add, null) }, text = { Text(spec.createLabel) },
                containerColor = Violet, contentColor = Color.White, shape = RoundedCornerShape(18.dp),
                modifier = Modifier.align(Alignment.BottomEnd).padding(18.dp),
            )
        }
    }

    pending?.let { (a, r) ->
        var text by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { pending = null }, containerColor = Color.White, shape = RoundedCornerShape(24.dp),
            title = { Text(a.label) },
            text = {
                Column {
                    a.confirm?.let { Text(it, color = Muted) }
                    if (a.prompt != null) { Spacer(Modifier.height(10.dp)); CnxField(text, { text = it }, a.prompt, singleLine = false, minLines = 2) }
                }
            },
            confirmButton = {
                Button(onClick = { vm.act(a, r, text); pending = null }, enabled = !a.promptRequired || text.isNotBlank(),
                    colors = ButtonDefaults.buttonColors(containerColor = if (a.danger) Red else Violet), shape = RoundedCornerShape(14.dp)) { Text(a.label) }
            },
            dismissButton = { TextButton(onClick = { pending = null }) { Text("Cancel") } },
        )
    }
}

// --------------------------------------------------------------------------- form
@HiltViewModel
class FormVm @Inject constructor(private val repo: Repository) : ViewModel() {
    val values = mutableStateMapOf<String, String>()
    val errors = mutableStateMapOf<String, String>()
    val options = mutableStateMapOf<String, List<Pair<String, String>>>()
    val saving = MutableStateFlow(false)
    val messages = MutableSharedFlow<String>(extraBufferCapacity = 4)
    private var started: String? = null

    fun start(key: String, spec: FormSpec, row: JsonObject?) {
        if (started == key) return
        started = key
        values.clear(); errors.clear()
        spec.fields.forEach { f ->
            val raw = row?.get(f.readKey ?: f.key)
            values[f.key] = when {
                row == null -> f.default ?: ""
                raw == null || raw is JsonNull -> f.default ?: ""
                raw is JsonArray -> raw.mapNotNull { (it as? JsonPrimitive)?.content }.joinToString(",")
                f.type == FType.Switch -> if ((raw as? JsonPrimitive)?.let { it.booleanOrNull ?: (it.longOrNull != 0L) } == true) "1" else "0"
                f.hundredths || (f.type == FType.Money && (f.readKey ?: f.key).endsWith("_paise")) -> (raw as? JsonPrimitive)?.content?.toDoubleOrNull()?.let { rupeesInput(it.toLong()) } ?: ""
                f.type == FType.Time -> (raw as? JsonPrimitive)?.content?.take(5) ?: ""
                f.type == FType.Date -> (raw as? JsonPrimitive)?.content?.take(10) ?: ""
                else -> (raw as? JsonPrimitive)?.content ?: ""
            }
        }
        spec.fields.mapNotNull { f -> f.source?.let { f.key to it } }.forEach { (k, src) ->
            viewModelScope.launch {
                runCatching { extractList(call { repo.api.getJson(src.url, src.query) }.data, src.itemsKey) }
                    .onSuccess { list -> options[k] = list.map { (it.s(src.valueKey) ?: "") to src.text(it) } }
                    .onFailure { options[k] = emptyList() }
            }
        }
    }

    fun body(spec: FormSpec, row: JsonObject?): JsonObject {
        val m = linkedMapOf<String, JsonElement>()
        spec.fields.filter { it.type != FType.Section && it.visibleWhen(values) && !(it.createOnly && row != null) }.forEach { f ->
            val v = values[f.key].orEmpty().trim()
            when (f.type) {
                FType.MultiSelect, FType.Weekdays -> m[f.key] = JsonArray(v.split(',').filter { it.isNotBlank() }.map { it.toLongOrNull()?.let { n -> JsonPrimitive(n) } ?: JsonPrimitive(it) })
                FType.Switch -> m[f.key] = JsonPrimitive(if (v == "1") 1 else 0)
                FType.Number -> if (v.isNotEmpty()) m[f.key] = v.toLongOrNull()?.let { n -> JsonPrimitive(n) } ?: JsonPrimitive(v)
                FType.Select -> when {
                    v.isNotEmpty() -> m[f.key] = v.toLongOrNull()?.let { n -> JsonPrimitive(n) } ?: JsonPrimitive(v)
                    f.noneLabel != null -> m[f.key] = JsonNull
                }
                else -> if (v.isNotEmpty()) m[f.key] = JsonPrimitive(v)
            }
        }
        return JsonObject(m + spec.extra(row))
    }

    fun save(spec: FormSpec, row: JsonObject?, done: (String) -> Unit) {
        errors.clear()
        spec.fields.filter { it.required && it.visibleWhen(values) && it.type != FType.Section && !(it.createOnly && row != null) }.forEach { f ->
            if (values[f.key].isNullOrBlank()) errors[f.key] = "Required"
        }
        if (errors.isNotEmpty()) { messages.tryEmit("Please fill the required fields."); return }
        saving.value = true
        viewModelScope.launch {
            try {
                val b = body(spec, row)
                val r = if (row == null || spec.updateUrl == null) call { repo.api.postJson(spec.createUrl!!, b) }
                else if (spec.updatePut) call { repo.api.putJson(spec.updateUrl.invoke(row.id()), b) }
                else call { repo.api.postJson(spec.updateUrl.invoke(row.id()), b) }
                FormBus.saved()
                done(r.message.ifBlank { "Saved." })
            } catch (e: Exception) {
                (e as? ApiError)?.fieldErrors?.forEach { (k, v) -> errors[k] = v }
                messages.tryEmit((e as? ApiError)?.let { it.fieldErrors.values.firstOrNull() ?: it.message } ?: (e.message ?: "Could not save"))
            } finally { saving.value = false }
        }
    }
}

private val weekdayNames = listOf("1" to "Mon", "2" to "Tue", "3" to "Wed", "4" to "Thu", "5" to "Fri", "6" to "Sat", "7" to "Sun")

@Composable
fun FormScreen(key: String, spec: FormSpec, row: JsonObject?, snack: SnackbarHostState, onDone: (String) -> Unit, vm: FormVm = hiltViewModel()) {
    LaunchedEffect(key, row) { vm.start(key + ":" + (row?.id() ?: 0), spec, row) }
    LaunchedEffect(Unit) { vm.messages.collect { snack.showSnackbar(it) } }
    val saving by vm.saving.collectAsState()
    Column(Modifier.fillMaxSize().imePadding()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            spec.fields.filter { it.visibleWhen(vm.values) && !(it.createOnly && row != null) }.forEach { f ->
                FieldInput(f, vm)
            }
            Spacer(Modifier.height(8.dp))
        }
        Surface(color = Color.White, shadowElevation = 10.dp) {
            GradientButton(spec.submitText, { vm.save(spec, row) { m -> onDone(m) } }, Modifier.fillMaxWidth().navigationBarsPadding().padding(16.dp), loading = saving, icon = Icons.Outlined.Check)
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
fun FieldInput(f: FieldSpec, vm: FormVm) {
    val v = vm.values[f.key].orEmpty()
    val err = vm.errors[f.key]
    val lbl = f.label + if (f.required) " *" else ""
    val set: (String) -> Unit = { vm.values[f.key] = it; vm.errors.remove(f.key) }
    when (f.type) {
        FType.Section -> Text(f.label, style = MaterialTheme.typography.titleMedium, color = Violet, modifier = Modifier.padding(top = 8.dp))
        FType.Text -> CnxField(v, set, lbl, error = err, supporting = f.help)
        FType.Area -> CnxField(v, set, lbl, error = err, singleLine = false, minLines = 3, supporting = f.help)
        FType.Number -> CnxField(v, { set(it.filter(Char::isDigit)) }, lbl, keyboard = KeyboardType.Number, error = err, supporting = f.help)
        FType.Money -> CnxField(v, { set(it.filter { c -> c.isDigit() || c == '.' }) }, lbl, keyboard = KeyboardType.Decimal, prefix = "₹ ", error = err, supporting = f.help)
        FType.Phone -> CnxField(v, { set(it.filter(Char::isDigit).take(15)) }, lbl, keyboard = KeyboardType.Phone, icon = Icons.Outlined.Phone, error = err, supporting = f.help)
        FType.Email -> CnxField(v, set, lbl, keyboard = KeyboardType.Email, icon = Icons.Outlined.Email, error = err, supporting = f.help)
        FType.Password -> CnxField(v, set, lbl, password = true, icon = Icons.Outlined.Lock, error = err, supporting = f.help)
        FType.Date -> DateField(lbl, v.ifBlank { null }, allowClear = !f.required, error = err) { set(it ?: "") }
        FType.Time -> TimeField(lbl, v.ifBlank { null }) { set(it ?: "") }
        FType.Select -> {
            val opts: List<Pair<String?, String>> = (if (f.noneLabel != null) listOf<Pair<String?, String>>(null to f.noneLabel) else emptyList()) +
                (f.source?.let { vm.options[f.key] } ?: f.options).map { (a, b) -> a to b }
            SelectField(lbl, opts, v.ifBlank { null }, error = err) { set(it ?: "") }
            f.help?.let { Text(it, color = Muted, fontSize = 12.sp) }
        }
        FType.MultiSelect -> {
            val opts = f.source?.let { vm.options[f.key] } ?: f.options
            val chosen = v.split(',').filter { it.isNotBlank() }.toSet()
            Column {
                Text(lbl, fontWeight = FontWeight.SemiBold, color = Ink)
                f.help?.let { Text(it, color = Muted, fontSize = 12.sp) }
                Spacer(Modifier.height(6.dp))
                if (opts.isEmpty()) Text("Nothing to choose yet", color = Muted, fontSize = 13.sp)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    opts.forEach { (k, t) ->
                        val on = k in chosen
                        FilterChip(on, { set((if (on) chosen - k else chosen + k).joinToString(",")) }, label = { Text(t) },
                            leadingIcon = if (on) { { Icon(Icons.Outlined.Check, null, Modifier.size(16.dp)) } } else null,
                            colors = FilterChipDefaults.filterChipColors(selectedContainerColor = VioletSoft, selectedLabelColor = Indigo800, selectedLeadingIconColor = Violet))
                    }
                }
            }
        }
        FType.Weekdays -> {
            val chosen = v.split(',').filter { it.isNotBlank() }.toSet()
            Column {
                Text(lbl, fontWeight = FontWeight.SemiBold, color = Ink)
                Spacer(Modifier.height(6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    weekdayNames.forEach { (k, t) ->
                        val on = k in chosen
                        Box(Modifier.weight(1f).height(40.dp).clip(CircleShape).background(if (on) Violet else VioletSoft).clickable { set((if (on) chosen - k else chosen + k).sortedBy { it.toInt() }.joinToString(",")) }, contentAlignment = Alignment.Center) {
                            Text(t, color = if (on) Color.White else Indigo800, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
                f.help?.let { Text(it, color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
            }
        }
        FType.Switch -> Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color.White).clickable { set(if (v == "1") "0" else "1") }.padding(14.dp, 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(f.label, fontWeight = FontWeight.SemiBold, color = Ink)
                f.help?.let { Text(it, color = Muted, fontSize = 12.sp) }
            }
            Switch(v == "1", { set(if (it) "1" else "0") }, colors = SwitchDefaults.colors(checkedTrackColor = Violet))
        }
    }
}
