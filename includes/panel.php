<?php
declare(strict_types=1);

/**
 * Shared shell for all web panels (admin / owner / teacher / student).
 * The page itself is static; every module loads data from the same REST API the Android app uses.
 *
 * $menu: [['#/route', 'bi-icon', 'Label', 'feature?'], ['---', 'Section title'], ...]
 */
function render_panel(array $user, string $panel, string $title, array $menu, array $scripts): void
{
    $csrf = Auth::csrfToken();
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $root = rtrim(dirname($base), '/');
    $ctx = new Ctx($user, 'session');
    require_once CNX_ROOT . '/api/v1/routes/auth.php'; // for me()
    $me = me($ctx);
    $academy = $me['academy']['name'] ?? 'ClassNex Platform';
    $boot = ['csrf' => $csrf, 'api' => $root . '/api/v1', 'root' => $root, 'me' => $me, 'panel' => $panel];
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: blob:; connect-src 'self' https://cdn.jsdelivr.net https://tessdata.projectnaptha.com https://api.razorpay.com https://lumberjack.razorpay.com data: blob:; worker-src 'self' blob: https://cdn.jsdelivr.net; frame-src https://api.razorpay.com https://checkout.razorpay.com; media-src 'self' blob:");
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#1e2a78">
<title><?= e($title) ?> · ClassNex</title>
<link rel="icon" href="<?= e($root) ?>/assets/img/favicon.png">
<link href="<?= e($root) ?>/assets/vendor/fonts/fonts.css" rel="stylesheet">
<link href="<?= e($root) ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="<?= e($root) ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e($root) ?>/assets/css/app.css?v=<?= CNX_VERSION ?>" rel="stylesheet">
</head>
<body class="panel panel-<?= e($panel) ?>">
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="#/"><img src="<?= e($root) ?>/assets/img/classnex-icon.png" alt=""><span><strong>ClassNex</strong><small><?= e(mb_strimwidth($academy, 0, 28, '…')) ?></small></span></a>
    <nav class="nav flex-column">
      <?php foreach ($menu as $m):
          if ($m[0] === '---'): ?><div class="nav-section"><?= e($m[1]) ?></div><?php continue; endif;
          if (!empty($m[3]) && $me['academy'] && !in_array($m[3], $me['features'], true)) { continue; } ?>
        <a class="nav-link" href="<?= e($m[0]) ?>" data-route="<?= e($m[0]) ?>"><i class="bi <?= e($m[1]) ?>"></i><span><?= e($m[2]) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <main class="main">
    <header class="topbar">
      <button class="btn btn-icon d-lg-none" id="menuToggle" aria-label="Menu"><i class="bi bi-list"></i></button>
      <div class="topbar-title" id="pageTitle"><?= e($title) ?></div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <div class="input-group input-group-sm d-none d-md-flex global-search" id="globalSearchWrap" hidden>
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input class="form-control" id="globalSearch" placeholder="Search student, ID, phone…" autocomplete="off">
        </div>
        <a class="btn btn-icon position-relative" href="#/notifications" aria-label="Notifications"><i class="bi bi-bell"></i><span class="badge rounded-pill bg-danger" id="bellCount" hidden></span></a>
        <div class="dropdown">
          <button class="btn btn-user dropdown-toggle" data-bs-toggle="dropdown"><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><span class="d-none d-sm-inline"><?= e($user['name']) ?></span></button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><h6 class="dropdown-header"><?= e(cnx_role_name($user['role'])) ?></h6></li>
            <li><a class="dropdown-item" href="#/profile"><i class="bi bi-person me-2"></i>My profile</a></li>
            <li><a class="dropdown-item" href="#/support"><i class="bi bi-life-preserver me-2"></i>Help &amp; support</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= e($root) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
          </ul>
        </div>
      </div>
    </header>
    <div id="banner"></div>
    <section class="content" id="view"><div class="loading-block"><div class="spinner-border text-primary"></div></div></section>
  </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>
<script>window.CNX_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e($root) ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e($root) ?>/assets/js/app.js?v=<?= CNX_VERSION ?>"></script>
<?php foreach ($scripts as $s): ?><script src="<?= e($s) ?>?v=<?= CNX_VERSION ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
