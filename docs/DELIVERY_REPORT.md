# ClassNex – Final Delivery Report (18 Nov 2026)

Repository: https://github.com/genusitsolution-ops/ClassNex (branch `main`)
Target: https://genusitsolution.com/ClassNex/ · Android API: https://genusitsolution.com/ClassNex/api/v1/

## 1. Features completed

Website with CMS · academy registration with **Super Admin approval** · ClassNex Super Admin panel (academies, registration
requests, packages with student limits / billing cycles incl. custom days / WhatsApp mode / features, subscriptions,
integrations, templates, reminder rules, notification queue, website CMS, reports, audit log, system health, backups) ·
Class Admin panel · Teacher panel · Parent portal · Student portal · **unlimited branches** with one academy-wide,
student-based subscription and unique student counting · branch management, transfers and bulk moves · teacher management
and per-teacher permissions · 30-second student registration with camera photo, document upload and OCR (Aadhaar never
required, never stored) · **present-by-default** attendance with audited corrections · attendance history with branch
filters · fees (monthly, quarterly, yearly, one-time, partial, advance, discounts, late fees, reversals, refunds) ·
numbered receipts per branch · reminders (automatic and manual) · WhatsApp (Disabled / Manual click-to-send / Official
API) · push, email, SMS, in-app notifications · branch-wise and combined reports (PDF / Excel / CSV) · native Android app
for every role using the same API.

## 2. The seven requested fixes

| # | Fix | Done |
|---|---|---|
| 1 | **Android branch management** | Branches list (quota bar, per-branch + combined figures), add / edit / deactivate, branch detail (students, batches, teachers, attendance, collection, pending, reports), branch students, student branch filter + branch name, change branch keeping the Student ID, paginated multi-select assign with confirmation, attendance history, reports screen, duplicate dialog, WhatsApp click-to-send, academy registration, Class Admin wording, production API URL. Same API, no separate database. |
| 2 | **Attendance history branch filter** | Date, date range, branch, activity, batch, student, status; totals for students, present, absent, late, leave and %; by class / by student / by branch; individual branch and combined academy views; PDF, Excel, CSV; web and Android. History stays with the branch where the class happened (sessions store the branch). |
| 3 | **200-student limit removed** | Server-side search (name, Student ID, WhatsApp), filters (current branch, activity, batch), paging, multi-select or “all N matching”, selected count, preview + confirmation, bulk assign and bulk transfer (into a batch). Tested with 3,000 students; quota untouched. |
| 4 | **Smarter duplicate detection** | Name similarity + WhatsApp + guardian + DOB; siblings sharing a number are admitted; candidates shown with reasons; Open existing / Link existing (enrols the existing profile) / Class Admin creates a separate student with a reason → audit log. No automatic merging. |
| 5 | **WhatsApp modes** | Disabled / Manual click-to-send (fee reminders, receipts, attendance, admission, announcements, custom, bulk preparation, history) / Official Cloud API (automatic reminders, receipts, attendance, admissions, announcements, **scheduled messages**, queue, cron, webhooks, retries, duplicate prevention, history). Old *full* / *transactional* values mapped without changing behaviour. Super Admin chooses the mode per package. |
| 6 | **Role names** | ClassNex Super Admin / Class Admin / Teacher / Parent / Student on website, panels, login, emails, reports, Android and docs. Role keys and API unchanged. Panel folders `class-admin/`, `parent/` (old `/owner/` redirects). |
| 7 | **Integration audit** | Found and fixed: missing Super Admin approval of registrations; Apache turning CSRF 419 into 500; session cookie path; PHP-FPM-breaking `.htaccess`; history student/status filters returning nothing; router collision risk; Android signature clash; present-by-default attendance missing. Details in QA_REPORT.md. |

## 3. Web application status
Complete and tested: every screen opens without errors for every role at desktop and phone width (187 browser checks),
on the PHP dev server and on **Apache installed under `/ClassNex/` through `install.php`**.

## 4. Android application status
Kotlin / Compose / MVVM / Hilt / Retrofit source complete. Compiles, passes the API contract tests and builds debug APK,
release APK and AAB in GitHub Actions (run 44285942328 for commit afb74ea). Downloads: GitHub → Releases →
**android-latest** (`ClassNex-debug.apk`, `ClassNex-release.apk`, `ClassNex-release.aab`). The release builds are
**unsigned** until the signing secrets are added (docs/ANDROID.md). **Not yet tested on a physical device.**

