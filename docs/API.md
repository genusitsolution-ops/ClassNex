# ClassNex REST API v1

One API serves the website, all web panels and the Android app. Base URL: `https://YOUR-DOMAIN/api/v1/`.

## Conventions

**Response envelope** (every endpoint):

```json
{ "success": true, "message": "Payment recorded. Receipt RCPT/2026-27/00042 generated.", "data": { … }, "meta": { "page": 1, "per_page": 25, "total": 140, "pages": 6 } }
```

Errors use HTTP status codes plus a machine-readable `code` and, for validation, per-field `errors`:

```json
{ "success": false, "message": "Enter a valid 10-digit Indian mobile number.", "code": "validation", "errors": { "whatsapp": "Enter a valid 10-digit Indian mobile number." } }
```

| Status | Meaning |
|---|---|
| 401 `unauthenticated` | missing / expired / revoked token → log in again |
| 402 `feature_locked` / `limit_reached` / `subscription_expired` | the academy's plan does not allow this (backend-enforced) |
| 403 `forbidden` | role / permission does not allow this |
| 404 `not_found` | record does not exist **or belongs to another academy / family** (tenant isolation never reveals existence) |
| 409 | conflict (e.g. `duplicate_student`, invoice already paid) |
| 419 `csrf` | web session request without a valid `X-CSRF-Token` |
| 422 `validation` | field errors in `errors` |
| 429 `rate_limited` | too many requests / login attempts |

**Authentication**

- *Android / integrations*: `POST /auth/login` with `{"identifier": "9876543210" | "email", "password": "…", "client": "android", "device_name": "…"}` → `data.token`. Send `Authorization: Bearer <token>` on every call. Tokens are stored hashed (SHA-256), expire after 30 days (configurable) and are revoked on logout or password change.
- If one mobile number has several accounts (e.g. parent in two academies), login returns `data.choose_account = true` and `data.accounts`; repeat the call with `account_id`.
- *Web panels*: `client: "web"` starts an HTTP-only session cookie; every non-GET request must carry `X-CSRF-Token`.

**Money** is always integer **paise** (`amount_paise: 150000` = ₹1,500.00). Inputs named `amount` accept rupees as text (`"1500"`, `"1500.50"`).

**Idempotency** – `POST /payments` accepts an `Idempotency-Key` header. Re-sending the same key returns the original payment (`duplicate: true`) instead of charging twice. The Android app and web panel generate one key per payment screen.

**Pagination** – list endpoints accept `page` and `per_page` (max 200) and return `meta`.

**Files** – uploads are `multipart/form-data` (`photo`, `file`, `logo`). Private files (photos, documents, receipts) are only served through authenticated endpoints; receipts also have an unguessable public share link (`/receipt.php?t=…`).

## Common flows

```http
POST /api/v1/students               (multipart) name, whatsapp, activity_id, batch_id?, guardian_name, is_minor, photo?
POST /api/v1/students/{id}/documents (multipart) file, doc_type, ocr_fields?
POST /api/v1/ocr/parse              {"text": "<text read on device>"} → suggested name/dob/address (Aadhaar masked)
GET  /api/v1/attendance/sheet?batch_id=3&date=2026-11-17
POST /api/v1/attendance             {"batch_id":3,"date":"2026-11-17","entries":[{"student_id":12,"status":"present"}]}
GET  /api/v1/students/{id}/dues
POST /api/v1/payments               {"student_id":12,"amount":"1500","method":"upi","reference":"…","invoice_ids":[44]}
GET  /api/v1/receipts/{id}/pdf
POST /api/v1/messages/send          {"target":"batch","batch_id":3,"message":"…","channels":["whatsapp","inapp","push"],"client_ref":"uuid"}
```

## Webhooks

