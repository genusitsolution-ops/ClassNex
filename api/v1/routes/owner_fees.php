<?php
declare(strict_types=1);

/** Fee plans, invoices, collection, receipts, reversals and refunds. */
return static function (Router $r): void {
    $fees = ['roles' => ['owner', 'teacher'], 'feature' => 'fees'];

    // ------------------------------------------------------------ fee structures
    $r->get('/fee-structures', static fn(Ctx $c) => Http::ok(DB::all('SELECT f.*, a.name AS activity_name, b.name AS batch_name FROM fee_structures f LEFT JOIN activities a ON a.id = f.activity_id LEFT JOIN batches b ON b.id = f.batch_id WHERE f.academy_id = ? ORDER BY f.status, f.name', [$c->academy()])), $fees + ['perm' => 'fees.view']);
    $saveStructure = static function (Ctx $c, ?int $id) {
        $aid = $c->academy();
        $in = Http::input();
        $rules = ['name' => 'required|max:120', 'fee_type' => 'required|in:admission,registration,one_time,recurring,additional', 'frequency_months' => 'int', 'amount' => 'required|money',
                  'due_day' => 'int', 'late_fee' => 'money', 'late_fee_after_days' => 'int', 'activity_id' => 'int', 'batch_id' => 'int', 'auto_assign' => 'bool', 'status' => 'in:active,inactive'];
        $d = Validator::make($in, $id ? array_intersect_key($rules, $in) : $rules, ['amount' => 'Fee amount'])->validated();
        foreach (['amount' => 'amount_paise', 'late_fee' => 'late_fee_paise'] as $k => $col) {
            if (array_key_exists($k, $d)) {
                $d[$col] = (int) $d[$k];
                unset($d[$k]);
            }
        }
        if (($d['fee_type'] ?? null) === 'recurring' && !in_array((int) ($d['frequency_months'] ?? 0), [1, 2, 3, 4, 6, 12], true)) {
            throw ApiException::validation(['frequency_months' => 'Choose how often this fee repeats.']);
        }
        if (isset($d['fee_type']) && $d['fee_type'] !== 'recurring') {
            $d['frequency_months'] = null;
        }
        if (isset($d['due_day'])) {
            $d['due_day'] = max(1, min(28, (int) $d['due_day']));
        }
        if (!empty($d['activity_id'])) {
            Crud::find('activities', (int) $d['activity_id'], $aid, 'Activity');
        }
        if (!empty($d['batch_id'])) {
            $b = Crud::find('batches', (int) $d['batch_id'], $aid, 'Batch');
            $d['activity_id'] = $b['activity_id'];
        }
        return Crud::save($c, 'fee_structures', $id, [], [], $id ? $d : $d + ['academy_id' => $aid]);
    };
    $r->post('/fee-structures', static fn(Ctx $c) => Http::ok(['id' => $saveStructure($c, null)], 'Fee plan saved. It applies to new admissions automatically.'), $fees + ['perm' => 'fees.manage']);
    $r->put('/fee-structures/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $saveStructure($c, (int) $p['id'])], 'Fee plan updated. Existing students keep their current fee.'), $fees + ['perm' => 'fees.manage']);

    // apply a fee plan to all active students of a batch / activity (bulk)
    $r->post('/fee-structures/{id}/apply', static function (Ctx $c, array $p) {
        $aid = $c->academy();
        $s = Crud::find('fee_structures', (int) $p['id'], $aid, 'Fee plan');
        $w = "m.academy_id = ? AND m.status = 'active' AND st.status = 'active'";
        $prm = [$aid];
        if ($s['batch_id']) {
            $w .= ' AND m.batch_id = ?';
            $prm[] = $s['batch_id'];
        } elseif ($s['activity_id']) {
            $w .= ' AND m.activity_id = ?';
            $prm[] = $s['activity_id'];
        }
        $ids = DB::col("SELECT DISTINCT m.student_id FROM student_activity_mapping m JOIN students st ON st.id = m.student_id WHERE $w", $prm);
        $n = 0;
        $start = Http::input()['start_date'] ?? date('Y-m-d');
        foreach ($ids as $sid) {
            if (DB::val("SELECT 1 FROM student_fee_assignments WHERE student_id = ? AND fee_structure_id = ? AND status IN ('active','paused','completed')", [$sid, $s['id']])) {
                continue;
            }
            BillingService::assign($aid, (int) $sid, ['fee_structure_id' => $s['id'], 'name' => $s['name'], 'fee_type' => $s['fee_type'], 'frequency_months' => $s['frequency_months'], 'amount_paise' => (int) $s['amount_paise'], 'due_day' => $s['due_day'], 'late_fee_paise' => $s['late_fee_paise'], 'late_fee_after_days' => $s['late_fee_after_days'], 'start_date' => $start], $c);
            $n++;
        }
        return Http::ok(['assigned' => $n], "Fee plan assigned to $n student(s).");
    }, ['roles' => ['owner'], 'perm' => 'fees.manage'] + $fees);

    // discounts / scholarships
    $r->get('/discounts', static fn(Ctx $c) => Http::ok(DB::all('SELECT * FROM discounts WHERE academy_id = ? ORDER BY status, name', [$c->academy()])), $fees + ['perm' => 'fees.view']);
    $saveDiscount = static function (Ctx $c, ?int $id) {
        $in = Http::input();
        $d = Validator::make($in, array_intersect_key(['name' => 'required|max:120', 'kind' => 'in:discount,scholarship,concession', 'value_type' => 'required|in:percent,flat', 'value' => 'required|money', 'status' => 'in:active,inactive'], $id ? $in : ['name' => 1, 'kind' => 1, 'value_type' => 1, 'value' => 1, 'status' => 1]))->validated();
        if (isset($d['value'])) {
            // money cast gives value*100: for percent that is basis points, for flat it is paise
            if (($d['value_type'] ?? null) === 'percent' && $d['value'] > 10000) {
                throw ApiException::validation(['value' => 'Percentage cannot exceed 100.']);
            }
        }
        return Crud::save($c, 'discounts', $id, [], [], $id ? $d : $d + ['academy_id' => $c->academy()]);
    };
    $r->post('/discounts', static fn(Ctx $c) => Http::ok(['id' => $saveDiscount($c, null)], 'Saved.'), ['roles' => ['owner'], 'perm' => 'fees.manage'] + $fees);
    $r->put('/discounts/{id}', static fn(Ctx $c, array $p) => Http::ok(['id' => $saveDiscount($c, (int) $p['id'])], 'Saved.'), ['roles' => ['owner'], 'perm' => 'fees.manage'] + $fees);

    // --------------------------------------------------- student fee assignments
    $r->post('/students/{id}/fees', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $in = Http::input();
        $d = Validator::make($in, ['name' => 'required|max:120', 'fee_type' => 'required|in:admission,registration,one_time,recurring,additional', 'frequency_months' => 'int', 'amount' => 'required|money',
                                   'discount' => 'money', 'discount_id' => 'int', 'due_day' => 'int', 'start_date' => 'date', 'end_date' => 'date', 'late_fee' => 'money', 'late_fee_after_days' => 'int', 'prorate_first' => 'bool'])->validated();
        if (!empty($d['discount_id'])) {
            Crud::find('discounts', (int) $d['discount_id'], $c->academy(), 'Discount');
        }
        $id = BillingService::assign($c->academy(), $sid, $d + ['amount_paise' => $d['amount'], 'discount_paise' => $d['discount'] ?? 0, 'late_fee_paise' => $d['late_fee'] ?? 0], $c);
        return Http::ok(['id' => $id], 'Fee assigned. Dues are generated automatically.');
    }, $fees + ['perm' => 'fees.manage']);

    $r->put('/fee-assignments/{id}', static function (Ctx $c, array $p) {
        $a = Crud::find('student_fee_assignments', (int) $p['id'], $c->academy(), 'Fee');
        $in = Http::input();
        $d = Validator::make($in, array_intersect_key(['amount' => 'money', 'discount' => 'money', 'discount_id' => 'int', 'due_day' => 'int', 'status' => 'in:active,paused,stopped', 'paused_until' => 'date', 'end_date' => 'date', 'name' => 'max:120', 'late_fee' => 'money'], $in))->validated();
        $upd = [];
        foreach (['amount' => 'amount_paise', 'discount' => 'discount_paise', 'late_fee' => 'late_fee_paise'] as $k => $col) {
            if (array_key_exists($k, $d)) {
                $upd[$col] = (int) $d[$k];
            }
        }
        $upd += array_intersect_key($d, array_flip(['discount_id', 'due_day', 'status', 'paused_until', 'end_date', 'name']));
        if (($upd['status'] ?? '') === 'active') {
            $upd['paused_until'] = null;
        }
        DB::update('student_fee_assignments', $upd, 'id = ?', [$a['id']]);
        [$o, $n] = Audit::diff($a, $upd);
        Audit::log($c, 'fee.assignment_update', 'student_fee_assignments', $a['id'], $o, $n);
        if (($upd['status'] ?? $a['status']) === 'active') {
            BillingService::billAssignment((int) $a['id'], date('Y-m-d'), $c);
        }
        return Http::ok(null, 'Fee updated. Changes apply to future dues.');
    }, ['roles' => ['owner'], 'perm' => 'fees.manage'] + $fees);

    // ------------------------------------------------------------------ invoices
    $r->get('/invoices', static function (Ctx $c) {
        $w = 'i.academy_id = ?';
        $p = [$c->academy()];
        if ($sid = (int) Http::query('student_id')) {
            $w .= ' AND i.student_id = ?';
            $p[] = $sid;
        }
        $st = Http::query('status', 'open');
        if ($st === 'open') {
            $w .= " AND i.status IN ('unpaid','partial')";
        } elseif ($st === 'overdue') {
            $w .= " AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE()";
        } elseif ($st !== 'all') {
            $w .= ' AND i.status = ?';
            $p[] = $st;
        }
        if ($q = Http::query('q')) {
            $w .= ' AND (s.name LIKE ? OR s.student_code LIKE ? OR i.invoice_no LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%");
        }
        if ($b = (int) Http::query('batch_id')) {
            $w .= " AND i.student_id IN (SELECT student_id FROM student_activity_mapping WHERE batch_id = ? AND status = 'active')";
            $p[] = $b;
        }
        if ($from = Http::query('due_from')) {
            $w .= ' AND i.due_date >= ?';
            $p[] = $from;
        }
        if ($to = Http::query('due_to')) {
            $w .= ' AND i.due_date <= ?';
            $p[] = $to;
        }
        $res = Http::paged("SELECT i.*, i.total_paise - i.paid_paise AS balance_paise, s.name AS student_name, s.student_code, s.country_code, s.whatsapp,
                              GREATEST(DATEDIFF(CURDATE(), i.due_date),0) AS days_overdue
                            FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE $w ORDER BY " . ($st === 'all' ? 'i.id DESC' : 'i.due_date, s.name'), $p,
            "SELECT COUNT(*) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE $w");
        $res['meta']['total_balance_paise'] = (int) DB::val("SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE $w AND i.status IN ('unpaid','partial')", $p);
        return $res;
    }, $fees + ['perm' => 'fees.view']);

    $r->get('/invoices/{id}', static function (Ctx $c, array $p) {
        $i = Crud::find('fee_invoices', (int) $p['id'], $c->academy(), 'Invoice');
        $i['items'] = DB::all('SELECT * FROM fee_invoice_items WHERE invoice_id = ? ORDER BY id', [$i['id']]);
        $i['payments'] = DB::all('SELECT a.amount_paise, a.reason, a.created_at, p.id AS payment_id, p.method, p.paid_on, r.receipt_no FROM payment_allocations a JOIN payments p ON p.id = a.payment_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE a.invoice_id = ? ORDER BY a.id', [$i['id']]);
        $i['reminders'] = DB::all('SELECT offset_days, scheduled_for, status FROM scheduled_reminders WHERE invoice_id = ? ORDER BY scheduled_for', [$i['id']]);
        return Http::ok($i);
    }, $fees + ['perm' => 'fees.view']);

    // ad-hoc charge (exam fee, kit, tournament ...)
    $r->post('/students/{id}/charges', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $d = Validator::make(Http::input(), ['title' => 'required|max:160', 'amount' => 'required|money', 'due_date' => 'date', 'discount' => 'money'])->validated();
        $items = [['item_type' => 'fee', 'description' => $d['title'], 'amount_paise' => $d['amount']]];
        if (!empty($d['discount'])) {
            $items[] = ['item_type' => 'discount', 'description' => 'Discount', 'amount_paise' => -min($d['discount'], $d['amount'])];
        }
        $id = BillingService::createInvoice($c->academy(), $sid, ['title' => $d['title'], 'due_date' => $d['due_date'] ?? date('Y-m-d'), 'items' => $items], $c);
        BillingService::applyAdvance($sid);
        Audit::log($c, 'invoice.charge', 'fee_invoices', $id, null, $d);
        return Http::ok(['id' => $id], 'Charge added.');
    }, $fees + ['perm' => 'fees.manage']);

    $r->post('/invoices/{id}/cancel', static function (Ctx $c, array $p) {
        $in = Http::input();
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw ApiException::validation(['reason' => 'Please give a reason.']);
        }
        BillingService::cancelInvoice($c->academy(), (int) $p['id'], $reason, $c, ($in['as'] ?? '') === 'waived' ? 'waived' : 'cancelled');
        return Http::ok(null, 'Invoice ' . (($in['as'] ?? '') === 'waived' ? 'waived' : 'cancelled') . '.');
    }, ['roles' => ['owner'], 'perm' => 'fees.manage'] + $fees);

    // ------------------------------------------------------- collect / payments
    // Everything the "Collect Fee" screen needs in one call.
    $r->get('/students/{id}/dues', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $s = $c->assertStudent($sid);
        return Http::ok([
            'student'  => ['id' => $sid, 'name' => $s['name'], 'student_code' => $s['student_code'], 'guardian_name' => $s['guardian_name'], 'whatsapp' => $s['country_code'] . ' ' . $s['whatsapp']],
            'invoices' => DB::all("SELECT id, invoice_no, title, due_date, total_paise, paid_paise, total_paise - paid_paise AS balance_paise, status FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date, id", [$sid]),
            'balance'  => BillingService::balance($sid),
            'recent_payments' => DB::all('SELECT p.id, p.amount_paise, p.method, p.paid_on, p.status, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.student_id = ? ORDER BY p.id DESC LIMIT 5', [$sid]),
        ]);
    }, $fees + ['perm' => 'fees.view']);

    $r->post('/payments', static function (Ctx $c) {
        $in = Http::input();
        $sid = (int) ($in['student_id'] ?? 0);
        $c->assertStudent($sid);
        $d = Validator::make($in, ['amount' => 'required|money', 'method' => 'required|in:cash,upi,bank_transfer,online,cheque,card,other', 'reference' => 'max:120', 'paid_on' => 'date', 'notes' => 'max:255'], ['amount' => 'Amount'])->validated();
        if (in_array($d['method'], ['upi', 'bank_transfer', 'cheque'], true) && empty($d['reference']) && !empty($in['require_reference'])) {
            throw ApiException::validation(['reference' => 'Enter the transaction / cheque reference.']);
        }
        $res = PaymentService::record($c->academy(), $sid, [
            'amount_paise'    => $d['amount'],
            'method'          => $d['method'],
            'reference'       => $d['reference'] ?? null,
            'paid_on'         => $d['paid_on'] ?? date('Y-m-d'),
            'notes'           => $d['notes'] ?? null,
            'invoice_ids'     => (array) ($in['invoice_ids'] ?? []),
            'idempotency_key' => (string) (Http::header('Idempotency-Key') ?? $in['idempotency_key'] ?? ''),
        ], $c);
        return Http::ok($res, $res['duplicate'] ? 'This payment was already recorded.' : 'Payment recorded. Receipt ' . $res['receipt_no'] . ' generated.');
    }, $fees + ['perm' => 'fees.collect']);

    $r->get('/payments', static function (Ctx $c) {
        $w = 'p.academy_id = ?';
        $prm = [$c->academy()];
        foreach (['student_id' => 'p.student_id', 'method' => 'p.method', 'status' => 'p.status', 'collected_by' => 'p.collected_by'] as $k => $col) {
            if (($v = Http::query($k)) !== null && $v !== '') {
                $w .= " AND $col = ?";
                $prm[] = $v;
            }
        }
        if ($from = Http::query('from')) {
            $w .= ' AND p.paid_on >= ?';
            $prm[] = $from;
        }
        if ($to = Http::query('to')) {
            $w .= ' AND p.paid_on <= ?';
            $prm[] = $to;
        }
        if ($b = (int) Http::query('batch_id')) {
            $w .= ' AND p.student_id IN (SELECT student_id FROM student_activity_mapping WHERE batch_id = ?)';
            $prm[] = $b;
        }
        if ($a = (int) Http::query('activity_id')) {
            $w .= ' AND p.student_id IN (SELECT student_id FROM student_activity_mapping WHERE activity_id = ?)';
            $prm[] = $a;
        }
        if ($q = Http::query('q')) {
            $w .= ' AND (s.name LIKE ? OR s.student_code LIKE ? OR r.receipt_no LIKE ? OR p.reference LIKE ?)';
            array_push($prm, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $res = Http::paged("SELECT p.id, p.student_id, p.amount_paise, p.refunded_paise, p.method, p.reference, p.paid_on, p.status, p.created_at, s.name AS student_name, s.student_code,
                              r.id AS receipt_id, r.receipt_no, r.balance_after_paise, r.public_token, u.name AS collected_by_name,
                              (SELECT GROUP_CONCAT(DISTINCT i.title SEPARATOR ', ') FROM payment_allocations a JOIN fee_invoices i ON i.id = a.invoice_id WHERE a.payment_id = p.id AND a.reason = 'payment') AS fee_period
                            FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id LEFT JOIN users u ON u.id = p.collected_by
                            WHERE $w ORDER BY p.paid_on DESC, p.id DESC", $prm, "SELECT COUNT(*) FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE $w");
        $res['meta']['total_paise'] = (int) DB::val("SELECT COALESCE(SUM(p.amount_paise - p.refunded_paise),0) FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE $w AND p.status = 'success'", $prm);
        foreach ($res['data'] as &$row) {
            $row['receipt_url'] = $row['public_token'] ? cnx_url('receipt.php?t=' . $row['public_token']) : null;
            unset($row['public_token']);
        }
        return $res;
    }, $fees + ['perm' => 'fees.view']);

    $r->get('/payments/{id}', static fn(Ctx $c, array $p) => Http::ok(PaymentService::get($c->academy(), (int) $p['id'])), $fees + ['perm' => 'fees.view']);

    $r->post('/payments/{id}/reverse', static function (Ctx $c, array $p) {
        return Http::ok(PaymentService::reverse($c->academy(), (int) $p['id'], (string) (Http::input()['reason'] ?? ''), $c), 'Payment reversed. The receipt is marked cancelled.');
    }, $fees + ['perm' => 'payments.reverse']);

    $r->post('/payments/{id}/refund', static function (Ctx $c, array $p) {
        $in = Http::input();
        $d = Validator::make($in, ['amount' => 'required|money', 'reason' => 'required|max:255', 'method' => 'in:cash,upi,bank_transfer,cheque,other', 'reference' => 'max:120'])->validated();
        return Http::ok(PaymentService::refund($c->academy(), (int) $p['id'], $d['amount'], $d['reason'], $d['method'] ?? 'cash', $d['reference'] ?? null, $c), 'Refund recorded.');
    }, $fees + ['perm' => 'payments.reverse']);

    $r->post('/payments/{id}/share', static function (Ctx $c, array $p) {
        $pay = PaymentService::get($c->academy(), (int) $p['id']);
        $ch = Http::input()['channel'] ?? 'whatsapp';
        if ($ch === 'link') {
            $msg = "Payment receipt {$pay['receipt_no']} for {$pay['student_name']}: {$pay['receipt_url']}";
            $s = DB::one('SELECT country_code, whatsapp FROM students WHERE id = ?', [$pay['student_id']]);
            return Http::ok(['whatsapp_link' => 'https://wa.me/' . Phone::wa($s['country_code'], $s['whatsapp']) . '?text=' . rawurlencode($msg), 'url' => $pay['receipt_url']]);
        }
        $channels = $ch === 'email' ? ['email'] : ['whatsapp'];
        $vars = ['amount' => Money::format((int) $pay['amount_paise']), 'receipt_no' => $pay['receipt_no'], 'balance' => Money::format((int) $pay['balance_after_paise']), 'receipt_link' => $pay['receipt_url']];
        $n = NotificationService::toStudents($c->academy(), 'payment_success', [(int) $pay['student_id']], $vars, ['channels' => $channels, 'dedupe' => "share{$pay['id']}-" . time(), 'created_by' => $c->userId]);
        if (!array_sum($n)) {
            throw new ApiException('Could not send: the ' . $channels[0] . ' channel is not enabled for your plan or no contact is available.', 409);
        }
        return Http::ok($n, 'Receipt sent.');
    }, $fees + ['perm' => 'fees.view']);

    // fee summary for dashboards
    $r->get('/fees/summary', static function (Ctx $c) {
        $aid = $c->academy();
        $m0 = Http::query('from') ?: date('Y-m-01');
        $m1 = Http::query('to') ?: date('Y-m-d');
        return Http::ok([
            'collected_paise' => (int) DB::val("SELECT COALESCE(SUM(amount_paise - refunded_paise),0) FROM payments WHERE academy_id = ? AND status = 'success' AND paid_on BETWEEN ? AND ?", [$aid, $m0, $m1]),
            'pending_paise'   => (int) DB::val("SELECT COALESCE(SUM(total_paise - paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active'", [$aid]),
            'overdue_paise'   => (int) DB::val("SELECT COALESCE(SUM(total_paise - paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE() AND s.status = 'active'", [$aid]),
            'by_method'       => DB::all("SELECT method, SUM(amount_paise - refunded_paise) AS total FROM payments WHERE academy_id = ? AND status = 'success' AND paid_on BETWEEN ? AND ? GROUP BY method", [$aid, $m0, $m1]),
        ]);
    }, $fees + ['perm' => 'fees.view']);
};
