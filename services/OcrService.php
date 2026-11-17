<?php
declare(strict_types=1);

/**
 * Document text → suggested form fields. OCR itself runs on the device (ML Kit on Android,
 * Tesseract.js in the browser) or, optionally, Google Cloud Vision on the server.
 * The extracted values are only suggestions: the user confirms or corrects every field.
 * Aadhaar numbers are never returned or stored — only the last 4 digits are shown masked.
 */
final class OcrService
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function parse(string $text): array
    {
        $text = str_replace(["\r", "\t"], ["\n", ' '], $text);
        $lines = array_values(array_filter(array_map(static fn($l) => trim(preg_replace('/\s+/', ' ', $l)), explode("\n", $text)), static fn($l) => $l !== ''));
        $flat = implode("\n", $lines);
        $out = ['name' => null, 'dob' => null, 'gender' => null, 'address' => null, 'guardian_name' => null, 'pincode' => null, 'document_type' => 'other', 'id_last4' => null];

        // ---- document type & Aadhaar masking
        if (preg_match('/\b(\d{4})\s?(\d{4})\s?(\d{4})\b/', $flat, $m) && preg_match('/aadhaar|aadhar|unique identification|government of india|आधार|भारत सरकार/iu', $flat)) {
            $out['document_type'] = 'aadhaar';
            $out['id_last4'] = 'XXXX XXXX ' . $m[3];
        } elseif (preg_match('/birth\s*certificate|certificate of birth|registration of birth|जन्म/iu', $flat)) {
            $out['document_type'] = 'birth_certificate';
        } elseif (preg_match('/school|vidyalaya|identity card|student id/i', $flat)) {
            $out['document_type'] = 'school_id';
        }
        $flat = preg_replace('/\b\d{4}\s?\d{4}\s?\d{4}\b/', '[ID REMOVED]', $flat) ?? $flat;
        $lines = array_map(static fn($l) => preg_replace('/\b\d{4}\s?\d{4}\s?\d{4}\b/', '[ID REMOVED]', $l) ?? $l, $lines);

        // ---- date of birth
        $dobLineIdx = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/(dob|d\.o\.b|date of birth|birth date|जन्म तिथि|year of birth)/iu', $l)) {
                $d = self::findDate($l) ?? self::findDate($lines[$i + 1] ?? '');
                if (!$d && preg_match('/year of birth\D*(\d{4})/i', $l, $ym)) {
                    $d = $ym[1] . '-01-01';
                }
                if ($d) {
                    $out['dob'] = $d;
                    $dobLineIdx = $i;
                    break;
                }
            }
        }
        if (!$out['dob']) {
            foreach ($lines as $l) {
                if (($d = self::findDate($l)) && $d < date('Y-m-d') && $d > '1930-01-01') {
                    $out['dob'] = $d;
                    break;
                }
            }
        }

        // ---- gender
        if (preg_match('/\b(female|महिला)\b/iu', $flat)) {
            $out['gender'] = 'female';
        } elseif (preg_match('/\b(male|पुरुष)\b/iu', $flat)) {
            $out['gender'] = 'male';
        }

        // ---- guardian (S/O, D/O, C/O may appear at the start of the address line)
        foreach ($lines as $l) {
            if (preg_match('/(?:^|[\s,:])(?:s\/o|d\/o|c\/o|w\/o)\s*[:\-.]?\s*([^,]+)/i', $l, $m)
                || preg_match('/^(?:father\'?s name|mother\'?s name|name of (?:the )?(?:father|mother|parent))\s*[:\-.]?\s*(.+)$/i', $l, $m)) {
                $out['guardian_name'] = self::cleanName($m[1]);
                break;
            }
        }

        // ---- name
        foreach ($lines as $l) {
            if (preg_match('/^(?:name(?: of (?:the )?(?:child|student|candidate))?|student name|full name)\s*[:\-.]\s*(.+)$/i', $l, $m)) {
                $out['name'] = self::cleanName($m[1]);
                break;
            }
        }
        if (!$out['name'] && $dobLineIdx !== null) {
            // Aadhaar layout: the English name is printed just above the DOB line
            for ($i = $dobLineIdx - 1; $i >= max(0, $dobLineIdx - 3); $i--) {
                if (self::looksLikeName($lines[$i])) {
                    $out['name'] = self::cleanName($lines[$i]);
                    break;
                }
            }
        }

        // ---- address
        $addr = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^(?:address|addr|पता|permanent address|residential address)\s*[:\-.]?\s*(.*)$/iu', $l, $m)) {
                $parts = $m[1] !== '' ? [$m[1]] : [];
                for ($j = $i + 1; $j < min(count($lines), $i + 6); $j++) {
                    $parts[] = $lines[$j];
                    if (preg_match('/\b\d{6}\b/', $lines[$j])) {
                        break;
                    }
                }
                $addr = implode(', ', $parts);
                break;
            }
        }
        if ($addr) {
            $addr = preg_replace('/^(?:s\/o|d\/o|c\/o|w\/o)\s*[:\-]?\s*[^,]+,\s*/i', '', $addr);
            $out['address'] = mb_substr(trim(preg_replace('/\s*,\s*,+/', ',', $addr), ' ,'), 0, 300);
        }
        if (preg_match('/\b([1-9]\d{5})\b/', $out['address'] ?? $flat, $m)) {
            $out['pincode'] = $m[1];
        }
        $found = count(array_filter([$out['name'], $out['dob'], $out['address'], $out['gender']]));
        $out['confidence'] = $found >= 3 ? 'high' : ($found >= 1 ? 'medium' : 'low');
        $out['message'] = $found ? 'Please check the highlighted values before saving.' : 'Could not read details clearly. Please type them in, or try a sharper, well-lit photo.';
        return $out;
    }

    private static function findDate(string $s): ?string
    {
        if (preg_match('/\b(\d{1,2})[\/\-. ](\d{1,2})[\/\-. ](\d{4})\b/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3})[a-z]*\.?,?\s+(\d{4})\b/', $s, $m)) {
            $mo = self::MONTHS[strtolower($m[2])] ?? null;
            if ($mo && checkdate($mo, (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $mo, $m[1]);
            }
        }
        return null;
    }

    private static function looksLikeName(string $l): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z.\' ]{2,60}$/', $l)
            && !preg_match('/government|india|unique|authority|male|female|dob|birth|address|enrol|vid|help|www/i', $l)
            && str_word_count($l) >= 1 && str_word_count($l) <= 5;
    }

    private static function cleanName(string $s): string
    {
        $s = trim(preg_replace('/[^\p{L}.\' ]/u', ' ', $s) ?? $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
    }

    /** Optional server-side OCR via Google Cloud Vision (Super Admin → Global Settings). */
    public static function visionText(string $imageBytes): ?string
    {
        $key = Crypto::decrypt(Settings::get('google_vision_key_enc'));
        if (!$key) {
            return null;
        }
        $res = HttpClient::request('POST', 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($key), [], [
            'requests' => [['image' => ['content' => base64_encode($imageBytes)], 'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']]]],
        ], 30);
        return $res['json']['responses'][0]['fullTextAnnotation']['text'] ?? null;
    }
}
