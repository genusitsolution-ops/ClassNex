<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/auth_layout.php';

$slug = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['a'] ?? ''));
$a = $slug ? DB::one("SELECT id, name, slug, logo_path, address, city, mobile, online_admission_enabled FROM academies WHERE slug = ? AND status = 'active'", [$slug]) : null;
$ok = $a && $a['online_admission_enabled'] && Feature::has((int) $a['id'], 'online_admission') && Subscription::state((int) $a['id'])['writable'];
auth_page_start($a ? 'Admission – ' . $a['name'] : 'Online admission');
if (!$ok) {
    echo '<div class="form-card auth-card text-center"><h1 class="h5 fw-bold">Online admission is not available</h1><p class="text-muted">Please contact the academy directly.</p></div>';
    auth_page_end();
    exit;
}
$aid = (int) $a['id'];
$fields = Settings::academy($aid)['online_admission_fields'];
$acts = DB::all("SELECT id, name, monthly_fee_paise FROM activities WHERE academy_id = ? AND status = 'active' ORDER BY name", [$aid]);
$batches = DB::all("SELECT id, activity_id, name, start_time, end_time FROM batches WHERE academy_id = ? AND status = 'active' ORDER BY name", [$aid]);
?>
<div class="form-card mx-auto" style="max-width:640px">
  <div class="d-flex align-items-center gap-3 mb-3"><?php if ($a['logo_path']): ?><img src="<?= e($a['logo_path']) ?>" style="height:64px;width:64px;object-fit:contain" alt=""><?php endif; ?><div><h1 class="h4 fw-bold mb-0"><?= e($a['name']) ?></h1><div class="text-muted small"><?= e(trim(($a['city'] ?? '') . ' · Online admission form', ' ·')) ?></div></div></div>
  <form data-api="/public/academy/<?= e($a['slug']) ?>/admission" enctype="multipart/form-data" novalidate>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Student name *</label><input class="form-control" name="student_name" required><div class="invalid-feedback"></div></div>
      <div class="col-md-6"><label class="form-label">Parent / guardian name</label><input class="form-control" name="parent_name"></div>
      <div class="col-md-6"><label class="form-label">WhatsApp number *</label><div class="input-group"><span class="input-group-text">+91</span><input class="form-control" name="whatsapp" inputmode="numeric" maxlength="10" required><div class="invalid-feedback"></div></div></div>
      <?php if ($fields['email'] ?? true): ?><div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email"><div class="invalid-feedback"></div></div><?php endif; ?>
      <div class="col-md-6"><label class="form-label">Activity</label><select class="form-select" name="activity_id" id="act"><option value="">Select…</option><?php foreach ($acts as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e($x['name']) ?><?= $x['monthly_fee_paise'] ? ' – ' . e(Money::format((int) $x['monthly_fee_paise'], '₹', false)) . '/month' : '' ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Preferred batch</label><select class="form-select" name="batch_id" id="bat"><option value="">Any</option></select></div>
      <?php if ($fields['dob'] ?? true): ?><div class="col-md-6"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="dob" max="<?= date('Y-m-d') ?>"><div class="invalid-feedback"></div></div><?php endif; ?>
      <?php if ($fields['photo'] ?? true): ?><div class="col-md-6"><label class="form-label">Student photo</label><input class="form-control" type="file" name="photo" accept="image/*" capture="user"></div><?php endif; ?>
      <?php if ($fields['address'] ?? true): ?><div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="2"></textarea></div><?php endif; ?>
      <?php if (($fields['document'] ?? false) && Feature::has($aid, 'documents')): ?><div class="col-12"><label class="form-label">Supporting document (optional – birth certificate etc. Aadhaar is NOT required)</label><input class="form-control" type="file" name="document" accept="image/*,application/pdf"></div><?php endif; ?>
      <div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="message" rows="2"></textarea></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="consent_given" id="cg"><label class="form-check-label small" for="cg">I am the parent / guardian (or the student, if 18+) and I consent to <?= e($a['name']) ?> storing these details to process the admission. <a href="page.php?p=privacy-policy" target="_blank">Privacy policy</a></label><div class="invalid-feedback"></div></div></div>
      <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off">
      <div class="col-12"><button class="btn btn-primary btn-lg w-100" type="submit">Submit application</button><div data-result hidden></div></div>
    </div>
  </form>
</div>
<script>
const B = <?= json_encode(array_map(static fn($b) => ['id' => (int) $b['id'], 'a' => (int) $b['activity_id'], 'n' => $b['name'] . ($b['start_time'] ? ' (' . date('g:i A', strtotime($b['start_time'])) . ')' : '')], $batches)) ?>;
document.getElementById('act').addEventListener('change', (e) => { document.getElementById('bat').innerHTML = '<option value="">Any</option>' + B.filter((b) => String(b.a) === e.target.value).map((b) => `<option value="${b.id}">${b.n.replace(/</g, '&lt;')}</option>`).join(''); });
</script>
<?php auth_page_end();
