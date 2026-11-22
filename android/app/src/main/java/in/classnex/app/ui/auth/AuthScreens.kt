package `in`.classnex.app.ui.auth

import android.content.Context
import android.os.Build
import androidx.compose.animation.*
import androidx.compose.animation.core.*
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import `in`.classnex.app.R
import `in`.classnex.app.data.Repository
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.*
import `in`.classnex.app.fcm.PushRegistrar
import `in`.classnex.app.ui.common.*
import `in`.classnex.app.ui.theme.*
import kotlinx.coroutines.delay
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
                    pending = identifier.trim() to password
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

    /** Credentials kept in memory only while the user picks one of several accounts. */
    private var pending: Pair<String, String>? = null
    fun loginAs(accountId: Long) { pending?.let { (i, p) -> pending = null; login(i, p, accountId) } }
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

    /** Track Registration Status: mobile/email + the password chosen at registration. */
    fun trackStatus(identifier: String, password: String, onResult: (`in`.classnex.app.data.net.RegStatus?, String) -> Unit) {
        _ui.value = _ui.value.copy(busy = true, error = null)
        viewModelScope.launch {
            try {
                val r = call { repo.api.registrationStatus(`in`.classnex.app.data.net.RegStatusRequest(identifier.trim(), password)) }
                _ui.value = _ui.value.copy(busy = false)
                onResult(r.data, r.message)
            } catch (e: Exception) {
                _ui.value = _ui.value.copy(busy = false)
                onResult(null, e.message ?: "Could not check the status")
            }
        }
    }

    fun clearError() { _ui.value = _ui.value.copy(error = null, info = null) }

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

// ===================================================================== splash
@Composable
fun SplashScreen() {
    val s = remember { Animatable(.7f) }
    val a = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        launch { s.animateTo(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessLow)) }
        a.animateTo(1f, tween(600))
    }
    Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color.White, Color(0xFFF1ECFF)))), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(32.dp)) {
            Image(painterResource(R.drawable.classnex_logo), "ClassNex", Modifier.width(230.dp).scale(s.value).alpha(a.value))
            Spacer(Modifier.height(18.dp))
            Text("Manage Your Academy, Class & Students in One Place", color = Ink, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, fontSize = 17.sp, modifier = Modifier.alpha(a.value))
            Spacer(Modifier.height(28.dp))
            LinearProgressIndicator(Modifier.width(120.dp).clip(RoundedCornerShape(50)), color = Violet, trackColor = VioletSoft)
        }
    }
}

// ================================================================= onboarding
@Composable
fun OnboardingScreen(onDone: () -> Unit) {
    val pages = listOf(
        Triple(Icons.Outlined.PersonAdd, "Add students in seconds", "Photo, name and WhatsApp number — that's it. Scan a document to fill the rest.") to Violet,
        Triple(Icons.Outlined.HowToReg, "One-tap attendance", "Everyone starts present – just tap the few absentees. Parents are informed automatically.") to Green,
        Triple(Icons.Outlined.CurrencyRupee, "Fees without follow-ups", "Receipts after every payment and polite reminders before due dates.") to Orange,
    )
    var i by remember { mutableIntStateOf(0) }
    Column(Modifier.fillMaxSize().background(Brand.hero).systemBarsPadding().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = onDone) { Text("Skip", color = Violet) } }
        Spacer(Modifier.weight(1f))
        AnimatedContent(i, transitionSpec = { (slideInHorizontally { it / 3 } + fadeIn()) togetherWith (slideOutHorizontally { -it / 3 } + fadeOut()) }, label = "onb") { idx ->
            val (p, tint) = pages[idx]
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.size(150.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(104.dp).clip(CircleShape).background(tint.copy(alpha = .14f)), contentAlignment = Alignment.Center) { Icon(p.first, null, tint = tint, modifier = Modifier.size(50.dp)) }
                }
                Spacer(Modifier.height(30.dp))
                Text(p.second, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, color = Ink)
                Spacer(Modifier.height(10.dp))
                Text(p.third, color = Muted, textAlign = TextAlign.Center, fontSize = 15.sp)
            }
        }
        Spacer(Modifier.weight(1f))
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            pages.indices.forEach {
                val w by animateDpAsState(if (it == i) 24.dp else 8.dp, label = "dot")
                Box(Modifier.height(8.dp).width(w).clip(RoundedCornerShape(50)).background(if (it == i) Violet else Line))
            }
        }
        Spacer(Modifier.height(22.dp))
        GradientButton(if (i < pages.lastIndex) "Next" else "Get started", { if (i < pages.lastIndex) i++ else onDone() }, Modifier.fillMaxWidth())
    }
}

