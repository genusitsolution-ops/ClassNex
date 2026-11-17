<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';

$hero = Settings::web('hero', []);
$features = Settings::web('features', []);
$cats = Settings::web('categories', []);
$steps = Settings::web('steps', []);
$stats = Settings::web('stats', []);
$faq = Settings::web('faq', []);
$testis = DB::all("SELECT * FROM testimonials WHERE status = 'active' ORDER BY sort_order, id LIMIT 6");
$pkgs = site_packages();
site_header('', '', 'index.php');
?>
<section class="hero">
  <div class="container"><div class="row align-items-center g-5">
    <div class="col-lg-6">
      <span class="badge rounded-pill text-bg-light border mb-3 px-3 py-2"><i class="bi bi-stars text-warning"></i> Made in India for academies &amp; classes</span>
      <h1 class="mb-3"><?= preg_replace('/(one simple app)/i', '<span class="grad">$1</span>', e($hero['title'] ?? 'All-in-One Class Management')) ?></h1>
      <p class="lead mb-4"><?= e($hero['subtitle'] ?? '') ?></p>
      <div class="d-flex flex-wrap gap-2 mb-4"><a class="btn btn-primary btn-lg px-4" href="register.php"><?= e($hero['cta_primary'] ?? 'Start Free Trial') ?> <i class="bi bi-arrow-right"></i></a><a class="btn btn-outline-primary btn-lg px-4" href="how-it-works.php"><?= e($hero['cta_secondary'] ?? 'See How It Works') ?></a></div>
      <div class="d-flex flex-wrap gap-4 small text-muted"><span><i class="bi bi-check-circle-fill text-success"></i> Free trial</span><span><i class="bi bi-check-circle-fill text-success"></i> No card needed</span><span><i class="bi bi-check-circle-fill text-success"></i> Web + Android</span></div>
    </div>
    <div class="col-lg-6 text-center position-relative">
      <?php if (!empty($hero['image'])): ?><img src="<?= e($hero['image']) ?>" class="img-fluid rounded-4 shadow" alt="ClassNex"><?php else: ?>
      <img src="assets/img/classnex-logo.png" class="hero-logo mb-4" alt="ClassNex – All-in-One Class Management">
      <div class="hero-card text-start mx-auto" style="max-width:420px">
        <div class="mini"><i class="bi bi-person-check text-white g2"></i><div><strong>Rahul marked Present</strong><div class="small text-muted">Parent notified on WhatsApp · 6:32 AM</div></div></div>
        <div class="mini"><i class="bi bi-receipt text-white g1"></i><div><strong>₹1,500 received</strong><div class="small text-muted">Receipt RCPT/2026-27/00042 shared</div></div></div>
        <div class="mini mb-0"><i class="bi bi-bell text-white g3"></i><div><strong>12 fee reminders sent</strong><div class="small text-muted">Automatically, 3 days before due date</div></div></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="chips justify-content-center mt-5"><?php foreach ($cats as $c): ?><span class="chip"><?= e($c) ?></span><?php endforeach; ?></div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="text-center mb-5"><div class="eyebrow">Everything in one place</div><h2>Powerful inside. Simple outside.</h2><p class="text-muted mx-auto" style="max-width:640px">Built for busy academy owners — not accountants or IT experts. The most-used actions are always one tap away.</p></div>
    <div class="row g-4"><?php foreach ($features as $i => $f): ?>
      <div class="col-md-6 col-lg-3"><div class="feature"><div class="ic g<?= $i % 8 + 1 ?>"><i class="bi <?= e($f['icon'] ?? 'bi-star') ?>"></i></div><h3><?= e($f['title'] ?? '') ?></h3><p><?= e($f['text'] ?? '') ?></p></div></div>
    <?php endforeach; ?></div>
  </div>
</section>

<?php if ($stats): ?><section class="pb-5"><div class="container"><div class="stat-band"><div class="row text-center g-3"><?php foreach ($stats as $s): ?><div class="col-6 col-md-3"><div class="v"><?= e($s['value'] ?? '') ?></div><div class="l"><?= e($s['label'] ?? '') ?></div></div><?php endforeach; ?></div></div></div></section><?php endif; ?>

