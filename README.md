# ClassNex – All-in-One Class Management

ClassNex is a multi-tenant SaaS platform for academies, institutes and activity classes — cricket, dance, yoga, music, martial arts, swimming, coaching, tuition and more. One backend serves the marketing website, the web panels and a native Android app.

**Roles:** the **ClassNex Super Admin** (platform operator, Genus IT Solution) approves and manages academies and packages · each customer academy is run by its **Class Admin / Academy Admin** · **Teachers / Instructors** are academy staff · **Students / Parents** use the portal and app.
Production address: **https://genusitsolution.com/ClassNex/** (the app works in any sub-folder or at a domain root).

| Component | Where |
|---|---|
| Marketing website (CMS-driven) | `/index.php`, `/features.php`, `/pricing.php` … |
| Super Admin panel | `/admin/` |
| Class Admin (Academy Admin) panel | `/class-admin/` (old `/owner/` redirects) |
| Teacher panel | `/teacher/` |
| Parent portal / Student portal | `/parent/` · `/student/` |
| REST API v1 (web + Android) | `/api/v1/` |
| Android app (Kotlin, Compose) | `/android/` |
| Cron runner | `/cron/run.php` |

## Highlights

- **30-second student registration** – photo (camera/gallery), name, WhatsApp number, activity and batch. Optional details are tucked away. Documents can be scanned and **OCR pre-fills** name, DOB, address and guardian for the user to confirm. Aadhaar is never required; Aadhaar numbers are masked and never stored.
- **Academy registration with Super Admin approval** – academies register on the website or in the app; the ClassNex Super Admin approves (choosing the package) or rejects with a reason; the trial starts on approval.
- **Present-by-default attendance** – the register opens with everyone present; tap only the absent / late / on-leave students and save. Duplicate-proof, with audited corrections and parent notifications.
- **Fees that collect themselves** – monthly/quarterly/half-yearly/yearly/one-time fees at activity, batch or student level; partial and advance payments, discounts, scholarships, late fees, fee pauses, proration; reversals and refunds on an **append-only ledger**.
- **Receipts** – numbered PDF receipts, print, WhatsApp share link, email, parent download.
- **Notification engine** – official WhatsApp Business Cloud API, Firebase push (HTTP v1), SMTP email, optional SMS, in-app inbox. DB-backed queue with locking, retries with backoff, webhook delivery status, STOP opt-out, rate limiting, test mode.
- **Automatic reminders** – default 7, 3, 1 days before, on the due date, 3 and 7 days after; configurable per academy.
- **Unlimited branches, one student-based subscription** – an academy adds as many branches as it needs at no extra cost. Packages are priced on the **total active unique students across all branches** (e.g. Starter ₹100 / 15 students, Growth ₹200 / 100 students). The limit is checked inside a locked transaction, so concurrent registrations at different branches cannot exceed it; branch transfers and duplicates never inflate the count; deactivated students free a slot and keep their history. Branch-wise attendance, fees, receipts and reports, plus a combined academy report.
- **Attendance history** – by date or range, branch, activity, batch, student and status, with totals and percentage, per branch or combined, exported as PDF / Excel / CSV.
- **WhatsApp in three package modes** – *Disabled*, *Manual click-to-send* (ClassNex prepares the message, WhatsApp / WhatsApp Business opens to send, with history) and *Official WhatsApp Business Cloud API* (automatic background sending, scheduled messages, delivery webhooks, retries). See [docs/WHATSAPP.md](docs/WHATSAPP.md).
- **Smart duplicate check** – name, WhatsApp, guardian and date of birth; siblings sharing a number are fine; open / link the existing student, or (Class Admin) create a separate one with an audited reason.
- **Packages & subscriptions** – fully dynamic plans (custom billing durations, WhatsApp mode), feature flags and limits enforced by the backend; trial, renewal, upgrade, grace, read-only expiry (data never deleted), suspension; online renewal through Razorpay.
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

Start with [docs/README.md](docs/README.md) – deployment for `/ClassNex/`, database, API, Android, WhatsApp, Firebase, cron,
backup and restore, security, maintenance, QA report and the final delivery report.

## Tests

```bash
CNX_DB=classnex_test CNX_URL=http://127.0.0.1:8081 php -S 127.0.0.1:8081 tests/devserver.php &
CNX_DB=classnex_test php tests/e2e_final.php    http://127.0.0.1:8081   # approval, history, 3,000 students, duplicates, WhatsApp modes, sync
CNX_DB=classnex_test php tests/e2e_branches.php http://127.0.0.1:8081   # unlimited branches + academy-wide quota
CNX_DB=classnex_test php tests/e2e.php          http://127.0.0.1:8081   # 30 core business workflows
CNX_DB=classnex_test python3 tests/ui_smoke.py  http://127.0.0.1:8081 /tmp/shots   # every screen, desktop + phone width
```

GitHub Actions run the three PHP suites on MySQL 8, and the Android contract tests + APK/AAB build, on every push.
Results: [docs/QA_REPORT.md](docs/QA_REPORT.md).

## Project structure

```
/                 website pages, login, register, receipt, admission, install
/admin            Super Admin panel (index.php + admin.js)
/class-admin      Class Admin panel; /teacher Teacher panel (shared assets/js/academy.js); /owner redirects
/parent /student  parent and student portal (student/portal.js)
/api/v1           REST API (index.php router + routes/*.php)
/classes          core: DB, Auth, Router, Validator, Crypto, Feature, SimplePdf …
/services         business logic: billing, payments, notifications, channels …
/includes         bootstrap, functions, shared panel and website layout
/config           config.php (the only configuration file; template config.sample.php)
/cron             cron runner (+ queue/reminders/subscriptions shortcuts)
/cli              migrate, create-superadmin, backup/restore, build-install-sql, api-docs
/database         migrations (schema + seed) and install.sql
/storage          private files, logs, backups (web access denied)
/uploads          public images (logos, CMS)
/assets           CSS, JS, images, self-hosted vendor libraries
/android          native Android app
/docs /tests
```
