<?php
declare(strict_types=1);

/** Parent / student portal. Every query is limited to the caller's own children. */
return static function (Router $r): void {
    $fam = ['roles' => ['parent', 'student'], 'feature' => 'parent_access'];

    $r->get('/portal/children', static function (Ctx $c) {
        $ids = $c->familyStudentIds();
        $rows = $ids ? DB::all('SELECT id, name, student_code, photo_path IS NOT NULL AS has_photo FROM students WHERE id IN (' . DB::inList($ids) . ') ORDER BY name', $ids) : [];
        foreach ($rows as &$s) {
            $s['photo_url'] = $s['has_photo'] ? cnx_url('api/v1/files/student-photo/' . $s['id']) : null;
        }
        return Http::ok($rows);
    }, $fam);

    $r->get('/portal/students/{id}/dashboard', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $s = $c->assertStudent($sid);
        $aid = $c->academy();
        $today = DB::one('SELECT a.status, b.name AS batch_name FROM attendance a JOIN attendance_sessions se ON se.id = a.session_id JOIN batches b ON b.id = se.batch_id WHERE a.student_id = ? AND se.session_date = CURDATE() ORDER BY a.id DESC LIMIT 1', [$sid]);
        $month = AttendanceService::studentMonth($sid, date('Y-m'));
        // next class from the weekly schedule
        $next = null;
        $sched = DB::all("SELECT bs.weekday, COALESCE(bs.start_time, b.start_time) AS start_time, b.name AS batch_name, a.name AS activity_name, b.location
                          FROM student_activity_mapping m JOIN batches b ON b.id = m.batch_id JOIN activities a ON a.id = m.activity_id JOIN batch_schedules bs ON bs.batch_id = b.id
                          WHERE m.student_id = ? AND m.status = 'active' AND b.status = 'active'", [$sid]);
        $holidays = DB::col('SELECT holiday_date FROM holidays WHERE academy_id = ? AND holiday_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)', [$aid]);
        for ($i = 0; $i < 14 && !$next; $i++) {
            $d = date('Y-m-d', strtotime("+$i day"));
            $wd = (int) date('N', strtotime($d));
            if (in_array($d, $holidays, true)) {
                continue;
            }
            foreach ($sched as $row) {
                if ((int) $row['weekday'] === $wd && ($i > 0 || !$row['start_time'] || $row['start_time'] > date('H:i:s'))) {
                    $next = $row + ['date' => $d];
                    break;
                }
            }
        }
        $data = [
            'student' => ['id' => $sid, 'name' => $s['name'], 'student_code' => $s['student_code'], 'photo_url' => $s['photo_path'] ? cnx_url('api/v1/files/student-photo/' . $sid) : null],
            'enrollments' => DB::all("SELECT a.name AS activity_name, b.name AS batch_name, b.start_time, b.end_time, b.location, t.name AS teacher_name FROM student_activity_mapping m JOIN activities a ON a.id = m.activity_id LEFT JOIN batches b ON b.id = m.batch_id LEFT JOIN teachers t ON t.id = b.teacher_id WHERE m.student_id = ? AND m.status = 'active'", [$sid]),
            'today_attendance' => $today,
            'month_attendance' => ['percentage' => $month['percentage'], 'counts' => $month['counts']],
            'next_class' => $next,
            'notifications' => DB::all('SELECT id, title, body, created_at, read_at FROM notifications WHERE user_id = ? AND (student_id = ? OR student_id IS NULL) ORDER BY id DESC LIMIT 5', [$c->userId, $sid]),
        ];
        if (Feature::has($aid, 'fees')) {
            $data['fees'] = BillingService::balance($sid);
            $data['next_due'] = DB::one("SELECT id, title, due_date, total_paise - paid_paise AS balance_paise FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date LIMIT 1", [$sid]);
            $data['last_payment'] = DB::one("SELECT p.id, p.amount_paise, p.paid_on, p.method, r.receipt_no, r.id AS receipt_id FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.student_id = ? AND p.status = 'success' ORDER BY p.paid_on DESC, p.id DESC LIMIT 1", [$sid]);
            $data['online_payment'] = Feature::has($aid, 'online_payments') && RazorpayService::config($aid) !== null;
        }
        return Http::ok($data);
    }, $fam);

    $r->get('/portal/students/{id}/fees', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        Feature::assert($c->academy(), 'fees');
        return Http::ok([
            'balance'  => BillingService::balance($sid),
            'pending'  => DB::all("SELECT id, invoice_no, title, due_date, total_paise, paid_paise, total_paise - paid_paise AS balance_paise, status, due_date < CURDATE() AS overdue FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date", [$sid]),
            'paid'     => DB::all("SELECT id, invoice_no, title, due_date, total_paise, paid_paise, status FROM fee_invoices WHERE student_id = ? AND status IN ('paid','waived') ORDER BY due_date DESC LIMIT 50", [$sid]),
            'payments' => DB::all("SELECT p.id, p.amount_paise, p.refunded_paise, p.method, p.reference, p.paid_on, p.status, r.id AS receipt_id, r.receipt_no, r.balance_after_paise FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.student_id = ? ORDER BY p.paid_on DESC, p.id DESC LIMIT 100", [$sid]),
            'online_payment' => Feature::has($c->academy(), 'online_payments') && RazorpayService::config($c->academy()) !== null,
        ]);
    }, $fam);

    $r->get('/portal/students/{id}/timetable', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        return Http::ok(DB::all("SELECT bs.weekday, COALESCE(bs.start_time, b.start_time) AS start_time, COALESCE(bs.end_time, b.end_time) AS end_time, b.name AS batch_name, a.name AS activity_name, b.location, t.name AS teacher_name
                                 FROM student_activity_mapping m JOIN batches b ON b.id = m.batch_id JOIN activities a ON a.id = m.activity_id JOIN batch_schedules bs ON bs.batch_id = b.id LEFT JOIN teachers t ON t.id = b.teacher_id
                                 WHERE m.student_id = ? AND m.status = 'active' ORDER BY bs.weekday, start_time", [$sid]));
    }, $fam);

    // online payment (Razorpay) for pending fees
    $r->post('/portal/students/{id}/pay', static function (Ctx $c, array $p) {
        $sid = (int) $p['id'];
        $c->assertStudent($sid);
        $aid = $c->academy();
        Feature::assert($aid, 'online_payments');
        $in = Http::input();
        $ids = array_map('intval', (array) ($in['invoice_ids'] ?? []));
        $sql = "SELECT id, total_paise - paid_paise AS bal FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial')";
        $rows = $ids ? DB::all($sql . ' AND id IN (' . DB::inList($ids) . ')', array_merge([$sid], $ids)) : DB::all($sql, [$sid]);
        $amount = array_sum(array_map('intval', array_column($rows, 'bal')));
        if (!empty($in['amount'])) {
            $amount = min($amount, Money::toPaise($in['amount']));
        }
        if ($amount <= 0) {
            throw ApiException::validation(['amount' => 'There is nothing to pay right now.']);
        }
        $order = RazorpayService::createOrder($aid, 'fee', $sid, $amount, array_column($rows, 'id'), $c->userId);
        $acad = DB::one('SELECT name, logo_path FROM academies WHERE id = ?', [$aid]);
        $u = $c->user;
        return Http::ok($order + ['name' => $acad['name'], 'description' => 'Fee payment', 'image' => $acad['logo_path'] ? cnx_url($acad['logo_path']) : null,
                                  'prefill' => ['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['country_code'] . $u['mobile']]]);
    }, $fam + ['write' => false]);

    // checkout callback (any logged-in role: parent fees or owner subscription)
    $r->post('/payments/verify', static function (Ctx $c) {
        $in = Http::input();
        $res = RazorpayService::verifyAndRecord((string) ($in['razorpay_order_id'] ?? ''), (string) ($in['razorpay_payment_id'] ?? ''), (string) ($in['razorpay_signature'] ?? ''));
        return Http::ok($res, 'Payment successful. Thank you!');
    }, ['write' => false]);
};
