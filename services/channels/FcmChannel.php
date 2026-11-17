<?php
declare(strict_types=1);

/** Firebase Cloud Messaging HTTP v1 with a service-account JWT (no SDK needed). */
final class FcmChannel
{
    public static function config(): array
    {
        $c = DB::one('SELECT * FROM firebase_configurations WHERE id = 1') ?? ['mode' => 'off'];
        $c['service_account'] = json_decode((string) Crypto::decrypt($c['service_account_enc'] ?? null), true);
        return $c;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function accessToken(array $sa): ?string
    {
        $cached = json_decode((string) Crypto::decrypt(Settings::get('fcm_token_cache')), true);
        if ($cached && ($cached['exp'] ?? 0) > time() + 60 && ($cached['sa'] ?? '') === ($sa['client_email'] ?? '')) {
            return $cached['token'];
        }
        $now = time();
        $jwt = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        if (!openssl_sign($jwt, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt .= '.' . self::b64($sig);
        $res = HttpClient::request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
        $token = $res['json']['access_token'] ?? null;
        if ($token) {
            Settings::set('fcm_token_cache', Crypto::encrypt(json_encode(['token' => $token, 'exp' => $now + (int) ($res['json']['expires_in'] ?? 3600), 'sa' => $sa['client_email']])));
        }
        return $token;
    }

    public static function send(array $job): array
    {
        $c = self::config();
        if ($c['mode'] === 'off') {
            return ['ok' => false, 'error' => 'Push channel is turned off', 'retry' => false];
        }
        $tokens = DB::col('SELECT fcm_token FROM device_tokens WHERE user_id = ?', [(int) $job['recipient']]);
        if (!$tokens) {
            return ['ok' => false, 'error' => 'Recipient has no registered device (app not installed / not logged in)', 'retry' => false];
        }
        $payload = json_decode((string) $job['payload'], true) ?: [];
        $data = array_map('strval', array_filter($payload['data'] ?? [], static fn($v) => $v !== null && $v !== ''));
        if ($c['mode'] === 'test') {
            return ['ok' => true, 'id' => 'test-' . $job['id'], 'simulated' => true, 'response' => count($tokens) . ' device(s)'];
        }
        $sa = $c['service_account'];
        if (!$sa || empty($sa['private_key'])) {
            return ['ok' => false, 'error' => 'Firebase service account is not configured', 'retry' => false];
        }
        $access = self::accessToken($sa);
        if (!$access) {
            return ['ok' => false, 'error' => 'Could not obtain Firebase access token', 'retry' => true];
        }
        $project = $c['project_id'] ?: $sa['project_id'];
        $okIds = [];
        $lastErr = null;
        $retry = false;
        foreach ($tokens as $t) {
            $res = HttpClient::request('POST', "https://fcm.googleapis.com/v1/projects/$project/messages:send", ['Authorization: Bearer ' . $access], [
                'message' => [
                    'token'        => $t,
                    'notification' => ['title' => $job['subject'] ?: 'ClassNex', 'body' => mb_substr($job['body'], 0, 1000)],
                    'data'         => (object) $data,
                    'android'      => ['priority' => 'high', 'notification' => ['channel_id' => 'classnex_default']],
                ],
            ]);
            if ($res['status'] === 200) {
                $okIds[] = $res['json']['name'] ?? 'ok';
                continue;
            }
            $status = $res['json']['error']['status'] ?? '';
            if (in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true) || $res['status'] === 404) {
                DB::exec('DELETE FROM device_tokens WHERE fcm_token = ?', [$t]);
            }
            $retry = $retry || $res['status'] >= 500 || $res['status'] === 429;
            $lastErr = $res['json']['error']['message'] ?? ('HTTP ' . $res['status']);
        }
        if ($okIds) {
            return ['ok' => true, 'id' => mb_substr(implode(',', $okIds), 0, 160), 'http' => 200];
        }
        return ['ok' => false, 'error' => $lastErr, 'retry' => $retry];
    }
}
