# Maintenance Guide (for the next PHP developer)

Core PHP 8.2+ and MySQL with PDO. No framework, no Composer, no build step. Every web screen and the Android app use
the same REST API (`/api/v1/`), so a business rule lives in **one** place on the server.

## Where things are

```
ClassNex/                     ← upload this folder to public_html/ClassNex
├── index.php, features.php, pricing.php, about.php, contact.php, register.php, login.php, …   public website pages
├── install.php               one-time web installer (locks itself after installing)
├── .htaccess                 routing for /api/v1, private-folder protection, security headers
├── admin/                    ClassNex Super Admin panel   (index.php = page shell + menu, admin.js = screens)
├── class-admin/              Class Admin (Academy Admin) panel  (index.php = page shell + menu)
├── teacher/                  Teacher panel (same screens as Class Admin, limited by permissions)
├── parent/  student/         Parent and Student portal (student/portal.js)
├── owner/                    old address – redirects to class-admin/
├── api/v1/index.php          API front controller (CORS, auth, JSON errors)
├── api/v1/routes/            API endpoints, one file per area:
│     auth.php  public.php  admin.php (Super Admin)  students.php  structure.php (branches, activities,
│     batches, teachers, leaves)  attendance.php  fees.php (fees, invoices, payments, receipts)
│     academy.php (dashboard, messages, WhatsApp, reports, subscription, settings, support)  portal.php  files.php  webhooks.php
├── config/config.php         the ONLY configuration file (database, app_url, app_key). Template: config.sample.php
├── includes/                 bootstrap.php (loads config, autoload, errors), functions.php (e(), cnx_url(), cnx_role_name()),
│                             panel.php (shared header/sidebar/footer of all panels), site.php (website header/footer), auth_layout.php
├── classes/                  small reusable building blocks: DB (PDO, transactions), Auth, Ctx (who is calling, tenant checks),
│                             Router, Http, Validator, Feature (package features/limits/quota), Subscription, Money, Phone,
│                             Upload, Crypto, SimplePdf, XlsxWriter, Audit, RateLimiter, Settings, Sequence, Migrator
├── services/                 business logic: StudentService, BranchService, AttendanceService, BillingService, PaymentService,
│                             ReceiptService, ReportService, NotificationService (+ channels/WhatsApp, FCM, SMTP, SMS),
│                             ManualWhatsApp (click-to-send), ReminderService, QueueProcessor, AcademyService (registration/approval),
│                             SubscriptionCron, BackupService, OcrService, RazorpayService
├── assets/                   css/ (app.css panels, site.css website), js/ (app.js shared panel core, academy.js Class Admin +
│                             Teacher screens, site.js website forms), img/, vendor/ (Bootstrap, icons, fonts – self-hosted)
├── uploads/                  public images only (logos, website images); scripts cannot run here
├── storage/                  private: logs/, private/ (photos, documents, backups), cache/  – blocked from the web
├── cron/run.php              one-minute cron runner (+ queue.php, reminders.php, subscriptions.php shortcuts)
├── cli/                      migrate.php, create-superadmin.php, backup.php, build-install-sql.php, api-docs.php
├── database/                 migrations/ (001–004) and install.sql
├── docs/                     all guides;  tests/  automated tests;  android/  Android Studio project
```

The panels are “single page” screens: `class-admin/index.php` prints the shared layout (`includes/panel.php`) and the
menu; each menu entry (e.g. `#/branches`) is a `route('/branches', …)` function in `assets/js/academy.js` that calls the
API and draws the screen. To find a screen, search `route('/name'` in the JS file; to find its data, search the
endpoint path (e.g. `'/branches'`) in `api/v1/routes/`.

## How a request works

`/ClassNex/api/v1/students` → `.htaccess` → `api/v1/index.php` → `Router` finds the route in `routes/students.php` →
guards (`roles`, `perm`, `feature`, read-only subscription) → `Ctx` (current user + academy) → service code with
prepared statements → `Http::ok($data)` JSON. Errors are thrown as `ApiException` and become JSON with an HTTP code;
stack traces are logged to `storage/logs/php-error.log`, never shown (unless `debug = true` in config).

## Adding something (example: a new report)

1. Server: add a `case 'my_report':` in `services/ReportService.php::run()` returning `self::out(title, columns, rows)`.
   PDF / Excel / CSV export work automatically (`?format=pdf|xlsx|csv`).
2. Web: add it to the report list in `assets/js/academy.js` (`route('/reports'`).
3. Android: `ReportScreen("my_report", branch)` already renders any report.
4. Test: add a check to `tests/e2e_final.php`.

Database change: add `database/migrations/005_something.sql` (never edit an applied migration), run
`php cli/migrate.php`, then `php cli/build-install-sql.php` to refresh `database/install.sql`.

## Rules worth knowing

- Always filter by `academy_id` (use `$c->academy()`, `$c->assertStudent()`, `Crud::find(..., $academyId)`); other
  academies' IDs must answer **404**.
- Money in paise (`Money::toPaise`, `Money::format`). Dates `Y-m-d`.
- Student quota: only `Feature::assertStudentCapacity()` (inside the transaction) – never count per branch.
- Messages: only through `NotificationService` (API) or `ManualWhatsApp` (click-to-send).
- Role key `owner` = **Class Admin**. Show `cnx_role_name()` / JS `label(role)` to people, never the raw key.

## Tests (run before every release)

```bash
CNX_DB=classnex_test CNX_URL=http://127.0.0.1:8081 php -S 127.0.0.1:8081 tests/devserver.php &
CNX_DB=classnex_test php tests/e2e_final.php    http://127.0.0.1:8081   # 97 checks
CNX_DB=classnex_test php tests/e2e_branches.php http://127.0.0.1:8081   # 80 checks
CNX_DB=classnex_test php tests/e2e.php          http://127.0.0.1:8081   # 107 checks (leaves data for the browser test)
CNX_DB=classnex_test python3 tests/ui_smoke.py  http://127.0.0.1:8081 /tmp/shots   # every screen, desktop + phone
sudo bash tests/subdir_install_test.sh 8090    # Apache install under /ClassNex/ through install.php
```
The database name must contain `test`; the suites drop and re-create it. GitHub Actions runs the PHP suites on MySQL 8
and the Android build + API contract tests on every push.
