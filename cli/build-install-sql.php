<?php
// Builds database/install.sql = all migrations in order + their schema_migrations records.
// Import that one file in phpMyAdmin for a fresh installation (or use install.php, which runs the same migrations).
//   php cli/build-install-sql.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$files = glob("$root/database/migrations/*.sql");
sort($files);
$out = "-- ClassNex full installation SQL (MySQL 8+ / MariaDB 10.4+), generated " . date('Y-m-d') . " from database/migrations/.\n"
     . "-- Import into an EMPTY database, then create the Super Admin: php cli/create-superadmin.php \"Name\" email mobile password\n"
     . "-- (or open install.php, which also writes config/config.php). Do NOT import this into a database that already has ClassNex tables:\n"
     . "-- for upgrades run php cli/migrate.php, which applies only the new migrations.\n\n"
     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 1;\n\n";
$versions = [];
foreach ($files as $f) {
    $v = basename($f, '.sql');
    $versions[] = $v;
    $out .= "-- ============================================================ $v\n" . rtrim((string) file_get_contents($f)) . "\n\n";
}
$out .= "CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;\n";
foreach ($versions as $v) {
    $out .= "INSERT IGNORE INTO schema_migrations (version) VALUES ('$v');\n";
}
file_put_contents("$root/database/install.sql", $out);
echo "Written database/install.sql (" . count($versions) . " migrations, " . round(strlen($out) / 1024) . " KB)\n";