// ====================================================================== login
@Composable
fun LoginScreen(vm: AuthViewModel) {
    val ui by vm.ui.collectAsState()
    var mode by remember { mutableStateOf("login") }
    Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFFEDE7FF), Lavender, Color.White))).systemBarsPadding().imePadding()) {
        AnimatedContent(mode, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(150)) }, label = "auth") { m ->
            when (m) {
                "register" -> RegisterPage(vm) { vm.clearError(); mode = "login" }
                "track" -> TrackPage(vm) { vm.clearError(); mode = "login" }
                else -> LoginPage(vm, onRegister = { vm.clearError(); mode = "register" }, onTrack = { vm.clearError(); mode = "track" })
            }
        }
    }
    if (ui.accounts.isNotEmpty()) {
        var sel by remember { mutableStateOf(ui.accounts.first().id) }
        AlertDialog(onDismissRequest = vm::dismissAccounts, containerColor = Color.White, shape = RoundedCornerShape(24.dp), title = { Text("Choose account") },
            text = {
                Column {
                    Text("This number has more than one account.", color = Muted)
                    ui.accounts.forEach { a ->
                        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).selectable(sel == a.id) { sel = a.id }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(sel == a.id, { sel = a.id }, colors = RadioButtonDefaults.colors(selectedColor = Violet))
                            Column { Text(a.academy ?: "", fontWeight = FontWeight.Bold); Text("${roleName(a.role)} · ${a.name}", color = Muted, fontSize = 13.sp) }
                        }
                    }
                }
            },
            confirmButton = { Button(onClick = { vm.dismissAccounts(); vm.loginAs(sel) }, colors = ButtonDefaults.buttonColors(containerColor = Violet)) { Text("Continue") } },
            dismissButton = { TextButton(onClick = vm::dismissAccounts) { Text("Cancel") } })
    }
}


@Composable
private fun AuthHeader(title: String, sub: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
        Image(painterResource(R.drawable.classnex_logo), "ClassNex", Modifier.width(190.dp))
        Spacer(Modifier.height(22.dp))
        Text(title, fontSize = 25.sp, fontWeight = FontWeight.ExtraBold, color = Ink)
        Text(sub, color = Muted, textAlign = TextAlign.Center)
    }
}

