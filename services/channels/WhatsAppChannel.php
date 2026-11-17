<?php
declare(strict_types=1);

/**
 * Official WhatsApp Business Cloud API (Meta Graph API).
 * Business-initiated messages use approved templates; plain text is used only when a template
 * name is not configured (works only inside the 24-hour customer-service window).
 * In "test" mode nothing is sent — the message is logged as simulated so the whole flow can be tested.
 */
final class WhatsAppChannel
{
    public static function config(): array
    {
        $c = DB::one('SELECT * FROM whatsapp_configurations WHERE id = 1') ?? ['mode' => 'off'];
        $c['access_token'] = Crypto::decrypt($c['access_token_enc'] ?? null);
        $c['app_secret'] = Crypto::decrypt($c['app_secret_enc'] ?? null);
        return $c;
    }

    /** @return array{ok:bool, id?:string, error?:string, retry?:bool, http?:int, response?:string, simulated?:bool} */
    public static function send(array $job): array
    {
        $c = self::config();
        if ($c['mode'] === 'off') {
            return ['ok' => false, 'error' => 'WhatsApp channel is turned off', 'retry' => false];
        }
        $payload = json_decode((string) $job['payload'], true) ?: [];
        $to = preg_replace('/\D/', '', $job['recipient']);
        if ($payload['template'] ?? null) {
            $msg = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => [
                    'name'       => $payload['template'],
                    'language'   => ['code' => $payload['language'] ?? 'en'],
                    'components' => $payload['params'] ? [[
                        'type'       => 'body',
                        'parameters' => array_map(static fn($p) => ['type' => 'text', 'text' => mb_substr((string) $p === '' ? '-' : (string) $p, 0, 1000)], $payload['params']),
                    ]] : [],
                ],
            ];
        } else {
            $msg = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($job['body'], 0, 4096)]];
        }
        if ($c['mode'] === 'test') {
            return ['ok' => true, 'id' => 'test-' . $job['id'], 'simulated' => true, 'response' => json_encode($msg)];
        }
        if (!$c['phone_number_id'] || !$c['access_token']) {
            return ['ok' => false, 'error' => 'WhatsApp is in live mode but Phone Number ID / Access Token are missing', 'retry' => false];
        }
        $base = rtrim((string) (Settings::get('whatsapp_api_base') ?: 'https://graph.facebook.com'), '/');
        $url = sprintf('%s/%s/%s/messages', $base, $c['api_version'] ?: 'v21.0', $c['phone_number_id']);
        $res = HttpClient::request('POST', $url, ['Authorization: Bearer ' . $c['access_token']], $msg);
        $id = $res['json']['messages'][0]['id'] ?? null;
        if ($res['status'] >= 200 && $res['status'] < 300 && $id) {
            return ['ok' => true, 'id' => $id, 'http' => $res['status'], 'response' => $res['body']];
        }
        $code = (int) ($res['json']['error']['code'] ?? 0);
        // 130429/131048/131056/80007 = throughput / rate limits, 5xx = Meta outage → retry later
        $retry = $res['error'] !== null || $res['status'] >= 500 || $res['status'] === 429 || in_array($code, [4, 80007, 130429, 131048, 131056, 131000, 2], true);
        return [
            'ok'       => false,
            'error'    => $res['error'] ?: ($res['json']['error']['message'] ?? ('HTTP ' . $res['status'])),
            'retry'    => $retry,
            'http'     => $res['status'],
            'response' => mb_substr($res['body'], 0, 2000),
        ];
    }

    /** Verifies X-Hub-Signature-256 on webhook calls (requires App Secret). */
    public static function verifySignature(string $raw, ?string $header): bool
    {
        $secret = self::config()['app_secret'] ?? null;
        if (!$secret) {
            return true; // signature checking is enabled as soon as the App Secret is saved
        }
        if (!$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    /** Applies delivery status updates and STOP opt-outs from the webhook. */
    public static function handleWebhook(array $body): int
    {
        $n = 0;
        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $val = $change['value'] ?? [];
                foreach ($val['statuses'] ?? [] as $st) {
                    $map = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed'];
                    $status = $map[$st['status'] ?? ''] ?? null;
                    if (!$status || empty($st['id'])) {
                        continue;
                    }
                    $job = DB::one('SELECT id, status, academy_id FROM notification_queue WHERE provider_message_id = ?', [$st['id']]);
                    if (!$job) {
                        continue;
                    }
                    $rank = ['pending' => 0, 'processing' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
                    if (($rank[$status] ?? 0) > ($rank[$job['status']] ?? 0) || $status === 'failed') {
                        DB::update('notification_queue', [
                            'status'       => $status,
                            'delivered_at' => in_array($status, ['delivered', 'read'], true) ? date('Y-m-d H:i:s') : null,
                            'last_error'   => $status === 'failed' ? mb_substr(json_encode($st['errors'] ?? []), 0, 500) : null,
                        ], 'id = ?', [$job['id']]);
                    }
                    DB::insert('notification_logs', ['queue_id' => $job['id'], 'academy_id' => $job['academy_id'], 'channel' => 'whatsapp', 'action' => 'webhook:' . $status, 'response_body' => json_encode($st)]);
                    $n++;
                }
                foreach ($val['messages'] ?? [] as $m) {
                    $text = strtoupper(trim((string) ($m['text']['body'] ?? $m['button']['text'] ?? '')));
                    $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    if ($from && in_array($text, ['STOP', 'UNSUBSCRIBE', 'STOP ALL'], true)) {
                        DB::exec("INSERT IGNORE INTO notification_opt_outs (phone, channel, source) VALUES (?, 'whatsapp', 'webhook')", [$from]);
                        $n++;
                    } elseif ($from && in_array($text, ['START', 'SUBSCRIBE'], true)) {
                        DB::exec("DELETE FROM notification_opt_outs WHERE phone = ? AND channel = 'whatsapp'", [$from]);
                        $n++;
                    }
                }
            }
        }
        return $n;
    }
}
