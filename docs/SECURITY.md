# Security & Privacy

## Tenant isolation
- Every academy-owned table carries `academy_id`; every query on academy data is filtered by the caller's academy taken from the authenticated session/token — never from request input.
- Teachers are further restricted to their assigned batches (`Ctx::batchScope()`), parents/students to their linked children (`Ctx::familyStudentIds()`).
- Records of another tenant or family return **404**, so their existence is never revealed.
- Verified by the automated E2E suite (owner A cannot read/collect for owner B; parent cannot read another family's child, fees or receipts).

## Authentication
- Passwords: PHP `password_hash` (bcrypt/argon2 per PHP default, re-hashed automatically); minimum 8 chars with letters and digits.
- Login rate limits per IP and per identifier; account locked for 15 minutes after 5 wrong passwords.
- Android/API: random 256-bit bearer tokens, stored only as SHA-256 hashes, 30-day expiry, revoked on logout and on password change/reset.
- Web: HTTP-only, SameSite=Lax, Secure (on HTTPS) session cookie with session-id regeneration at login; **CSRF token** required on every state-changing request.
- Activation / reset links: single-use random tokens (hashed), 7 days / 1 hour validity. "Forgot password" responds identically whether or not the account exists.

- Web sessions use an HttpOnly, SameSite=Lax cookie limited to the installation path (e.g. `/ClassNex/`), Secure on HTTPS; every web write needs the CSRF token (refused with 403 `csrf`).
- The installer refuses to run once installed (`config/config.php` or `storage/installed.lock`), uses a CSRF token and limits attempts; delete `install.php` after installing.
- Academies registered from the website cannot log in until the ClassNex Super Admin approves them.

## Authorisation
- Roles: super_admin, owner, teacher, student, parent. Owners hold all academy permissions; teachers get `students.view` and `attendance.mark` by default and the owner can grant more per teacher (financial permissions are flagged). Teacher fee access is **off by default**.
- Package features and limits are enforced in the API router/services (HTTP 402) — hiding a button in the UI is never the only control.
- Expired subscriptions become read-only after the grace period; suspended academies lock non-owner logins. Data is never deleted because of expiry.

## Input & output
- All SQL uses PDO prepared statements; identifiers in dynamic SQL come only from code.
- Server-side validation for every field; consistent field errors.
- Output escaping in PHP templates (`e()`), DOM text escaping in JS (`esc()`), CMS HTML passed through a whitelist sanitizer; CSV exports neutralise spreadsheet formulas.
- Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, Content-Security-Policy on panels; HSTS once HTTPS is enabled.

## Files
- Student photos and documents live in `storage/private` (blocked from the web) and are streamed only after authentication + tenant checks.
- Documents are encrypted at rest with libsodium `secretbox` using the installation `app_key`; uploads are type-checked by content (finfo), images re-encoded with GD (strips EXIF/GPS and hidden payloads), PDFs validated.
- `uploads/` (public logos/CMS images) cannot execute scripts.
- Document retention: auto-deleted after the configured period (default 3 years); owners can delete any document permanently.

## Secrets
- WhatsApp tokens, app secrets, SMTP/SMS passwords, Firebase service accounts, Razorpay secrets and Vision keys are encrypted in the database and only shown masked (••••1234). They are never sent to browsers or the Android app.
- `config/config.php`, keystores and `google-services.json` are git-ignored. CI uses GitHub secrets.

## Payments
- Amounts are integer paise. Every payment runs in a DB transaction with row locks; idempotency keys prevent duplicates; one receipt per payment (unique key).
- The allocation ledger is append-only: reversals and refunds add negative rows; nothing is silently edited or deleted. Every financial action is in `audit_logs`.
- Online payments are recorded only after server-side Razorpay signature verification or a signed webhook whose amount matches the order.

## Children's data & Indian law (DPDP Act 2023)
- Collect only what is needed: name, WhatsApp number and activity are mandatory; everything else optional.
- Guardian consent captured with who/when (`students.consent_*`); online admission requires consent.
- **Aadhaar is never mandatory.** OCR never stores Aadhaar numbers (only `XXXX XXXX 1234` shown for confirmation); no Aadhaar OTP or biometrics are ever collected.
- No advertising, profiling or data sale. Data-subject requests: owners can correct or delete student documents and records (financial records are retained as legally required).
- WhatsApp STOP opt-outs are honoured automatically.

## Audit & monitoring
- `audit_logs`: logins, student/teacher/fee/payment/attendance changes (old → new values, secrets redacted), settings, integrations, admin actions, document views.
- `notification_logs`: every provider request/response; `cron_runs`: every background run. *System Health* shows PHP/DB versions, extensions, HTTPS, cron, queue and error log.

## Reporting vulnerabilities
Email the support address in *Super Admin → Global Settings*. Please do not test against production academies.
