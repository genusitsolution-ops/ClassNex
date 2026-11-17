<?php
declare(strict_types=1);

function auth_page_start(string $title): void
{
    header('X-Frame-Options: DENY');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($title) ?> · ClassNex</title><link rel="icon" href="assets/img/favicon.png">
<link href="assets/vendor/fonts/fonts.css" rel="stylesheet">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/site.css?v=<?= CNX_VERSION ?>" rel="stylesheet"></head>
<body><div class="auth-wrap"><div class="container">
<div class="text-center mb-4"><a href="index.php"><img src="assets/img/classnex-logo.png" alt="ClassNex" style="max-width:190px"></a></div>
<?php
}

function auth_page_end(): void
{
    ?>
<p class="text-center small text-muted mt-4"><a href="index.php" class="text-muted">← Back to website</a> · <a href="page.php?p=privacy-policy" class="text-muted">Privacy</a> · <a href="page.php?p=terms" class="text-muted">Terms</a></p>
</div></div><script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script><script src="assets/js/site.js?v=<?= CNX_VERSION ?>"></script></body></html>
<?php
}
