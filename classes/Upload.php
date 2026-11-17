<?php
declare(strict_types=1);

/**
 * Safe file uploads. Accepts either a multipart file field or a base64 data URI in the JSON body
 * (the web camera capture and Android both use multipart; data URIs are a fallback).
 * Images are always re-encoded with GD, which strips EXIF/GPS metadata and any embedded payload.
 */
final class Upload
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    public const DOC_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** @return array{bytes:string,mime:string,name:string,size:int}|null */
    public static function read(string $field, int $maxBytes = 8_388_608): ?array
    {
        if (!empty($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES[$field];
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw ApiException::validation([$field => 'The file could not be uploaded. Please try again.']);
            }
            if ($f['size'] > $maxBytes) {
                throw ApiException::validation([$field => 'File is too large (max ' . round($maxBytes / 1048576) . ' MB).']);
            }
            $bytes = (string) file_get_contents($f['tmp_name']);
            $name = basename((string) $f['name']);
        } else {
            $data = Http::input()[$field] ?? null;
            if (!is_string($data) || !preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) {
                return null;
            }
            $bytes = base64_decode($m[2], true);
            if ($bytes === false) {
                throw ApiException::validation([$field => 'Invalid file data.']);
            }
            if (strlen($bytes) > $maxBytes) {
                throw ApiException::validation([$field => 'File is too large.']);
            }
            $name = $field;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        return ['bytes' => $bytes, 'mime' => $mime, 'name' => mb_substr($name, 0, 200), 'size' => strlen($bytes)];
    }

    /** Re-encodes an image as JPEG, scaled to fit $maxSide. */
    public static function compressImage(string $bytes, int $maxSide = 800, int $quality = 80): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw ApiException::validation(['photo' => 'This image could not be read. Please use a JPG or PNG photo.']);
        }
        // honour camera orientation before EXIF is discarded
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $o = (int) ($exif['Orientation'] ?? 1);
            $img = match ($o) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => $img,
            };
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, $quality);
        return (string) ob_get_clean();
    }

    /** Stores a private photo; returns relative storage path. */
    public static function storePhoto(string $field, int $academyId, string $kind = 'photos'): ?string
    {
        $f = self::read($field, 10_485_760);
        if (!$f) {
            return null;
        }
        if (!in_array($f['mime'], self::IMAGE_MIMES, true)) {
            throw ApiException::validation([$field => 'Please upload a JPG, PNG or WEBP image.']);
        }
        $jpeg = self::compressImage($f['bytes'], 800, 80);
        $rel = "private/$kind/$academyId/" . bin2hex(random_bytes(12)) . '.jpg';
        self::put($rel, $jpeg);
        return $rel;
    }

    /** Stores an encrypted private document; returns [path, mime, size, original name]. */
    public static function storeDocument(string $field, int $academyId): ?array
    {
        $f = self::read($field, 8_388_608);
        if (!$f) {
            return null;
        }
        if (!in_array($f['mime'], self::DOC_MIMES, true)) {
            throw ApiException::validation([$field => 'Documents must be JPG, PNG, WEBP or PDF.']);
        }
        $bytes = $f['bytes'];
        $mime = $f['mime'];
        if ($mime !== 'application/pdf') {
            $bytes = self::compressImage($bytes, 1800, 82);
            $mime = 'image/jpeg';
        } elseif (!str_starts_with($bytes, '%PDF-')) {
            throw ApiException::validation([$field => 'This PDF file appears to be damaged.']);
        }
        $rel = "private/documents/$academyId/" . bin2hex(random_bytes(16)) . '.bin';
        self::put($rel, Crypto::encryptBytes($bytes));
        return ['path' => $rel, 'mime' => $mime, 'size' => strlen($bytes), 'name' => $f['name']];
    }

    /** Public image (academy logo, CMS image) under /uploads. Returns web path. */
    public static function storePublicImage(string $field, string $dir, int $maxSide = 600): ?string
    {
        $f = self::read($field, 5_242_880);
        if (!$f) {
            return null;
        }
        if (!in_array($f['mime'], self::IMAGE_MIMES, true)) {
            throw ApiException::validation([$field => 'Please upload a JPG, PNG or WEBP image.']);
        }
        $img = imagecreatefromstring($f['bytes']);
        if (!$img) {
            throw ApiException::validation([$field => 'Invalid image.']);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxSide / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale)));
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        $rel = 'uploads/' . trim($dir, '/') . '/' . bin2hex(random_bytes(10)) . '.png';
        $abs = CNX_ROOT . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0755, true);
        }
        imagepng($out, $abs, 7);
        return $rel;
    }

    public static function put(string $rel, string $bytes): void
    {
        $abs = Config::storage($rel);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0750, true);
        }
        file_put_contents($abs, $bytes, LOCK_EX);
    }

    public static function readStored(string $rel): ?string
    {
        $abs = Config::storage($rel);
        if (!is_file($abs) || str_contains($rel, '..')) {
            return null;
        }
        $raw = (string) file_get_contents($abs);
        return str_starts_with($raw, 'CNX1') ? Crypto::decryptBytes($raw) : $raw;
    }

    public static function delete(?string $rel): void
    {
        if ($rel && !str_contains($rel, '..')) {
            $abs = Config::storage($rel);
            if (is_file($abs)) {
                unlink($abs);
            }
        }
    }
}