| URL | Purpose |
|---|---|
| `GET/POST /api/v1/webhooks/whatsapp` | Meta verification handshake; delivery statuses (sent/delivered/read/failed); STOP / START opt-out. Verified with `X-Hub-Signature-256` once the App Secret is saved. |
| `POST /api/v1/webhooks/razorpay/{academyId}` | Student fee payments (academy's own Razorpay account) |
| `POST /api/v1/webhooks/razorpay/platform` | ClassNex subscription payments |

Razorpay webhooks are verified with `X-Razorpay-Signature`; the order amount is re-checked before a payment is recorded.

## Endpoints

Generated from the router (`php cli/api-docs.php`). *Permission* applies to teachers (owners hold every permission); *Plan feature* must be in the academy's package.

<!-- ROUTES -->

| Group | Method | Path | Access | Permission | Plan feature |
|---|---|---|---|---|---|
| admin | GET | `/api/v1/admin/dashboard` | super_admin |  |  |
| admin | GET | `/api/v1/admin/owners` | super_admin |  |  |
| admin | POST | `/api/v1/admin/owners` | super_admin |  |  |
| admin | GET | `/api/v1/admin/academies/{id}` | super_admin |  |  |
| admin | PUT | `/api/v1/admin/academies/{id}` | super_admin |  |  |
| admin | POST | `/api/v1/admin/academies/{id}/invite` | super_admin |  |  |
| admin | GET | `/api/v1/admin/packages` | super_admin |  |  |
| admin | POST | `/api/v1/admin/packages` | super_admin |  |  |
| admin | PUT | `/api/v1/admin/packages/{id}` | super_admin |  |  |
| admin | POST | `/api/v1/admin/academies/{id}/subscription` | super_admin |  |  |
| admin | GET | `/api/v1/admin/subscription-payments` | super_admin |  |  |
| admin | POST | `/api/v1/admin/subscription-payments` | super_admin |  |  |
| admin | GET | `/api/v1/admin/integrations` | super_admin |  |  |
| admin | POST | `/api/v1/admin/integrations/{name}` | super_admin |  |  |
| admin | POST | `/api/v1/admin/integrations/{name}/test` | super_admin |  |  |
| admin | GET | `/api/v1/admin/templates` | super_admin |  |  |
| admin | PUT | `/api/v1/admin/templates/{id}` | super_admin |  |  |
| admin | GET | `/api/v1/admin/reminder-rules` | super_admin |  |  |
| admin | POST | `/api/v1/admin/reminder-rules` | super_admin |  |  |
| admin | GET | `/api/v1/admin/queue` | super_admin |  |  |
| admin | GET | `/api/v1/admin/queue/{id}/logs` | super_admin |  |  |
| admin | POST | `/api/v1/admin/queue/action` | super_admin |  |  |
| admin | GET | `/api/v1/admin/cms` | super_admin |  |  |
| admin | POST | `/api/v1/admin/cms/settings` | super_admin |  |  |
| admin | GET | `/api/v1/admin/cms/pages/{id}` | super_admin |  |  |
| admin | POST | `/api/v1/admin/cms/pages` | super_admin |  |  |
| admin | POST | `/api/v1/admin/testimonials` | super_admin |  |  |
| admin | DELETE | `/api/v1/admin/testimonials/{id}` | super_admin |  |  |
| admin | GET | `/api/v1/admin/enquiries` | super_admin |  |  |
| admin | PUT | `/api/v1/admin/enquiries/{id}` | super_admin |  |  |
| admin | GET | `/api/v1/admin/support` | super_admin |  |  |
| admin | GET | `/api/v1/admin/audit-logs` | super_admin |  |  |
| admin | GET | `/api/v1/admin/settings` | super_admin |  |  |
| admin | POST | `/api/v1/admin/settings` | super_admin |  |  |
| admin | GET | `/api/v1/admin/health` | super_admin |  |  |
| admin | POST | `/api/v1/admin/backups` | super_admin |  |  |
| admin | GET | `/api/v1/admin/backups/{name}` | super_admin |  |  |
| auth | POST | `/api/v1/auth/login` | public |  |  |
| auth | POST | `/api/v1/auth/logout` | any logged-in user |  |  |
| auth | GET | `/api/v1/auth/me` | any logged-in user |  |  |
| auth | POST | `/api/v1/auth/forgot-password` | public |  |  |
| auth | POST | `/api/v1/auth/activate` | public |  |  |
| auth | POST | `/api/v1/auth/change-password` | any logged-in user |  |  |
| auth | PUT | `/api/v1/auth/profile` | any logged-in user |  |  |
| auth | POST | `/api/v1/devices` | any logged-in user |  |  |
| auth | GET | `/api/v1/notifications` | any logged-in user |  |  |
| auth | POST | `/api/v1/notifications/read` | any logged-in user |  |  |
| files | GET | `/api/v1/files/student-photo/{id}` | any logged-in user |  |  |
| files | GET | `/api/v1/files/teacher-photo/{id}` | owner, teacher |  |  |
| files | GET | `/api/v1/files/admission-photo/{id}` | owner |  |  |
| files | GET | `/api/v1/files/document/{id}` | owner, teacher |  |  |
| files | GET | `/api/v1/files/admission-document/{id}` | owner |  |  |
| files | GET | `/api/v1/receipts/{id}/pdf` | owner, teacher, parent, student |  |  |
| owner attendance | GET | `/api/v1/attendance/sheet` | owner, teacher | attendance.mark | attendance |
| owner attendance | POST | `/api/v1/attendance` | owner, teacher | attendance.mark | attendance |
| owner attendance | GET | `/api/v1/attendance/today` | owner, teacher |  | attendance |
| owner attendance | GET | `/api/v1/attendance/history` | owner, teacher |  | attendance |
| owner attendance | GET | `/api/v1/students/{id}/attendance` | owner, teacher, parent, student |  |  |
| owner attendance | GET | `/api/v1/holidays` | owner, teacher, parent, student |  |  |
| owner attendance | POST | `/api/v1/holidays` | owner | settings.manage |  |
| owner attendance | DELETE | `/api/v1/holidays/{id}` | owner | settings.manage |  |
| owner attendance | POST | `/api/v1/batches/{id}/cancel-class` | owner, teacher | attendance.mark |  |
| owner fees | GET | `/api/v1/fee-structures` | owner, teacher | fees.view | fees |
| owner fees | POST | `/api/v1/fee-structures` | owner, teacher | fees.manage | fees |
| owner fees | PUT | `/api/v1/fee-structures/{id}` | owner, teacher | fees.manage | fees |
| owner fees | POST | `/api/v1/fee-structures/{id}/apply` | owner | fees.manage | fees |
| owner fees | GET | `/api/v1/discounts` | owner, teacher | fees.view | fees |
| owner fees | POST | `/api/v1/discounts` | owner | fees.manage | fees |
| owner fees | PUT | `/api/v1/discounts/{id}` | owner | fees.manage | fees |
| owner fees | POST | `/api/v1/students/{id}/fees` | owner, teacher | fees.manage | fees |
| owner fees | PUT | `/api/v1/fee-assignments/{id}` | owner | fees.manage | fees |
| owner fees | GET | `/api/v1/invoices` | owner, teacher | fees.view | fees |
| owner fees | GET | `/api/v1/invoices/{id}` | owner, teacher | fees.view | fees |
| owner fees | POST | `/api/v1/students/{id}/charges` | owner, teacher | fees.manage | fees |
| owner fees | POST | `/api/v1/invoices/{id}/cancel` | owner | fees.manage | fees |
| owner fees | GET | `/api/v1/students/{id}/dues` | owner, teacher | fees.view | fees |
| owner fees | POST | `/api/v1/payments` | owner, teacher | fees.collect | fees |
| owner fees | GET | `/api/v1/payments` | owner, teacher | fees.view | fees |
| owner fees | GET | `/api/v1/payments/{id}` | owner, teacher | fees.view | fees |
| owner fees | POST | `/api/v1/payments/{id}/reverse` | owner, teacher | payments.reverse | fees |
| owner fees | POST | `/api/v1/payments/{id}/refund` | owner, teacher | payments.reverse | fees |
| owner fees | POST | `/api/v1/payments/{id}/share` | owner, teacher | fees.view | fees |
| owner fees | GET | `/api/v1/fees/summary` | owner, teacher | fees.view | fees |
| owner misc | GET | `/api/v1/dashboard` | owner, teacher |  |  |
| owner misc | POST | `/api/v1/messages/send` | owner, teacher | notifications.send |  |
| owner misc | GET | `/api/v1/messages/log` | owner | notifications.send |  |
| owner misc | POST | `/api/v1/messages/retry` | owner | notifications.send |  |
| owner misc | GET | `/api/v1/notification-settings` | owner |  |  |
| owner misc | POST | `/api/v1/notification-settings` | owner | settings.manage |  |
| owner misc | POST | `/api/v1/templates` | owner | settings.manage |  |
| owner misc | GET | `/api/v1/announcements` | owner, teacher, parent, student |  |  |
| owner misc | POST | `/api/v1/announcements` | owner, teacher | notifications.send |  |
| owner misc | DELETE | `/api/v1/announcements/{id}` | owner |  |  |
| owner misc | GET | `/api/v1/reports` | owner, teacher |  |  |
| owner misc | GET | `/api/v1/reports/{name}` | owner, teacher, super_admin |  |  |
| owner misc | GET | `/api/v1/subscription` | owner |  |  |
| owner misc | POST | `/api/v1/subscription/checkout` | owner |  |  |
| owner misc | GET | `/api/v1/payment-gateway` | owner |  |  |
| owner misc | POST | `/api/v1/payment-gateway` | owner | settings.manage |  |
| owner misc | GET | `/api/v1/audit-logs` | owner | settings.manage |  |
| owner misc | GET | `/api/v1/support` | owner, teacher, parent, student |  |  |
| owner misc | POST | `/api/v1/support` | owner, teacher, parent, student |  |  |
| owner misc | GET | `/api/v1/support/{id}` | owner, teacher, parent, student, super_admin |  |  |
| owner misc | POST | `/api/v1/support/{id}/reply` | owner, teacher, parent, student, super_admin |  |  |
| owner structure | GET | `/api/v1/academy` | owner, teacher |  |  |
| owner structure | POST | `/api/v1/academy` | owner | settings.manage |  |
| owner structure | GET | `/api/v1/branches` | owner, teacher |  |  |
| owner structure | POST | `/api/v1/branches` | owner | structure.manage |  |
| owner structure | PUT | `/api/v1/branches/{id}` | owner | structure.manage |  |
| owner structure | GET | `/api/v1/activities` | owner, teacher |  |  |
| owner structure | POST | `/api/v1/activities` | owner | structure.manage |  |
| owner structure | PUT | `/api/v1/activities/{id}` | owner | structure.manage |  |
| owner structure | GET | `/api/v1/batches` | owner, teacher |  |  |
| owner structure | POST | `/api/v1/batches` | owner | structure.manage |  |
| owner structure | PUT | `/api/v1/batches/{id}` | owner | structure.manage |  |
| owner structure | GET | `/api/v1/batches/{id}/students` | owner, teacher |  |  |
| owner structure | GET | `/api/v1/timetable` | owner, teacher |  |  |
| owner structure | GET | `/api/v1/teachers` | owner |  |  |
| owner structure | POST | `/api/v1/teachers` | owner | teachers.manage |  |
| owner structure | PUT | `/api/v1/teachers/{id}` | owner | teachers.manage |  |
| owner structure | POST | `/api/v1/teachers/{id}/invite` | owner | teachers.manage |  |
| owner structure | GET | `/api/v1/permissions` | owner |  |  |
| owner structure | GET | `/api/v1/leaves` | owner, teacher |  |  |
| owner structure | POST | `/api/v1/leaves` | teacher |  |  |
| owner structure | PUT | `/api/v1/leaves/{id}` | owner | teachers.manage |  |
| owner students | GET | `/api/v1/students` | owner, teacher | students.view |  |
| owner students | POST | `/api/v1/students` | owner, teacher | students.manage |  |
| owner students | GET | `/api/v1/students/{id}` | owner, teacher | students.view |  |
| owner students | POST | `/api/v1/students/{id}` | owner, teacher | students.manage |  |
| owner students | POST | `/api/v1/students/{id}/transfer` | owner, teacher | students.manage |  |
| owner students | POST | `/api/v1/students/{id}/enroll` | owner | students.manage |  |
| owner students | POST | `/api/v1/students/{id}/invite` | owner, teacher | students.manage |  |
| owner students | GET | `/api/v1/parents` | owner | students.view |  |
| owner students | POST | `/api/v1/students/{id}/parents` | owner, teacher | students.manage |  |
| owner students | DELETE | `/api/v1/students/{id}/parents/{pid}` | owner | students.manage |  |
| owner students | PUT | `/api/v1/parents/{id}` | owner | students.manage |  |
| owner students | GET | `/api/v1/students/{id}/documents` | owner, teacher | documents.manage | documents |
| owner students | POST | `/api/v1/students/{id}/documents` | owner, teacher | documents.manage | documents |
| owner students | DELETE | `/api/v1/documents/{id}` | owner | documents.manage |  |
| owner students | GET | `/api/v1/admissions` | owner | admissions.manage |  |
| owner students | POST | `/api/v1/admissions/{id}/approve` | owner | admissions.manage |  |
| owner students | POST | `/api/v1/admissions/{id}/reject` | owner | admissions.manage |  |
| portal | GET | `/api/v1/portal/children` | parent, student |  | parent_access |
| portal | GET | `/api/v1/portal/students/{id}/dashboard` | parent, student |  | parent_access |
| portal | GET | `/api/v1/portal/students/{id}/fees` | parent, student |  | parent_access |
| portal | GET | `/api/v1/portal/students/{id}/timetable` | parent, student |  | parent_access |
| portal | POST | `/api/v1/portal/students/{id}/pay` | parent, student |  | parent_access |
| portal | POST | `/api/v1/payments/verify` | any logged-in user |  |  |
| public | GET | `/api/v1/public/packages` | public |  |  |
| public | POST | `/api/v1/public/enquiry` | public |  |  |
| public | POST | `/api/v1/public/register-owner` | public |  |  |
| public | GET | `/api/v1/public/academy/{slug}` | public |  |  |
| public | POST | `/api/v1/public/academy/{slug}/admission` | public |  |  |
| public | POST | `/api/v1/ocr/parse` | any logged-in user |  |  |
| public | POST | `/api/v1/ocr/extract` | any logged-in user |  |  |
| webhooks | GET | `/api/v1/webhooks/whatsapp` | public |  |  |
| webhooks | POST | `/api/v1/webhooks/whatsapp` | public |  |  |
| webhooks | POST | `/api/v1/webhooks/razorpay/{acad}` | public |  |  |
