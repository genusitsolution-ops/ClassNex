<?php
declare(strict_types=1);

/**
 * Authenticated encryption for credentials and private documents.
 *
 * Uses libsodium (secretbox) when the PHP "sodium" extension is installed, otherwise OpenSSL AES-256-GCM.
 * Both are authenticated (tampering is detected). Stored values carry their format, so both kinds can be
 * read later: text "v1:" / files "CNX1" = sodium, text "v2:" / files "CNX2" = AES-256-GCM.
 * Values written with sodium can only be read where sodium is available (keep it enabled if it was).
 */
final class Crypto
{
    private const GCM_IV = 12;
    private const GCM_TAG = 16;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') || in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    private static function useSodium(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    private static function key(): string
    {
        $k = base64_decode((string) Config::get('app_key', ''), true);
        if ($k === false || strlen($k) !== 32) {
            throw new RuntimeException('config app_key must be a base64-encoded 32-byte key.');
        }
        return $k;
    }

    /** Returns the raw encrypted payload and its format tag. */
    private static function seal(string $plain): array
    {
        if (self::useSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return [1, $nonce . sodium_crypto_secretbox($plain, $nonce, self::key())];
        }
        $iv = random_bytes(self::GCM_IV);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return [2, $iv . $tag . $cipher];
    }

    private static function open(int $version, string $raw): ?string
    {
        if ($version === 1) {
            if (!self::useSodium()) {
                throw new RuntimeException('This value was encrypted with libsodium; enable the PHP "sodium" extension to read it.');
            }
            if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
            return $plain === false ? null : $plain;
        }
        if (strlen($raw) < self::GCM_IV + self::GCM_TAG) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, self::GCM_IV + self::GCM_TAG), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, self::GCM_IV), substr($raw, self::GCM_IV, self::GCM_TAG));
        return $plain === false ? null : $plain;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        [$v, $raw] = self::seal($plain);
        return "v$v:" . base64_encode($raw);
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || !preg_match('/^v([12]):/', $enc, $m)) {
            return null;
        }
        $raw = base64_decode(substr($enc, 3), true);
        return $raw === false ? null : self::open((int) $m[1], $raw);
    }

    public static function encryptBytes(string $bytes): string
    {
        [$v, $raw] = self::seal($bytes);
        return "CNX$v" . $raw;
    }

    public static function decryptBytes(string $blob): ?string
    {
        if (!preg_match('/^CNX([12])/', $blob, $m)) {
            return null;
        }
        return self::open((int) $m[1], substr($blob, 4));
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
