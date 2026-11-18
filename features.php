<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
site_header('Features', 'Student registration, attendance, fee collection, receipts, WhatsApp reminders, parent app and reports — everything an academy needs.', 'features.php');
$groups = [
    ['bi-person-plus', 'g1', 'Quick student registration', ['Name, WhatsApp and batch — done in 30–60 seconds', 'Camera photo capture with auto-compression', 'Scan birth certificate / ID to auto-fill name, DOB and address (you confirm)', 'Aadhaar never required; documents encrypted and auto-deleted after retention period', 'Automatic Student ID, parent account and fee plan', 'Online admission link with approval workflow']],
    ['bi-calendar-check', 'g2', 'One-tap attendance', ['Today\'s date and batch roster load automatically', 'Mark all present, then fix the few absentees', 'Present / Absent / Late / Leave / Holiday', 'No duplicates — corrections are logged with reason', 'Parents informed on WhatsApp & app', 'Daily, monthly and percentage reports']],
    ['bi-cash-coin', 'g3', 'Complete fee management', ['Monthly, quarterly, half-yearly, yearly and one-time fees', 'Activity, batch or student-level amounts', 'Partial payments, advance credit, discounts & scholarships', 'Late fees, fee pauses and prorated first month', 'Cash, UPI, bank, cheque or online (Razorpay)', 'Reversals and refunds with a full audit trail — nothing is silently edited']],
    ['bi-receipt', 'g4', 'Instant receipts', ['Numbered receipt for every payment, automatically', 'Your logo, address, signature and balance', 'PDF download, print, WhatsApp and email', 'Parents can re-download from the app any time', 'Duplicate-payment protection']],
    ['bi-whatsapp', 'g5', 'Automatic reminders', ['Official WhatsApp Business Cloud API', 'Default schedule: 7, 3, 1 days before, on due date, 3 and 7 days after', 'Bulk messages to a batch, activity or everyone', 'Push notifications, email and SMS too', 'Delivery tracking, retries and opt-out handling']],
    ['bi-people', 'g7', 'Teacher & parent apps', ['One Android app for Class Admins, teachers, parents and students', 'Teachers see only their batches', 'Parents switch between children without logging out', 'Attendance calendar, fees, receipts, timetable, announcements']],
    ['bi-bar-chart', 'g6', 'Reports & exports', ['Students, admissions, attendance, fee collection, pending & overdue', 'Batch-wise and activity-wise performance', 'Filter by date, batch, activity or student', 'Export to PDF, Excel or CSV, or print']],
    ['bi-shield-lock', 'g8', 'Security & privacy', ['Each academy\'s data fully isolated', 'Role-based permissions checked on the server', 'Encrypted documents and credentials', 'Parental consent records, retention & deletion controls', 'Daily backups']],
];
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">Features</div><h1>Everything your academy needs</h1><p class="lead text-muted mx-auto" style="max-width:700px">Powerful features, kept simple. Use only what you need — the rest stays out of your way.</p></div></section>
<section class="section pt-4"><div class="container"><div class="row g-4"><?php foreach ($groups as [$ic, $g, $t, $items]): ?>
  <div class="col-lg-6"><div class="feature"><div class="d-flex gap-3 align-items-center mb-2"><div class="ic <?= $g ?> mb-0"><i class="bi <?= $ic ?>"></i></div><h3 class="mb-0"><?= e($t) ?></h3></div><ul class="check-list mb-0 mt-2"><?php foreach ($items as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul></div></div>
<?php endforeach; ?></div></div></section>
<?php site_cta(); site_footer();
