<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$pages = ['', 'features.php', 'solutions.php', 'pricing.php', 'how-it-works.php', 'about.php', 'contact.php', 'support.php', 'register.php'];
foreach (DB::col("SELECT slug FROM cms_pages WHERE status = 'published'") as $s) {
    $pages[] = 'page.php?p=' . $s;
}
echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($pages as $p) {
    echo '<url><loc>' . htmlspecialchars(cnx_url($p)) . '</loc></url>';
}
echo '</urlset>';
