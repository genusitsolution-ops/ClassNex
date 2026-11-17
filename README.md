# ClassNex – All-in-One Class Management

ClassNex is a multi-tenant SaaS platform for academies, institutes and activity classes — cricket, dance, yoga, music, martial arts, swimming, coaching, tuition and more. One backend serves the marketing website, four web panels and a native Android app.

| Component | Where |
|---|---|
| Marketing website (CMS-driven) | `/index.php`, `/features.php`, `/pricing.php` … |
| Super Admin panel | `/admin/` |
| Owner panel | `/owner/` |
| Teacher panel | `/teacher/` |
| Parent / Student portal | `/student/` |
| REST API v1 (web + Android) | `/api/v1/` |
| Android app (Kotlin, Compose) | `/android/` |
| Cron runner | `/cron/run.php` |

## Highlights

- **30-second student registration** – photo (camera/gallery), name, WhatsApp number, activity and batch. Optional details are tucked away. Documents can be scanned and **OCR pre-fills** name, DOB, address and guardian for the user to confirm. Aadhaar is never required; Aadhaar numbers are masked and never stored.
- **One-tap attendance** – today's roster loads automatically; *Mark all present*, fix exceptions, save. Duplicate-proof, with audited corrections and parent notifications.
- **Fees that collect themselves** – monthly/quarterly/half-yearly/yearly/one-time fees at activity, batch or student level; partial and advance payments, discounts, scholarships, late fees, fee pauses, proration; reversals and refunds on an **append-only ledger**.
- **Receipts** – numbered PDF receipts, print, WhatsApp share link, email, parent download.
- **Notification engine** – official WhatsApp Business Cloud API, Firebase push (HTTP v1), SMTP email, optional SMS, in-app inbox. DB-backed queue with locking, retries with backoff, webhook delivery status, STOP opt-out, rate limiting, test mode.
- **Automatic reminders** – default 7, 3, 1 days before, on the due date, 3 and 7 days after; configurable per academy.
- **Packages & subscriptions** – fully dynamic plans, feature flags and limits enforced by the backend; trial, renewal, upgrade, grace, read-only expiry (data never deleted), suspension; online renewal through Razorpay.
- **Security** – tenant isolation on every query, RBAC with per-teacher grants, CSRF, bcrypt/argon passwords, hashed bearer tokens, rate limiting, account lockout, libsodium-encrypted credentials and documents, private file storage, audit logs, backups.

## Requirements

PHP 8.2+ (pdo_mysql, sodium, gd, curl, mbstring, openssl, zip, fileinfo), MySQL 8+ (MariaDB 10.6+ also works), Apache with mod_rewrite (cPanel hosting is fine). No Redis, Supervisor or Composer needed — background work runs on cron.

## Quick start

```bash
# 1. upload the project to public_html (or clone it)
# 2. create a MySQL database + user in cPanel
# 3. open https://your-domain/install.php and follow the wizard
#    (or: cp config/config.sample.php config/config.php, edit, then)
php cli/migrate.php
php cli/create-superadmin.php "Your Name" you@example.com 9876543210 'StrongPass123'
# 4. add the cron job (every minute)
* * * * * /usr/local/bin/php /home/USER/public_html/cron/run.php all >/dev/null 2>&1
```

Then log in at `/login.php` as Super Admin, review **Packages**, connect **Integrations** (WhatsApp, Firebase, SMTP, Razorpay) and create owners — or let owners self-register from `/register.php`.

## Documentation

- [Deployment guide](docs/DEPLOYMENT.md) – cPanel, SSL, permissions, cron, integrations, backups
- [Super Admin setup](docs/SUPER_ADMIN_SETUP.md)
- [Cron jobs](docs/CRON.md)
- [REST API reference](docs/API.md)
- [Android app](docs/ANDROID.md) – building, signing, Firebase, Play Store
- [Security & privacy](docs/SECURITY.md)
- [Architecture & database](docs/ARCHITECTURE.md)
- [QA report](docs/QA_REPORT.md) and the latest automated run in [docs/QA_TEST_RUN.md](docs/QA_TEST_RUN.md)

## Tests

```bash
# end-to-end API workflows against a disposable database (needs MySQL/MariaDB)
CNX_DB=classnex_test php -S 127.0.0.1:8081 tests/devserver.php &
CNX_DB=classnex_test php tests/e2e.php http://127.0.0.1:8081
# UI smoke test (headless Chrome via Playwright) – every screen for every role
python3 tests/ui_smoke.py http://127.0.0.1:8081 /tmp/shots
```

GitHub Actions run the E2E suite on MySQL 8 and build the Android APK on every push.

## Project structure

```
/                 website pages, login, register, receipt, admission, install
/admin            Super Admin panel (index.php + admin.js)
/owner /teacher   academy panels (shared assets/js/academy.js)
/student          parent / student portal
/api/v1           REST API (index.php router + routes/*.php)
/classes          core: DB, Auth, Router, Validator, Crypto, Feature, SimplePdf …
/services         business logic: billing, payments, notifications, channels …
/cron             cron runner
/cli              migrate, create-superadmin, backup/restore
/database         migrations (schema + seed)
/storage          private files, logs, backups (web access denied)
/uploads          public images (logos, CMS)
/assets           CSS, JS, images, self-hosted vendor libraries
/android          native Android app
/docs /tests
```
