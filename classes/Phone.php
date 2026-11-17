<?php
declare(strict_types=1);

final class Phone
{
    public static function digits(string $s): string
    {
        $d = preg_replace('/\D+/', '', $s) ?? '';
        // Accept numbers typed with +91 / 91 / 0 prefix for India.
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        return $d;
    }

    public static function code(?string $cc): string
    {
        $cc = '+' . ltrim(trim((string) ($cc ?: '+91')), '+');
        return preg_match('/^\+\d{1,4}$/', $cc) ? $cc : '+91';
    }

    /** Validates and returns the national number, or null. */
    public static function normalize(?string $number, string $cc = '+91'): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $d = $cc === '+91' ? self::digits($number) : (preg_replace('/\D+/', '', $number) ?? '');
        if ($cc === '+91') {
            return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
        }
        return preg_match('/^\d{6,14}$/', $d) ? $d : null;
    }

    public static function e164(string $cc, string $number): string
    {
        return '+' . ltrim($cc, '+') . $number;
    }

    /** E.164 without "+" — the format WhatsApp Cloud API expects. */
    public static function wa(string $cc, string $number): string
    {
        return ltrim($cc, '+') . $number;
    }
}
