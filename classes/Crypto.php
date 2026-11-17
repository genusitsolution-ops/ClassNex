<?php
declare(strict_types=1);

/** Authenticated encryption (libsodium secretbox) for credentials and private documents. */
final class Crypto
{
    private static function key(): string
    {
        $k = base64_decode((string) Config::get('app_key', ''), true);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('config app_key must be a base64-encoded 32-byte key.');
        }
        return $k;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || $enc === '' || !str_starts_with($enc, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }

    public static function encryptBytes(string $bytes): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return "CNX1" . $nonce . sodium_crypto_secretbox($bytes, $nonce, self::key());
    }

    public static function decryptBytes(string $blob): ?string
    {
        if (!str_starts_with($blob, 'CNX1')) {
            return null;
        }
        $blob = substr($blob, 4);
        $plain = sodium_crypto_secretbox_open(
            substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }

    /** "••••1234" preview of a stored secret for settings screens. */
    public static function mask(?string $enc): string
    {
        $p = self::decrypt($enc);
        if (!$p) {
            return '';
        }
        return str_repeat('•', 8) . substr($p, -4);
    }
}
