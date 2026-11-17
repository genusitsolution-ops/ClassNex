<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/site.php';
$slug = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['p'] ?? ''));
$page = DB::one("SELECT * FROM cms_pages WHERE slug = ? AND status = 'published'", [$slug]);
if (!$page) {
    http_response_code(404);
    site_header('Page not found');
    echo '<section class="page-hero"><div class="container text-center"><h1>Page not found</h1><p><a href="index.php">Go to home</a></p></div></section>';
    site_footer();
    exit;
}
site_header($page['meta_title'] ?: $page['title'], (string) $page['meta_description']);
?>
<section class="page-hero"><div class="container text-center"><h1><?= e($page['title']) ?></h1><p class="text-muted small">Last updated <?= e(date('d M Y', strtotime($page['updated_at']))) ?></p></div></section>
<section class="section pt-2"><div class="container cms-content" style="max-width:820px"><?= HtmlSanitizer::clean((string) $page['content']) ?></div></section>
<?php site_footer();
