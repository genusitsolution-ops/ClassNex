<?php
declare(strict_types=1);

/**
 * Razorpay online payments. Each academy uses its own keys for student fees (money goes directly
 * to the academy); the platform keys (academy_id NULL) collect ClassNex subscription payments.
 * Payment status is trusted only after signature verification on the server or a signed webhook.
 */
final class RazorpayService
{
    public static function config(?int $academyId): ?array
    {
        $c = DB::one("SELECT * FROM payment_gateway_configs WHERE academy_id <=> ? AND provider = 'razorpay'", [$academyId]);
        if (!$c || !$c['is_enabled'] || !$c['key_id']) {
            return null;
        }
        $c['key_secret'] = Crypto::decrypt($c['key_secret_enc']);
        $c['webhook_secret'] = Crypto::decrypt($c['webhook_secret_enc']);
        return $c['key_secret'] ? $c : null;
    }

    public static function createOrder(?int $academyId, string $purpose, int $refId, int $amountPaise, array $invoiceIds, ?int $userId): array
    {
        $c = self::config($academyId);
        if (!$c) {
            throw new ApiException('Online payment is not set up yet. Please pay at the academy.', 409, [], 'gateway_not_configured');
        }
        if ($amountPaise < 100) {
            throw ApiException::validation(['amount' => 'Minimum online payment is ₹1.']);
        }
        $res = HttpClient::request('POST', 'https://api.razorpay.com/v1/orders', ['Authorization: Basic ' . base64_encode($c['key_id'] . ':' . $c['key_secret'])], [
            'amount'   => $amountPaise,
            'currency' => 'INR',
            'receipt'  => substr("$purpose-$refId-" . time(), 0, 40),
            'notes'    => ['purpose' => $purpose, 'ref_id' => (string) $refId, 'academy_id' => (string) $academyId],
        ]);
        $orderId = $res['json']['id'] ?? null;
        if (!$orderId) {
            throw new ApiException('Could not start online payment: ' . ($res['json']['error']['description'] ?? 'gateway error'), 502);
        }
        DB::insert('gateway_orders', [
            'academy_id' => $academyId, 'purpose' => $purpose, 'ref_id' => $refId, 'invoice_ids' => implode(',', $invoiceIds),
            'provider' => 'razorpay', 'order_id' => $orderId, 'amount_paise' => $amountPaise, 'created_by' => $userId,
        ]);
        return ['order_id' => $orderId, 'key_id' => $c['key_id'], 'amount' => $amountPaise, 'currency' => 'INR'];
    }

    /** Called after checkout (client) — verifies the signature, then records the payment. */
    public static function verifyAndRecord(string $orderId, string $paymentId, string $signature): array
    {
        $order = DB::one('SELECT * FROM gateway_orders WHERE order_id = ?', [$orderId]);
        if (!$order) {
            throw ApiException::notFound('Order');
        }
        $c = self::config($order['academy_id'] !== null ? (int) $order['academy_id'] : null);
        if (!$c || !hash_equals(hash_hmac('sha256', "$orderId|$paymentId", $c['key_secret']), $signature)) {
            throw new ApiException('Payment could not be verified. If money was deducted it will be confirmed automatically shortly.', 400, [], 'signature_invalid');
        }
        return self::complete($order, $paymentId);
    }

    public static function webhook(?int $academyId, string $raw, ?string $sig): int
    {
        $c = self::config($academyId);
        if (!$c || !$c['webhook_secret'] || !$sig || !hash_equals(hash_hmac('sha256', $raw, $c['webhook_secret']), $sig)) {
            throw new ApiException('Invalid signature', 401);
        }
        $body = json_decode($raw, true) ?: [];
        $event = $body['event'] ?? '';
        if (!in_array($event, ['payment.captured', 'order.paid'], true)) {
            return 0;
        }
        $p = $body['payload']['payment']['entity'] ?? null;
        if (!$p || empty($p['order_id'])) {
            return 0;
        }
        $order = DB::one('SELECT * FROM gateway_orders WHERE order_id = ?', [$p['order_id']]);
        if (!$order || ($order['academy_id'] !== null ? (int) $order['academy_id'] : null) !== $academyId) {
            return 0;
        }
        if ((int) $p['amount'] !== (int) $order['amount_paise']) {
            error_log("razorpay amount mismatch for order {$order['order_id']}");
            return 0;
        }
        self::complete($order, (string) $p['id']);
        return 1;
    }

    private static function complete(array $order, string $paymentId): array
    {
        return DB::tx(static function () use ($order, $paymentId) {
            $o = DB::one('SELECT * FROM gateway_orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if ($o['purpose'] === 'fee') {
                $result = PaymentService::record((int) $o['academy_id'], (int) $o['ref_id'], [
                    'amount_paise'       => (int) $o['amount_paise'],
                    'method'             => 'online',
                    'reference'          => $paymentId,
                    'idempotency_key'    => 'rzp-' . $paymentId,
                    'gateway'            => 'razorpay',
                    'gateway_order_id'   => $o['order_id'],
                    'gateway_payment_id' => $paymentId,
                    'invoice_ids'        => array_filter(array_map('intval', explode(',', (string) $o['invoice_ids']))),
                    'notes'              => 'Paid online',
                ], null);
            } else {
                $sp = DB::one('SELECT * FROM subscription_payments WHERE id = ? FOR UPDATE', [$o['ref_id']]);
                if ($sp && $sp['status'] !== 'paid') {
                    DB::update('subscription_payments', ['status' => 'paid', 'gateway_payment_id' => $paymentId, 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$sp['id']]);
                    $subId = Subscription::renew((int) $sp['academy_id'], (int) $sp['package_id'], (int) $sp['amount_paise'], null, 'Online renewal');
                    $cur = DB::one('SELECT starts_on, ends_on FROM owner_subscriptions WHERE id = ?', [$subId]);
                    DB::update('subscription_payments', ['subscription_id' => $subId, 'period_from' => $cur['starts_on'], 'period_to' => $cur['ends_on']], 'id = ?', [$sp['id']]);
                }
                $result = ['subscription_payment_id' => (int) $o['ref_id']];
            }
            DB::update('gateway_orders', ['status' => 'paid', 'payment_id' => $paymentId], 'id = ?', [$o['id']]);
            return $result;
        });
    }
}
