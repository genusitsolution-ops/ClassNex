<?php
declare(strict_types=1);

final class Audit
{
    private const HIDDEN = ['password', 'password_hash', 'access_token', 'key_secret', 'token', 'app_secret'];

    public static function log(?Ctx $ctx, string $action, ?string $entity = null, int|string|null $entityId = null, ?array $old = null, ?array $new = null, ?int $academyId = null): void
    {
        try {
            DB::insert('audit_logs', [
                'academy_id' => $academyId ?? $ctx?->academyId,
                'user_id'    => $ctx?->userId,
                'action'     => $action,
                'entity'     => $entity,
                'entity_id'  => $entityId === null ? null : (string) $entityId,
                'old_values' => $old ? json_encode(self::scrub($old), JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $new ? json_encode(self::scrub($new), JSON_UNESCAPED_UNICODE) : null,
                'ip'         => PHP_SAPI === 'cli' ? 'cli' : Http::ip(),
                'user_agent' => PHP_SAPI === 'cli' ? 'cron' : Http::userAgent(),
            ]);
        } catch (Throwable $e) {
            error_log('audit failed: ' . $e->getMessage());
        }
    }

    private static function scrub(array $a): array
    {
        foreach ($a as $k => $v) {
            foreach (self::HIDDEN as $h) {
                if (is_string($k) && str_contains($k, $h)) {
                    $a[$k] = '***';
                }
            }
        }
        return $a;
    }

    /** Returns only the keys of $new whose values differ from $old. */
    public static function diff(array $old, array $new): array
    {
        $o = $n = [];
        foreach ($new as $k => $v) {
            if (array_key_exists($k, $old) && (string) $old[$k] !== (string) $v) {
                $o[$k] = $old[$k];
                $n[$k] = $v;
            }
        }
        return [$o, $n];
    }
}
