<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/auth_layout.php';

$pkgs = DB::all("SELECT id, name, price_paise, billing_cycle, trial_days FROM packages WHERE status = 'active' AND is_public = 1 ORDER BY sort_order");
$sel = (int) ($_GET['plan'] ?? Settings::int('default_trial_package_id', 2));
$cats = Settings::web('categories', []);
$open = Settings::get('self_registration_enabled', '1') === '1';
auth_page_start('Register your academy');
?>
<div class="form-card mx-auto" style="max-width:620px">
  <h1 class="h4 fw-bold text-center mb-1">Create your academy</h1>
  <p class="text-center text-muted mb-4">Free trial · No credit card · Ready in 2 minutes</p>
  <?php if (!$open): ?><div class="alert alert-info">Online registration is currently closed. <a href="contact.php">Contact us</a> and we'll set up your account.</div><?php else: ?>
  <form data-api="/public/register-owner" data-on-success="redirect" novalidate>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Your name *</label><input class="form-control" name="owner_name" required><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Mobile number * <small class="text-muted">(your login)</small></label><div class="input-group"><span class="input-group-text">+91</span><input class="form-control" name="mobile" inputmode="numeric" maxlength="10" required><div class="invalid-feedback"></div></div><input type="hidden" name="country_code" value="+91"></div>
      <div class="col-md-6"><label class="form-label">Academy / class name *</label><input class="form-control" name="academy_name" required><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">City</label><input class="form-control" name="city"></div>
      <div class="col-md-6"><label class="form-label">Type of class</label><select class="form-select" name="category"><option value="">Select…</option><?php foreach ($cats as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Email <small class="text-muted">(optional)</small></label><input class="form-control" type="email" name="email"><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Plan</label><select class="form-select" name="package_id"><?php foreach ($pkgs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] == $sel ? 'selected' : '' ?>><?= e($p['name']) ?> – <?= e(Money::format((int) $p['price_paise'], '₹', false)) ?>/<?= e(str_replace('_', ' ', $p['billing_cycle'])) ?><?= $p['trial_days'] ? ' (' . (int) $p['trial_days'] . ' days free)' : '' ?></option><?php endforeach; ?></select><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Create password *</label><input class="form-control" type="password" name="password" autocomplete="new-password" required><div class="invalid-feedback"></div><div class="form-text">8+ characters with letters and numbers</div></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="accept_terms" id="t"><label class="form-check-label small" for="t">I agree to the <a href="page.php?p=terms" target="_blank">Terms</a> and <a href="page.php?p=privacy-policy" target="_blank">Privacy Policy</a></label><div class="invalid-feedback"></div></div></div>
      <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off"><input type="hidden" name="client" value="web">
      <div class="col-12"><button class="btn btn-primary btn-lg w-100" type="submit">Create my academy</button><div data-result hidden></div></div>
    </div>
  </form><?php endif; ?>
  <p class="text-center mt-4 mb-0 small">Already registered? <a href="login.php" class="fw-bold">Log in</a></p>
</div>
<?php auth_page_end();