@Composable
private fun LoginPage(vm: AuthViewModel, onRegister: () -> Unit, onTrack: () -> Unit) {
    val ui by vm.ui.collectAsState()
    var id by remember { mutableStateOf("") }
    var pw by remember { mutableStateOf("") }
    var forgot by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Spacer(Modifier.height(20.dp))
        AuthHeader("Welcome Back 👋", "Log in to manage your academy")
        Spacer(Modifier.height(26.dp))
        CnxCard(padding = 20.dp) {
            CnxField(id, { id = it }, "Mobile number or email", icon = Icons.Outlined.PhoneAndroid, keyboard = KeyboardType.Email)
            Spacer(Modifier.height(12.dp))
            CnxField(pw, { pw = it }, "Password", icon = Icons.Outlined.Lock, password = true)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { TextButton(onClick = { forgot = true }) { Text("Forgot password?", color = Violet) } }
            AnimatedVisibility(ui.error != null) { ui.error?.let { InfoBanner(it, Red, Icons.Outlined.ErrorOutline, Modifier.padding(bottom = 12.dp)) } }
            GradientButton("Log In", { vm.login(id, pw) }, Modifier.fillMaxWidth(), loading = ui.busy, icon = Icons.AutoMirrored.Outlined.Login)
        }
        Spacer(Modifier.height(20.dp))
        Row(verticalAlignment = Alignment.CenterVertically) { HorizontalDivider(Modifier.weight(1f), color = Line); Text("  New to ClassNex?  ", color = Muted, fontSize = 12.sp); HorizontalDivider(Modifier.weight(1f), color = Line) }
        Spacer(Modifier.height(14.dp))
        OutlinedButton(onClick = onRegister, modifier = Modifier.fillMaxWidth().height(52.dp), shape = RoundedCornerShape(16.dp), border = androidx.compose.foundation.BorderStroke(1.5.dp, Violet)) {
            Icon(Icons.Outlined.Storefront, null, tint = Violet); Spacer(Modifier.width(8.dp)); Text("Register Your Academy", color = Violet, fontWeight = FontWeight.Bold)
        }
        TextButton(onClick = onTrack, modifier = Modifier.padding(top = 6.dp)) { Icon(Icons.Outlined.TrackChanges, null, tint = Indigo600, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)); Text("Track Registration Status", color = Indigo600, fontWeight = FontWeight.SemiBold) }
        Spacer(Modifier.height(10.dp))
        Text("For Class Admins, teachers, parents and students.", color = Muted, fontSize = 12.sp, textAlign = TextAlign.Center)
    }
    if (forgot) {
        var fid by remember { mutableStateOf(id) }
        AlertDialog(onDismissRequest = { forgot = false }, containerColor = Color.White, shape = RoundedCornerShape(24.dp), title = { Text("Reset password") },
            text = { Column { Text("We'll send a reset link to your registered WhatsApp / email.", color = Muted); Spacer(Modifier.height(10.dp)); CnxField(fid, { fid = it }, "Mobile or email"); ui.info?.let { Spacer(Modifier.height(8.dp)); InfoBanner(it, Green) } } },
            confirmButton = { Button(onClick = { vm.forgot(fid) }, enabled = fid.isNotBlank(), colors = ButtonDefaults.buttonColors(containerColor = Violet)) { Text("Send link") } },
            dismissButton = { TextButton(onClick = { forgot = false }) { Text("Close") } })
    }
}

@Composable
private fun RegisterPage(vm: AuthViewModel, back: () -> Unit) {
    val ui by vm.ui.collectAsState()
    var name by remember { mutableStateOf("") }
    var mobile by remember { mutableStateOf("") }
    var academy by remember { mutableStateOf("") }
    var city by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var terms by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf<String?>(null) }
    androidx.activity.compose.BackHandler(onBack = back)
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp)) {
        IconButton(onClick = back) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", tint = Ink) }
        AuthHeader("Register Your Academy", "Free trial after approval by the ClassNex team")
        Spacer(Modifier.height(20.dp))
        if (done != null) {
            CnxCard(padding = 20.dp) {
                IconCircle(Icons.Outlined.CheckCircle, Green, 56.dp, 30.dp)
                Spacer(Modifier.height(12.dp))
                Text("Registration received", fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Ink)
                Text(done ?: "", color = Muted)
                Spacer(Modifier.height(16.dp))
                GradientButton("Back to Log In", back, Modifier.fillMaxWidth())
            }
            return@Column
        }
        CnxCard(padding = 20.dp) {
            CnxField(name, { name = it }, "Your name *", icon = Icons.Outlined.Person)
            Spacer(Modifier.height(10.dp))
            CnxField(mobile, { mobile = it.filter(Char::isDigit).take(10) }, "Mobile (used to log in) *", icon = Icons.Outlined.PhoneAndroid, keyboard = KeyboardType.Phone, prefix = "+91 ")
            Spacer(Modifier.height(10.dp))
            CnxField(academy, { academy = it }, "Academy / class name *", icon = Icons.Outlined.Storefront)
            Spacer(Modifier.height(10.dp))
            CnxField(city, { city = it }, "City", icon = Icons.Outlined.LocationCity)
            Spacer(Modifier.height(10.dp))
            CnxField(email, { email = it }, "Email", icon = Icons.Outlined.Email, keyboard = KeyboardType.Email)
            Spacer(Modifier.height(10.dp))
            CnxField(password, { password = it }, "Create password *", icon = Icons.Outlined.Lock, password = true, supporting = "8+ characters with letters and numbers")
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { terms = !terms }, verticalAlignment = Alignment.CenterVertically) {
                Checkbox(terms, { terms = it }, colors = CheckboxDefaults.colors(checkedColor = Violet)); Text("I agree to the Terms and Privacy Policy", fontSize = 13.sp, color = Ink)
            }
            AnimatedVisibility(ui.error != null) { ui.error?.let { InfoBanner(it, Red, Icons.Outlined.ErrorOutline, Modifier.padding(vertical = 8.dp)) } }
            Spacer(Modifier.height(8.dp))
            GradientButton("Register", { vm.register(RegisterAcademy(name.trim(), mobile, academy.trim(), password, city.ifBlank { null }, email.ifBlank { null })) { msg -> done = msg } },
                Modifier.fillMaxWidth(), enabled = terms && name.isNotBlank() && mobile.length == 10 && academy.isNotBlank() && password.length >= 8, loading = ui.busy)
        }
    }
}

