<?php
declare(strict_types=1);

final class Settings
{
    private static array $cache = [];
    private static array $web = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = DB::val('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        }
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public static function set(string $key, ?string $value): void
    {
        DB::exec('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
        self::$cache[$key] = $value;
    }

    public static function web(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$web)) {
            $raw = DB::val('SELECT setting_value FROM website_settings WHERE setting_key = ?', [$key]);
            $dec = $raw === null ? null : json_decode((string) $raw, true);
            self::$web[$key] = $dec ?? $raw;
        }
        return self::$web[$key] ?? $default;
    }

    public static function setWeb(string $key, mixed $value): void
    {
        $v = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::exec('INSERT INTO website_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $v]);
        unset(self::$web[$key]);
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$web = [];
    }

    /** Academy-level settings stored as JSON on academies.settings */
    public static function academy(int $academyId): array
    {
        $raw = DB::val('SELECT settings FROM academies WHERE id = ?', [$academyId]);
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        return $s + [
            'prorate_first_month'      => false,
            'attendance_notify'        => ['present' => true, 'absent' => true, 'late' => true, 'corrected' => true],
            'send_admission_message'   => true,
            'send_payment_message'     => true,
            'invoice_prefix'           => 'INV',
            'receipt_prefix'           => 'RCPT',
            'online_admission_fields'  => ['dob' => true, 'address' => true, 'email' => true, 'photo' => true, 'document' => false],
            'student_id_prefix'        => '',
            // owner's WhatsApp choice within the plan: '' = as the plan allows, 'api' = automatic, 'manual' = click-to-send, 'off'
            'whatsapp_preference'      => '',
        ];
    }
}
