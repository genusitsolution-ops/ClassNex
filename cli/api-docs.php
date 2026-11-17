<?php
// Regenerates the endpoint table in docs/API.md from the live router definitions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
$router = new Router();
foreach (glob(CNX_ROOT . '/api/v1/routes/*.php') as $f) {
    $group = basename($f, '.php');
    $before = count($router->definitions());
    (require $f)($router);
    $groups[$group] = [$before, count($router->definitions())];
}
$defs = $router->definitions();
$out = "| Group | Method | Path | Access | Permission | Plan feature |\n|---|---|---|---|---|---|\n";
foreach ($groups as $g => [$a, $b]) {
    for ($i = $a; $i < $b; $i++) {
        $d = $defs[$i];
        $o = $d['opts'];
        $access = ($o['auth'] ?? true) === false ? 'public' : (empty($o['roles']) ? 'any logged-in user' : implode(', ', $o['roles']));
        $out .= sprintf("| %s | %s | `/api/v1%s` | %s | %s | %s |\n", str_replace('_', ' ', $g), $d['method'], $d['path'], $access, $o['perm'] ?? '', $o['feature'] ?? '');
    }
}
$doc = CNX_ROOT . '/docs/API.md';
$txt = is_file($doc) ? file_get_contents($doc) : '';
$marker = '<!-- ROUTES -->';
$txt = str_contains($txt, $marker) ? preg_replace('/' . preg_quote($marker, '/') . '.*$/s', $marker . "\n\n" . $out, $txt) : $txt . "\n$marker\n\n$out";
file_put_contents($doc, $txt);
echo count($defs) . " endpoints written to docs/API.md\n";
