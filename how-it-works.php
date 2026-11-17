<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
site_header('How It Works', 'Set up your academy on ClassNex in minutes: add activities, batches and students, then let attendance alerts, receipts and fee reminders run automatically.', 'how-it-works.php');
$flow = [
    ['bi-building-add', 'Register your academy', 'Sign up with your mobile number. Your free trial starts immediately — no card needed.'],
    ['bi-collection', 'Add activities and batches', 'Cricket, Dance, Maths… set the monthly fee once. Add batch timings and class days.'],
    ['bi-person-plus', 'Register students in seconds', 'Photo, name, WhatsApp number, batch — save. Or share your online admission link and approve applications.'],
    ['bi-calendar-check', 'Mark attendance', 'Open the batch, tap "All present", fix exceptions, save. Parents are informed automatically.'],
    ['bi-cash-coin', 'Collect fees', 'Dues are created every month. Collect full or partial payments — the receipt is generated and shared on WhatsApp.'],
    ['bi-alarm', 'Reminders run themselves', 'Before and after each due date, families get a polite reminder with their exact amount. You stop chasing.'],
    ['bi-graph-up', 'Track everything', 'See collections, dues, attendance and admissions on your dashboard; export reports any time.'],
];
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">How it works</div><h1>From sign-up to automatic reminders</h1><p class="lead text-muted">Seven simple steps. Most academies finish setup on day one.</p></div></section>
<section class="section pt-4"><div class="container" style="max-width:820px"><?php foreach ($flow as $i => [$ic, $t, $d]): ?>
  <div class="step"><div class="n"><i class="bi <?= $ic ?>"></i></div><h4><?= $i + 1 ?>. <?= e($t) ?></h4><p><?= e($d) ?></p></div>
<?php endforeach; ?></div></section>
<?php site_cta(); site_footer();
