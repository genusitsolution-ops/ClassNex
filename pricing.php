<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
site_header('Pricing', 'Simple monthly plans for academies of every size. Start with a free trial.', 'pricing.php');
$pkgs = site_packages();
$lim = static fn($v) => $v === null ? 'Unlimited' : number_format((int) $v);
?>
<section class="page-hero"><div class="container text-center"><div class="eyebrow">Pricing</div><h1>Plans for every academy</h1><p class="lead text-muted">One academy, one subscription. Pay only for active students — add as many branches as you like at no extra cost. Your data is never deleted if a plan expires.</p></div></section>
<section class="section pt-4"><div class="container">
  <div class="row g-4 justify-content-center mb-5"><?php foreach ($pkgs as $p): ?>
    <div class="col-md-6 col-lg-4"><div class="price-card <?= $p['is_featured'] ? 'featured' : '' ?>"><?php if ($p['is_featured']): ?><span class="tag">Most popular</span><?php endif; ?>
      <h3 class="h5 fw-bold"><?= e($p['name']) ?></h3><div class="amt"><?= e(Money::format((int) $p['price_paise'], '₹', false)) ?><small class="fs-6 text-muted fw-semibold">/<?= e($p['billing_cycle'] === 'custom' ? $p['billing_days'] . ' days' : str_replace('_', ' ', $p['billing_cycle'])) ?></small></div><p class="text-muted"><?= e($p['description']) ?></p>
      <ul><li><i class="bi bi-people-fill"></i> <strong><?= $lim($p['student_limit']) ?> active students</strong> across all branches</li><li><i class="bi bi-building"></i> Unlimited branches</li><li><i class="bi bi-person-badge"></i> <?= $lim($p['teacher_limit']) ?> teachers</li><li><i class="bi bi-whatsapp"></i> WhatsApp: <?= e(['full' => 'receipts, attendance & auto reminders', 'transactional' => 'receipts & attendance', 'off' => 'not included'][$p['whatsapp_mode'] ?? 'full']) ?></li></ul>
      <a href="register.php?plan=<?= (int) $p['id'] ?>" class="btn <?= $p['is_featured'] ? 'btn-primary' : 'btn-outline-primary' ?> mt-auto"><?= $p['trial_days'] ? 'Start ' . (int) $p['trial_days'] . '-day free trial' : 'Get started' ?></a></div></div>
  <?php endforeach; ?></div>
  <div class="table-responsive"><table class="table align-middle bg-white rounded-4 overflow-hidden shadow-sm"><thead class="table-light"><tr><th>Feature</th><?php foreach ($pkgs as $p): ?><th class="text-center"><?= e($p['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach (Feature::FEATURES as $k => $label): ?><tr><td><?= e($label) ?></td><?php foreach ($pkgs as $p): ?><td class="text-center"><?= in_array($k, $p['features'], true) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <tr><td>WhatsApp messages / month</td><?php foreach ($pkgs as $p): ?><td class="text-center"><?= in_array('whatsapp', $p['features'], true) ? $lim($p['monthly_whatsapp_limit']) : '–' ?></td><?php endforeach; ?></tr>
    <tr><td>Document storage</td><?php foreach ($pkgs as $p): ?><td class="text-center"><?= $p['storage_limit_mb'] === null ? 'Unlimited' : round($p['storage_limit_mb'] / 1024, 1) . ' GB' ?></td><?php endforeach; ?></tr>
  </tbody></table></div>
  <p class="small text-muted text-center mt-3">Prices exclusive of GST where applicable. WhatsApp conversation charges by Meta are billed as per the platform plan.</p>
</div></section>
<?php site_cta(); site_footer();
