<?php
// Usage: php cli/migrate.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
Migrator::run(static fn($m) => print($m . PHP_EOL));
