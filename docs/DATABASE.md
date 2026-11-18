# Database Documentation

MySQL 8.0+ (MariaDB 10.4+ also tested), `utf8mb4`, InnoDB. **67 tables, 82 foreign keys, 184 secondary indexes**
(counted on a freshly migrated database). Money is stored as integer **paise** (`*_paise`), never as floats.

## Files

| File | Use |
|---|---|
| `database/migrations/001_core_schema.sql` | All tables, keys and foreign keys |
| `database/migrations/002_core_seed.sql` | Roles, permissions, packages, notification templates, reminder rules, website content, settings |
| `database/migrations/003_branches_student_quota.sql` | Unlimited branches, academy-wide student quota, branch on invoices / payments / receipts, packages with custom billing days |
| `database/migrations/004_history_duplicates_whatsapp_modes.sql` | Branch on attendance sessions, search indexes, WhatsApp modes (off / manual / api), `manual_messages`, registration approval, Class Admin wording |
| `database/install.sql` | All migrations in one file + their `schema_migrations` rows, for phpMyAdmin import into an **empty** database. Rebuild with `php cli/build-install-sql.php` |

`schema_migrations` records applied migrations. `php cli/migrate.php` (and the web installer) applies only the
missing ones, so upgrades keep existing data. Verified: importing `install.sql` gives exactly the same tables and
columns as running the migrations, and `cli/migrate.php` then reports “up to date”.

## Main tables and relationships

```
packages ─< package_features
packages ─< owner_subscriptions >─ academies ─< academy_branches
users (role: super_admin | owner(Class Admin) | teacher | parent | student, academy_id)
academies ─< activities ─< batches >─ academy_branches          batches ─< batch_schedules, teacher_batches >─ teachers
academies ─< students (branch_id → academy_branches) ─< student_activity_mapping >─ activities / batches
students ─< parent_student_mapping >─ parents ;  students ─< student_documents
attendance_sessions (batch_id, branch_id, session_date) ─< attendance (student_id, status) ─< attendance_corrections
fee_structures ─< student_fee_assignments ─< fee_invoices (branch_id) ─< fee_invoice_items
payments (branch_id) ─< payment_allocations >─ fee_invoices ;  payments ─1 receipts (branch_id) ;  payments ─< refunds
notification_templates → notification_queue (idempotency_key UNIQUE) → notification_logs ; notifications (in-app)
manual_messages (click-to-send WhatsApp history) ; notification_opt_outs ; device_tokens
audit_logs, support_tickets ─< support_ticket_replies, sequences (gap-free numbers), cron_runs, rate_limits
system_settings, website_settings, cms_pages, testimonials, enquiries, *_configurations (encrypted credentials)
```

Key rules enforced in the schema:
- **Tenant isolation**: every academy table has `academy_id` with a foreign key; the API adds `academy_id = ?` to every query.
- **One attendance record per student per class**: `UNIQUE (batch_id, session_date)` on sessions, `UNIQUE (session_id, student_id)` on attendance.
- **No duplicate invoices**: unique period key per assignment; **no duplicate messages**: unique `idempotency_key`.
- **Gap-free numbering** per academy and financial year (`sequences`, row lock): Student IDs, invoice and receipt numbers.
- **Branch history**: `attendance_sessions.branch_id`, `fee_invoices.branch_id`, `payments.branch_id`, `receipts.branch_id` are
  stamped when created, so moving a student or batch never rewrites the past.
- **Student quota**: counted as `COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'` inside a transaction
  that locks the academy row (`SELECT … FOR UPDATE`), so parallel registrations cannot exceed it.

## Default data after installation

Roles and permissions, four sample packages (Starter ₹100 / 15 students – manual WhatsApp, Growth ₹200 / 100 students,
Professional, Enterprise – edit or deactivate them), notification templates, default reminder rules
(7/3/1 days before, due date, 3/7 days after), website pages (home, features, pricing, privacy, terms), and settings such
as `registration_requires_approval = 1`. **No demo academies, students or payments are installed.**

## Super Admin

Created by `install.php`, or `php cli/create-superadmin.php "Name" email mobile 'password'`. More Super Admins can be
added the same way. Passwords are stored with PHP `password_hash` (bcrypt/argon), never in plain text.

Backups and restore: [BACKUP_RESTORE.md](BACKUP_RESTORE.md).
