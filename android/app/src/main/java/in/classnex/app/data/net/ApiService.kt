package `in`.classnex.app.data.net

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/** The same REST API v1 used by the web panels. */
interface ApiService {
    // auth
    @POST("auth/login") suspend fun login(@Body body: LoginRequest): ApiResponse<LoginData>
    @GET("auth/me") suspend fun me(): ApiResponse<Me>
    @POST("auth/logout") suspend fun logout(@Body body: Map<String, String>): ApiResponse<Unit?>
    @POST("auth/forgot-password") suspend fun forgot(@Body body: ForgotRequest): ApiResponse<Unit?>
    @POST("auth/change-password") suspend fun changePassword(@Body body: ChangePassword): ApiResponse<Unit?>
    @POST("devices") suspend fun registerDevice(@Body body: DeviceRequest): ApiResponse<Unit?>
    @GET("notifications") suspend fun notifications(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50): ApiResponse<List<AppNotification>>
    @POST("notifications/read") suspend fun markRead(@Body body: ReadRequest): ApiResponse<Unit?>

    // academy (owner / teacher)
    @GET("dashboard") suspend fun dashboard(): ApiResponse<Dashboard>
    @GET("activities") suspend fun activities(@Query("status") status: String = "active"): ApiResponse<List<Activity>>
    @GET("batches") suspend fun batches(@Query("status") status: String = "active"): ApiResponse<List<Batch>>
    @GET("students") suspend fun students(@Query("q") q: String?, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50, @Query("dues") dues: String? = null): ApiResponse<List<StudentRow>>
    @GET("students/{id}") suspend fun student(@Path("id") id: Long): ApiResponse<StudentDetail>
    @Multipart @POST("students") suspend fun createStudent(@PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part photo: MultipartBody.Part?): ApiResponse<CreateStudentData>
    @Multipart @POST("students/{id}/documents") suspend fun uploadDocument(@Path("id") id: Long, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part file: MultipartBody.Part): ApiResponse<Map<String, Long>>
    @POST("ocr/parse") suspend fun ocrParse(@Body body: OcrText): ApiResponse<OcrResult>

    @GET("attendance/today") suspend fun attendanceToday(@Query("date") date: String? = null): ApiResponse<TodayAtt>
    @GET("attendance/sheet") suspend fun attendanceSheet(@Query("batch_id") batchId: Long, @Query("date") date: String): ApiResponse<AttSheet>
    @POST("attendance") suspend fun saveAttendance(@Body body: AttSave): ApiResponse<AttResult>
    @GET("students/{id}/attendance") suspend fun studentAttendance(@Path("id") id: Long, @Query("month") month: String): ApiResponse<AttMonth>

    @GET("students/{id}/dues") suspend fun dues(@Path("id") id: Long): ApiResponse<Dues>
    @POST("payments") suspend fun pay(@Header("Idempotency-Key") key: String, @Body body: PaymentRequest): ApiResponse<PaymentResult>
    @GET("payments") suspend fun payments(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50, @Query("q") q: String? = null): ApiResponse<List<PaymentRow>>
    @POST("payments/{id}/share") suspend fun shareReceipt(@Path("id") id: Long, @Body body: Map<String, String>): ApiResponse<ShareLink>
    @GET("invoices") suspend fun invoices(@Query("status") status: String = "open", @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50, @Query("q") q: String? = null): ApiResponse<List<Invoice>>
    @POST("messages/send") suspend fun sendMessage(@Body body: MessageRequest): ApiResponse<Map<String, Long>>

    // portal (parent / student)
    @GET("portal/students/{id}/dashboard") suspend fun portalDashboard(@Path("id") id: Long): ApiResponse<PortalDashboard>
    @GET("portal/students/{id}/fees") suspend fun portalFees(@Path("id") id: Long): ApiResponse<PortalFees>

    // super admin
    @GET("admin/dashboard") suspend fun adminDashboard(): ApiResponse<AdminDashboard>
    @GET("admin/owners") suspend fun owners(@Query("q") q: String? = null, @Query("per_page") perPage: Int = 100): ApiResponse<List<OwnerRow>>
}