## 5. Backend status
One REST API (171 endpoints, docs/API.md) used by web and Android. Tenant isolation, roles and permissions, package
features, academy-wide quota, CSRF, rate limits, audit log.

## 6. Database status
67 tables, 82 foreign keys, 184 indexes. Migrations 001–004 and `database/install.sql` (verified identical to a migrated
database). Upgrades keep data (`php cli/migrate.php`). No demo data in production.

## 7. API status
All endpoints exercised by the automated suites listed below; JSON envelope `{success, message, data, meta, errors, code}`.

## 8. WhatsApp status
Manual click-to-send: working and tested. Official Cloud API: implemented and tested against a simulated endpoint; **live
sending not verified** – needs Meta credentials and approved templates (docs/WHATSAPP.md).

## 9. Firebase status
Implemented (FCM HTTP v1). **Not verified** – needs `google-services.json` and a service-account key (docs/FIREBASE.md).

## 10. Razorpay status
Implemented (subscription payments and parent fee payments, signature-verified webhooks). **Not verified** – needs test,
then live keys.

## 11. Automated test results (all passed)

| Suite | Dev server (MariaDB 10.11) | Apache `/ClassNex/` install | GitHub Actions (MySQL 8.0) |
|---|---|---|---|
| e2e_final.php | 97 / 97 | 97 / 97 | 97 / 97 |
| e2e_branches.php | 80 / 80 | 80 / 80 | 80 / 80 |
| e2e.php | 107 / 107 | 107 / 107 | 107 / 107 |
| ui_smoke.py (desktop + phone) | 187 / 187 | 187 / 187 | – |
| Android ApiContractTest + builds | – | – | passed |

## 12. Physical device test results
**Pending.** No Android device was available to me. Checklist: QA_REPORT.md → *Physical device checklist*.

## 13. GitHub commits (this phase, all pushed to `main`)

| Commit | Content |
|---|---|
| `0dc8b68` | Attendance history branch filters, paginated bulk branch moves, smarter duplicate check, WhatsApp modes (fixes 2–5, server + web) |
| `114de4e` | Class Admin terminology, `class-admin/` and `parent/` folders, verified `/ClassNex/` sub-folder install (fix 6) |
| `431e862` | Android: branches, history, bulk assign, duplicates, WhatsApp modes, wording, contract tests (fix 1) |
| `4e9d75e` | Academy registration with Super Admin approval (web + Android), Android build fix |
| `afb74ea` | Integration audit fixes, final test suite, install.sql, present-by-default attendance (fix 7) |
| `2da960c` | Documentation, clearer API route file names |
| (this report) | Delivery report |

## 14. Production deployment
Step by step in **docs/DEPLOYMENT.md** (upload to `public_html/ClassNex`, `install.php`, permissions, SSL, cron, integrations,
verification checklist). Cron (verify the PHP path with `which php` first):
`* * * * * /usr/local/bin/php /home/CPANELUSER/public_html/ClassNex/cron/run.php all >/dev/null 2>&1`

## 15. Credentials still required
- MySQL database + user on the server (cPanel).
- Meta WhatsApp Business: verified business, phone number ID, business account ID, permanent token, app secret, approved templates.
- Firebase: `google-services.json` (GitHub secret `GOOGLE_SERVICES_JSON`) + service-account JSON (Super Admin → Integrations).
- SMTP mailbox; SMS gateway (optional, DLT templates in India).
- Razorpay platform keys (and each academy's own keys for parent payments).
- Android signing: keystore + passwords as GitHub secrets (never committed).

## 16. Pending items
1. Deploy to https://genusitsolution.com/ClassNex/ (I have no server access) and run the verification checklist.
2. Confirm the server's PHP CLI path for the cron job.
3. Add the credentials above, test each integration in *Test* mode, then switch to *Live*.
4. Run the physical-device checklist on 1–2 phones; add signing secrets for a signed release / Play Store upload.
5. Note on naming: the brief said “Genuine IT Solutions”; the domain and repository are *Genus IT Solution*, so the website
   footer reads “Operated by Genus IT Solution”. Change it in *Super Admin → Website CMS → SEO & footer* if needed.
