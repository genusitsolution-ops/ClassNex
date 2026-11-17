<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
site_header('Solutions', 'Class management software for cricket academies, dance studios, yoga classes, music schools, martial arts, swimming, coaching and tuition centres.', 'solutions.php');
$sol = [
    ['🏏', 'Cricket & Football Academies', 'Age-group batches (U-12, U-14…), morning and evening sessions, kit fees and tournament charges, coach-wise attendance.'],
    ['🧘', 'Yoga & Fitness Studios', 'Monthly or quarterly memberships, adult members managing their own accounts, class timetables and renewal reminders.'],
    ['🥋', 'Karate & Martial Arts', 'Belt-level batches, grading/exam fees as one-time charges, multiple branches and instructors.'],
    ['💃', 'Dance Studios', 'Style-wise activities (Kathak, Bollywood, Hip-hop), event fees, announcements and parent communication.'],
    ['🎵', 'Music & Singing Classes', 'Individual and group batches, instrument-wise fees, flexible schedules and quarterly billing.'],
    ['🏊', 'Swimming Classes', 'Seasonal batches with start and end dates, capacity limits and medical notes.'],
    ['🎨', 'Drawing & Art Classes', 'Weekend batches, material charges, exhibition announcements and holiday calendars.'],
    ['📚', 'Coaching & Tuition', 'Subject-wise batches, installment-based fees, attendance alerts for parents and monthly reports.'],
    ['🗣️', 'Language Centres', 'Course-based one-time fees, levels as batches, adult learners and online admission links.'],
];
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">Solutions</div><h1>Made for every kind of class</h1><p class="lead text-muted mx-auto" style="max-width:700px">ClassNex is not limited to one category. Create your own activities, batches and fee plans — the platform adapts to you.</p></div></section>
<section class="section pt-4"><div class="container"><div class="row g-4"><?php foreach ($sol as [$em, $t, $d]): ?><div class="col-md-6 col-lg-4"><div class="solution"><div class="em mb-2"><?= $em ?></div><h3 class="h5 fw-bold"><?= e($t) ?></h3><p class="text-muted mb-0"><?= e($d) ?></p></div></div><?php endforeach; ?></div>
<div class="text-center mt-5"><p class="text-muted">Running something else? Gym, chess, robotics, abacus, theatre… If you take attendance and collect fees, ClassNex fits.</p></div></div></section>
<?php site_cta(); site_footer();
