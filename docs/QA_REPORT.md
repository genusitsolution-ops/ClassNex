# QA Report – ClassNex (final delivery, 18 Nov 2026)

Everything below actually ran. Nothing is reported as passed unless it ran and passed. Items that could not be tested
are listed as **pending** with the reason.

## Summary

| Area | Result | Where it ran |
|---|---|---|
| Automated API / business tests – 3 suites, **284 checks** | **284 / 284 passed** | (a) PHP 8.3 + MariaDB 10.11 dev server; (b) **Apache 2.4 + mod_php, installed under `/ClassNex/` through `install.php`**; (c) GitHub Actions, PHP 8.2 + **MySQL 8.0** |
| Browser tests (headless Chrome) – every screen of every role, desktop **and** phone width | **187 / 187 passed** | (a) dev server; (b) Apache `/ClassNex/` installation |
| Android – compile, API contract unit tests, debug APK, release APK + AAB (R8) | **Passed** | GitHub Actions (see run IDs in DELIVERY_REPORT.md) |
| Android on a physical device | **Pending** – no device available to the developer | checklist below |
| WhatsApp Cloud API, Firebase push, SMTP, SMS, Razorpay **live** | **Awaiting credentials** – not verified against the real services | see “External integrations” |
| Live server https://genusitsolution.com/ClassNex/ | **Not deployed by me** (no server access); an identical Apache sub-folder install was tested | DEPLOYMENT.md |

## Automated suites

| Suite | Checks | What it covers | Per-check log |
|---|---|---|---|
| `tests/e2e_final.php` | 97 | Website registration → pending → login refused with explanation → Super Admin notified → approve with package (trial starts on approval, audited) → login; rejection with reason; unauthorized approval. **Attendance history**: combined totals (students, present, absent, late, leave, %), branch-wise breakdown, single branch, single date, date range, activity, batch, student, status, branch view, history stays with the old branch after a student **or a whole batch** moves, PDF / Excel / CSV exports, teacher sees only own batches. **Duplicates**: siblings sharing a number admitted, same name + number + guardian flagged (high), same name + guardian (medium), different DOBs not flagged, teacher cannot override, Class Admin override with reason → audit log, link existing student (no new profile), live check while typing. **Large data**: 3,000 extra students, page 40 of 61, page size capped, search by Student ID / WhatsApp / name with timings, “not in this branch” filter, preview count, “all matching” bulk move of 3,000 students, bulk transfer into a batch, wrong-branch batch refused, teacher refused, quota unchanged. **WhatsApp modes**: API / Manual / Disabled packages, manual never queues API messages, click-to-send prepared for fee reminder, receipt, attendance, admission, announcement and custom (personalised) with correct wa.me links, history with “opened”, purpose filter, receipt share logged, history private per academy, disabled mode refused, API mode queue with duplicate prevention, scheduled message waits in the queue and is not sent early, past schedule refused. **Web ↔ Android**: branch created on web visible via token, student created via token visible on web, transfer on web visible on Android, identical history totals, identical quota refusal. **Unauthorized** access cases, **subscription expiry** (writes blocked, history readable, attendance blocked), role names, `/class-admin/` redirect, old `/owner/` 301. | QA_FINAL_RUN.md |
| `tests/e2e_branches.php` | 80 | Unlimited branches on the smallest package, one academy-wide student quota, exact limit message, 16th student blocked at any branch, work continues at the limit, deactivation frees a slot, re-activation re-checked, transfers / bulk assign keep the count, duplicate override cannot bypass the limit, upgrade to 100 applies to all branches, custom 45-day cycle, **8 parallel registrations with 1 slot left → exactly 1 succeeds**, branch-wise and combined reports (PDF/XLSX/CSV), branch receipts, Super Admin branch stats, cross-academy isolation, WhatsApp manual vs API behaviour | QA_BRANCHES_RUN.md |
| `tests/e2e.php` | 107 | The 30 original business workflows (packages, academy, quick registration with photo/documents/OCR, parents, attendance + corrections, fees: monthly, one-time, partial, advance, discounts, late fee, reversals, refunds, receipts, reminders, queue retries, WhatsApp webhook, opt-out, tenant isolation, web+Android consistency, subscriptions, online admissions, reports, sessions, cron idempotency, **backup + restore into a new database**, account lockout) | QA_TEST_RUN.md |
| `tests/ui_smoke.py` | 187 | Logs in as Class Admin, teacher, parent and Super Admin; opens every screen (incl. Branches, branch detail, attendance history views, WhatsApp history, registration requests); fails on any JavaScript error or error banner; then opens **every screen again at 390 px phone width** and fails if the page is wider than the screen. Also the public website pages. | console output |
| `tests/subdir_install_test.sh` | – | Copies the project into an Apache web root as `/ClassNex/`, runs **install.php over HTTP**, checks the installer locks itself (403) and that `config/`, `storage/`, `database/install.sql`, `docs/`, `cron/`, `classes/`, `includes/`, `.git/` all return **403**. All four suites above were then run against `http://127.0.0.1:8090/ClassNex`. | – |
| `android/.../ApiContractTest.kt` | 7 tests | Real API responses (21 fixtures captured by `tests/android_fixtures.php`) parsed with the app's own models: Me + WhatsApp mode, dashboard quota, branches / summary / detail, branch students, attendance history (3 views), reports, bulk preview, duplicate candidates, WhatsApp click-to-send, invoices, payments | GitHub Actions log |

