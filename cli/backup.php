<?php
// php cli/backup.php                 -> creates storage/private/backups/classnex-YYYYmmdd-HHMMSS.sql.gz
// php cli/backup.php restore FILE    -> restores a backup (DESTROYS current data — take a fresh backup first)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';

if (($argv[1] ?? '') === 'restore') {
    $file = $argv[2] ?? '';
    if (!is_file($file)) {
        fwrite(STDERR, "Backup file not found: $file\n");
        exit(1);
    }
    echo "This will REPLACE the current database with $file. Type YES to continue: ";
    if (trim((string) fgets(STDIN)) !== 'YES') {
        echo "Cancelled.\n";
        exit(1);
    }
    $n = BackupService::restore($file);
    echo "Restored ($n statements).\n";
    exit(0);
}
$f = BackupService::create();
echo "Backup written: $f (" . round(filesize($f) / 1024) . " KB)\n";
