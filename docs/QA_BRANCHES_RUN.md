# Branch & student-quota test run

Run: 2026-11-18 15:36:14 · Database: classnex_subdir_test · PHP 8.3.6

**80 / 80 passed**

| # | Check | Result |
|---|---|---|
| 1 | Migration 003 (branches & student quota) applied on a clean database | PASS |
| 2 | No package carries a branch limit (branches are unlimited by default) | PASS |
| 3 | Seed: Starter = ₹100 for 15 students | PASS |
| 4 | Seed: Growth = ₹200 for 100 students | PASS |
| 5 | Super admin logs in | PASS |
| 6 | Super Admin creates 15-student and 100-student packages (custom 45-day cycle, WhatsApp mode) | PASS |
| 7 | Custom billing cycle requires a number of days | PASS |
| 8 | WhatsApp mode stored on the package | PASS |
| 9 | 1. Academy created on the 15-student package | PASS |
| 10 | 2. Six branches created on the smallest package — no upgrade asked | PASS |
| 11 | Still one subscription for the whole academy (no per-branch subscriptions) | PASS |
| 12 | Batches belong to branches | PASS |
| 13 | Branch manager assigned (teacher) | PASS |
| 14 | 3–4. 15 students registered across two branches (8 + 7) | PASS |
| 15 | Branch taken from the batch automatically | PASS |
| 16 | 5. Combined active count = 15 (all branches together) | PASS |
| 17 | 6. 16th student blocked with the exact upgrade message | PASS |
| 18 | Limit applies to a brand-new empty branch too (no per-branch quota) | PASS |
| 19 | Rejected registrations left no partial records | PASS |
| 20 | At the limit: attendance for existing students still works | PASS |
| 21 | At the limit: fee collection for existing students still works | PASS |
| 22 | Payment and receipt carry the branch | PASS |
| 23 | Fee dues carry the branch | PASS |
| 24 | Receipt identifies both the academy and the branch | PASS |
| 25 | Branch receipt PDF generated | PASS |
| 26 | Deactivating a student frees one slot (history kept) | PASS |
| 27 | Inactive student's fee history is kept | PASS |
| 28 | Freed slot can be used by a new student | PASS |
| 29 | Re-activating beyond the limit is blocked | PASS |
| 30 | 7. Student transferred to another branch (with new batch) | PASS |
| 31 | 8. Transfer does not create a second profile or change the active count | PASS |
| 32 | Old batch closed, new batch active, same Student ID | PASS |
| 33 | Changing to a batch at another branch also moves the branch, count unchanged | PASS |
| 34 | Branch-only move without a batch | PASS |
| 35 | Batch from a different branch is rejected | PASS |
| 36 | Bulk assign students/teachers to a branch, count unchanged | PASS |
| 37 | Branch moves are audited | PASS |
| 38 | Same person at another branch is detected as a duplicate (not a new profile) | PASS |
| 39 | Duplicate override needs a reason | PASS |
| 40 | A verified duplicate override still cannot bypass the student limit | PASS |
| 41 | Inactive duplicate is found (can be linked / re-activated instead) | PASS |
| 42 | No duplicate profiles were created | PASS |
| 43 | 10. Super Admin upgrades the academy to the 100-student package | PASS |
| 44 | Custom 45-day billing cycle applied (2026-11-18 → 2027-01-01) | PASS |
| 45 | Re-activation now allowed | PASS |
| 46 | 11. Every branch (incl. new empty ones) can use the new quota | PASS |
| 47 | Combined active count now 22 of 100 (16 + 6 new) | PASS |
| 48 | Dashboard shows 22 / 100 students across all branches | PASS |
| 49 | 8 simultaneous registrations across 3 branches with 1 slot left → exactly 1 succeeds | PASS |
| 50 | 13. Combined report: total active students = sum of branches = unique count (23) | PASS |
| 51 | Branch-wise fee collection (₹1,000 VN + ₹400 Palasia = ₹1,400 total) | PASS |
| 52 | Branch-wise attendance % | PASS |
| 53 | Branch pending fees add up to the academy total | PASS |
| 54 | 12. Individual branch report (fee collection for Palasia only) | PASS |
| 55 | Branch student report | PASS |
| 56 | Combined academy report with per-branch rows and a total row | PASS |
| 57 | Combined branch report exports as PDF | PASS |
| 58 | Combined branch report exports as XLSX | PASS |
| 59 | Combined branch report exports as CSV | PASS |
| 60 | Pending fees filtered by branch | PASS |
| 61 | Payment history filtered by branch | PASS |
| 62 | Branch details: manager, batches, figures | PASS |
| 63 | Super Admin sees combined and per-branch statistics for the academy | PASS |
| 64 | Super Admin owner list shows the branch count | PASS |
| 65 | Branch deactivated (records kept) | PASS |
| 66 | New students cannot be added to an inactive branch | PASS |
| 67 | Other academy sees none of the branches | PASS |
| 68 | Other academy cannot open a branch by ID | PASS |
| 69 | Other academy cannot edit a branch | PASS |
| 70 | Other academy cannot assign into a foreign branch | PASS |
| 71 | Foreign student IDs are ignored by bulk assign | PASS |
| 72 | Cannot register a student into another academy's branch | PASS |
| 73 | Cannot move another academy's student | PASS |
| 74 | Cannot report on another academy's branch | PASS |
| 75 | Quotas are per academy (other academy starts at 0) | PASS |
| 76 | Manual WhatsApp mode reaches the academy (click-to-send only, no API) | PASS |
| 77 | Manual mode: automatic fee reminder is NOT sent through the WhatsApp API | PASS |
| 78 | Manual mode: nothing is sent in the background through the API | PASS |
| 79 | Manual mode: receipt click-to-send link prepared and logged | PASS |
| 80 | API mode (Growth package): automatic fee reminder IS queued for the WhatsApp API | PASS |
