package `in`.classnex.app.fcm

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import `in`.classnex.app.MainActivity
import `in`.classnex.app.R
import `in`.classnex.app.data.SessionStore
import `in`.classnex.app.data.call
import `in`.classnex.app.data.net.ApiService
import `in`.classnex.app.data.net.DeviceRequest
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import javax.inject.Inject
import javax.inject.Singleton

const val CHANNEL_ID = "classnex_default"

@AndroidEntryPoint
class ClassNexMessagingService : FirebaseMessagingService() {
    @Inject lateinit var registrar: PushRegistrar

    override fun onNewToken(token: String) {
        registrar.onToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val title = message.notification?.title ?: message.data["title"] ?: getString(R.string.app_name)
        val body = message.notification?.body ?: message.data["body"] ?: return
        showNotification(this, title, body)
    }
}

fun showNotification(context: Context, title: String, body: String) {
    val intent = PendingIntent.getActivity(context, 0, Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP), PendingIntent.FLAG_IMMUTABLE)
    val n = NotificationCompat.Builder(context, CHANNEL_ID)
        .setSmallIcon(R.drawable.ic_notification)
        .setContentTitle(title)
        .setContentText(body)
        .setStyle(NotificationCompat.BigTextStyle().bigText(body))
        .setAutoCancel(true)
        .setContentIntent(intent)
        .setPriority(NotificationCompat.PRIORITY_HIGH)
        .build()
    (context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager).notify((System.currentTimeMillis() % Int.MAX_VALUE).toInt(), n)
}

/** Registers the FCM token with the backend after login. Safe when Firebase is not configured. */
@Singleton
class PushRegistrar @Inject constructor(private val api: ApiService, private val session: SessionStore) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun onToken(token: String) {
        session.pendingFcmToken = token
        if (session.token != null) scope.launch { runCatching { call { api.registerDevice(DeviceRequest(token)) } } }
    }

    fun register(context: Context) {
        if (FirebaseApp.getApps(context).isEmpty()) return // google-services.json not supplied at build time
        runCatching { FirebaseMessaging.getInstance().token.addOnSuccessListener { onToken(it) } }
    }
}
