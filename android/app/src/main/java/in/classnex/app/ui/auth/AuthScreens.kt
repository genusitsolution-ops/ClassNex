package `in`.classnex.app.ui.auth

import android.os.Build
import androidx.compose.foundation.Image
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.CurrencyRupee
import androidx.compose.material.icons.outlined.PersonAdd
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import `in`.classnex.app.R
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.AccountChoice
import `in`.classnex.app.data.net.ForgotRequest
import `in`.classnex.app.data.net.LoginRequest
import `in`.classnex.app.data.net.Me
import `in`.classnex.app.fcm.PushRegistrar
import `in`.classnex.app.ui.theme.Blue
import `in`.classnex.app.ui.theme.Green
import `in`.classnex.app.ui.theme.Muted
import `in`.classnex.app.ui.theme.Orange
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import android.content.Context
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

data class AuthUi(
    val checking: Boolean = true,
    val me: Me? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val accounts: List<AccountChoice> = emptyList(),
    val info: String? = null,
)

/** Holds the logged-in user for the whole app. */
@HiltViewModel
class AuthViewModel @Inject constructor(
    private val repo: Repository,
    private val push: PushRegistrar,
    @ApplicationContext private val context: Context,
) : ViewModel() {
    private val _ui = MutableStateFlow(AuthUi())
    val ui = _ui.asStateFlow()
    val onboarded get() = repo.session.onboarded

    init { restore() }

    fun restore() {
        if (repo.session.token == null) { _ui.value = AuthUi(checking = false); return }
        viewModelScope.launch {
            _ui.value = try {
                val me = call { repo.api.me() }.data!!
                push.register(context)
                AuthUi(checking = false, me = me)
            } catch (e: Exception) {
                if (repo.session.token == null) AuthUi(checking = false) else AuthUi(checking = false, error = e.message)
            }
        }
    }

    fun finishOnboarding() { repo.session.onboarded = true }

    fun login(identifier: String, password: String, accountId: Long? = null) {
        if (identifier.isBlank() || password.isBlank()) { _ui.value = _ui.value.copy(error = "Enter your mobile number or email and password."); return }
        _ui.value = _ui.value.copy(busy = true, error = null)
        viewModelScope.launch {
            try {
                val d = call { repo.api.login(LoginRequest(identifier.trim(), password, device_name = Build.MANUFACTURER + " " + Build.MODEL, account_id = accountId)) }.data!!
                if (d.choose_account) {
                    _ui.value = _ui.value.copy(busy = false, accounts = d.accounts)
                } else {
                    repo.session.token = d.token
                    repo.session.role = d.me?.role
                    push.register(context)
                    _ui.value = AuthUi(checking = false, me = d.me)
                }
            } catch (e: Exception) {
                _ui.value = _ui.value.copy(busy = false, error = e.message)
            }
        }
    }

    fun dismissAccounts() { _ui.value = _ui.value.copy(accounts = emptyList()) }

    /** Academy registration (same API as the website). With Super Admin approval on, the account waits for approval. */
    fun register(body: `in`.classnex.app.data.net.RegisterAcademy, onDone: (String) -> Unit) {
        _ui.value = _ui.value.copy(busy = true, error = null)
        viewModelScope.launch {
            try {
                val r = call { repo.api.registerAcademy(body) }
                val token = r.data?.token
                if (token != null) {
                    repo.session.token = token
                    restore()
                } else {
                    _ui.value = _ui.value.copy(busy = false)
                }
                onDone(r.message)
            } catch (e: Exception) {
                val err = e as? `in`.classnex.app.data.ApiError
                _ui.value = _ui.value.copy(busy = false, error = err?.fieldErrors?.values?.firstOrNull() ?: e.message)
            }
        }
    }

    fun forgot(identifier: String) {
        viewModelScope.launch {
            _ui.value = _ui.value.copy(info = try { call { repo.api.forgot(ForgotRequest(identifier)) }.message } catch (e: Exception) { e.message })
        }
    }

    fun refreshMe() {
        viewModelScope.launch { runCatching { call { repo.api.me() }.data!! }.onSuccess { _ui.value = _ui.value.copy(me = it) } }
    }

    fun logout() {
        val fcm = repo.session.pendingFcmToken
        viewModelScope.launch {
            runCatching { call { repo.api.logout(if (fcm != null) mapOf("fcm_token" to fcm) else emptyMap()) } }
            repo.session.clear()
            _ui.value = AuthUi(checking = false)
        }
    }

    fun expired() {
        repo.session.clear()
        _ui.value = AuthUi(checking = false, error = "Your session has expired. Please log in again.")
    }
}

