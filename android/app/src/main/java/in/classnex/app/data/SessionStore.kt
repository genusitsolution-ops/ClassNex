package `in`.classnex.app.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import javax.inject.Inject
import javax.inject.Singleton

/** Token and small preferences, encrypted with an Android Keystore key. */
@Singleton
class SessionStore @Inject constructor(@ApplicationContext context: Context) {
    private val prefs: SharedPreferences = try {
        EncryptedSharedPreferences.create(
            context, "classnex_secure",
            MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (e: Exception) {
        // Keystore corruption (rare, e.g. after device restore): start clean rather than crash.
        context.deleteSharedPreferences("classnex_secure")
        context.getSharedPreferences("classnex_fallback", Context.MODE_PRIVATE)
    }

    var token: String?
        get() = prefs.getString("token", null)
        set(v) { prefs.edit().putString("token", v).apply() }
    var role: String?
        get() = prefs.getString("role", null)
        set(v) { prefs.edit().putString("role", v).apply() }
    var selectedChild: Long
        get() = prefs.getLong("child", 0)
        set(v) { prefs.edit().putLong("child", v).apply() }
    var onboarded: Boolean
        get() = prefs.getBoolean("onboarded", false)
        set(v) { prefs.edit().putBoolean("onboarded", v).apply() }
    var pendingFcmToken: String?
        get() = prefs.getString("fcm", null)
        set(v) { prefs.edit().putString("fcm", v).apply() }

    fun clear() {
        prefs.edit().remove("token").remove("role").remove("child").apply()
    }
}

/** App-wide events: emitted when the server says the session is no longer valid. */
@Singleton
class SessionEvents @Inject constructor() {
    private val _expired = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    val expired: SharedFlow<Unit> = _expired
    fun sessionExpired() { _expired.tryEmit(Unit) }
}
