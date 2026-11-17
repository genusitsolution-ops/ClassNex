<?php
declare(strict_types=1);

return static function (Router $r): void {
    // Meta webhook verification handshake
    $r->get('/webhooks/whatsapp', static function () {
        $cfg = WhatsAppChannel::config();
        $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
        $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
        $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
        if ($mode === 'subscribe' && !empty($cfg['webhook_verify_token']) && hash_equals((string) $cfg['webhook_verify_token'], (string) $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }
        throw new ApiException('Verification failed', 403);
    }, ['auth' => false]);

    $r->post('/webhooks/whatsapp', static function () {
        $raw = (string) file_get_contents('php://input');
        if (!WhatsAppChannel::verifySignature($raw, Http::header('X-Hub-Signature-256'))) {
            throw new ApiException('Invalid signature', 401);
        }
        $n = WhatsAppChannel::handleWebhook(json_decode($raw, true) ?: []);
        return Http::ok(['processed' => $n]);
    }, ['auth' => false]);

    // Razorpay: /webhooks/razorpay/{academyId} for fees, /webhooks/razorpay/platform for subscriptions
    $r->post('/webhooks/razorpay/{acad}', static function ($_, array $p) {
        $aid = $p['acad'] === 'platform' ? null : (int) $p['acad'];
        $n = RazorpayService::webhook($aid, (string) file_get_contents('php://input'), Http::header('X-Razorpay-Signature'));
        return Http::ok(['processed' => $n]);
    }, ['auth' => false]);
};
