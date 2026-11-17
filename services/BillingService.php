<?php
declare(strict_types=1);

/**
 * Fee obligations: assignments → invoices (one per billing period), late fees and
 * automatic use of advance credit. Every write runs in a transaction and is idempotent
 * (invoice dedupe_key = assignment + period), so cron can safely run any number of times.
 */
final class BillingService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Assigns a fee to a student and bills whatever is already due. */
    public static function assign(int $academyId, int $studentId, array $d, ?Ctx $ctx): int
    {
        $type = $d['fee_type'];
        $recurring = $type === 'recurring';
        $freq = $recurring ? (int) ($d['frequency_months'] ?? 1) : null;
        if ($recurring && !in_array($freq, [1, 2, 3, 4, 6, 12], true)) {
            throw ApiException::validation(['frequency_months' => 'Choose monthly, quarterly, half-yearly or yearly.']);
        }
        $start = $d['start_date'] ?? date('Y-m-d');
        $amount = (int) $d['amount_paise'];
        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'Fee amount must be greater than zero.']);
        }
        $id = DB::tx(static function () use ($academyId, $studentId, $d, $type, $freq, $start, $amount, $recurring, $ctx) {
            $id = DB::insert('student_fee_assignments', [
                'academy_id'          => $academyId,
                'student_id'          => $studentId,
                'fee_structure_id'    => $d['fee_structure_id'] ?? null,
                'enrollment_id'       => $d['enrollment_id'] ?? null,
                'name'                => mb_substr($d['name'], 0, 120),
                'fee_type'            => $type,
                'frequency_months'    => $freq,
                'amount_paise'        => $amount,
                'discount_id'         => $d['discount_id'] ?? null,
                'discount_paise'      => (int) ($d['discount_paise'] ?? 0),
                'due_day'             => max(1, min(28, (int) ($d['due_day'] ?? 5))),
                'late_fee_paise'      => (int) ($d['late_fee_paise'] ?? 0),
                'late_fee_after_days' => (int) ($d['late_fee_after_days'] ?? 0),
                'start_date'          => $start,
                'end_date'            => $d['end_date'] ?? null,
                'next_period_start'   => $recurring ? date('Y-m-01', strtotime($start)) : null,
                'prorate_first'       => !empty($d['prorate_first']) ? 1 : 0,
                'status'              => 'active',
                'created_by'          => $ctx?->userId,
            ]);
            Audit::log($ctx, 'fee.assign', 'student_fee_assignments', $id, null, ['student_id' => $studentId, 'name' => $d['name'], 'amount' => $amount], $academyId);
            return $id;
        });
        self::billAssignment($id, date('Y-m-d'), $ctx);
        self::applyAdvance($studentId);
        return $id;
    }

    /** Generates every invoice of this assignment whose period has started (plus advance window). */
    public static function billAssignment(int $assignmentId, string $today, ?Ctx $ctx = null): int
    {
        $created = 0;
        $advance = Settings::int('invoice_advance_days', 7);
        $horizon = date('Y-m-d', strtotime("$today +$advance days"));
        for ($guard = 0; $guard < 36; $guard++) {
            $made = DB::tx(static function () use ($assignmentId, $horizon, $today, $ctx) {
                $a = DB::one('SELECT * FROM student_fee_assignments WHERE id = ? FOR UPDATE', [$assignmentId]);
                if (!$a) {
                    return false;
                }
                $student = DB::one('SELECT status FROM students WHERE id = ?', [$a['student_id']]);
                if (($student['status'] ?? '') !== 'active') {
                    return false;
                }
                if ($a['fee_type'] !== 'recurring') {
                    if ($a['status'] !== 'active') {
                        return false;
                    }
                    $amount = (int) $a['amount_paise'];
                    self::createInvoice((int) $a['academy_id'], (int) $a['student_id'], [
                        'assignment_id' => $a['id'],
                        'title'         => $a['name'],
                        'due_date'      => max($a['start_date'], $today),
                        'dedupe_key'    => 'a' . $a['id'],
                        'items'         => self::items($a['name'], $amount, (int) $a['discount_paise'], $a['discount_id'] ? (int) $a['discount_id'] : null),
                    ], $ctx);
                    DB::update('student_fee_assignments', ['status' => 'completed'], 'id = ?', [$a['id']]);
                    return true;
                }
                if (!in_array($a['status'], ['active', 'paused'], true) || !$a['next_period_start'] || $a['next_period_start'] > $horizon) {
                    return false;
                }
                $ps = $a['next_period_start'];
                $freq = (int) $a['frequency_months'];
                $pe = date('Y-m-d', strtotime("$ps +$freq months -1 day"));
                $next = date('Y-m-d', strtotime("$ps +$freq months"));
                if ($a['end_date'] && $ps > $a['end_date']) {
                    DB::update('student_fee_assignments', ['status' => 'completed'], 'id = ?', [$a['id']]);
                    return false;
                }
                // paused: skip periods that start during the pause (fee pause / concession)
                if ($a['status'] === 'paused') {
                    if ($a['paused_until'] && $ps > $a['paused_until']) {
                        DB::update('student_fee_assignments', ['status' => 'active', 'paused_until' => null], 'id = ?', [$a['id']]);
                        return true;
                    }
                    if (!$a['paused_until']) {
                        return false; // paused indefinitely
                    }
                    DB::update('student_fee_assignments', ['next_period_start' => $next], 'id = ?', [$a['id']]);
                    return true;
                }
                $amount = (int) $a['amount_paise'];
                $label = $a['name'];
                // proration of the first period when the student joins mid-month
                if ($a['prorate_first'] && $ps === date('Y-m-01', strtotime($a['start_date'])) && $a['start_date'] > $ps) {
                    $days = (int) date('t', strtotime($ps)) * $freq;
                    $remaining = (int) ((strtotime($pe) - strtotime($a['start_date'])) / 86400) + 1;
                    $amount = intdiv($amount * $remaining + intdiv($days, 2), $days);
                    $label .= ' (prorated)';
                }
                $dueDay = min((int) $a['due_day'], (int) date('t', strtotime($ps)));
                $due = date('Y-m-', strtotime($ps)) . str_pad((string) $dueDay, 2, '0', STR_PAD_LEFT);
                if ($due < $a['start_date']) {
                    $due = $a['start_date'];
                }
                self::createInvoice((int) $a['academy_id'], (int) $a['student_id'], [
                    'assignment_id' => $a['id'],
                    'title'         => $a['name'] . ' – ' . self::periodLabel($ps, $pe),
                    'period_start'  => $ps,
                    'period_end'    => $pe,
                    'due_date'      => $due,
                    'dedupe_key'    => 'a' . $a['id'] . ':' . $ps,
                    'items'         => self::items($label . ' (' . self::periodLabel($ps, $pe) . ')', $amount, (int) $a['discount_paise'], $a['discount_id'] ? (int) $a['discount_id'] : null),
                ], $ctx);
                DB::update('student_fee_assignments', ['next_period_start' => $next], 'id = ?', [$a['id']]);
                return true;
            });
            if (!$made) {
                break;
            }
            $created++;
        }
        return $created;
    }

    private static function items(string $label, int $amount, int $flatDiscount, ?int $discountId): array
    {
        $items = [['item_type' => 'fee', 'description' => $label, 'amount_paise' => $amount]];
        $disc = $flatDiscount;
        $dname = 'Discount';
        if ($discountId) {
            $d = DB::one("SELECT * FROM discounts WHERE id = ? AND status = 'active'", [$discountId]);
            if ($d) {
                $disc += $d['value_type'] === 'percent' ? Money::percentOf($amount, (int) $d['value']) : (int) $d['value'];
                $dname = $d['name'];
            }
        }
        if ($disc > 0) {
            $items[] = ['item_type' => 'discount', 'description' => $dname, 'amount_paise' => -min($disc, $amount)];
        }
        return $items;
    }

    public static function periodLabel(string $ps, string $pe): string
    {
        $a = self::MONTHS[(int) date('n', strtotime($ps))] . ' ' . date('Y', strtotime($ps));
        $b = self::MONTHS[(int) date('n', strtotime($pe))] . ' ' . date('Y', strtotime($pe));
        return $a === $b ? $a : "$a – $b";
    }

    /** Creates an invoice (idempotent on dedupe_key). Returns the invoice id. */
    public static function createInvoice(int $academyId, int $studentId, array $d, ?Ctx $ctx = null): int
    {
        return DB::tx(static function () use ($academyId, $studentId, $d, $ctx) {
            if (!empty($d['dedupe_key'])) {
                $existing = DB::val('SELECT id FROM fee_invoices WHERE academy_id = ? AND dedupe_key = ?', [$academyId, $d['dedupe_key']]);
                if ($existing) {
                    return (int) $existing;
                }
            }
            $sub = 0;
            $disc = 0;
            foreach ($d['items'] as $it) {
                if ($it['amount_paise'] < 0) {
                    $disc += -$it['amount_paise'];
                } else {
                    $sub += $it['amount_paise'];
                }
            }
            $prefix = Settings::academy($academyId)['invoice_prefix'] ?: 'INV';
            $fy = Sequence::fy($d['issue_date'] ?? null);
            $no = sprintf('%s/%s/%05d', $prefix, $fy, Sequence::next($academyId, "invoice-$fy"));
            $id = DB::insert('fee_invoices', [
                'academy_id'     => $academyId,
                'student_id'     => $studentId,
                'assignment_id'  => $d['assignment_id'] ?? null,
                'invoice_no'     => $no,
                'title'          => mb_substr($d['title'], 0, 160),
                'period_start'   => $d['period_start'] ?? null,
                'period_end'     => $d['period_end'] ?? null,
                'issue_date'     => $d['issue_date'] ?? date('Y-m-d'),
                'due_date'       => $d['due_date'],
                'subtotal_paise' => $sub,
                'discount_paise' => $disc,
                'total_paise'    => max(0, $sub - $disc),
                'status'         => $sub - $disc <= 0 ? 'paid' : 'unpaid',
                'dedupe_key'     => $d['dedupe_key'] ?? null,
                'created_by'     => $ctx?->userId,
            ]);
            foreach ($d['items'] as $it) {
                DB::insert('fee_invoice_items', ['invoice_id' => $id] + $it);
            }
            ReminderService::scheduleForInvoice($id);
            return $id;
        });
    }

    /** Recalculates paid/total/status of an invoice from its items and allocation ledger. */
    public static function recompute(int $invoiceId): void
    {
        $inv = DB::one('SELECT * FROM fee_invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
        if (!$inv) {
            return;
        }
        $paid = (int) DB::val('SELECT COALESCE(SUM(amount_paise),0) FROM payment_allocations WHERE invoice_id = ?', [$invoiceId]);
        $total = max(0, (int) $inv['subtotal_paise'] - (int) $inv['discount_paise'] + (int) $inv['late_fee_paise']);
        $status = $inv['status'];
        if (!in_array($status, ['cancelled', 'waived'], true)) {
            $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        }
        DB::update('fee_invoices', ['paid_paise' => $paid, 'total_paise' => $total, 'status' => $status], 'id = ?', [$invoiceId]);
    }

    /** Uses unallocated (advance) payment credit to settle open invoices, oldest first. */
    public static function applyAdvance(int $studentId): int
    {
        return DB::tx(static function () use ($studentId) {
            $credits = self::credits($studentId, true);
            if (!$credits) {
                return 0;
            }
            $applied = 0;
            $open = DB::all("SELECT id, total_paise - paid_paise AS due FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date, id FOR UPDATE", [$studentId]);
            foreach ($open as $inv) {
                $due = (int) $inv['due'];
                foreach ($credits as &$c) {
                    if ($due <= 0) {
                        break;
                    }
                    if ($c['available'] <= 0) {
                        continue;
                    }
                    $amt = min($due, $c['available']);
                    DB::insert('payment_allocations', ['payment_id' => $c['id'], 'invoice_id' => $inv['id'], 'amount_paise' => $amt, 'reason' => 'advance_applied']);
                    $c['available'] -= $amt;
                    $due -= $amt;
                    $applied += $amt;
                }
                unset($c);
                self::recompute((int) $inv['id']);
            }
            return $applied;
        });
    }

    /** Payments of a student that still have unallocated money. */
    public static function credits(int $studentId, bool $lock = false): array
    {
        $rows = DB::all("SELECT p.id, p.amount_paise - p.refunded_paise - COALESCE((SELECT SUM(a.amount_paise) FROM payment_allocations a WHERE a.payment_id = p.id),0) AS available
                         FROM payments p WHERE p.student_id = ? AND p.status = 'success' ORDER BY p.paid_on, p.id" . ($lock ? ' FOR UPDATE' : ''), [$studentId]);
        return array_values(array_filter(array_map(static fn($r) => ['id' => (int) $r['id'], 'available' => (int) $r['available']], $rows), static fn($r) => $r['available'] > 0));
    }

    public static function balance(int $studentId): array
    {
        $due = (int) DB::val("SELECT COALESCE(SUM(total_paise - paid_paise),0) FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial')", [$studentId]);
        $overdue = (int) DB::val("SELECT COALESCE(SUM(total_paise - paid_paise),0) FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') AND due_date < CURDATE()", [$studentId]);
        $advance = array_sum(array_column(self::credits($studentId), 'available'));
        return ['outstanding_paise' => $due, 'overdue_paise' => $overdue, 'advance_paise' => $advance];
    }

    /** Adds a one-time late fee to overdue invoices (once per invoice). */
    public static function applyLateFees(string $today): int
    {
        $rows = DB::all("SELECT i.id, a.late_fee_paise FROM fee_invoices i JOIN student_fee_assignments a ON a.id = i.assignment_id
                         WHERE i.status IN ('unpaid','partial') AND i.late_fee_paise = 0 AND a.late_fee_paise > 0
                           AND DATE_ADD(i.due_date, INTERVAL a.late_fee_after_days DAY) < ?", [$today]);
        foreach ($rows as $r) {
            DB::tx(static function () use ($r) {
                $locked = DB::one('SELECT late_fee_paise FROM fee_invoices WHERE id = ? FOR UPDATE', [$r['id']]);
                if ((int) $locked['late_fee_paise'] > 0) {
                    return;
                }
                DB::insert('fee_invoice_items', ['invoice_id' => $r['id'], 'item_type' => 'late_fee', 'description' => 'Late fee', 'amount_paise' => (int) $r['late_fee_paise']]);
                DB::update('fee_invoices', ['late_fee_paise' => (int) $r['late_fee_paise']], 'id = ?', [$r['id']]);
                self::recompute((int) $r['id']);
            });
        }
        return count($rows);
    }

    /** Cron entry: bills all due recurring periods across all academies. */
    public static function generateAll(string $today): array
    {
        $ids = DB::col("SELECT a.id FROM student_fee_assignments a JOIN students s ON s.id = a.student_id
                        WHERE a.fee_type = 'recurring' AND a.status IN ('active','paused') AND s.status = 'active'
                          AND a.next_period_start <= DATE_ADD(?, INTERVAL ? DAY)", [$today, Settings::int('invoice_advance_days', 7)]);
        $count = 0;
        $students = [];
        foreach ($ids as $id) {
            $n = self::billAssignment((int) $id, $today);
            if ($n) {
                $count += $n;
                $students[] = (int) DB::val('SELECT student_id FROM student_fee_assignments WHERE id = ?', [$id]);
            }
        }
        foreach (array_unique($students) as $sid) {
            self::applyAdvance($sid);
        }
        return ['invoices' => $count, 'late_fees' => self::applyLateFees($today)];
    }

    public static function cancelInvoice(int $academyId, int $invoiceId, string $reason, Ctx $ctx, string $as = 'cancelled'): void
    {
        DB::tx(static function () use ($academyId, $invoiceId, $reason, $ctx, $as) {
            $inv = DB::one('SELECT * FROM fee_invoices WHERE id = ? AND academy_id = ? FOR UPDATE', [$invoiceId, $academyId]);
            if (!$inv) {
                throw ApiException::notFound('Invoice');
            }
            if ((int) $inv['paid_paise'] > 0) {
                throw new ApiException('This invoice has payments. Reverse the payment first.', 409);
            }
            if (in_array($inv['status'], ['cancelled', 'waived'], true)) {
                return;
            }
            DB::update('fee_invoices', ['status' => $as, 'cancelled_reason' => $reason], 'id = ?', [$invoiceId]);
            DB::exec("UPDATE scheduled_reminders SET status = 'skipped' WHERE invoice_id = ? AND status = 'pending'", [$invoiceId]);
            Audit::log($ctx, "invoice.$as", 'fee_invoices', $invoiceId, ['status' => $inv['status']], ['status' => $as, 'reason' => $reason]);
        });
    }
}