@Composable
fun SplashScreen() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Image(painterResource(R.drawable.classnex_logo), null, Modifier.width(220.dp))
            Spacer(Modifier.height(24.dp))
            CircularProgressIndicator()
        }
    }
}

@Composable
fun OnboardingScreen(onDone: () -> Unit) {
    val pages = listOf(
        Triple(Icons.Outlined.PersonAdd, "Add students in seconds", "Photo, name and WhatsApp number — that's it. Scan a document to fill the rest.") to Blue,
        Triple(Icons.Outlined.CalendarMonth, "One-tap attendance", "Mark the whole batch present, fix the few absentees. Parents are informed automatically.") to Green,
        Triple(Icons.Outlined.CurrencyRupee, "Fees without follow-ups", "Receipts on WhatsApp after every payment and polite automatic reminders before due dates.") to Orange,
    )
    var i by remember { mutableIntStateOf(0) }
    val (p, tint) = pages[i]
    Column(Modifier.fillMaxSize().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = onDone) { Text("Skip") } }
        Spacer(Modifier.weight(1f))
        Image(painterResource(R.drawable.classnex_icon), null, Modifier.size(120.dp))
        Spacer(Modifier.height(32.dp))
        Surface(shape = CircleShape, color = tint.copy(alpha = .12f)) { Icon(p.first, null, tint = tint, modifier = Modifier.padding(20.dp).size(40.dp)) }
        Spacer(Modifier.height(20.dp))
        Text(p.second, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
        Spacer(Modifier.height(10.dp))
        Text(p.third, color = Muted, textAlign = TextAlign.Center, fontSize = 16.sp)
        Spacer(Modifier.weight(1f))
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { pages.indices.forEach { Surface(shape = CircleShape, color = if (it == i) Blue else Color(0xFFD9DEEA), modifier = Modifier.size(if (it == i) 10.dp else 8.dp)) {} } }
        Spacer(Modifier.height(20.dp))
        Button(onClick = { if (i < pages.lastIndex) i++ else onDone() }, modifier = Modifier.fillMaxWidth().height(52.dp)) { Text(if (i < pages.lastIndex) "Next" else "Get started", fontSize = 16.sp) }
    }
}

