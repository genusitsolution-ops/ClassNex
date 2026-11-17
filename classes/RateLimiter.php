<?php
declare(strict_types=1);

/** Fixed-window rate limiter backed by MySQL (no Redis needed). */
final class RateLimiter
{
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        if (PHP_SAPI === 'cli' && !getenv('CNX_TEST_RATELIMIT')) {
            return;
        }
        $bucket = substr(hash('sha256', $bucket), 0, 64);
        $now = date('Y-m-d H:i:s');
        DB::exec(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < DATE_SUB(?, INTERVAL ? SECOND), 1, hits + 1),
               window_start = IF(window_start < DATE_SUB(?, INTERVAL ? SECOND), ?, window_start)',
            [$bucket, $now, $now, $windowSeconds, $now, $windowSeconds, $now]
        );
        $hits = (int) DB::val('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($hits > $max) {
            throw new ApiException('Too many requests. Please wait a few minutes and try again.', 429, [], 'rate_limited');
        }
    }
}
