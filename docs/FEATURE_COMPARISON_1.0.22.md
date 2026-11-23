# Web vs Android – Class Admin feature comparison (app 1.0.22)

Legend: ✅ available · 🆕 added in 1.0.22 · ↗ opens the web panel · ❌ not in the app (reason given)

Every Android screen reads and writes through the same `/api/v1` endpoints as the web panel; nothing is mocked. Plan features (`me.has`) and teacher permissions (`me.can`) hide items exactly as on the web.

## Academy Management
| Feature | Web | Android |
|---|---|---|
| Branches – list, add, edit, branch students, assign/transfer students | ✅ | ✅ |
| Teachers – add, edit, permissions, branch access, invite | ✅ | ✅ |
| Teacher photo upload | ✅ | ❌ – the teacher form sends JSON; photo upload is web-only for now |
| Activities, Batches (add, edit, students in batch) | ✅ | ✅ |
| Students – add (with photo), edit, deactivate, search | ✅ | ✅ |
| Student list filters: status, activity, batch | ✅ | 🆕 |
| Student documents – upload, view, delete | ✅ | ✅ |
| Enrol a student in another activity | ✅ | 🆕 |
| Parents – list, link to student, invite to app | ✅ | ✅ |
| Unlink a parent from a student | ✅ | 🆕 |
| Bulk move students between branches | ✅ | ✅ (branch assign screen) |
| Bulk import from Excel / photo (OCR) | ✅ | ❌ – needs file parsing UI; use the web panel |

## Operations
| Feature | Web | Android |
|---|---|---|
| Timetable | ✅ | ✅ |
| Admission requests – approve / reject | ✅ | ✅ |
| Online admission settings (on/off, fields) | ✅ | 🆕 |
| Holidays, Teacher leaves, Announcements | ✅ | ✅ |
| Mark attendance, attendance history | ✅ | ✅ (redesigned) |

## Finance & Reports
| Feature | Web | Android |
|---|---|---|
| Collect fee (cash/UPI/card/bank, advance) | ✅ | ✅ (redesigned) |
| Pending / overdue fees | ✅ | ✅ (tabs) |
| Payments list, receipts | ✅ | ✅ |
| Refund a payment | ✅ | 🆕 |
| Cancel a fee (invoice) | ✅ | 🆕 |
| Fee plans, discounts | ✅ | ✅ |
| Change a student's fee assignment | ✅ | ❌ – no form yet (PUT `/fee-assignments`); planned |
| Reports (fees, attendance, students) | ✅ | ✅ (view) |
| Report export PDF / Excel / CSV | ✅ | ❌ – exports are web downloads; view-only in the app |
| Receipts & IDs settings (prefixes, footer, pro-rata, confirmations) | ✅ | 🆕 |
| Online payments (Razorpay keys) | ✅ | 🆕 (secret fields left blank keep the saved value) |

## Communication
| Feature | Web | Android |
|---|---|---|
| Reminders ON/OFF, WhatsApp choice | ✅ | ✅ |
| Advanced reminder schedule matrix | ✅ | ✅ (Settings → Notification settings) |
| Message student/parent, message log, WhatsApp log | ✅ | ✅ |
| Notifications | ✅ | ✅ (push via FCM) |

## Account
| Feature | Web | Android |
|---|---|---|
| Academy profile | ✅ | ✅ |
| Subscription – view plan and usage | ✅ | ✅ |
| Subscription – pay / upgrade | ✅ | ↗ opens the web panel (payment checkout is web) |
| Activity log | ✅ | 🆕 |
| Help & support tickets | ✅ | ✅ |
| Change password, logout | ✅ | ✅ |

## Roles
Owner (Class Admin), Teacher, Parent, Student and Super Admin each get their own home and menu. Teachers see only the items their permissions allow (verified in the emulator run: teacher More shows Academy Management with permitted items only). Parent/Student portal and Super Admin screens were restyled with the same components but have no new features in this release.
