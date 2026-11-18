<?php
declare(strict_types=1);

/** Layout helpers for the public website. All content comes from the Website CMS (website_settings). */
function site_header(string $title = '', string $description = '', string $active = ''): void
{
    $seo = Settings::web('seo', []);
    $fullTitle = $title ? "$title | ClassNex" : ($seo['title'] ?? 'ClassNex – All-in-One Class Management');
    $desc = $description ?: ($seo['description'] ?? '');
    $url = cnx_url(ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
    $nav = [['index.php', 'Home'], ['features.php', 'Features'], ['solutions.php', 'Solutions'], ['pricing.php', 'Pricing'], ['how-it-works.php', 'How It Works'], ['about.php', 'About'], ['contact.php', 'Contact']];
    header('X-Frame-Options: SAMEORIGIN');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="keywords" content="<?= e($seo['keywords'] ?? '') ?>">
<link rel="canonical" href="<?= e($url) ?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?= e($fullTitle) ?>"><meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e(cnx_url('assets/img/classnex-logo.png')) ?>"><meta property="og:url" content="<?= e($url) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#1e2a78">
<link rel="icon" href="assets/img/favicon.png"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link href="assets/vendor/fonts/fonts.css" rel="stylesheet">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/site.css?v=<?= CNX_VERSION ?>" rel="stylesheet">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'ClassNex', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Android', 'description' => $desc, 'url' => cnx_url()], JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<nav class="navbar navbar-expand-lg site-nav sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php"><img src="assets/img/classnex-icon.png" alt="ClassNex" height="44"><span class="brand-txt">Class<span>Nex</span></span></a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nv" aria-label="Menu"><i class="bi bi-list fs-2"></i></button>
    <div class="collapse navbar-collapse" id="nv">
      <ul class="navbar-nav mx-auto">
        <?php foreach ($nav as [$href, $label]): ?>
          <li class="nav-item"><a class="nav-link <?= $active === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex gap-2 mt-3 mt-lg-0"><a class="btn btn-outline-primary" href="login.php">Login</a><a class="btn btn-primary" href="register.php">Start Free Trial</a></div>
    </div>
  </div>
</nav>
<main>
<?php
}

function site_footer(): void
{
    $f = Settings::web('footer', []);
    $c = Settings::web('contact', []);
    $s = array_filter((array) Settings::web('social', []));
    $icons = ['facebook' => 'bi-facebook', 'instagram' => 'bi-instagram', 'youtube' => 'bi-youtube', 'linkedin' => 'bi-linkedin', 'twitter' => 'bi-twitter-x'];
    ?>
</main>
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4"><img src="assets/img/classnex-logo.png" alt="ClassNex" class="footer-logo mb-3"><p class="text-white-50"><?= e($f['text'] ?? '') ?></p>
        <div class="d-flex gap-3 fs-5"><?php foreach ($s as $k => $u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e($k) ?>"><i class="bi <?= e($icons[$k] ?? 'bi-link') ?>"></i></a><?php endforeach; ?></div></div>
      <div class="col-6 col-lg-2"><h6>Product</h6><a href="features.php">Features</a><a href="solutions.php">Solutions</a><a href="pricing.php">Pricing</a><a href="how-it-works.php">How it works</a></div>
      <div class="col-6 col-lg-2"><h6>Company</h6><a href="about.php">About us</a><a href="contact.php">Contact</a><a href="support.php">Support</a><a href="register.php">Register</a></div>
      <div class="col-6 col-lg-2"><h6>Legal</h6><a href="page.php?p=privacy-policy">Privacy Policy</a><a href="page.php?p=terms">Terms &amp; Conditions</a><a href="page.php?p=refund-policy">Refund Policy</a></div>
      <div class="col-6 col-lg-2"><h6>Contact</h6><?php if (!empty($c['email'])): ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?php endif; ?><?php if (!empty($c['phone'])): ?><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a><?php endif; ?><?php if (!empty($c['whatsapp'])): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['whatsapp'])) ?>" target="_blank"><i class="bi bi-whatsapp"></i> WhatsApp</a><?php endif; ?><span class="text-white-50 small"><?= e($c['address'] ?? '') ?></span></div>
    </div>
    <hr class="border-secondary my-4"><div class="d-flex flex-wrap justify-content-between small text-white-50"><span>© <?= date('Y') ?> <?= e($f['copyright'] ?? 'ClassNex') ?></span><span>All-in-One Class Management · Web + Android<?= !empty($f['operated_by']) ? ' · Operated by ' . e($f['operated_by']) : '' ?></span></div>
  </div>
</footer>
<?php if (!empty($c['whatsapp'])): ?><a class="wa-float" href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['whatsapp'])) ?>" target="_blank" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/site.js?v=<?= CNX_VERSION ?>"></script>
</body>
</html>
<?php
}

function site_cta(): void
{
    ?>
<section class="cta-band"><div class="container text-center"><h2>Ready to simplify your academy?</h2><p>Start your free trial today. No credit card required. Set up in under 10 minutes.</p><a href="register.php" class="btn btn-light btn-lg px-4">Start Free Trial</a> <a href="contact.php" class="btn btn-outline-light btn-lg px-4 ms-2">Talk to us</a></div></section>
<?php
}

function site_packages(): array
{
    $rows = DB::all("SELECT * FROM packages WHERE status = 'active' AND is_public = 1 ORDER BY sort_order, price_paise");
    foreach ($rows as &$p) {
        $p['features'] = DB::col('SELECT feature_key FROM package_features WHERE package_id = ? AND enabled = 1', [$p['id']]);
    }
    return $rows;
}
