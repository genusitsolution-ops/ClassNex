package `in`.classnex.app.data

import android.content.Context
import android.net.Uri
import `in`.classnex.app.BuildConfig
import `in`.classnex.app.data.net.ApiResponse
import `in`.classnex.app.data.net.ApiService
import `in`.classnex.app.di.AppJson
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.OkHttpClient
import okhttp3.Request
import retrofit2.HttpException
import java.io.File
import java.io.IOException
import javax.inject.Inject
import javax.inject.Singleton

/** A failed call with a user-friendly message and optional field errors. */
class ApiError(message: String, val status: Int = 0, val fieldErrors: Map<String, String> = emptyMap(), val code: String? = null) : Exception(message)

/** Runs an API call and unwraps {success, data}; maps every failure to ApiError. */
suspend fun <T> call(block: suspend () -> ApiResponse<T>): ApiResponse<T> = withContext(Dispatchers.IO) {
    try {
        val r = block()
        if (!r.success) throw ApiError(r.message.ifBlank { "Request failed" }, 200, r.errors ?: emptyMap(), r.code)
        r
    } catch (e: HttpException) {
        val raw = e.response()?.errorBody()?.string()
        val obj = runCatching { AppJson.parseToJsonElement(raw ?: "").jsonObject }.getOrNull()
        val msg = obj?.get("message")?.jsonPrimitive?.content ?: "Server error (${e.code()})"
        val errs = runCatching { obj?.get("errors")?.jsonObject?.mapValues { it.value.jsonPrimitive.content } }.getOrNull() ?: emptyMap()
        throw ApiError(msg, e.code(), errs, obj?.get("code")?.jsonPrimitive?.content)
    } catch (e: IOException) {
        throw ApiError("No internet connection. Please check your network and try again.")
    } catch (e: ApiError) {
        throw e
    } catch (e: Exception) {
        throw ApiError(e.message ?: "Something went wrong")
    }
}

@Singleton
class Repository @Inject constructor(
    val api: ApiService,
    val session: SessionStore,
    private val client: OkHttpClient,
    @ApplicationContext private val context: Context,
) {
    /** Downloads an authenticated file (receipt PDF) into the cache and returns it. */
    suspend fun download(path: String, fileName: String): File = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(BuildConfig.API_BASE_URL + path.trimStart('/')).build()
        client.newCall(req).execute().use { res ->
            if (!res.isSuccessful) throw ApiError(if (res.code == 404) "File not found" else "Download failed (${res.code})", res.code)
            val dir = File(context.cacheDir, "receipts").apply { mkdirs() }
            val out = File(dir, fileName.replace(Regex("[^A-Za-z0-9._-]"), "-"))
            res.body!!.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } }
            out
        }
    }

    /** Copies a content Uri (gallery / camera) into the cache and returns the file. */
    suspend fun copyToCache(uri: Uri, name: String): File = withContext(Dispatchers.IO) {
        val dir = File(context.cacheDir, "camera").apply { mkdirs() }
        val f = File(dir, name)
        context.contentResolver.openInputStream(uri)?.use { i -> f.outputStream().use { i.copyTo(it) } } ?: throw ApiError("Could not read the selected file")
        f
    }
}
