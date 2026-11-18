# Architecture

```
            ┌───────────────┐   ┌──────────────────────────────┐   ┌──────────────────┐
            │  Website (PHP │   │  Web panels (static shell +  │   │  Android app     │
            │  + CMS data)  │   │  vanilla JS, Bootstrap 5)    │   │  Kotlin/Compose  │
            └──────┬────────┘   └──────────────┬───────────────┘   └────────┬─────────┘
                   │  same-origin session+CSRF │        Bearer token        │
                   └──────────────┬────────────┴────────────────────────────┘
                                  ▼
                       REST API v1  (api/v1/index.php → Router)
                       auth · roles/permissions · package features · tenant scope
                                  ▼
            services/  Billing · Payment · Receipt · Attendance · Student · Notification
                       Reminder · Queue · Report · OCR · Razorpay · Subscription · Backup
                                  ▼
                        MySQL 8 (single database, tenant column on every row)
                                  ▲
            cron/run.php (every minute): queue · invoices · reminders · subscriptions …
                                  ▼
            WhatsApp Cloud API · FCM v1 · SMTP · SMS gateway · Razorpay
```

**Why this shape:** the client requirement is long-lived, simple-to-host software. Core PHP 8.2 with PDO (no framework, no Composer) deploys on any cPanel host; one REST API guarantees the web and Android behave identically; all background work is a cron-driven, database-backed queue (no Redis/Supervisor).

## Request lifecycle

1. `api/v1/index.php` loads `includes/bootstrap.php` (config, autoloader, error handling) and registers every `routes/*.php` file.
2. `Router::dispatch()` matches the route and runs `guard()`: authentication (`Auth`), role list, permission (`Ctx::can`), package feature (`Feature::has`), subscription writability.
3. The handler validates input (`Validator`), applies tenant scope (`Ctx::academy()`, `batchScope()`, `assertStudent()`), calls services inside transactions, writes `audit_logs`, and returns the JSON envelope.
4. Exceptions become consistent JSON errors (`ApiException`, PDO errors hidden in production).

## Key design decisions

| Topic | Decision |
|---|---|
| Users | One `users` table for all roles (simpler auth for web + Android). Role-specific profiles live in `teachers`, `parents`, `students`. One person may hold several accounts (e.g. parent in two academies) → account chooser at login. |
| Money | Integer paise everywhere; Indian grouping in UI; amounts in words on receipts. |
| Fees | `fee_structures` (plans) → `student_fee_assignments` (per student, overriding amount/discount) → `fee_invoices` (one per period, unique dedupe key) → `payment_allocations` (append-only ledger) ← `payments` → `receipts` (one per payment). Advance = unallocated payment money, applied automatically to new invoices. |
| Numbering | Gap-free per-academy sequences (`sequences` table, row lock) for student IDs, invoice and receipt numbers per financial year (`RCPT/2026-27/00001`). |
| Notifications | `NotificationService` resolves recipients (parents for minors, student for adults), checks plan feature + academy preference + opt-out + monthly WhatsApp limit, renders templates, writes in-app rows immediately and queues the rest with idempotency keys. `QueueProcessor` claims rows atomically and delivers through channel classes. |
| Attendance | One session per batch per day, one row per student (unique keys). Corrections write `attendance_corrections`; parents are notified only when the status actually changes. |
| Files | Private files under `storage/private` (documents encrypted), public images under `uploads/`. PDFs and XLSX are generated without libraries (`SimplePdf`, `XlsxWriter`). |
| Frontend | Panels are a single PHP shell per role + hash-routed vanilla JS views (`assets/js/app.js` core + role script). No build step; Bootstrap/icons/fonts are self-hosted. |
| Android | MVVM: Compose screens → Hilt ViewModels (StateFlow state, SharedFlow messages) → `Repository` → Retrofit `ApiService`. Role-based navigation built from `/auth/me`. |

## Database

Migrations: `database/migrations/001_core_schema.sql` (tables, keys, indexes, foreign keys) and `002_core_seed.sql` (roles, permissions, packages, templates, reminder rules, website content). Run with `php cli/migrate.php`; applied versions are tracked in `schema_migrations`.

| Area | Tables |
|---|---|
| Platform | `packages`, `package_features`, `academies`, `owner_subscriptions`, `subscription_payments`, `system_settings`, `website_settings`, `cms_pages`, `testimonials`, `enquiries`, `support_tickets`, `support_ticket_replies` |
| Identity & access | `users`, `api_tokens`, `user_tokens`, `device_tokens`, `roles`, `permissions`, `role_permissions`, `user_permissions`, `rate_limits` |
| Structure | `academy_branches`, `activities`, `batches`, `batch_schedules`, `teacher_batches`, `teachers`, `teacher_leaves`, `holidays`, `announcements` |
| Students | `students`, `parents`, `parent_student_mapping`, `student_activity_mapping` (enrolment history incl. transfers), `student_documents`, `admissions` |
| Attendance | `attendance_sessions`, `attendance`, `attendance_corrections` |
| Fees | `fee_structures`, `discounts`, `student_fee_assignments`, `fee_invoices`, `fee_invoice_items`, `payments`, `payment_allocations`, `refunds`, `receipts`, `sequences`, `payment_gateway_configs`, `gateway_orders` |
| Notifications | `notification_templates`, `notification_preferences`, `notifications` (in-app), `notification_queue`, `notification_logs`, `notification_opt_outs`, `reminder_rules`, `scheduled_reminders`, `whatsapp_configurations`, `firebase_configurations`, `email_configurations`, `sms_configurations` |
| Operations | `audit_logs`, `cron_runs`, `schema_migrations` |

The specification's `super_admins` and `owners` tables are represented by `users.role` (+ `academies.owner_user_id`), which keeps a single authentication path for all clients.
