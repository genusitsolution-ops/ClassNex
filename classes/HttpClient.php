<?php
declare(strict_types=1);

/** cURL wrapper for outbound API calls (WhatsApp, FCM, Razorpay, SMS). */
final class HttpClient
{
    /** @var callable|null test hook: fn(method, url, headers, body) => [status, body] */
    public static $fake = null;

    public static function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 20): array
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        if (self::$fake) {
            [$status, $resp] = (self::$fake)($method, $url, $headers, $body);
            return ['status' => $status, 'body' => $resp, 'json' => json_decode((string) $resp, true), 'error' => null];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp, 'json' => json_decode((string) $resp, true), 'error' => $err];
    }
}
