<?php
declare(strict_types=1);

/**
 * Optional SMS through any HTTP SMS gateway (MSG91, Textlocal, Twilio, Fast2SMS ...).
 * params_template is JSON with placeholders {to} {to_national} {message} {sender} {api_key}.
 * Indian DLT rules: register templates with your provider; message text must match them.
 */
final class SmsChannel
{
    public static function config(): array
    {
        $c = DB::one('SELECT * FROM sms_configurations WHERE id = 1') ?? ['mode' => 'off'];
        $c['api_key'] = Crypto::decrypt($c['api_key_enc'] ?? null);
        return $c;
    }

    public static function send(array $job): array
    {
        $c = self::config();
        if ($c['mode'] === 'off') {
            return ['ok' => false, 'error' => 'SMS channel is turned off', 'retry' => false];
        }
        if ($c['mode'] === 'test') {
            return ['ok' => true, 'id' => 'test-' . $job['id'], 'simulated' => true];
        }
        if (!$c['endpoint_url']) {
            return ['ok' => false, 'error' => 'SMS gateway URL not configured', 'retry' => false];
        }
        $to = $job['recipient'];
        $repl = [
            '{to}'          => ltrim($to, '+'),
            '{to_national}' => substr(preg_replace('/\D/', '', $to), -10),
            '{message}'     => $job['body'],
            '{sender}'      => (string) $c['sender_id'],
            '{api_key}'     => (string) $c['api_key'],
        ];
        $tpl = json_decode((string) $c['params_template'], true) ?: ['to' => '{to}', 'message' => '{message}'];
        $params = [];
        $headers = [];
        foreach ($tpl as $k => $v) {
            $val = strtr((string) $v, $repl);
            if (str_starts_with((string) $k, 'header:')) {
                $headers[] = substr((string) $k, 7) . ': ' . $val;
            } else {
                $params[$k] = $val;
            }
        }
        $url = $c['endpoint_url'];
        if ($c['http_method'] === 'GET') {
            $res = HttpClient::request('GET', $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params), $headers);
        } else {
            $res = HttpClient::request('POST', $url, $headers, $params);
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'id' => mb_substr((string) ($res['json']['message_id'] ?? $res['json']['request_id'] ?? $res['json']['sid'] ?? 'ok'), 0, 150), 'http' => $res['status'], 'response' => mb_substr($res['body'], 0, 1000)];
        }
        return ['ok' => false, 'error' => $res['error'] ?: 'HTTP ' . $res['status'], 'retry' => $res['status'] >= 500 || $res['status'] === 429 || $res['error'] !== null, 'http' => $res['status'], 'response' => mb_substr($res['body'], 0, 1000)];
    }
}
