<?php
declare(strict_types=1);

final class Migrator
{
    /** Applies every pending migration in database/migrations (sorted by file name). */
    public static function run(?callable $out = null): array
    {
        $out ??= static fn(string $m) => null;
        $pdo = DB::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $done = DB::col('SELECT version FROM schema_migrations');
        $files = glob(CNX_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $out("Applying $version ...");
            foreach (self::split((string) file_get_contents($file)) as $stmt) {
                $pdo->exec($stmt);
            }
            DB::insert('schema_migrations', ['version' => $version]);
            $applied[] = $version;
        }
        $out($applied ? 'Applied: ' . implode(', ', $applied) : 'Database is up to date.');
        return $applied;
    }

    /** Splits SQL on semicolons that end a line, ignoring "--" comment lines. */
    public static function split(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buf = '';
        $stmts = [];
        $inString = false;
        foreach ($lines as $line) {
            if (!$inString && preg_match('/^\s*--/', $line)) {
                continue;
            }
            $buf .= $line . "\n";
            // track quote balance (SQL escapes quotes by doubling, which keeps the count even)
            if (substr_count($line, "'") % 2 === 1) {
                $inString = !$inString;
            }
            if (!$inString && preg_match('/;\s*$/', $line)) {
                $s = trim($buf);
                if ($s !== '' && $s !== ';') {
                    $stmts[] = rtrim($s, ";\n ");
                }
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }
}
