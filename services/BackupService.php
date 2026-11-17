<?php
declare(strict_types=1);

/**
 * Pure-PHP database backup (works on shared hosting without mysqldump) and CLI restore.
 * Backups are gzip SQL files in storage/private/backups; the oldest beyond 14 are removed.
 */
final class BackupService
{
    public static function create(): string
    {
        $dir = Config::storage('private/backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $file = $dir . '/classnex-' . date('Ymd-His') . '.sql.gz';
        $gz = gzopen($file, 'wb6');
        gzwrite($gz, "-- ClassNex backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $pdo = DB::pdo();
        foreach (DB::col('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $table) {
            if (in_array($table, ['rate_limits'], true)) {
                continue;
            }
            $create = DB::one("SHOW CREATE TABLE `$table`");
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");
            $st = $pdo->query("SELECT * FROM `$table`", PDO::FETCH_ASSOC);
            $batch = [];
            while ($row = $st->fetch()) {
                $batch[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        $all = glob($dir . '/classnex-*.sql.gz') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) {
            unlink($old);
        }
        return $file;
    }

    /** Restores a backup file. CLI only — it replaces the entire database. */
    public static function restore(string $file): int
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Restore runs from the command line only.');
        }
        $sql = str_ends_with($file, '.gz') ? (string) gzdecode((string) file_get_contents($file)) : (string) file_get_contents($file);
        $pdo = DB::pdo();
        $n = 0;
        $buf = '';
        foreach (preg_split('/\n/', $sql) as $line) {
            if ($buf === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $buf .= $line . "\n";
            if (preg_match('/;\s*$/', $line) && self::balanced($buf)) {
                $pdo->exec($buf);
                $buf = '';
                $n++;
            }
        }
        return $n;
    }

    private static function balanced(string $s): bool
    {
        // count unescaped single quotes; PDO::quote escapes quotes as \' and doubles backslashes
        $s = str_replace(['\\\\', "\\'"], '', $s);
        return substr_count($s, "'") % 2 === 0;
    }
}