@Composable
private fun TrackPage(vm: AuthViewModel, back: () -> Unit) {
    val ui by vm.ui.collectAsState()
    var id by remember { mutableStateOf("") }
    var pw by remember { mutableStateOf("") }
    var res by remember { mutableStateOf<RegStatus?>(null) }
    var msg by remember { mutableStateOf<String?>(null) }
    androidx.activity.compose.BackHandler(onBack = back)
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp)) {
        IconButton(onClick = back) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", tint = Ink) }
        AuthHeader("Track Registration", "Enter the mobile number and password you registered with")
        Spacer(Modifier.height(20.dp))
        CnxCard(padding = 20.dp) {
            CnxField(id, { id = it }, "Mobile number or email", icon = Icons.Outlined.PhoneAndroid, keyboard = KeyboardType.Email)
            Spacer(Modifier.height(10.dp))
            CnxField(pw, { pw = it }, "Password", icon = Icons.Outlined.Lock, password = true)
            Spacer(Modifier.height(14.dp))
            GradientButton("Check Status", { vm.trackStatus(id, pw) { r, m -> res = r; msg = m } }, Modifier.fillMaxWidth(), enabled = id.isNotBlank() && pw.isNotBlank(), loading = ui.busy, icon = Icons.Outlined.Search)
        }
        Spacer(Modifier.height(16.dp))
        AnimatedVisibility(msg != null, enter = fadeIn() + expandVertically()) {
            val r = res
            CnxCard(padding = 18.dp) {
                if (r == null) InfoBanner(msg ?: "", Red, Icons.Outlined.ErrorOutline)
                else {
                    val (icon, color) = when (r.status) { "approved" -> Icons.Outlined.Verified to Green; "rejected" -> Icons.Outlined.Cancel to Red; else -> Icons.Outlined.HourglassTop to Amber }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        IconCircle(icon, color, 52.dp, 26.dp)
                        Column(Modifier.padding(start = 12.dp)) {
                            Text(r.academy_name, fontWeight = FontWeight.ExtraBold, color = Ink)
                            Pill(when (r.status) { "approved" -> "Approved"; "rejected" -> "Not approved"; else -> "Waiting for approval" }, color)
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(msg ?: "", color = Ink)
                    KeyRow("Registered on", r.registered_on?.let(::prettyDate))
                    KeyRow("Approved on", r.approved_on?.let(::prettyDate))
                    if (r.status == "approved") { Spacer(Modifier.height(10.dp)); GradientButton("Go to Log In", back, Modifier.fillMaxWidth()) }
                }
            }
        }
    }
}
