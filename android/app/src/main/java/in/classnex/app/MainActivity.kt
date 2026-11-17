package `in`.classnex.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.hilt.navigation.compose.hiltViewModel
import `in`.classnex.app.data.SessionEvents
import `in`.classnex.app.ui.auth.AuthViewModel
import `in`.classnex.app.ui.auth.LoginScreen
import `in`.classnex.app.ui.auth.OnboardingScreen
import `in`.classnex.app.ui.auth.SplashScreen
import `in`.classnex.app.ui.nav.HomeScaffold
import `in`.classnex.app.ui.theme.ClassNexTheme
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {
    @Inject lateinit var events: SessionEvents

    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            ClassNexTheme {
                val auth: AuthViewModel = hiltViewModel()
                val ui by auth.ui.collectAsState()
                var onboarded by remember { mutableStateOf(auth.onboarded) }
                LaunchedEffect(Unit) { events.expired.collect { auth.expired() } }
                when {
                    ui.checking -> SplashScreen()
                    ui.me != null -> HomeScaffold(ui.me!!, onLogout = auth::logout)
                    !onboarded -> OnboardingScreen { auth.finishOnboarding(); onboarded = true }
                    else -> LoginScreen(auth)
                }
            }
        }
    }
}
