<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
$a = Settings::web('about', []);
site_header('About Us', 'About ClassNex — all-in-one class management software for academies and institutes.', 'about.php');
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">About</div><h1><?= e($a['title'] ?? 'About ClassNex') ?></h1></div></section>
<section class="section pt-4"><div class="container" style="max-width:860px"><div class="row g-5 align-items-center"><div class="col-md-5 text-center"><img src="assets/img/classnex-logo.png" class="img-fluid" alt="ClassNex"></div><div class="col-md-7 cms-content"><?= nl2br(e($a['body'] ?? '')) ?></div></div>
<div class="row g-4 mt-4"><div class="col-md-4"><div class="feature"><h3>Simple first</h3><p>If a Class Admin needs training to use a screen, we redesign the screen.</p></div></div><div class="col-md-4"><div class="feature"><h3>Your data, protected</h3><p>Strict isolation between academies, encryption, and privacy by default for children's data.</p></div></div><div class="col-md-4"><div class="feature"><h3>Built to last</h3><p>A dependable platform on standard technology that runs on everyday hosting.</p></div></div></div></div></section>
<?php site_cta(); site_footer();
