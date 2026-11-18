<?php
declare(strict_types=1);

/** Owner dashboard, messaging, reminders, announcements, reports, subscription, settings, support. */
return static function (Router $r): void {
    $own = ['roles' => ['owner']];
    $staff = ['roles' => ['owner', 'teacher']];

    $r->get('/dashboard', static function (Ctx $c) {
        $aid = $c->academy();
        $today = date('Y-m-d');
        $m0 = date('Y-m-01');
        if ($c->role === 'teacher') {
            $scope = $c->batchScope() ?: [0];
            $in = DB::inList($scope);
            $wd = (int) date('N');
            return Http::ok([
                'role' => 'teacher',
                'todays_classes' => DB::all("SELECT b.id, b.name, a.name AS activity_name, COALESCE(bs.start_time, b.start_time) AS start_time, COALESCE(bs.end_time, b.end_time) AS end_time, b.location,
                                               EXISTS(SELECT 1 FROM attendance_sessions se WHERE se.batch_id = b.id AND se.session_date = CURDATE()) AS marked
                                             FROM batches b JOIN activities a ON a.id = b.activity_id LEFT JOIN batch_schedules bs ON bs.batch_id = b.id AND bs.weekday = ?
                                             WHERE b.id IN ($in) AND b.status = 'active' AND (bs.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM batch_schedules x WHERE x.batch_id = b.id))
                                             ORDER BY start_time", array_merge([$wd], $scope)),
                'assigned_batches' => count($c->batchScope()),
                'total_students' => (int) DB::val("SELECT COUNT(DISTINCT m.student_id) FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id IN ($in) AND m.status = 'active' AND s.status = 'active'", $scope),
                'attendance_pending' => (int) DB::val("SELECT COUNT(*) FROM batches b WHERE b.id IN ($in) AND b.status = 'active' AND NOT EXISTS (SELECT 1 FROM attendance_sessions se WHERE se.batch_id = b.id AND se.session_date = CURDATE())
                                                      AND (EXISTS (SELECT 1 FROM batch_schedules bs WHERE bs.batch_id = b.id AND bs.weekday = ?) OR NOT EXISTS (SELECT 1 FROM batch_schedules x WHERE x.batch_id = b.id))", array_merge($scope, [$wd])),
                'announcements' => DB::all("SELECT id, title, body, publish_at FROM announcements WHERE academy_id = ? AND publish_at <= NOW() AND audience IN ('all','teachers') ORDER BY publish_at DESC LIMIT 5", [$aid]),
            ]);
        }
        $fees = Feature::has($aid, 'fees');
        $att = DB::one("SELECT SUM(a.status IN ('present','late')) AS present, SUM(a.status = 'absent') AS absent, COUNT(*) AS marked
                        FROM attendance a JOIN attendance_sessions s ON s.id = a.session_id WHERE s.academy_id = ? AND s.session_date = ?", [$aid, $today]);
        $data = [
            'role'             => 'owner',
            'subscription'     => Subscription::state($aid),
            'total_students'   => (int) DB::val('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$aid]),
            'active_students'  => (int) DB::val("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$aid]),
            'new_admissions'   => (int) DB::val('SELECT COUNT(*) FROM students WHERE academy_id = ? AND admission_date >= ?', [$aid, $m0]),
            'pending_online_admissions' => (int) DB::val("SELECT COUNT(*) FROM admissions WHERE academy_id = ? AND status = 'pending'", [$aid]),
            'total_teachers'   => (int) DB::val("SELECT COUNT(*) FROM teachers WHERE academy_id = ? AND status = 'active'", [$aid]),
            'total_batches'    => (int) DB::val("SELECT COUNT(*) FROM batches WHERE academy_id = ? AND status = 'active'", [$aid]),
            'total_activities' => (int) DB::val("SELECT COUNT(*) FROM activities WHERE academy_id = ? AND status = 'active'", [$aid]),
            'today_attendance' => ['present' => (int) ($att['present'] ?? 0), 'absent' => (int) ($att['absent'] ?? 0), 'marked' => (int) ($att['marked'] ?? 0)],
            'batches_not_marked' => (int) DB::val("SELECT COUNT(*) FROM batches b WHERE b.academy_id = ? AND b.status = 'active' AND NOT EXISTS (SELECT 1 FROM attendance_sessions se WHERE se.batch_id = b.id AND se.session_date = CURDATE())
                                                   AND (EXISTS (SELECT 1 FROM batch_schedules bs WHERE bs.batch_id = b.id AND bs.weekday = ?) OR NOT EXISTS (SELECT 1 FROM batch_schedules x WHERE x.batch_id = b.id))", [$aid, (int) date('N')]),
            'recent_notifications' => DB::all('SELECT event_key, channel, recipient_name, status, created_at FROM notification_queue WHERE academy_id = ? ORDER BY id DESC LIMIT 6', [$aid]),
            'student_quota' => ['active' => (int) DB::val("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$aid]), 'limit' => Feature::forAcademy($aid)['limits']['students'] ?? null],
            'branches' => ((int) DB::val('SELECT COUNT(*) FROM academy_branches WHERE academy_id = ?', [$aid])) ? BranchService::summary($aid, $m0, $today)['branches'] : [],
            'setup' => [
                'activities' => (int) DB::val('SELECT COUNT(*) FROM activities WHERE academy_id = ?', [$aid]) > 0,
                'batches'    => (int) DB::val('SELECT COUNT(*) FROM batches WHERE academy_id = ?', [$aid]) > 0,
                'students'   => (int) DB::val('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$aid]) > 0,
                'logo'       => (bool) DB::val('SELECT logo_path FROM academies WHERE id = ?', [$aid]),
            ],
        ];
        if ($fees) {
            $data += [
                'month_collection_paise' => (int) DB::val("SELECT COALESCE(SUM(amount_paise - refunded_paise),0) FROM payments WHERE academy_id = ? AND status = 'success' AND paid_on >= ?", [$aid, $m0]),
                'today_collection_paise' => (int) DB::val("SELECT COALESCE(SUM(amount_paise - refunded_paise),0) FROM payments WHERE academy_id = ? AND status = 'success' AND paid_on = ?", [$aid, $today]),
                'pending_fees_paise'     => (int) DB::val("SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active'", [$aid]),
                'overdue_fees_paise'     => (int) DB::val("SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE() AND s.status = 'active'", [$aid]),
                'overdue_students'       => (int) DB::val("SELECT COUNT(DISTINCT i.student_id) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE() AND s.status = 'active'", [$aid]),
                'upcoming_dues'          => DB::all("SELECT i.id, i.student_id, s.name AS student_name, i.title, i.due_date, i.total_paise - i.paid_paise AS balance_paise FROM fee_invoices i JOIN students s ON s.id = i.student_id
                                                     WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND s.status = 'active' ORDER BY i.due_date LIMIT 8", [$aid]),
                'recent_payments'        => DB::all('SELECT p.id, s.name AS student_name, p.amount_paise, p.method, p.paid_on, p.status, r.receipt_no FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.academy_id = ? ORDER BY p.id DESC LIMIT 6', [$aid]),
                'collection_trend'       => DB::all("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS month, SUM(amount_paise - refunded_paise) AS total FROM payments WHERE academy_id = ? AND status = 'success' AND paid_on >= DATE_SUB(?, INTERVAL 5 MONTH) GROUP BY month ORDER BY month", [$aid, $m0]),
            ];
        }
        return Http::ok($data);
    }, $staff);

    // --------------------------------------------------------------- messaging
    $r->post('/messages/send', static function (Ctx $c) {
        $aid = $c->academy();
        Feature::assert($aid, 'manual_reminders');
        $in = Http::input();
        $target = $in['target'] ?? 'students';
        $ids = [];
        switch ($target) {
            case 'students':
                $ids = array_map('intval', (array) ($in['student_ids'] ?? []));
                $ids = $ids ? DB::col('SELECT id FROM students WHERE academy_id = ? AND id IN (' . DB::inList($ids) . ')', array_merge([$aid], $ids)) : [];
                break;
            case 'batch':
                $c->assertBatch((int) ($in['batch_id'] ?? 0));
                $ids = DB::col("SELECT m.student_id FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = ? AND m.status = 'active' AND s.status = 'active'", [(int) $in['batch_id']]);
                break;
            case 'activity':
                $ids = DB::col("SELECT DISTINCT m.student_id FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.activity_id = ? AND m.academy_id = ? AND m.status = 'active' AND s.status = 'active'", [(int) ($in['activity_id'] ?? 0), $aid]);
                break;
            case 'all':
                if ($c->role !== 'owner') {
                    throw ApiException::forbidden();
                }
                $ids = DB::col("SELECT id FROM students WHERE academy_id = ? AND status = 'active'", [$aid]);
                break;
            case 'dues':
                Feature::assert($aid, 'fees');
                $ids = DB::col("SELECT DISTINCT i.student_id FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active'" . (!empty($in['overdue_only']) ? ' AND i.due_date < CURDATE()' : ''), [$aid]);
                break;
        }
        if (!$ids) {
            throw ApiException::validation(['target' => 'No students selected.']);
        }
        $channels = array_values(array_intersect((array) ($in['channels'] ?? ['whatsapp', 'inapp', 'push']), NotificationService::CHANNELS));
        $dedupe = 'm' . $c->userId . '-' . substr(hash('sha256', ($in['client_ref'] ?? '') ?: microtime()), 0, 12);
        if (($in['type'] ?? 'message') === 'fee_reminder') {
            // personalised fee reminder per student with their own pending amount
            $total = array_fill_keys(NotificationService::CHANNELS, 0);
            foreach ($ids as $sid) {
                $inv = DB::one("SELECT * FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date LIMIT 1", [$sid]);
                if (!$inv) {
                    continue;
                }
                $pending = (int) DB::val("SELECT SUM(total_paise - paid_paise) FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial')", [$sid]);
                $res = NotificationService::toStudents($aid, $inv['due_date'] < date('Y-m-d') ? 'fee_overdue' : 'fee_due', [(int) $sid], [
                    'fee_title' => $inv['title'], 'amount' => Money::format($pending), 'due_date' => date('j M Y', strtotime($inv['due_date'])),
                ], ['channels' => $channels, 'dedupe' => "$dedupe-$sid", 'created_by' => $c->userId, 'priority' => 3]);
                foreach ($res as $k => $v) {
                    $total[$k] += $v;
                }
            }
            Audit::log($c, 'message.fee_reminder', null, null, null, ['students' => count($ids), 'queued' => $total]);
            return Http::ok($total, 'Fee reminders queued for ' . count($ids) . ' student(s).');
        }
        $msg = trim((string) ($in['message'] ?? ''));
        if (mb_strlen($msg) < 2) {
            throw ApiException::validation(['message' => 'Type a message.']);
        }
        if (mb_strlen($msg) > 1000) {
            throw ApiException::validation(['message' => 'Message is too long (max 1000 characters).']);
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')) ?: 'Message from ' . DB::val('SELECT name FROM academies WHERE id = ?', [$aid]), 0, 120);
        $res = NotificationService::toStudents($aid, 'manual_message', $ids, ['message' => $msg, 'title' => $title], ['channels' => $channels, 'dedupe' => $dedupe, 'created_by' => $c->userId, 'priority' => 3]);
        Audit::log($c, 'message.send', null, null, null, ['target' => $target, 'students' => count($ids), 'queued' => $res]);
        return Http::ok($res, 'Message queued for ' . count($ids) . ' student(s).');
    }, $staff + ['perm' => 'notifications.send']);

    $r->get('/messages/log', static function (Ctx $c) {
        $w = 'q.academy_id = ?';
        $p = [$c->academy()];
        foreach (['status', 'channel', 'event_key'] as $k) {
            if ($v = Http::query($k)) {
                $w .= " AND q.$k = ?";
                $p[] = $v;
            }
        }
        if ($sid = (int) Http::query('student_id')) {
            $w .= ' AND q.student_id = ?';
            $p[] = $sid;
        }
        return Http::paged("SELECT q.id, q.event_key, q.channel, q.recipient, q.recipient_name, q.student_id, q.body, q.status, q.attempts, q.last_error, q.created_at, q.sent_at, q.delivered_at
                            FROM notification_queue q WHERE $w ORDER BY q.id DESC", $p, "SELECT COUNT(*) FROM notification_queue q WHERE $w");
    }, $own + ['perm' => 'notifications.send']);

    $r->post('/messages/retry', static function (Ctx $c) {
        $n = QueueProcessor::retry((array) (Http::input()['ids'] ?? []), $c->academy());
        return Http::ok(['retried' => $n], "$n message(s) will be retried.");
    }, $own + ['perm' => 'notifications.send']);

    $r->get('/notification-settings', static function (Ctx $c) {
        $aid = $c->academy();
        $matrix = [];
        foreach (NotificationService::EVENTS as $ev => $label) {
            foreach (NotificationService::CHANNELS as $ch) {
                $matrix[$ev][$ch] = NotificationService::channelEnabled($aid, $ev, $ch);
            }
        }
        $avail = [];
        foreach (NotificationService::CHANNELS as $ch) {
            $avail[$ch] = Feature::has($aid, $ch);
        }
        return Http::ok([
            'events' => NotificationService::EVENTS, 'channels' => NotificationService::CHANNELS, 'available' => $avail, 'matrix' => $matrix,
            'reminder_rules' => ReminderService::rules($aid), 'auto_reminders' => Feature::has($aid, 'auto_reminders'),
            'custom_templates' => Feature::has($aid, 'custom_templates'),
            'templates' => Feature::has($aid, 'custom_templates') ? DB::all('SELECT * FROM notification_templates WHERE academy_id = ?', [$aid]) : [],
        ]);
    }, $own);

    $r->post('/notification-settings', static function (Ctx $c) {
        $aid = $c->academy();
        $in = Http::input();
        DB::tx(static function () use ($aid, $in) {
            foreach ((array) ($in['matrix'] ?? []) as $ev => $chs) {
                if (!isset(NotificationService::EVENTS[$ev])) {
                    continue;
                }
                foreach ((array) $chs as $ch => $on) {
                    if (in_array($ch, NotificationService::CHANNELS, true)) {
                        DB::exec('INSERT INTO notification_preferences (academy_id, event_key, channel, enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)', [$aid, $ev, $ch, $on ? 1 : 0]);
                    }
                }
            }
            if (isset($in['reminder_rules']) && is_array($in['reminder_rules'])) {
                Feature::assert($aid, 'auto_reminders');
                $offsets = array_values(array_unique(array_filter(array_map('intval', $in['reminder_rules']), static fn($o) => $o >= -30 && $o <= 60)));
                if (count($offsets) > 10) {
                    throw ApiException::validation(['reminder_rules' => 'Use at most 10 reminder days.']);
                }
                DB::exec('DELETE FROM reminder_rules WHERE academy_id = ?', [$aid]);
                foreach ($offsets as $o) {
                    DB::insert('reminder_rules', ['academy_id' => $aid, 'offset_days' => $o, 'is_enabled' => 1]);
                }
                if (!$offsets) {
                    DB::insert('reminder_rules', ['academy_id' => $aid, 'offset_days' => 0, 'is_enabled' => 0]);
                }
            }
        });
        if (isset($in['reminder_rules'])) {
            ReminderService::reschedule($aid);
        }
        Audit::log($c, 'settings.notifications', 'academies', $aid);
        return Http::ok(null, 'Notification settings saved.');
    }, $own + ['perm' => 'settings.manage']);

    $r->post('/templates', static function (Ctx $c) {
        $aid = $c->academy();
        Feature::assert($aid, 'custom_templates');
        $d = Validator::make(Http::input(), ['event_key' => 'required|max:50', 'channel' => 'required|in:inapp,push,email,sms', 'title' => 'max:160', 'body' => 'required|max:2000', 'is_active' => 'bool'])->validated();
        if (!isset(NotificationService::EVENTS[$d['event_key']])) {
            throw ApiException::validation(['event_key' => 'Unknown event.']);
        }
        DB::exec('INSERT INTO notification_templates (academy_id, event_key, channel, title, body, is_active) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), is_active = VALUES(is_active)',
            [$aid, $d['event_key'], $d['channel'], $d['title'] ?? null, $d['body'], $d['is_active'] ?? 1]);
        return Http::ok(null, 'Template saved. (WhatsApp uses the platform\'s Meta-approved templates.)');
    }, $own + ['perm' => 'settings.manage']);

    // ------------------------------------------------------------ announcements
    $r->get('/announcements', static function (Ctx $c) {
        $aud = match ($c->role) {
            'teacher' => "AND audience IN ('all','teachers')",
            'parent', 'student' => "AND audience IN ('all','students','batch') AND publish_at <= NOW()",
            default => '',
        };
        $rows = DB::all("SELECT a.*, b.name AS batch_name FROM announcements a LEFT JOIN batches b ON b.id = a.batch_id WHERE a.academy_id = ? $aud ORDER BY a.publish_at DESC LIMIT 100", [$c->academy()]);
        if (in_array($c->role, ['parent', 'student'], true)) {
            $mine = $c->familyStudentIds() ?: [0];
            $batches = array_map('intval', DB::col('SELECT batch_id FROM student_activity_mapping WHERE student_id IN (' . DB::inList($mine) . ") AND status = 'active'", $mine));
            $rows = array_values(array_filter($rows, static fn($a) => $a['audience'] !== 'batch' || in_array((int) $a['batch_id'], $batches, true)));
        }
        return Http::ok($rows);
    }, ['roles' => ['owner', 'teacher', 'parent', 'student']]);

    $r->post('/announcements', static function (Ctx $c) {
        $aid = $c->academy();
        $in = Http::input();
        $d = Validator::make($in, ['title' => 'required|max:160', 'body' => 'required|max:3000', 'audience' => 'in:all,students,teachers,batch', 'batch_id' => 'int', 'publish_at' => 'max:19', 'notify' => 'bool'])->validated();
        $d['audience'] ??= 'all';
        if ($d['audience'] === 'batch') {
            $c->assertBatch((int) ($d['batch_id'] ?? 0));
        } else {
            $d['batch_id'] = null;
        }
        if ($c->role === 'teacher' && $d['audience'] !== 'batch') {
            throw ApiException::forbidden('Teachers can post announcements to their own batches.');
        }
        $d['publish_at'] = !empty($d['publish_at']) ? date('Y-m-d H:i:s', strtotime($d['publish_at'])) : date('Y-m-d H:i:s');
        $id = DB::insert('announcements', $d + ['academy_id' => $aid, 'created_by' => $c->userId, 'notify' => $d['notify'] ?? 1]);
        if (($d['notify'] ?? 1) && $d['publish_at'] <= date('Y-m-d H:i:s')) {
            AnnouncementService::publish($id);
        }
        Audit::log($c, 'announcement.create', 'announcements', $id, null, ['title' => $d['title']]);
        return Http::ok(['id' => $id], $d['publish_at'] > date('Y-m-d H:i:s') ? 'Announcement scheduled.' : 'Announcement published.');
    }, $staff + ['perm' => 'notifications.send']);

    $r->delete('/announcements/{id}', static function (Ctx $c, array $p) {
        Crud::find('announcements', (int) $p['id'], $c->academy(), 'Announcement');
        DB::exec('DELETE FROM announcements WHERE id = ?', [(int) $p['id']]);
        return Http::ok(null, 'Announcement removed.');
    }, $own);

    // ------------------------------------------------------------------ reports
    $r->get('/reports', static fn(Ctx $c) => Http::ok(array_map(static fn($v, $k) => ['key' => $k, 'title' => $v[0], 'financial' => $v[1]], ReportService::ACADEMY_REPORTS, array_keys(ReportService::ACADEMY_REPORTS))), $staff);
    $r->get('/reports/{name}', static function (Ctx $c, array $p) {
        if ($c->role !== 'super_admin') {
            if (!in_array($p['name'], ['students', 'attendance', 'monthly_attendance', 'branch_summary'], true)) {
                Feature::assert($c->academy(), 'reports');
            }
            if (!$c->can('reports.view')) {
                throw ApiException::forbidden();
            }
        }
        $f = array_intersect_key($_GET, array_flip(['from', 'to', 'batch_id', 'activity_id', 'student_id', 'status', 'month', 'method', 'branch_id']));
        $res = ReportService::run($c, $p['name'], $f);
        $fmt = Http::query('format');
        if (in_array($fmt, ['csv', 'xlsx', 'pdf'], true)) {
            ReportService::export($res, $fmt, $c->academyId ? (string) DB::val('SELECT name FROM academies WHERE id = ?', [$c->academyId]) : 'ClassNex');
        }
        return Http::ok($res);
    }, ['roles' => ['owner', 'teacher', 'super_admin']]);

    // ------------------------------------------------------------- subscription
    $r->get('/subscription', static function (Ctx $c) {
        $aid = $c->academy();
        return Http::ok([
            'current'  => Subscription::state($aid),
            'usage'    => Feature::summary($aid),
            'packages' => DB::all("SELECT id, name, description, price_paise, billing_cycle, billing_days, student_limit, whatsapp_mode FROM packages WHERE status = 'active' AND is_public = 1 ORDER BY sort_order"),
            'payments' => DB::all('SELECT sp.*, p.name AS package_name FROM subscription_payments sp JOIN packages p ON p.id = sp.package_id WHERE sp.academy_id = ? ORDER BY sp.id DESC LIMIT 50', [$aid]),
            'history'  => DB::all('SELECT s.*, p.name AS package_name FROM owner_subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.academy_id = ? ORDER BY s.id DESC LIMIT 50', [$aid]),
            'online_payment' => (bool) RazorpayService::config(null),
            'feature_labels' => Feature::FEATURES,
        ]);
    }, $own + ['write' => false]);

    $r->post('/subscription/checkout', static function (Ctx $c) {
        $aid = $c->academy();
        $pkg = DB::one("SELECT * FROM packages WHERE id = ? AND status = 'active'", [(int) (Http::input()['package_id'] ?? 0)]);
        if (!$pkg || (int) $pkg['price_paise'] <= 0) {
            throw ApiException::validation(['package_id' => 'Choose a paid plan.']);
        }
        $spId = DB::insert('subscription_payments', ['academy_id' => $aid, 'package_id' => $pkg['id'], 'amount_paise' => $pkg['price_paise'], 'method' => 'online', 'status' => 'pending', 'recorded_by' => $c->userId]);
        $order = RazorpayService::createOrder(null, 'subscription', $spId, (int) $pkg['price_paise'], [], $c->userId);
        DB::update('subscription_payments', ['gateway_order_id' => $order['order_id']], 'id = ?', [$spId]);
        $u = $c->user;
        return Http::ok($order + ['name' => 'ClassNex', 'description' => $pkg['name'] . ' plan', 'prefill' => ['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['country_code'] . $u['mobile']]]);
    }, $own + ['allow_readonly' => true]);

    // ------------------------------------------------- academy payment gateway
    $r->get('/payment-gateway', static function (Ctx $c) {
        $g = DB::one("SELECT key_id, key_secret_enc, webhook_secret_enc, is_enabled FROM payment_gateway_configs WHERE academy_id = ? AND provider = 'razorpay'", [$c->academy()]);
        return Http::ok(['provider' => 'razorpay', 'key_id' => $g['key_id'] ?? '', 'key_secret' => Crypto::mask($g['key_secret_enc'] ?? null), 'webhook_secret' => Crypto::mask($g['webhook_secret_enc'] ?? null), 'is_enabled' => (bool) ($g['is_enabled'] ?? false),
                         'webhook_url' => cnx_url('api/v1/webhooks/razorpay/' . $c->academy()), 'available' => Feature::has($c->academy(), 'online_payments')]);
    }, $own);
    $r->post('/payment-gateway', static function (Ctx $c) {
        $aid = $c->academy();
        Feature::assert($aid, 'online_payments');
        $in = Http::input();
        $row = ['academy_id' => $aid, 'provider' => 'razorpay', 'key_id' => trim((string) ($in['key_id'] ?? '')), 'is_enabled' => !empty($in['is_enabled']) ? 1 : 0];
        $existing = DB::one("SELECT * FROM payment_gateway_configs WHERE academy_id = ? AND provider = 'razorpay'", [$aid]);
        foreach (['key_secret', 'webhook_secret'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            $row[$k . '_enc'] = ($v !== '' && !str_contains($v, '•')) ? Crypto::encrypt($v) : ($existing[$k . '_enc'] ?? null);
        }
        if ($existing) {
            DB::update('payment_gateway_configs', $row, 'id = ?', [$existing['id']]);
        } else {
            DB::insert('payment_gateway_configs', $row);
        }
        Audit::log($c, 'settings.payment_gateway', 'payment_gateway_configs', $aid, null, ['enabled' => $row['is_enabled'], 'key_id' => $row['key_id']]);
        return Http::ok(null, 'Payment gateway settings saved.');
    }, $own + ['perm' => 'settings.manage']);

    // -------------------------------------------------------------- audit log
    $r->get('/audit-logs', static function (Ctx $c) {
        return Http::paged('SELECT l.id, l.action, l.entity, l.entity_id, l.old_values, l.new_values, l.ip, l.created_at, u.name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.academy_id = ? ORDER BY l.id DESC', [$c->academy()], 'SELECT COUNT(*) FROM audit_logs WHERE academy_id = ?');
    }, $own + ['perm' => 'settings.manage']);

    // ------------------------------------------------------------------ support
    $r->get('/support', static function (Ctx $c) {
        if ($c->role === 'owner') {
            // owner sees own platform tickets + tickets from parents/students of the academy
            $rows = DB::all("SELECT t.*, u.name AS user_name, u.role AS user_role FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.academy_id = ? ORDER BY t.status = 'closed', t.updated_at DESC LIMIT 200", [$c->academy()]);
        } else {
            $rows = DB::all('SELECT t.*, u.name AS user_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT 100', [$c->userId]);
        }
        return Http::ok($rows);
    }, ['roles' => ['owner', 'teacher', 'parent', 'student']]);
    $r->post('/support', static function (Ctx $c) {
        $d = Validator::make(Http::input(), ['subject' => 'required|max:200', 'message' => 'required|max:5000', 'priority' => 'in:low,normal,high'])->validated();
        $scope = $c->role === 'owner' ? 'platform' : 'academy';
        $id = DB::tx(static function () use ($c, $d, $scope) {
            $id = DB::insert('support_tickets', ['academy_id' => $c->academyId, 'user_id' => $c->userId, 'scope' => $scope, 'subject' => $d['subject'], 'priority' => $d['priority'] ?? 'normal']);
            DB::insert('support_ticket_replies', ['ticket_id' => $id, 'user_id' => $c->userId, 'message' => $d['message']]);
            return $id;
        });
        if ($scope === 'academy') {
            $owner = (int) DB::val('SELECT owner_user_id FROM academies WHERE id = ?', [$c->academy()]);
            NotificationService::toUsers($c->academy(), 'manual_message', [$owner], ['title' => 'New support request', 'message' => "{$c->user['name']}: {$d['subject']}"], ['channels' => ['inapp', 'push'], 'dedupe' => "tkt$id"]);
        }
        return Http::ok(['id' => $id], 'Your request has been submitted.');
    }, ['roles' => ['owner', 'teacher', 'parent', 'student'], 'write' => false]);
    $r->get('/support/{id}', static function (Ctx $c, array $p) {
        $t = SupportAccess::ticket($c, (int) $p['id']);
        $t['replies'] = DB::all('SELECT r.message, r.created_at, u.name, u.role FROM support_ticket_replies r LEFT JOIN users u ON u.id = r.user_id WHERE r.ticket_id = ? ORDER BY r.id', [$t['id']]);
        return Http::ok($t);
    }, ['roles' => ['owner', 'teacher', 'parent', 'student', 'super_admin']]);
    $r->post('/support/{id}/reply', static function (Ctx $c, array $p) {
        $t = SupportAccess::ticket($c, (int) $p['id']);
        $in = Http::input();
        $msg = trim((string) ($in['message'] ?? ''));
        if ($msg === '') {
            throw ApiException::validation(['message' => 'Type a reply.']);
        }
        DB::insert('support_ticket_replies', ['ticket_id' => $t['id'], 'user_id' => $c->userId, 'message' => mb_substr($msg, 0, 5000)]);
        $status = $in['status'] ?? null;
        $canSetStatus = $c->role === 'super_admin' || ($c->role === 'owner' && $t['scope'] === 'academy');
        DB::update('support_tickets', ['status' => $canSetStatus && in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true) ? $status : ($canSetStatus ? 'in_progress' : 'open')], 'id = ?', [$t['id']]);
        if ($t['user_id'] && (int) $t['user_id'] !== $c->userId) {
            NotificationService::toUsers($t['academy_id'] ? (int) $t['academy_id'] : null, 'manual_message', [(int) $t['user_id']], ['title' => 'Reply to: ' . $t['subject'], 'message' => mb_substr($msg, 0, 200)], ['channels' => ['inapp', 'push'], 'dedupe' => 'tr' . $t['id'] . '-' . time(), 'platform' => true]);
        }
        return Http::ok(null, 'Reply sent.');
    }, ['roles' => ['owner', 'teacher', 'parent', 'student', 'super_admin'], 'write' => false]);
};
