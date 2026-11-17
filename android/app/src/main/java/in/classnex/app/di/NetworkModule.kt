package `in`.classnex.app.di

import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.SessionEvents
import `in`.classnex.app.data.SessionStore
import `in`.classnex.app.data.net.ApiService
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import kotlinx.serialization.json.Json
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import java.util.concurrent.TimeUnit
import javax.inject.Singleton

val AppJson = Json {
    ignoreUnknownKeys = true
    coerceInputValues = true
    explicitNulls = false
    isLenient = true
    encodeDefaults = true
}

@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {
    @Provides @Singleton
    fun okHttp(session: SessionStore, events: SessionEvents): OkHttpClient {
        val auth = Interceptor { chain ->
            val req = chain.request().newBuilder().header("Accept", "application/json").header("User-Agent", "ClassNex-Android/${BuildConfig.VERSION_NAME}")
            session.token?.let { req.header("Authorization", "Bearer $it") }
            val res = chain.proceed(req.build())
            // token expired / revoked → force re-login (but not for the login call itself)
            if (res.code == 401 && session.token != null && !chain.request().url.encodedPath.endsWith("auth/login")) {
                session.clear()
                events.sessionExpired()
            }
            res
        }
        return OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .writeTimeout(60, TimeUnit.SECONDS)
            .addInterceptor(auth)
            .apply {
                if (BuildConfig.DEBUG) addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
            }
            .build()
    }

    @Provides @Singleton
    fun retrofit(client: OkHttpClient): Retrofit = Retrofit.Builder()
        .baseUrl(BuildConfig.API_BASE_URL)
        .client(client)
        .addConverterFactory(AppJson.asConverterFactory("application/json".toMediaType()))
        .build()

    @Provides @Singleton
    fun api(retrofit: Retrofit): ApiService = retrofit.create(ApiService::class.java)
}