<section class="section section-alt">
  <div class="container"><div class="row g-5 align-items-center">
    <div class="col-lg-5"><div class="eyebrow">How it works</div><h2 class="mb-4">Up and running in an afternoon</h2>
      <?php foreach ($steps as $i => $s): ?><div class="step"><div class="n"><?= $i + 1 ?></div><h4><?= e($s['title'] ?? '') ?></h4><p><?= e($s['text'] ?? '') ?></p></div><?php endforeach; ?>
      <a href="register.php" class="btn btn-primary btn-lg">Create your academy</a></div>
    <div class="col-lg-7"><div class="row g-3">
      <div class="col-sm-6"><div class="feature"><h3><i class="bi bi-phone text-primary"></i> For owners</h3><p>Dashboard with today's attendance, collections and overdue fees. Add students in 30 seconds with camera &amp; document scan.</p></div></div>
      <div class="col-sm-6"><div class="feature"><h3><i class="bi bi-person-video3 text-success"></i> For teachers</h3><p>See only their batches, mark attendance in one tap, apply for leave. No fee access unless you allow it.</p></div></div>
      <div class="col-sm-6"><div class="feature"><h3><i class="bi bi-people text-warning"></i> For parents</h3><p>Attendance calendar, pending fees, receipts and announcements — for every child, from one login.</p></div></div>
      <div class="col-sm-6"><div class="feature"><h3><i class="bi bi-shield-lock text-danger"></i> Safe &amp; private</h3><p>Each academy's data is fully isolated. Documents are encrypted. Aadhaar is never required.</p></div></div>
    </div></div>
  </div></div>
</section>

<section class="section">
  <div class="container"><div class="text-center mb-5"><div class="eyebrow">Pricing</div><h2>Simple plans that grow with you</h2></div>
    <div class="row g-4 justify-content-center"><?php foreach ($pkgs as $p): ?>
      <div class="col-md-6 col-lg-4"><div class="price-card <?= $p['is_featured'] ? 'featured' : '' ?>"><?php if ($p['is_featured']): ?><span class="tag">Most popular</span><?php endif; ?>
        <h3 class="h5 fw-bold"><?= e($p['name']) ?></h3><div class="amt"><?= e(Money::format((int) $p['price_paise'], '₹', false)) ?><small class="fs-6 text-muted fw-semibold">/<?= e(str_replace('_', ' ', $p['billing_cycle'])) ?></small></div><p class="text-muted small"><?= e($p['description']) ?></p>
        <ul><li><i class="bi bi-check-circle-fill"></i> <?= $p['student_limit'] ? 'Up to ' . (int) $p['student_limit'] : 'Unlimited' ?> students</li><?php foreach (array_slice($p['features'], 0, 6) as $f): ?><li><i class="bi bi-check-circle-fill"></i> <?= e(Feature::FEATURES[$f] ?? $f) ?></li><?php endforeach; ?></ul>
        <a href="register.php?plan=<?= (int) $p['id'] ?>" class="btn <?= $p['is_featured'] ? 'btn-primary' : 'btn-outline-primary' ?> mt-auto">Start <?= (int) $p['trial_days'] ?>-day free trial</a></div></div>
    <?php endforeach; ?></div>
    <p class="text-center mt-4"><a href="pricing.php">Compare all features <i class="bi bi-arrow-right"></i></a></p>
  </div>
</section>

<?php if ($testis): ?><section class="section section-alt"><div class="container"><div class="text-center mb-5"><div class="eyebrow">Loved by academies</div><h2>What owners say</h2></div><div class="row g-4"><?php foreach ($testis as $t): ?>
  <div class="col-md-4"><div class="testi"><div class="stars mb-2"><?= str_repeat('<i class="bi bi-star-fill"></i>', (int) $t['rating']) ?></div><p>“<?= e($t['message']) ?>”</p><div class="fw-bold"><?= e($t['name']) ?></div><div class="small text-muted"><?= e($t['designation']) ?></div></div></div>
<?php endforeach; ?></div></div></section><?php endif; ?>

<?php if ($faq): ?><section class="section"><div class="container" style="max-width:820px"><div class="text-center mb-4"><div class="eyebrow">FAQ</div><h2>Questions? Answers.</h2></div><div class="accordion faq" id="faq"><?php foreach ($faq as $i => $q): ?>
  <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button <?= $i ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#q<?= $i ?>"><?= e($q['q'] ?? '') ?></button></h3><div id="q<?= $i ?>" class="accordion-collapse collapse <?= $i ? '' : 'show' ?>" data-bs-parent="#faq"><div class="accordion-body text-muted"><?= e($q['a'] ?? '') ?></div></div></div>
<?php endforeach; ?></div></div></section><?php endif; ?>
<?php site_cta(); site_footer();
