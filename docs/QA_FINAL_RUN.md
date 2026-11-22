# Final integration test run

Run: 2026-11-22 19:23:27 · Database: classnex_test · PHP 8.3.6 · Server: http://127.0.0.1:8081

**104 / 104 passed**

| # | Check | Result |
|---|---|---|
| | **Setup** | |
| 1 | Migration 004 applied on a clean database | PASS |
| 2 | Every package uses one of the three WhatsApp modes | PASS |
| 3 | Super Admin creates packages in API, Manual and Disabled WhatsApp modes | PASS |
| 4 | Old/unknown WhatsApp mode values are rejected | PASS |
| | **Academy registration → Super Admin approval → activation** | |
| 5 | Website registration is received and waits for approval (no auto-login) | PASS |
| 6 | Academy is pending; the trial has not started yet | PASS |
| 7 | Login explains that the registration is awaiting approval | PASS |
| 8 | Super Admin is notified in the app of the new registration | PASS |
| 9 | Registration request listed for the Super Admin with the requested package | PASS |
| 10 | Dashboard shows the pending registration count | PASS |
| 11 | Super Admin approves the academy | PASS |
| 12 | Trial starts on the approval date | PASS |
| 13 | Approval is audited | PASS |
| 14 | Class Admin logs in after approval | PASS |
| 15 | A Class Admin cannot call Super Admin approval endpoints | PASS |
| 16 | Rejection needs a reason | PASS |
| 17 | Rejected registration cannot log in and is told why | PASS |
| 18 | Role key stays "owner" for API compatibility; package WhatsApp mode is exposed to the apps | PASS |
| 19 | Two branches, three batches, a teacher and seven students set up | PASS |
| | **Attendance history: filters, totals, branch & combined views, exports** | |
| 20 | Combined totals: students, present, absent, late, leave | PASS |
| 21 | Attendance % = (present + late) / (present + late + absent) = 77.8% | PASS |
| 22 | Combined academy view includes a branch-wise breakdown | PASS |
| 23 | Individual branch history (North: 2 classes, 3 students) | PASS |
| 24 | Single-date filter | PASS |
| 25 | Activity filter | PASS |
| 26 | Batch filter | PASS |
| 27 | Student filter (student view lists each day) | PASS |
| 28 | Status filter (absent only) | PASS |
| 29 | Branch view rows add up to the combined totals | PASS |
| 30 | After a branch transfer, old attendance stays in the old branch history and new attendance goes to the new branch | PASS |
| 31 | Moving a batch to another branch does not rewrite past attendance | PASS |
| 32 | Attendance history exports as PDF | PASS |
| 33 | Attendance history exports as XLSX | PASS |
| 34 | Attendance history exports as CSV | PASS |
| 35 | CSV export contains the rows and the totals | PASS |
| 36 | Teacher sees only the history of their own batches | PASS |
| | **Duplicate detection** | |
| 37 | Brother and sister sharing the parent's WhatsApp number are both admitted | PASS |
| 38 | Same name + same WhatsApp + same guardian → possible duplicate shown with reasons | PASS |
| 39 | Candidate details are shown with the number masked; Class Admin may override | PASS |
| 40 | A teacher cannot override a duplicate warning | PASS |
| 41 | Same name and number but different dates of birth are different people (not blocked) | PASS |
| 42 | Same name + same guardian with another number → medium-confidence match | PASS |
| 43 | Class Admin creates a separate student after verification | PASS |
| 44 | Duplicate override is written to the audit log with the reason | PASS |
| 45 | Link existing student: enrols the existing profile, no new student | PASS |
| 46 | Live duplicate check while typing | PASS |
| | **Large academy: server-side search, paging and bulk branch moves** | |
| 47 | 3,000 extra students loaded (0.1s) | PASS |
| 48 | Page 40 of the student list (50 per page, 9 ms) | PASS |
| 49 | Page size is capped by the server (never loads everyone) | PASS |
| 50 | Search by Student ID (9 ms) | PASS |
| 51 | Search by WhatsApp number | PASS |
| 52 | Search by name | PASS |
| 53 | Filter: students not yet in a branch | PASS |
| 54 | Preview shows how many students will move (nothing changed yet) | PASS |
| 55 | “All matching” bulk move of 3,000 students (95 ms) | PASS |
| 56 | Bulk move keeps the same profiles: active count unchanged | PASS |
| 57 | Bulk transfer of selected students into a batch at the new branch | PASS |
| 58 | Bulk transfer rejects a batch from another branch | PASS |
| 59 | Teachers cannot bulk-move students | PASS |
| 60 | Router: fixed paths never collide with /students/{id} | PASS |
| | **WhatsApp package modes: Disabled / Manual click-to-send / Official API** | |
| 61 | Manual mode: click-to-send available, official API not | PASS |
| 62 | Manual mode never sends WhatsApp in the background | PASS |
| 63 | Manual: fee_reminder prepared with a click-to-send link (2) | PASS |
| 64 | Manual: receipt prepared with a click-to-send link (1) | PASS |
| 65 | Manual: attendance prepared with a click-to-send link (1) | PASS |
| 66 | Manual: admission prepared with a click-to-send link (1) | PASS |
| 67 | Manual: announcement prepared with a click-to-send link (2) | PASS |
| 68 | Manual: custom prepared with a click-to-send link (2) | PASS |
| 69 | Custom message is personalised per student | PASS |
| 70 | Manual message history: 9 prepared, the opened one is marked | PASS |
| 71 | History filter by purpose | PASS |
| 72 | Receipt share link is logged in the history too | PASS |
| 73 | Manual history is private to each academy | PASS |
| 74 | Disabled mode: no WhatsApp features | PASS |
| 75 | Disabled mode: click-to-send is refused | PASS |
| 76 | API mode: messages queued for background sending; the same request twice creates no duplicates | PASS |
| 77 | API mode: scheduled message waits in the queue until its time | PASS |
| 78 | Queue run does not send a scheduled message early | PASS |
| 79 | Scheduling in the past is rejected | PASS |
| | **Web and Android use the same API and rules** | |
| 80 | Branch created on the web appears in the Android API | PASS |
| 81 | Student registered on Android appears on the web | PASS |
| 82 | Branch transfer on the web is visible on Android (same Student ID) | PASS |
| 83 | Attendance history totals identical on web and Android | PASS |
| 84 | Student quota enforced identically for web and Android | PASS |
| | **Unauthorized access** | |
| 85 | No login → 401 | PASS |
| 86 | Teacher cannot create branches | PASS |
| 87 | Class Admin cannot see platform registration requests | PASS |
| 88 | Another academy cannot filter by a foreign branch | PASS |
| 89 | Another academy cannot prepare WhatsApp for a foreign student | PASS |
| 90 | Marking another academy's message has no effect | PASS |
| 91 | Web write without CSRF token is refused (403) | PASS |
| | **Android app redesign: dashboard branch filter, registration status, teacher batches** | |
| 92 | Dashboard gives total branches, late / leave counts and scheduled reminders | PASS |
| 93 | Dashboard can be filtered to one branch (figures match the branch) | PASS |
| 94 | Branch filter keeps the one academy-wide student quota | PASS |
| 95 | Dashboard rejects a branch of another academy | PASS |
| 96 | Track Registration Status shows approved / rejected with the reason | PASS |
| 97 | Registration status needs the correct password (no information leak) | PASS |
| 98 | Teacher list includes assigned batch ids so the app can edit them safely | PASS |
| | **Subscription expiry** | |
| 99 | Expired: changes blocked, history still readable | PASS |
| 100 | Expired: attendance cannot be marked until renewal | PASS |
| | **Role names and installation paths** | |
| 101 | Super Admin goes to /admin/ | PASS |
| 102 | Class Admin goes to /class-admin/ | PASS |
| 103 | Old /owner/ address redirects to /class-admin/ | PASS |
| 104 | Class Admin panel shows “Class Admin”, not “Owner” | PASS |
