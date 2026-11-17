<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper. Every query uses prepared statements.
 * Table / column names passed to insert()/update() must come from code, never from user input.
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = Config::get('db', []);
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'] ?? 'localhost',
                (int) ($db['port'] ?? 3306),
                $db['name'] ?? '',
                $db['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $offset = (new DateTime())->format('P');
            self::$pdo->exec("SET time_zone = '{$offset}', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $st->bindValue($i + 1, $v, self::type($v));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $st->bindValue(':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $st->execute();
        return $st;
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v)  => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            $v === null => PDO::PARAM_NULL,
            default     => PDO::PARAM_STR,
        };
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $r = self::query($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function col(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')';
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        if (!$data) {
            return 0;
        }
        $set = implode(',', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        return self::exec("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $params));
    }

    /** Runs $fn inside a transaction (nesting-safe). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth === 0) {
            $pdo->beginTransaction();
        }
        self::$txDepth++;
        try {
            $result = $fn();
            self::$txDepth--;
            if (self::$txDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function inList(array $ids): string
    {
        return $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
    }
}
