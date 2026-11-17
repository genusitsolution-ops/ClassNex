<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
$c = Settings::web('contact', []);
$cats = Settings::web('categories', []);
site_header('Contact Us', 'Contact the ClassNex team for a demo, pricing or support.', 'contact.php');
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">Contact</div><h1>Let's talk</h1><p class="lead text-muted">Questions, a demo, or help moving from registers and spreadsheets? We reply within one working day.</p></div></section>
<section class="section pt-2"><div class="container"><div class="row g-4">
  <div class="col-lg-7"><form class="form-card" data-api="/public/enquiry" novalidate>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Your name *</label><input class="form-control" name="name" required><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Mobile / WhatsApp</label><input class="form-control" name="mobile" inputmode="tel"><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email"><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Academy name</label><input class="form-control" name="academy_name"></div>
      <div class="col-12"><label class="form-label">Type of class</label><select class="form-select" name="category"><option value="">Select…</option><?php foreach ($cats as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Message</label><textarea class="form-control" rows="4" name="message"></textarea></div>
      <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off"><input type="hidden" name="source" value="contact">
      <div class="col-12"><button class="btn btn-primary btn-lg" type="submit">Send message</button><div data-result hidden></div></div>
    </div></form></div>
  <div class="col-lg-5"><div class="feature"><h3 class="mb-3">Reach us</h3>
    <?php if (!empty($c['email'])): ?><p><i class="bi bi-envelope text-primary me-2"></i><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></p><?php endif; ?>
    <?php if (!empty($c['phone'])): ?><p><i class="bi bi-telephone text-primary me-2"></i><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a></p><?php endif; ?>
    <?php if (!empty($c['whatsapp'])): ?><p><i class="bi bi-whatsapp text-success me-2"></i><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['whatsapp'])) ?>" target="_blank">Chat on WhatsApp</a></p><?php endif; ?>
    <?php if (!empty($c['address'])): ?><p><i class="bi bi-geo-alt text-primary me-2"></i><?= e($c['address']) ?></p><?php endif; ?>
    <?php if (!empty($c['map_embed']) && str_starts_with($c['map_embed'], 'https://www.google.com/maps/embed')): ?><iframe src="<?= e($c['map_embed']) ?>" class="w-100 rounded-3 border-0" height="220" loading="lazy"></iframe><?php endif; ?>
  </div></div>
</div></div></section>
<?php site_footer();
