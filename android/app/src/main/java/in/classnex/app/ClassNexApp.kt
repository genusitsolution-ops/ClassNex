package `in`.classnex.app

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import `in`.classnex.app.fcm.CHANNEL_ID
import coil.ImageLoader
import coil.ImageLoaderFactory
import dagger.hilt.android.HiltAndroidApp
import okhttp3.OkHttpClient
import javax.inject.Inject

@HiltAndroidApp
class ClassNexApp : Application(), ImageLoaderFactory {
    @Inject lateinit var client: OkHttpClient

    override fun onCreate() {
        super.onCreate()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val ch = NotificationChannel(CHANNEL_ID, "ClassNex updates", NotificationManager.IMPORTANCE_HIGH).apply { description = "Attendance, fees, receipts and announcements" }
            getSystemService(NotificationManager::class.java).createNotificationChannel(ch)
        }
    }

    // Student photos are private: Coil uses the authenticated OkHttp client.
    override fun newImageLoader(): ImageLoader = ImageLoader.Builder(this).okHttpClient(client).crossfade(true).build()
}