## Bugs found and fixed during this final phase

| Found by | Problem | Fix |
|---|---|---|
| Apache `/ClassNex/` test | CSRF failures returned HTTP 419, which Apache turns into **500** | CSRF now answers 403 with code `csrf` |
| Apache `/ClassNex/` test | Session cookie path was `/` (shared with other sites on the domain) | Cookie limited to the installation path |
| Audit | `uploads/.htaccess` used `php_flag`, which breaks PHP-FPM hosts with a 500 | wrapped in `<IfModule>` |
| Audit | Website registrations went live without Super Admin approval (business rule) | Approval workflow (web + Android) |
| e2e_final | Attendance history **student and status filters returned nothing** (SQL parameters bound in the wrong order) | fixed |
| e2e_final | Registration requests list used a non-existent column | fixed |
| e2e_final | Searching a name containing numbers also matched phone digits | phone match only for number-like searches |
| Router review | `/students/bulk-branch` could be captured by `/students/{id}` | `{id}` parameters match digits only |
| Android CI | JVM signature clash in the student list ViewModel | renamed |

## Performance (3,000-student academy, dev server, single PHP process)

Student list page 40 (50 per page) and searches by Student ID / name / WhatsApp each returned well under 2 seconds
(the suite fails above 2 s); moving all 3,000 students to another branch in one “all matching” operation stayed under the
15-second limit of the test. Exact timings are printed in each run.

## External integrations

| Integration | Status |
|---|---|
| WhatsApp manual click-to-send | **Working and tested** (no credentials needed) |
| WhatsApp Cloud API | Request building, queue, retries (non-retryable 401 vs retryable errors), webhook status updates, STOP opt-out and duplicate prevention are tested against a simulated endpoint. **Live sending awaits** Meta business verification, number, token, app secret and approved templates. |
| Firebase push (FCM v1) | Code in place (queueing is covered by the tests; actual delivery is not); **awaits** `google-services.json` + service-account key. Not verified end-to-end. |
| SMTP / SMS | Code in place; **not verified** – needs a mailbox / gateway to test against. |
| Razorpay | Code in place (order creation, signature verification, webhooks); **not covered by the automated tests and not verified** – needs test keys first, then live keys. |

## Physical device checklist (pending – please run on 1–2 real phones)

1. Install `ClassNex-debug.apk` from Releases → *android-latest*; log in as Class Admin.
2. Dashboard shows *N of M students · all branches*. Branches → add a branch, edit it, deactivate / activate it.
3. Branch → Assign students → search, select several, assign (confirmation shows the count).
4. Students → branch filter; open a student → Change branch → Student ID unchanged.
5. Add Student with camera photo and document scan (OCR fills fields); register a sibling with the same number (allowed); register the same child again (duplicate dialog → Link this student).
6. Attendance → everyone pre-marked present → mark one absent → Save (manual-WhatsApp package: “Prepare WhatsApp” opens WhatsApp).
7. Attendance history → filters, By branch, download PDF and Excel (opens in a viewer).
8. Collect a fee → receipt PDF opens / shares; Pending fees → Remind all overdue.
9. Log in as a teacher and as a parent; check menus and data; register a new academy from the login screen (waits for approval).
10. With Firebase configured: a message from the web arrives as a push notification.
