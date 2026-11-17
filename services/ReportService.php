<?php
declare(strict_types=1);

/**
 * Report definitions. Each report returns title + columns + rows from live data, and can be
 * exported as JSON (screen), CSV, Excel or PDF with the same filters.
 * Filters: from, to, batch_id, activity_id, student_id, status
 */
final class ReportService
{
    public const ACADEMY_REPORTS = [
        'students'           => ['Student Report', false],
        'admissions'         => ['Admission Report', false],
        'attendance'         => ['Attendance Report (daily)', false],
        'monthly_attendance' => ['Monthly Attendance Summary', false],
        'fee_collection'     => ['Fee Collection Report', true],
        'pending_fees'       => ['Pending Fee Report', true],
        'overdue_fees'       => ['Overdue Fee Report', true],
        'payment_history'    => ['Payment History', true],
        'batch_wise'         => ['Batch-wise Report', true],
        'activity_wise'      => ['Activity-wise Report', true],
        'teachers'           => ['Teacher Report', false],
        'notifications'      => ['Notification Report', false],
    ];

    public static function run(Ctx $ctx, string $report, array $f): array
    {
        if ($ctx->role === 'super_admin') {
            return self::platform($report, $f);
        }
        if (!isset(self::ACADEMY_REPORTS[$report])) {
            throw ApiException::notFound('Report');
        }
        [$title, $financial] = self::ACADEMY_REPORTS[$report];
        if ($financial && !$ctx->can('reports.financial')) {
            throw ApiException::forbidden();
        }
        $aid = $ctx->academy();
        $from = $f['from'] ?? date('Y-m-01');
        $to = $f['to'] ?? date('Y-m-d');
        $p = [$aid];
        $w = '';
        $batchScope = $ctx->batchScope();
        $batchFilter = static function (string $col) use (&$w, &$p, $f, $batchScope) {
            if (!empty($f['batch_id'])) {
                $w .= " AND $col = ?";
                $p[] = (int) $f['batch_id'];
            }
            if ($batchScope !== null) {
                $w .= " AND $col IN (" . DB::inList($batchScope) . ')';
                array_push($p, ...$batchScope);
            }
        };
        $actFilter = static function (string $col) use (&$w, &$p, $f) {
            if (!empty($f['activity_id'])) {
                $w .= " AND $col = ?";
                $p[] = (int) $f['activity_id'];
            }
        };
        $stuFilter = static function (string $col) use (&$w, &$p, $f) {
            if (!empty($f['student_id'])) {
                $w .= " AND $col = ?";
                $p[] = (int) $f['student_id'];
            }
        };
        $money = static fn($v) => Money::format((int) $v, '₹');

        switch ($report) {
            case 'students':
            case 'admissions':
                if ($report === 'admissions') {
                    $w .= ' AND s.admission_date BETWEEN ? AND ?';
                    array_push($p, $from, $to);
                }
                if (!empty($f['status'])) {
                    $w .= ' AND s.status = ?';
                    $p[] = $f['status'];
                }
                $batchFilter('m.batch_id');
                $actFilter('m.activity_id');
                $rows = DB::all("SELECT s.student_code, s.name, s.guardian_name, CONCAT(s.country_code,' ',s.whatsapp) AS whatsapp, GROUP_CONCAT(DISTINCT a.name SEPARATOR ', ') AS activities,
                                 GROUP_CONCAT(DISTINCT b.name SEPARATOR ', ') AS batches, s.admission_date, s.source, s.status
                                 FROM students s LEFT JOIN student_activity_mapping m ON m.student_id = s.id AND m.status = 'active'
                                 LEFT JOIN activities a ON a.id = m.activity_id LEFT JOIN batches b ON b.id = m.batch_id
                                 WHERE s.academy_id = ? $w GROUP BY s.id ORDER BY s.admission_date DESC, s.name", $p);
                return self::out($title, ['Student ID', 'Name', 'Guardian', 'WhatsApp', 'Activity', 'Batch', 'Admission Date', 'Source', 'Status'], $rows);

            case 'attendance':
                $w .= ' AND se.session_date BETWEEN ? AND ?';
                array_push($p, $from, $to);
                $batchFilter('se.batch_id');
                $stuFilter('at.student_id');
                if (!empty($f['status'])) {
                    $w .= ' AND at.status = ?';
                    $p[] = $f['status'];
                }
                $rows = DB::all("SELECT se.session_date, b.name AS batch, s.student_code, s.name, at.status, at.remarks, u.name AS marked_by
                                 FROM attendance at JOIN attendance_sessions se ON se.id = at.session_id JOIN batches b ON b.id = se.batch_id
                                 JOIN students s ON s.id = at.student_id LEFT JOIN users u ON u.id = at.marked_by
                                 WHERE se.academy_id = ? $w ORDER BY se.session_date DESC, b.name, s.name LIMIT 20000", $p);
                return self::out($title, ['Date', 'Batch', 'Student ID', 'Student', 'Status', 'Remarks', 'Marked By'], array_map(static fn($r) => array_merge($r, ['status' => ucfirst($r['status'])]), $rows));

            case 'monthly_attendance':
                $month = $f['month'] ?? date('Y-m');
                $mf = "$month-01";
                $mt = date('Y-m-t', strtotime($mf));
                $p = [$mf, $mt, $aid];
                $batchFilter('se.batch_id');
                $stuFilter('s.id');
                $rows = DB::all("SELECT s.student_code, s.name, b.name AS batch,
                                 SUM(at.status='present') AS present, SUM(at.status='absent') AS absent, SUM(at.status='late') AS late, SUM(at.status='leave') AS leave_days,
                                 ROUND(100 * SUM(at.status IN ('present','late')) / NULLIF(SUM(at.status IN ('present','late','absent')),0), 1) AS pct
                                 FROM attendance at JOIN attendance_sessions se ON se.id = at.session_id AND se.session_date BETWEEN ? AND ?
                                 JOIN students s ON s.id = at.student_id JOIN batches b ON b.id = se.batch_id
                                 WHERE se.academy_id = ? $w GROUP BY s.id, b.id ORDER BY b.name, s.name", $p);
                return self::out("$title – " . date('F Y', strtotime($mf)), ['Student ID', 'Student', 'Batch', 'Present', 'Absent', 'Late', 'Leave', 'Attendance %'], array_map(static fn($r) => array_merge($r, ['pct' => $r['pct'] === null ? '-' : $r['pct'] . '%']), $rows));

            case 'fee_collection':
            case 'payment_history':
                $w .= ' AND p.paid_on BETWEEN ? AND ?';
                array_push($p, $from, $to);
                $stuFilter('p.student_id');
                if (!empty($f['batch_id']) || !empty($f['activity_id'])) {
                    $w .= ' AND p.student_id IN (SELECT student_id FROM student_activity_mapping WHERE academy_id = ?' . (!empty($f['batch_id']) ? ' AND batch_id = ?' : '') . (!empty($f['activity_id']) ? ' AND activity_id = ?' : '') . ')';
                    $p[] = $aid;
                    if (!empty($f['batch_id'])) {
                        $p[] = (int) $f['batch_id'];
                    }
                    if (!empty($f['activity_id'])) {
                        $p[] = (int) $f['activity_id'];
                    }
                }
                if ($report === 'fee_collection') {
                    $w .= " AND p.status = 'success'";
                } elseif (!empty($f['status'])) {
                    $w .= ' AND p.status = ?';
                    $p[] = $f['status'];
                }
                if (!empty($f['method'])) {
                    $w .= ' AND p.method = ?';
                    $p[] = $f['method'];
                }
                $rows = DB::all("SELECT p.paid_on, r.receipt_no, s.student_code, s.name, p.method, p.reference, p.amount_paise, p.refunded_paise, r.balance_after_paise, u.name AS collected_by, p.status
                                 FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id LEFT JOIN users u ON u.id = p.collected_by
                                 WHERE p.academy_id = ? $w ORDER BY p.paid_on DESC, p.id DESC", $p);
                $total = array_sum(array_map(static fn($r) => $r['status'] === 'success' ? (int) $r['amount_paise'] - (int) $r['refunded_paise'] : 0, $rows));
                $rows = array_map(static fn($r) => array_merge($r, ['method' => ucwords(str_replace('_', ' ', $r['method'])), 'amount_paise' => $money($r['amount_paise']), 'refunded_paise' => $money($r['refunded_paise']), 'balance_after_paise' => $money($r['balance_after_paise']), 'status' => ucfirst($r['status'])]), $rows);
                return self::out($title, ['Date', 'Receipt No', 'Student ID', 'Student', 'Method', 'Reference', 'Amount', 'Refunded', 'Balance After', 'Collected By', 'Status'], $rows, ['Net collected' => $money($total), 'Payments' => count($rows)]);

            case 'pending_fees':
            case 'overdue_fees':
                if ($report === 'overdue_fees') {
                    $w .= ' AND i.due_date < CURDATE()';
                }
                $stuFilter('i.student_id');
                if (!empty($f['batch_id']) || !empty($f['activity_id']) || $batchScope !== null) {
                    $sub = 'SELECT student_id FROM student_activity_mapping m WHERE m.academy_id = ?';
                    $p[] = $aid;
                    $w2 = '';
                    $p2 = [];
                    if (!empty($f['batch_id'])) { $w2 .= ' AND m.batch_id = ?'; $p2[] = (int) $f['batch_id']; }
                    if (!empty($f['activity_id'])) { $w2 .= ' AND m.activity_id = ?'; $p2[] = (int) $f['activity_id']; }
                    $w .= " AND i.student_id IN ($sub$w2)";
                    array_push($p, ...$p2);
                }
                $rows = DB::all("SELECT i.invoice_no, s.student_code, s.name, CONCAT(s.country_code,' ',s.whatsapp) AS whatsapp, i.title, i.due_date,
                                 i.total_paise, i.paid_paise, i.total_paise - i.paid_paise AS pending, GREATEST(DATEDIFF(CURDATE(), i.due_date),0) AS days_overdue
                                 FROM fee_invoices i JOIN students s ON s.id = i.student_id
                                 WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active' $w ORDER BY i.due_date, s.name", $p);
                $total = array_sum(array_map('intval', array_column($rows, 'pending')));
                $rows = array_map(static fn($r) => array_merge($r, ['total_paise' => $money($r['total_paise']), 'paid_paise' => $money($r['paid_paise']), 'pending' => $money($r['pending'])]), $rows);
                return self::out($title, ['Invoice', 'Student ID', 'Student', 'WhatsApp', 'Fee', 'Due Date', 'Total', 'Paid', 'Pending', 'Days Overdue'], $rows, ['Total pending' => $money($total), 'Invoices' => count($rows)]);

            case 'batch_wise':
            case 'activity_wise':
                $byBatch = $report === 'batch_wise';
                $rows = DB::all("SELECT a.name AS activity, " . ($byBatch ? 'b.name' : "'-'") . " AS batch,
                                 COUNT(DISTINCT CASE WHEN m.status='active' AND s.status='active' THEN m.student_id END) AS students,
                                 (SELECT COALESCE(SUM(al.amount_paise),0) FROM payment_allocations al JOIN fee_invoices fi ON fi.id = al.invoice_id JOIN payments py ON py.id = al.payment_id
                                   WHERE py.paid_on BETWEEN ? AND ? AND fi.student_id IN (SELECT mm.student_id FROM student_activity_mapping mm WHERE " . ($byBatch ? 'mm.batch_id = b.id' : 'mm.activity_id = a.id') . ")) AS collected,
                                 (SELECT COALESCE(SUM(fi.total_paise - fi.paid_paise),0) FROM fee_invoices fi WHERE fi.status IN ('unpaid','partial') AND fi.student_id IN
                                   (SELECT mm.student_id FROM student_activity_mapping mm WHERE mm.status='active' AND " . ($byBatch ? 'mm.batch_id = b.id' : 'mm.activity_id = a.id') . ")) AS pending
                                 FROM activities a " . ($byBatch ? 'JOIN batches b ON b.activity_id = a.id' : '') . "
                                 LEFT JOIN student_activity_mapping m ON " . ($byBatch ? 'm.batch_id = b.id' : 'm.activity_id = a.id') . "
                                 LEFT JOIN students s ON s.id = m.student_id
                                 WHERE a.academy_id = ? " . (!empty($f['activity_id']) ? ' AND a.id = ' . (int) $f['activity_id'] : '') . "
                                 GROUP BY a.id" . ($byBatch ? ', b.id' : '') . ' ORDER BY a.name' . ($byBatch ? ', b.name' : ''), [$from, $to, $aid]);
                $rows = array_map(static fn($r) => array_merge($r, ['collected' => $money($r['collected']), 'pending' => $money($r['pending'])]), $rows);
                if (!$byBatch) {
                    $rows = array_map(static function ($r) { unset($r['batch']); return $r; }, $rows);
                    return self::out($title, ['Activity', 'Active Students', 'Collected (period)', 'Pending (all)'], $rows);
                }
                return self::out($title, ['Activity', 'Batch', 'Active Students', 'Collected (period)', 'Pending (all)'], $rows);

            case 'teachers':
                $rows = DB::all("SELECT t.name, CONCAT(t.country_code,' ',t.mobile) AS mobile, t.joining_date,
                                 (SELECT COUNT(*) FROM batches b WHERE b.teacher_id = t.id AND b.status='active') +
                                 (SELECT COUNT(*) FROM teacher_batches tb WHERE tb.teacher_id = t.id) AS batches,
                                 (SELECT COUNT(*) FROM attendance_sessions se WHERE se.marked_by = t.user_id AND se.session_date BETWEEN ? AND ?) AS sessions_marked,
                                 t.status FROM teachers t WHERE t.academy_id = ? ORDER BY t.name", [$from, $to, $aid]);
                return self::out($title, ['Teacher', 'Mobile', 'Joined', 'Batches', 'Sessions marked (period)', 'Status'], $rows);

            case 'notifications':
                $rows = DB::all("SELECT DATE(q.created_at) AS day, q.event_key, q.channel, q.recipient_name, q.recipient, q.status, q.attempts, q.last_error
                                 FROM notification_queue q WHERE q.academy_id = ? AND DATE(q.created_at) BETWEEN ? AND ? ORDER BY q.id DESC LIMIT 10000", [$aid, $from, $to]);
                return self::out($title, ['Date', 'Event', 'Channel', 'Recipient', 'To', 'Status', 'Attempts', 'Note'], $rows);
        }
        throw ApiException::notFound('Report');
    }

    private static function platform(string $report, array $f): array
    {
        $money = static fn($v) => Money::format((int) $v, '₹');
        switch ($report) {
            case 'subscriptions':
                $rows = DB::all("SELECT a.name AS academy, u.name AS owner, CONCAT(u.country_code,' ',u.mobile) AS mobile, p.name AS package, s.status, s.starts_on, s.ends_on, s.price_paise
                                 FROM owner_subscriptions s JOIN academies a ON a.id = s.academy_id JOIN packages p ON p.id = s.package_id LEFT JOIN users u ON u.id = a.owner_user_id
                                 WHERE s.is_current = 1" . (!empty($f['status']) ? ' AND s.status = ?' : '') . ' ORDER BY s.ends_on', !empty($f['status']) ? [$f['status']] : []);
                return self::out('Subscription Report', ['Academy', 'Owner', 'Mobile', 'Package', 'Status', 'Starts', 'Ends', 'Price'], array_map(static fn($r) => array_merge($r, ['price_paise' => $money($r['price_paise'])]), $rows));
            case 'subscription_payments':
                $rows = DB::all("SELECT sp.paid_at, a.name AS academy, p.name AS package, sp.amount_paise, sp.method, sp.reference, sp.status FROM subscription_payments sp
                                 JOIN academies a ON a.id = sp.academy_id JOIN packages p ON p.id = sp.package_id WHERE DATE(COALESCE(sp.paid_at, sp.created_at)) BETWEEN ? AND ? ORDER BY sp.id DESC", [$f['from'] ?? date('Y-01-01'), $f['to'] ?? date('Y-m-d')]);
                return self::out('Subscription Payments', ['Paid At', 'Academy', 'Package', 'Amount', 'Method', 'Reference', 'Status'], array_map(static fn($r) => array_merge($r, ['amount_paise' => $money($r['amount_paise'])]), $rows));
            case 'academies':
                $rows = DB::all("SELECT a.name, a.code, u.name AS owner, a.city, (SELECT COUNT(*) FROM students s WHERE s.academy_id = a.id AND s.status='active') AS students,
                                 (SELECT COUNT(*) FROM teachers t WHERE t.academy_id = a.id AND t.status='active') AS teachers, a.status, a.created_at
                                 FROM academies a LEFT JOIN users u ON u.id = a.owner_user_id ORDER BY a.created_at DESC");
                return self::out('Academy Report', ['Academy', 'Code', 'Owner', 'City', 'Students', 'Teachers', 'Status', 'Created'], $rows);
            case 'notifications':
                $rows = DB::all("SELECT DATE(created_at) AS day, channel, status, COUNT(*) AS total FROM notification_queue WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY day, channel, status ORDER BY day DESC", [$f['from'] ?? date('Y-m-01'), $f['to'] ?? date('Y-m-d')]);
                return self::out('Notification Usage', ['Date', 'Channel', 'Status', 'Count'], $rows);
        }
        throw ApiException::notFound('Report');
    }

    private static function out(string $title, array $columns, array $rows, array $totals = []): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => array_map('array_values', $rows), 'totals' => $totals, 'generated_at' => date('d M Y H:i')];
    }

    /** Streams the report in the requested format and exits. */
    public static function export(array $r, string $format, string $academyName = 'ClassNex'): never
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($r['title'])) . '-' . date('Ymd');
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$slug.csv\"");
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $r['columns']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
            }
            foreach ($r['totals'] as $k => $v) {
                fputcsv($out, [$k, $v]);
            }
            exit;
        }
        if ($format === 'xlsx') {
            $rows = $r['rows'];
            foreach ($r['totals'] as $k => $v) {
                $rows[] = [$k, $v];
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"$slug.xlsx\"");
            echo XlsxWriter::build(mb_substr($r['title'], 0, 31), $r['columns'], $rows);
            exit;
        }
        // PDF (landscape-ish fit by shrinking columns)
        $pdf = new SimplePdf($r['title']);
        $m = 30;
        $pdf->margin = $m;
        $W = SimplePdf::W - 2 * $m;
        $pdf->font(true, 14)->color(20, 30, 70);
        $pdf->text($m, $m, $academyName);
        $pdf->font(true, 11)->color(36, 55, 160);
        $pdf->text($m, $m + 20, $r['title']);
        $pdf->font(false, 8)->color(110, 110, 110);
        $pdf->text($m, $m + 20, 'Generated ' . $r['generated_at'], 'R', $W);
        $pdf->y = $m + 42;
        $n = max(1, count($r['columns']));
        $cols = [];
        foreach ($r['columns'] as $c) {
            $cols[] = ['label' => $c, 'w' => $W / $n];
        }
        $pdf->table($cols, $r['rows'], 16, $n > 8 ? 6.5 : 7.5);
        if ($r['totals']) {
            $pdf->ensure(40);
            $pdf->y += 10;
            $pdf->font(true, 9)->color(20, 20, 20);
            foreach ($r['totals'] as $k => $v) {
                $pdf->text($m, $pdf->y, "$k: $v");
                $pdf->y += 14;
            }
        }
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"$slug.pdf\"");
        echo $pdf->output();
        exit;
    }
}
