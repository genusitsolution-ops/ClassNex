# QA Report

Date: 18 Nov 2026 · Build: ClassNex 1.0.0

## How the platform was tested

| Suite | What it covers | Environment | Result |
|---|---|---|---|
| `tests/e2e.php` | 107 checks over HTTP covering all 30 mandatory workflows plus edge cases, against a real database | MariaDB 10.11 + PHP 8.3 (dev sandbox) | **107 / 107 passed** |
| `tests/e2e.php` in GitHub Actions (`backend.yml`) | Same suite | **MySQL 8.0 + PHP 8.2** | **107 / 107 passed** |
| `tests/ui_smoke.py` | Headless Chrome logs in as owner, teacher, parent and super admin, opens every screen (92 pages incl. website), fails on any JS error or error banner; desktop + mobile screenshots | Chrome + PHP built-in server | **92 / 92 passed** |
| Android CI (`android.yml`) | Kotlin compile, resource merge, dex, debug APK (+ unsigned release APK/AAB with R8) | GitHub Actions, JDK 17, AGP 8.5 | **Debug APK built** (artifact `classnex-debug-apk`, 40 MB) |

The per-check log of the latest run is in [QA_TEST_RUN.md](QA_TEST_RUN.md) (regenerated on every run).

## Mandatory end-to-end scenarios

| # | Scenario | Status |
|---|---|---|
| 1 | Super Admin creates a package | ✅ automated |
| 2 | Super Admin creates a Class Owner | ✅ automated |
| 3 | Owner receives a subscription | ✅ automated |
| 4 | Owner creates / completes academy profile (logo) | ✅ automated |
| 5 | Owner creates activities | ✅ automated |
| 6 | Owner creates batches with schedule | ✅ automated |
| 7 | Owner registers a student (quick registration) | ✅ automated |
| 8 | Owner captures student photo (stored privately, compressed) | ✅ automated (upload path); camera UI verified in browser screenshots |
| 9 | Owner uploads a document (encrypted at rest) | ✅ automated |
| 10 | OCR extracts available information (name, DOB, gender, address, guardian; Aadhaar masked) | ✅ automated (parser); on-device OCR runs in ML Kit / Tesseract.js |
| 11 | Owner verifies and saves registration | ✅ automated |
| 12 | Parent account linked, invited, activated | ✅ automated |
| 13 | Teacher marks attendance | ✅ automated |
| 14 | Parent receives attendance notification | ✅ automated (in-app + WhatsApp queue) |
| 15 | Owner assigns monthly fees | ✅ automated (auto-assigned on admission) |
| 16 | Owner collects full / partial payment | ✅ automated |
| 17 | System generates receipt | ✅ automated |
| 18 | Parent downloads receipt (PDF) | ✅ automated |
| 19 | System calculates outstanding balance | ✅ automated |
| 20 | Automatic reminder is scheduled | ✅ automated |
| 21 | Cron processes the reminder | ✅ automated |
| 22 | WhatsApp sends the message when configured | ✅ automated in TEST mode (full pipeline, provider call simulated). Live sending needs real Meta credentials. |
| 23 | Failed messages are retried safely | ✅ automated (backoff, manual retry, webhook delivery status, STOP opt-out) |
| 24 | Super Admin changes a package | ✅ automated |
| 25 | Permissions update correctly | ✅ automated (WhatsApp/reports blocked instantly, student limit enforced) |
| 26 | Owner cannot access another owner's records | ✅ automated |
| 27 | Parent cannot access another family's records | ✅ automated |
| 28 | Web changes appear in Android API | ✅ automated (session-cookie client vs bearer client) |
| 29 | Android changes appear on web | ✅ automated |
| 30 | Payment and receipt consistent across platforms | ✅ automated |

## Additional checks (all automated, passing)

Duplicate registration · duplicate attendance · duplicate payment (idempotency key) · invalid mobile number · guardian required for minors · negative amounts · unauthorised role access (403) · teacher batch scope · teacher no-fee-by-default · old-attendance correction needs permission · expired session token · revoked token after logout · account lockout after repeated wrong passwords · CSRF rejection on web · expired subscription → read-only, data still readable, renewal restores access · online admission creates a pending application, approval registers the student · advance payment kept as credit and auto-applied · refund from advance · reversal re-opens dues and cancels receipt · ledger reconciliation (invoice paid totals = allocation ledger) · invoice cron idempotent · reminder cron idempotent · all cron tasks error-free · reports in JSON, CSV, XLSX and PDF · backup and restore into a fresh database.

## Known limitations / items needing your input

| Item | Detail |
|---|---|
| Live WhatsApp, FCM, SMTP, SMS, Razorpay | Fully implemented and tested in *Test* mode; real sending requires your credentials (see DEPLOYMENT.md §7). Meta template approval is required before live WhatsApp fee reminders. |
| Android on a physical device | The APK compiles and packages in CI; it has not been run on a device or emulator in this environment (no Android SDK available here). Please install the debug APK and run through owner, teacher and parent flows before release. |
| Release signing | Signed release APK/AAB are produced once the four signing secrets are added (ANDROID.md). |
| Online fee payment in the Android app | Parents tap *Pay online*, which opens the secure web portal checkout (Razorpay). A native Razorpay SDK screen can be added later. |
| Production deployment | Not deployed to your server from here; follow DEPLOYMENT.md. |
