<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
$faq = Settings::web('faq', []);
$c = Settings::web('contact', []);
site_header('Support', 'ClassNex help and support.', '');
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">Support</div><h1>How can we help?</h1><p class="lead text-muted">Already a customer? Log in and open <strong>Help &amp; Support</strong> to raise a ticket — we see your academy details instantly.</p><a class="btn btn-primary" href="login.php">Log in to raise a ticket</a> <a class="btn btn-outline-primary" href="contact.php">Contact us</a></div></section>
<section class="section pt-2"><div class="container" style="max-width:820px"><h2 class="h4 fw-bold mb-3">Common questions</h2><div class="accordion faq" id="faq">
<?php foreach (array_merge($faq, [
    ['q' => 'I forgot my password', 'a' => 'On the login page tap "Forgot password". A reset link is sent to your WhatsApp / email.'],
    ['q' => 'A parent did not receive the WhatsApp message', 'a' => 'Check the number on the student profile and the Message Log for the delivery status. Parents who replied STOP will not receive WhatsApp messages until they reply START.'],
    ['q' => 'How do parents install the app?', 'a' => 'Open the student profile and tap "App invite". The parent receives an activation link, sets a password and logs in on the ClassNex Android app or the website.'],
]) as $i => $q): ?>
  <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#s<?= $i ?>"><?= e($q['q']) ?></button></h3><div id="s<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body text-muted"><?= e($q['a']) ?></div></div></div>
<?php endforeach; ?></div>
<?php if (!empty($c['email'])): ?><p class="text-center mt-4">Still stuck? Email <a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></p><?php endif; ?></div></section>
<?php site_footer();
