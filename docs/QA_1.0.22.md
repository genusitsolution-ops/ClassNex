# QA report – ClassNex Android 1.0.22

Date: 23 Nov 2026 · Commit tested: `030e9aa` (code identical to `bf2a659` apart from one UI-test selector and a CI clean-up)

## What was actually run
| Suite | Where | Result |
|---|---|---|
| Android compile + unit tests, debug APK, release APK, AAB | GitHub Actions "Android build" | ✅ success |
| Emulator UI flow test (`UiFlowTest`, 44 steps, 5 roles) | GitHub Actions, Android 11 (API 30) emulator, real PHP + MySQL API seeded with test data | ✅ **44 passed, 0 failed**, 68 screenshots |
| Backend API end-to-end (`tests/e2e.php`) | local PHP 8.2 + MariaDB | ✅ 107 / 107 |
| Branch model (`tests/e2e_branches.php`) | local | ✅ 80 / 80 |
| Final checks incl. simple settings (`tests/e2e_final.php`) | local | ✅ 110 / 110 |
| Web panel smoke test (`tests/ui_smoke.py`) | local headless browser | ✅ 187 / 187 |
| Backend tests workflow | GitHub Actions | ✅ success |

Evidence: `testing/ui-steps.txt` (emulator log), `testing/screenshots/` (68 PNGs), `testing/backend-logs/`.

## Emulator steps passed
- onboarding pages
- login page
- wrong password shows a message
- register page
- track registration status
- login as Class Admin
- dashboard: quick actions first
- more tab
- add student form
- students list
- student profile
- mark attendance
- fees – pending and overdue
- collect fee in simple steps
- send reminder
- reminders and whatsapp settings
- payments and receipts
- branches
- activities and batches
- teachers and teacher form
- fee plans
- discounts
- timetable
- attendance history
- announcements
- reports
- subscription
- academy profile
- settings and academy settings
- help and support
- notifications
- login as teacher
- teacher dashboard
- teacher More shows only permitted sections
- teacher attendance
- login as parent
- parent home
- parent attendance
- parent fees
- parent More
- login as Super Admin
- super admin dashboard
- registrations
- academies

## Issues found and fixed during QA
| Issue | Root cause | Fix |
|---|---|---|
| UI step "fees – pending and overdue" timed out on the first 1.0.22 run (43/44) | The test tapped the text "Dancer 1" while the keyboard was open; the search box itself contained "Dancer 1", so the tap most likely landed on the search field, not the result row, so the student card never opened | Test now hides the keyboard and taps the clickable result row (matched by student ID). App code unchanged. Re-run: 44/44 |
| 1.0.21 builds were not kept as a separate GitHub release | CI token is not allowed to create that release (HTTP 403) | 1.0.21 APK/AAB are included in this package under `previous-builds/1.0.21/`; the one-off CI step was removed |

## Visual review (screenshots)
Home, Students (+ filter sheet), Attendance, Fees (3 tabs + student card), More (grouped tiles), Receipts & IDs, Activity log, teacher and parent homes were reviewed. No clipped text, overlapping elements or empty screens found. Bottom nav pill, light top bars and status pills render as in the reference.

## Not done – stated plainly
- **No physical-device testing.** Only the API 30 emulator was used. Please install the debug APK on at least one real phone (Android 7+ and a recent Android 14/15) before release.
- **Release APK/AAB are unsigned** – no keystore was provided. They cannot be uploaded to Play until signed.
- **Not deployed** to Google Play or production.
- Reduced-motion behaviour was implemented (animations skip when animator scale is 0) but was not exercised by an automated test.
- Live Razorpay, FCM push delivery and WhatsApp API sending were not tested end-to-end (need production credentials).