@Composable
fun LoginScreen(vm: AuthViewModel) {
    val ui by vm.ui.collectAsState()
    var id by remember { mutableStateOf("") }
    var pw by remember { mutableStateOf("") }
    var show by remember { mutableStateOf(false) }
    var forgot by remember { mutableStateOf(false) }
    var register by remember { mutableStateOf(false) }
    var registered by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Spacer(Modifier.height(24.dp))
        Image(painterResource(R.drawable.classnex_logo), "ClassNex", Modifier.width(210.dp))
        Spacer(Modifier.height(24.dp))
        Text("Welcome back", fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
        Text("Class Admins, teachers, parents and students", color = Muted)
        Spacer(Modifier.height(24.dp))
        OutlinedTextField(id, { id = it }, label = { Text("Mobile number or email") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next))
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(pw, { pw = it }, label = { Text("Password") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
            visualTransformation = if (show) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
            trailingIcon = { IconButton(onClick = { show = !show }) { Icon(if (show) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, null) } })
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = { forgot = true }) { Text("Forgot password?") } }
        ui.error?.let { Text(it, color = Color(0xFFDC2626), modifier = Modifier.padding(bottom = 8.dp), textAlign = TextAlign.Center) }
        Button(onClick = { vm.login(id, pw) }, enabled = !ui.busy, modifier = Modifier.fillMaxWidth().height(54.dp)) {
            if (ui.busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp) else Text("Log in", fontSize = 16.sp)
        }
        Spacer(Modifier.height(24.dp))
        TextButton(onClick = { register = true }) { Text("New academy? Register here") }
        Text("Registrations are approved by the ClassNex team before the free trial starts.", color = Muted, fontSize = 12.sp, textAlign = TextAlign.Center)
    }
    if (register) {
        var name by remember { mutableStateOf("") }
        var mobile by remember { mutableStateOf("") }
        var academy by remember { mutableStateOf("") }
        var city by remember { mutableStateOf("") }
        var email by remember { mutableStateOf("") }
        var password by remember { mutableStateOf("") }
        var terms by remember { mutableStateOf(false) }
        AlertDialog(onDismissRequest = { register = false }, title = { Text("Register your academy") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(name, { name = it }, label = { Text("Your name *") }, singleLine = true)
                    OutlinedTextField(mobile, { mobile = it.filter(Char::isDigit).take(10) }, label = { Text("Mobile (login) *") }, singleLine = true, prefix = { Text("+91 ") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                    OutlinedTextField(academy, { academy = it }, label = { Text("Academy / class name *") }, singleLine = true)
                    OutlinedTextField(city, { city = it }, label = { Text("City") }, singleLine = true)
                    OutlinedTextField(email, { email = it }, label = { Text("Email") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
                    OutlinedTextField(password, { password = it }, label = { Text("Create password *") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), supportingText = { Text("8+ characters with letters and numbers") })
                    Row(verticalAlignment = Alignment.CenterVertically) { androidx.compose.material3.Checkbox(terms, { terms = it }); Text("I agree to the Terms and Privacy Policy", fontSize = 13.sp) }
                    ui.error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }
                }
            },
            confirmButton = { Button(enabled = !ui.busy && terms && name.isNotBlank() && mobile.length == 10 && academy.isNotBlank() && password.length >= 8, onClick = {
                vm.register(`in`.classnex.app.data.net.RegisterAcademy(name.trim(), mobile, academy.trim(), password, city.ifBlank { null }, email.ifBlank { null })) { msg -> register = false; registered = msg }
            }) { Text("Register") } },
            dismissButton = { TextButton(onClick = { register = false }) { Text("Cancel") } })
    }
    registered?.let { msg ->
        AlertDialog(onDismissRequest = { registered = null }, title = { Text("Thank you!") }, text = { Text(msg) },
            confirmButton = { Button(onClick = { registered = null }) { Text("OK") } })
    }
    if (ui.accounts.isNotEmpty()) {
        var sel by remember { mutableStateOf(ui.accounts.first().id) }
        AlertDialog(onDismissRequest = vm::dismissAccounts, title = { Text("Choose account") },
            text = {
                Column {
                    Text("This number has more than one account.", color = Muted)
                    ui.accounts.forEach { a ->
                        Row(Modifier.fillMaxWidth().selectable(sel == a.id) { sel = a.id }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(sel == a.id, { sel = a.id })
                            Column { Text(a.academy ?: "", fontWeight = FontWeight.Bold); Text("${`in`.classnex.app.ui.common.roleName(a.role)} · ${a.name}", color = Muted, fontSize = 13.sp) }
                        }
                    }
                }
            },
            confirmButton = { Button(onClick = { vm.dismissAccounts(); vm.login(id, pw, sel) }) { Text("Continue") } },
            dismissButton = { TextButton(onClick = vm::dismissAccounts) { Text("Cancel") } })
    }
    if (forgot) {
        var fid by remember { mutableStateOf(id) }
        LaunchedEffect(Unit) { }
        AlertDialog(onDismissRequest = { forgot = false }, title = { Text("Reset password") },
            text = { Column { Text("We'll send a reset link on WhatsApp / email.", color = Muted); Spacer(Modifier.height(8.dp)); OutlinedTextField(fid, { fid = it }, label = { Text("Mobile or email") }, singleLine = true); ui.info?.let { Text(it, color = Green, modifier = Modifier.padding(top = 8.dp)) } } },
            confirmButton = { Button(onClick = { vm.forgot(fid) }) { Text("Send link") } },
            dismissButton = { TextButton(onClick = { forgot = false }) { Text("Close") } })
    }
}
