# Branch & student-quota test run

Run: 2026-11-18 09:01:17 · Database: classnex_test · PHP 8.3.6

**78 / 78 passed**

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
| 39 | Duplicate cannot be forced through to bypass the limit | PASS |
| 40 | Inactive duplicate → told to re-activate the existing profile | PASS |
| 41 | No duplicate profiles were created | PASS |
| 42 | 10. Super Admin upgrades the academy to the 100-student package | PASS |
| 43 | Custom 45-day billing cycle applied (2026-11-18 → 2027-01-01) | PASS |
| 44 | Re-activation now allowed | PASS |
| 45 | 11. Every branch (incl. new empty ones) can use the new quota | PASS |
| 46 | Combined active count now 22 of 100 (16 + 6 new) | PASS |
| 47 | Dashboard shows 22 / 100 students across all branches | PASS |
| 48 | 8 simultaneous registrations across 3 branches with 1 slot left → exactly 1 succeeds | PASS |
| 49 | 13. Combined report: total active students = sum of branches = unique count (23) | PASS |
| 50 | Branch-wise fee collection (₹1,000 VN + ₹400 Palasia = ₹1,400 total) | PASS |
| 51 | Branch-wise attendance % | PASS |
| 52 | Branch pending fees add up to the academy total | PASS |
| 53 | 12. Individual branch report (fee collection for Palasia only) | PASS |
| 54 | Branch student report | PASS |
| 55 | Combined academy report with per-branch rows and a total row | PASS |
| 56 | Combined branch report exports as PDF | PASS |
| 57 | Combined branch report exports as XLSX | PASS |
| 58 | Combined branch report exports as CSV | PASS |
| 59 | Pending fees filtered by branch | PASS |
| 60 | Payment history filtered by branch | PASS |
| 61 | Branch details: manager, batches, figures | PASS |
| 62 | Super Admin sees combined and per-branch statistics for the academy | PASS |
| 63 | Super Admin owner list shows the branch count | PASS |
| 64 | Branch deactivated (records kept) | PASS |
| 65 | New students cannot be added to an inactive branch | PASS |
| 66 | Other academy sees none of the branches | PASS |
| 67 | Other academy cannot open a branch by ID | PASS |
| 68 | Other academy cannot edit a branch | PASS |
| 69 | Other academy cannot assign into a foreign branch | PASS |
| 70 | Foreign student IDs are ignored by bulk assign | PASS |
| 71 | Cannot register a student into another academy's branch | PASS |
| 72 | Cannot move another academy's student | PASS |
| 73 | Cannot report on another academy's branch | PASS |
| 74 | Quotas are per academy (other academy starts at 0) | PASS |
| 75 | Transactional WhatsApp mode reaches the academy | PASS |
| 76 | Transactional mode: automatic fee reminder is NOT sent on WhatsApp | PASS |
| 77 | Transactional mode: payment receipt IS sent on WhatsApp | PASS |
| 78 | Full mode (Growth package): automatic fee reminder IS sent on WhatsApp | PASS |
