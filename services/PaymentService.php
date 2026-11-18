<?php
declare(strict_types=1);

/**
 * Payment collection, allocation, reversal and refund.
 * Financial rows are never edited or deleted: allocations form an append-only ledger.
 */
final class PaymentService
{
    public const METHODS = ['cash', 'upi', 'bank_transfer', 'online', 'cheque', 'card', 'other'];

    /**
     * Records a payment and allocates it to invoices (selected ones first, else oldest due first).
     * Any remainder stays as advance credit and is used automatically for future invoices.
     * Idempotent per (academy, idempotency_key): a retried request returns the original payment.
     */
    public static function record(int $academyId, int $studentId, array $d, ?Ctx $ctx): array
    {
        $amount = (int) $d['amount_paise'];
        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'Amount must be greater than zero.']);
        }
        if ($amount > 100_000_000_00) {
            throw ApiException::validation(['amount' => 'Amount looks too large. Please check.']);
        }
        $method = $d['method'] ?? 'cash';
        if (!in_array($method, self::METHODS, true)) {
            throw ApiException::validation(['method' => 'Choose a valid payment method.']);
        }
        $idem = substr(preg_replace('/[^A-Za-z0-9_\-:.]/', '', (string) ($d['idempotency_key'] ?? '')), 0, 64);
        if ($idem === '') {
            $idem = 'auto-' . bin2hex(random_bytes(12));
        }
        $paidOn = $d['paid_on'] ?? date('Y-m-d');
        if ($paidOn > date('Y-m-d')) {
            throw ApiException::validation(['paid_on' => 'Payment date cannot be in the future.']);
        }

        $result = DB::tx(static function () use ($academyId, $studentId, $d, $ctx, $amount, $method, $idem, $paidOn) {
            $dup = DB::one('SELECT id FROM payments WHERE academy_id = ? AND idempotency_key = ?', [$academyId, $idem]);
            if ($dup) {
                return ['payment_id' => (int) $dup['id'], 'duplicate' => true];
            }
            if (!empty($d['gateway_payment_id'])) {
                $g = DB::val('SELECT id FROM payments WHERE gateway = ? AND gateway_payment_id = ?', [$d['gateway'] ?? 'razorpay', $d['gateway_payment_id']]);
                if ($g) {
                    return ['payment_id' => (int) $g, 'duplicate' => true];
                }
            }
            $student = DB::one('SELECT id, branch_id FROM students WHERE id = ? AND academy_id = ? FOR UPDATE', [$studentId, $academyId]);
            if (!$student) {
                throw ApiException::notFound('Student');
            }
            $pid = DB::insert('payments', [
                'academy_id'         => $academyId,
                'student_id'         => $studentId,
                'branch_id'          => $student['branch_id'],
                'amount_paise'       => $amount,
                'method'             => $method,
                'reference'          => isset($d['reference']) ? mb_substr((string) $d['reference'], 0, 120) : null,
                'paid_on'            => $paidOn,
                'notes'              => isset($d['notes']) ? mb_substr((string) $d['notes'], 0, 255) : null,
                'status'             => 'success',
                'idempotency_key'    => $idem,
                'gateway'            => $d['gateway'] ?? null,
                'gateway_order_id'   => $d['gateway_order_id'] ?? null,
                'gateway_payment_id' => $d['gateway_payment_id'] ?? null,
                'collected_by'       => $ctx?->userId,
            ]);

            $remaining = $amount;
            $targets = array_map('intval', $d['invoice_ids'] ?? []);
            $sql = "SELECT id, total_paise - paid_paise AS due FROM fee_invoices WHERE student_id = ? AND academy_id = ? AND status IN ('unpaid','partial')";
            $open = DB::all($sql . ' ORDER BY due_date, id FOR UPDATE', [$studentId, $academyId]);
            if ($targets) {
                usort($open, static function ($a, $b) use ($targets) {
                    $ia = array_search((int) $a['id'], $targets, true);
                    $ib = array_search((int) $b['id'], $targets, true);
                    return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib);
                });
            }
            $allocated = [];
            foreach ($open as $inv) {
                if ($remaining <= 0) {
                    break;
                }
                $amt = min($remaining, (int) $inv['due']);
                if ($amt <= 0) {
                    continue;
                }
                DB::insert('payment_allocations', ['payment_id' => $pid, 'invoice_id' => $inv['id'], 'amount_paise' => $amt, 'reason' => 'payment']);
                BillingService::recompute((int) $inv['id']);
                $remaining -= $amt;
                $allocated[] = ['invoice_id' => (int) $inv['id'], 'amount_paise' => $amt];
            }
            $receiptId = self::createReceipt($academyId, $pid, $studentId, $amount);
            Audit::log($ctx, 'payment.record', 'payments', $pid, null, ['student_id' => $studentId, 'amount' => $amount, 'method' => $method, 'allocated' => $allocated, 'advance' => $remaining], $academyId);
            return ['payment_id' => $pid, 'receipt_id' => $receiptId, 'duplicate' => false];
        });

        if (!$result['duplicate']) {
            $academy = Settings::academy($academyId);
            if ($academy['send_payment_message'] ?? true) {
                NotificationService::paymentReceived($academyId, $result['payment_id']);
            }
        }
        return self::get($academyId, $result['payment_id']) + ['duplicate' => $result['duplicate']];
    }

    private static function createReceipt(int $academyId, int $paymentId, int $studentId, int $amount): int
    {
        $prefix = Settings::academy($academyId)['receipt_prefix'] ?: 'RCPT';
        $fy = Sequence::fy();
        $no = sprintf('%s/%s/%05d', $prefix, $fy, Sequence::next($academyId, "receipt-$fy"));
        $bal = BillingService::balance($studentId);
        return DB::insert('receipts', [
            'academy_id'          => $academyId,
            'payment_id'          => $paymentId,
            'receipt_no'          => $no,
            'student_id'          => $studentId,
            'branch_id'           => DB::val('SELECT branch_id FROM payments WHERE id = ?', [$paymentId]),
            'amount_paise'        => $amount,
            'balance_after_paise' => $bal['outstanding_paise'],
            'public_token'        => bin2hex(random_bytes(16)),
        ]);
    }

    public static function get(int $academyId, int $paymentId): array
    {
        $p = DB::one('SELECT p.*, s.name AS student_name, s.student_code, r.id AS receipt_id, r.receipt_no, r.public_token, r.balance_after_paise, r.status AS receipt_status, u.name AS collected_by_name, br.name AS branch_name
                      FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN academy_branches br ON br.id = p.branch_id LEFT JOIN receipts r ON r.payment_id = p.id LEFT JOIN users u ON u.id = p.collected_by
                      WHERE p.id = ? AND p.academy_id = ?', [$paymentId, $academyId]);
        if (!$p) {
            throw ApiException::notFound('Payment');
        }
        $p['allocations'] = DB::all('SELECT a.invoice_id, i.invoice_no, i.title, SUM(a.amount_paise) AS amount_paise FROM payment_allocations a JOIN fee_invoices i ON i.id = a.invoice_id
                                     WHERE a.payment_id = ? GROUP BY a.invoice_id, i.invoice_no, i.title HAVING SUM(a.amount_paise) <> 0', [$paymentId]);
        $p['receipt_url'] = $p['public_token'] ? cnx_url('receipt.php?t=' . $p['public_token']) : null;
        unset($p['idempotency_key']);
        return $p;
    }

    /** Reverses a payment entirely (wrong entry, bounced cheque). Ledger keeps both rows. */
    public static function reverse(int $academyId, int $paymentId, string $reason, Ctx $ctx): array
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ApiException::validation(['reason' => 'Please give a reason for reversing this payment.']);
        }
        DB::tx(static function () use ($academyId, $paymentId, $reason, $ctx) {
            $p = DB::one('SELECT * FROM payments WHERE id = ? AND academy_id = ? FOR UPDATE', [$paymentId, $academyId]);
            if (!$p) {
                throw ApiException::notFound('Payment');
            }
            if ($p['status'] !== 'success') {
                throw new ApiException('Only successful payments can be reversed.', 409);
            }
            if ((int) $p['refunded_paise'] > 0) {
                throw new ApiException('This payment has refunds recorded and cannot be reversed.', 409);
            }
            $net = DB::all('SELECT invoice_id, SUM(amount_paise) AS amt FROM payment_allocations WHERE payment_id = ? GROUP BY invoice_id HAVING SUM(amount_paise) <> 0', [$paymentId]);
            foreach ($net as $n) {
                DB::insert('payment_allocations', ['payment_id' => $paymentId, 'invoice_id' => $n['invoice_id'], 'amount_paise' => -(int) $n['amt'], 'reason' => 'reversal']);
                BillingService::recompute((int) $n['invoice_id']);
            }
            DB::update('payments', ['status' => 'reversed', 'reversed_by' => $ctx->userId, 'reversed_at' => date('Y-m-d H:i:s'), 'reversal_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$paymentId]);
            DB::exec("UPDATE receipts SET status = 'cancelled' WHERE payment_id = ?", [$paymentId]);
            Audit::log($ctx, 'payment.reverse', 'payments', $paymentId, ['status' => 'success'], ['status' => 'reversed', 'reason' => $reason]);
        });
        return self::get($academyId, $paymentId);
    }

    /** Refunds part or all of a payment. Unallocated (advance) money is refunded first. */
    public static function refund(int $academyId, int $paymentId, int $amount, string $reason, string $method, ?string $reference, Ctx $ctx): array
    {
        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'Refund amount must be greater than zero.']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw ApiException::validation(['reason' => 'Please give a reason for the refund.']);
        }
        DB::tx(static function () use ($academyId, $paymentId, $amount, $reason, $method, $reference, $ctx) {
            $p = DB::one('SELECT * FROM payments WHERE id = ? AND academy_id = ? FOR UPDATE', [$paymentId, $academyId]);
            if (!$p || $p['status'] !== 'success') {
                throw new ApiException('Only successful payments can be refunded.', 409);
            }
            $refundable = (int) $p['amount_paise'] - (int) $p['refunded_paise'];
            if ($amount > $refundable) {
                throw ApiException::validation(['amount' => 'Refund cannot exceed ' . Money::format($refundable) . '.']);
            }
            $allocated = (int) DB::val('SELECT COALESCE(SUM(amount_paise),0) FROM payment_allocations WHERE payment_id = ?', [$paymentId]);
            $free = $refundable - $allocated;
            $need = $amount - max(0, $free);
            if ($need > 0) {
                $net = DB::all('SELECT a.invoice_id, SUM(a.amount_paise) AS amt FROM payment_allocations a JOIN fee_invoices i ON i.id = a.invoice_id
                                WHERE a.payment_id = ? GROUP BY a.invoice_id, i.due_date HAVING SUM(a.amount_paise) > 0 ORDER BY i.due_date DESC', [$paymentId]);
                foreach ($net as $n) {
                    if ($need <= 0) {
                        break;
                    }
                    $take = min($need, (int) $n['amt']);
                    DB::insert('payment_allocations', ['payment_id' => $paymentId, 'invoice_id' => $n['invoice_id'], 'amount_paise' => -$take, 'reason' => 'refund']);
                    BillingService::recompute((int) $n['invoice_id']);
                    $need -= $take;
                }
            }
            DB::insert('refunds', ['academy_id' => $academyId, 'payment_id' => $paymentId, 'amount_paise' => $amount, 'method' => $method, 'reference' => $reference, 'reason' => mb_substr($reason, 0, 255), 'refunded_by' => $ctx->userId]);
            DB::exec('UPDATE payments SET refunded_paise = refunded_paise + ? WHERE id = ?', [$amount, $paymentId]);
            Audit::log($ctx, 'payment.refund', 'payments', $paymentId, null, ['amount' => $amount, 'reason' => $reason]);
        });
        return self::get($academyId, $paymentId);
    }
}
