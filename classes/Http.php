<?php
declare(strict_types=1);

/** Request helpers + JSON responses. */
final class Http
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function input(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true);
            self::$body = is_array($data) ? $data : [];
        } else {
            self::$body = $_POST;
        }
        return self::$body;
    }

    public static function setInput(array $data): void
    {
        self::$body = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $in = self::input();
        return $in[$key] ?? ($_GET[$key] ?? $default);
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if ($name === 'Authorization') {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            if (function_exists('apache_request_headers')) {
                foreach (apache_request_headers() as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) {
                        return $v;
                    }
                }
            }
        }
        return null;
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(mixed $data = null, string $message = 'OK', array $meta = []): array
    {
        $r = ['success' => true, 'message' => $message, 'data' => $data];
        if ($meta) {
            $r['meta'] = $meta;
        }
        return $r;
    }

    public static function page(): array
    {
        $page = max(1, (int) self::query('page', 1));
        $per = min(200, max(5, (int) self::query('per_page', 25)));
        return [$page, $per, ($page - 1) * $per];
    }

    public static function paged(string $sql, array $params, ?string $countSql = null): array
    {
        [$page, $per, $off] = self::page();
        $countSql ??= 'SELECT COUNT(*) FROM (' . $sql . ') t';
        $total = (int) DB::val($countSql, $params);
        $rows = DB::all($sql . " LIMIT $per OFFSET $off", $params);
        return self::ok($rows, 'OK', ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]);
    }
}
