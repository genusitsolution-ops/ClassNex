# ClassNex E2E Test Run

Date: 2026-11-23 21:48 IST  
Database: MariaDB/MySQL `10.11.14-MariaDB-0ubuntu0.24.04.1`  
PHP: 8.3.6

**107 passed, 0 failed**

| # | Result | Check |
|---|---|---|
| 1 | PASS | Migrations applied on a clean database |
| 2 | PASS | Super admin logs in (Bearer token) |
| 3 | PASS | Wrong password is rejected |
| 4 | PASS | 1. Super Admin creates a package |
| 5 | PASS | 2. Super Admin creates a Class Owner with academy |
| 6 | PASS | 3. Owner receives an active subscription on the package |
| 7 | PASS | Duplicate owner mobile is rejected |
| 8 | PASS | Owner logs in with mobile number |
| 9 | PASS | 4. Owner completes academy profile (with logo) |
| 10 | PASS | 5. Owner creates an activity |
| 11 | PASS | 6. Owner creates a batch with weekly schedule |
| 12 | PASS | Owner adds a teacher assigned to the Morning batch |
| 13 | PASS | Teacher logs in |
| 14 | PASS | 7. Quick registration with only essential fields → Student ID ACA0001 |
| 15 | PASS | 8. Student photo captured, compressed and stored privately |
| 16 | PASS | Photo is served only through the authenticated API (JPEG) |
| 17 | PASS | Private storage is not publicly reachable |
| 18 | PASS | Parent record created and linked from guardian name |
| 19 | PASS | Monthly (₹1,500) and registration (₹500) dues generated automatically |
| 20 | PASS | Duplicate registration is detected |
| 21 | PASS | Invalid mobile number is rejected with a field error |
| 22 | PASS | Guardian name required for minors |
| 23 | PASS | 10. OCR extracts name, DOB, gender, address and guardian |
| 24 | PASS | Aadhaar number is masked, never returned |
| 25 | PASS | 9. Owner uploads a document |
| 26 | PASS | Document encrypted at rest; identity number not stored |
| 27 | PASS | Authorised user can view the decrypted document |
| 28 | PASS | 11. Owner verifies OCR values and saves the profile |
| 29 | PASS | 12. Parent account created and invitation link generated |
| 30 | PASS | Parent activates the account and sets a password |
| 31 | PASS | Activation link cannot be reused |
| 32 | PASS | Parent logs in and sees the linked child |
| 33 | PASS | Parent with two children can switch between them |
| 34 | PASS | Teacher opens today's attendance sheet for an assigned batch |
| 35 | PASS | Teacher cannot open a batch that is not assigned |
| 36 | PASS | 13. Teacher marks attendance |
| 37 | PASS | Re-submitting does not duplicate attendance |
| 38 | PASS | 14. Parent receives attendance notification (in-app) |
| 39 | PASS | Same-day correction is saved with audit history |
| 40 | PASS | Correction triggers one parent WhatsApp update |
| 41 | PASS | Teacher cannot change old attendance without permission |
| 42 | PASS | Parent sees attendance history |
| 43 | PASS | Teacher has no financial access by default |
| 44 | PASS | 15. Monthly fee assigned; outstanding ₹2,000 |
| 45 | PASS | 16. Owner collects a partial payment |
| 46 | PASS | 17. Receipt generated automatically: RCPT/2026-27/00001 |
| 47 | PASS | Duplicate submission does not create a second payment or receipt |
| 48 | PASS | 19. Outstanding balance recalculated: ₹1,000 pending |
| 49 | PASS | 18. Parent downloads the receipt PDF |
| 50 | PASS | Receipt share link (WhatsApp) downloads the same PDF |
| 51 | PASS | Parent fee view matches owner view (same database) |
| 52 | PASS | Advance payment: ₹1,000 settles dues, ₹1,500 kept as credit |
| 53 | PASS | Advance credit applied automatically to a new charge |
| 54 | PASS | Refund taken from unused advance first |
| 55 | PASS | Reversal re-opens dues and cancels the receipt (nothing deleted) |
| 56 | PASS | Ledger keeps both the original and the reversal rows |
| 57 | PASS | Invoice paid totals reconcile exactly with the allocation ledger |
| 58 | PASS | Negative amounts are rejected |
| 59 | PASS | 20. Automatic reminders scheduled for the open invoice (offsets: 3,7 days; same-day reminders skipped) |
| 60 | PASS | 21. Cron processes the due reminder |
| 61 | PASS | Fee reminder queued for WhatsApp |
| 62 | PASS | Running the reminder cron again does not duplicate |
| 63 | PASS | 22. WhatsApp message processed (TEST mode: simulated, logged) |
| 64 | PASS | Reminder text is personalised: Dear Parent, the Cricket Monthly Fee – Nov 2026 of ₹1,000.00 for Rahul Sharma was due on 23 Nov 2026 and is still pending. Please pay at the earliest. – ABC Cricket Academy |
| 65 | PASS | Manual reminder sent to a selected student |
| 66 | PASS | 23. Failed send is retried later with backoff (attempt 1 failed: Failed to connect to 127.0.0.1 port 9 after 0 ms: Couldn't c) |
| 67 | PASS | Failed message can be retried manually and then succeeds |
| 68 | PASS | Delivery status is updated from the WhatsApp webhook |
| 69 | PASS | Opt-out (STOP) is respected |
| 70 | PASS | 24. Super Admin changes the package |
| 71 | PASS | 25. WhatsApp disabled immediately by the backend; in-app still works |
| 72 | PASS | Reports blocked when not in the package (HTTP 402) |
| 73 | PASS | Student limit of the package is enforced |
| 74 | PASS | Second academy registers its own student |
| 75 | PASS | 26. Owner cannot open another academy's student |
| 76 | PASS | Owner cannot see another academy's fees |
| 77 | PASS | Owner cannot collect fees for another academy |
| 78 | PASS | Student list contains only own academy records |
| 79 | PASS | 27. Parent cannot access another family's child |
| 80 | PASS | Parent cannot access a non-linked student of the same academy |
| 81 | PASS | Parent cannot download another family's receipt |
| 82 | PASS | Owner logs into the web panel (session cookie) |
| 83 | PASS | Web write without CSRF token is rejected |
| 84 | PASS | Student added through the web panel |
| 85 | PASS | 28. Student added on web appears in the Android API |
| 86 | PASS | 29. Attendance marked on Android appears on web |
| 87 | PASS | 30. Payment & receipt identical on web and Android |
| 88 | PASS | Expired subscription → account becomes read-only |
| 89 | PASS | Expired account can still read its data (nothing deleted) |
| 90 | PASS | Renewal restores full access |
| 91 | PASS | Online admission creates a pending application (not an active student) |
| 92 | PASS | Owner approves → student registered |
| 93 | PASS | Fee collection report returns live data |
| 94 | PASS | Pending fee report exports as CSV |
| 95 | PASS | Pending fee report exports as XLSX |
| 96 | PASS | Pending fee report exports as PDF |
| 97 | PASS | Super Admin subscription report |
| 98 | PASS | Owner dashboard uses real database figures |
| 99 | PASS | Super Admin dashboard statistics |
| 100 | PASS | Owner cannot open Super Admin endpoints |
| 101 | PASS | Logged-out token no longer works |
| 102 | PASS | Expired session token is rejected |
| 103 | PASS | Invoice cron is idempotent (repeated runs create no duplicates) |
| 104 | PASS | All cron tasks completed without errors |
| 105 | PASS | Database backup created: classnex-20261123-214851.sql.gz |
| 106 | PASS | Backup restores into a new database (4 payments match) |
| 107 | PASS | Account locks for 15 minutes after repeated wrong passwords |
