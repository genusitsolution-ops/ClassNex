<?php
declare(strict_types=1);

/** Public receipt link (sent on WhatsApp). The 32-char random token is the only key. */
require __DIR__ . '/includes/bootstrap.php';

$t = (string) ($_GET['t'] ?? '');
$rc = preg_match('/^[a-f0-9]{32}$/', $t) ? DB::one('SELECT id, receipt_no FROM receipts WHERE public_token = ?', [$t]) : null;
if (!$rc) {
    http_response_code(404);
}
if ($rc && isset($_GET['pdf'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . str_replace('/', '-', $rc['receipt_no']) . '.pdf"');
    header('X-Robots-Tag: noindex');
    echo ReceiptService::pdf((int) $rc['id']);
    exit;
}
$d = $rc ? ReceiptService::data((int) $rc['id']) : null;
header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= $d ? 'Receipt ' . e($d['receipt_no']) : 'Receipt not found' ?> – ClassNex</title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link rel="icon" href="assets/img/favicon.png">
<style>body{background:#f3f5fb}.card{max-width:520px;margin:2rem auto;border:0;border-radius:18px;box-shadow:0 10px 30px rgba(20,30,80,.08)}</style>
</head>
<body>
<div class="card p-4">
<?php if (!$d): ?>
  <h1 class="h4">Receipt not found</h1><p class="text-muted mb-0">This link is invalid. Please ask your academy for a new receipt link.</p>
<?php else: ?>
  <div class="d-flex align-items-center gap-3 mb-3">
    <?php if ($d['logo_path']): ?><img src="<?= e($d['logo_path']) ?>" alt="" style="height:56px;width:56px;object-fit:contain"><?php endif; ?>
    <div><div class="fw-bold fs-5"><?= e($d['academy_name']) ?></div><div class="text-muted small"><?= $d['branch_name'] ? e($d['branch_name']) . ' branch · ' : '' ?>Payment Receipt</div></div>
  </div>
  <?php if ($d['status'] === 'cancelled'): ?><div class="alert alert-danger py-2">This receipt has been cancelled.</div><?php endif; ?>
  <div class="display-6 fw-bold text-primary mb-1"><?= e(Money::format((int) $d['amount_paise'])) ?></div>
  <div class="text-muted mb-3">received on <?= e(date('d M Y', strtotime($d['paid_on']))) ?> · <?= e(ucwords(str_replace('_', ' ', $d['method']))) ?></div>
  <table class="table table-sm mb-3">
    <tr><th class="text-muted fw-normal">Receipt No</th><td class="text-end"><?= e($d['receipt_no']) ?></td></tr>
    <tr><th class="text-muted fw-normal">Student</th><td class="text-end"><?= e($d['student_name']) ?> (<?= e($d['student_code']) ?>)</td></tr>
    <?php foreach ($d['lines'] as $l): ?><tr><th class="text-muted fw-normal"><?= e($l['title']) ?></th><td class="text-end"><?= e(Money::format((int) $l['amount_paise'])) ?></td></tr><?php endforeach; ?>
    <tr><th class="text-muted fw-normal">Remaining balance</th><td class="text-end"><?= e(Money::format((int) $d['balance_after_paise'])) ?></td></tr>
  </table>
  <div class="d-grid gap-2">
    <a class="btn btn-primary btn-lg" href="?t=<?= e($t) ?>&pdf=1&download=1">Download PDF</a>
    <a class="btn btn-outline-secondary" href="?t=<?= e($t) ?>&pdf=1" target="_blank">View / Print</a>
  </div>
  <p class="text-center text-muted small mt-4 mb-0">Powered by <strong>ClassNex</strong> – All-in-One Class Management</p>
<?php endif; ?>
</div>
</body>
</html>
