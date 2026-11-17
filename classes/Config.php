<?php
declare(strict_types=1);

final class Config
{
    private static array $c = [];

    public static function load(array $config): void
    {
        self::$c = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $default;
            }
            $v = $v[$k];
        }
        return $v;
    }

    public static function isLoaded(): bool
    {
        return self::$c !== [];
    }

    public static function storage(string $rel = ''): string
    {
        $base = rtrim((string) self::get('storage_path', CNX_ROOT . '/storage'), '/');
        return $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
    }
}
