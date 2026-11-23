package `in`.classnex.app.data.net

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.QueryMap
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.DELETE
import retrofit2.http.Url
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/** The same REST API v1 used by the web panels. */
interface ApiService {
    // auth
    @POST("auth/login") suspend fun login(@Body body: LoginRequest): ApiResponse<LoginData>
    @GET("auth/me") suspend fun me(): ApiResponse<Me>
    @POST("auth/logout") suspend fun logout(@Body body: Map<String, String>): ApiResponse<Unit?>
    @POST("public/register-owner") suspend fun registerAcademy(@Body body: RegisterAcademy): ApiResponse<RegisterResult>
    @POST("public/registration-status") suspend fun registrationStatus(@Body body: RegStatusRequest): ApiResponse<RegStatus>
    @POST("auth/forgot-password") suspend fun forgot(@Body body: ForgotRequest): ApiResponse<Unit?>
    @POST("auth/change-password") suspend fun changePassword(@Body body: ChangePassword): ApiResponse<Unit?>
    @POST("devices") suspend fun registerDevice(@Body body: DeviceRequest): ApiResponse<Unit?>
    @GET("notifications") suspend fun notifications(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50): ApiResponse<List<AppNotification>>
    @POST("notifications/read") suspend fun markRead(@Body body: ReadRequest): ApiResponse<Unit?>

    // academy (owner / teacher)
    @GET("dashboard") suspend fun dashboard(@Query("branch_id") branchId: Long? = null): ApiResponse<Dashboard>
    @GET("activities") suspend fun activities(@Query("status") status: String = "active"): ApiResponse<List<Activity>>
    @GET("batches") suspend fun batches(@Query("status") status: String = "active"): ApiResponse<List<Batch>>
    @GET("students") suspend fun students(@Query("q") q: String?, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50, @Query("dues") dues: String? = null, @Query("branch_id") branchId: String? = null, @Query("not_branch_id") notBranchId: Long? = null, @Query("status") status: String? = null, @Query("activity_id") activityId: Long? = null, @Query("batch_id") batchId: Long? = null): ApiResponse<List<StudentRow>>
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
    @GET("admin/academies/{id}") suspend fun adminAcademy(@Path("id") id: Long): ApiResponse<kotlinx.serialization.json.JsonObject>
    @GET("admin/owners") suspend fun owners(@Query("q") q: String? = null, @Query("per_page") perPage: Int = 100): ApiResponse<List<OwnerRow>>

    // branches (unlimited; one academy-wide student quota)
    @GET("branches") suspend fun branches(@Query("status") status: String? = null): ApiResponse<List<Branch>>
    @GET("branches/summary") suspend fun branchSummary(@Query("from") from: String? = null, @Query("to") to: String? = null): ApiResponse<BranchSummary>
    @GET("branches/{id}") suspend fun branch(@Path("id") id: Long, @Query("from") from: String? = null, @Query("to") to: String? = null): ApiResponse<BranchDetail>
    @POST("branches") suspend fun createBranch(@Body body: BranchSave): ApiResponse<SavedId>
    @PUT("branches/{id}") suspend fun updateBranch(@Path("id") id: Long, @Body body: BranchSave): ApiResponse<SavedId>
    @GET("teachers") suspend fun teachers(@Query("status") status: String = "active"): ApiResponse<List<TeacherRow>>
    @POST("students/{id}/branch") suspend fun changeBranch(@Path("id") id: Long, @Body body: ChangeBranch): ApiResponse<StudentDetail>
    @POST("students/bulk-branch") suspend fun bulkBranch(@Body body: BulkBranch): ApiResponse<BulkBranchResult>
    @POST("students/{id}/link") suspend fun linkExisting(@Path("id") id: Long, @Body body: LinkExisting): ApiResponse<StudentDetail>

    // attendance history: filters date/from/to/branch_id/activity_id/batch_id/student_id/status, view=sessions|students|branches
    @GET("attendance/history") suspend fun attendanceHistory(@QueryMap filters: Map<String, String>): ApiResponse<List<HistoryRow>>

    // reports (branch_id filter; combined report = branch_summary)
    @GET("reports/{name}") suspend fun report(@Path("name") name: String, @QueryMap filters: Map<String, String>): ApiResponse<ReportData>

    // WhatsApp click-to-send (manual mode) – the user sends from WhatsApp; history is kept on the server
    @POST("whatsapp/manual/prepare") suspend fun waPrepare(@Body body: ManualPrepare): ApiResponse<ManualBatch>
    @POST("whatsapp/manual/{id}/opened") suspend fun waOpened(@Path("id") id: Long, @Body body: Map<String, String> = emptyMap()): ApiResponse<Unit?>
    // generic JSON access for the configurable module screens (activities, batches, teachers, fee plans, …)
    @GET suspend fun getJson(@Url url: String, @QueryMap query: Map<String, String> = emptyMap()): ApiResponse<JsonElement>
    @POST suspend fun postJson(@Url url: String, @Body body: JsonObject = JsonObject(emptyMap())): ApiResponse<JsonElement>
    @PUT suspend fun putJson(@Url url: String, @Body body: JsonObject): ApiResponse<JsonElement>
    @DELETE suspend fun deleteJson(@Url url: String): ApiResponse<JsonElement>
    @Multipart @POST suspend fun postMultipart(@Url url: String, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part file: MultipartBody.Part?): ApiResponse<JsonElement>

    @GET("whatsapp/manual/log") suspend fun waLog(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 50, @Query("purpose") purpose: String? = null): ApiResponse<List<ManualLogRow>>
}
