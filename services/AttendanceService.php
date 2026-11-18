<?php
declare(strict_types=1);

final class AttendanceService
{
    public const STATUSES = ['present', 'absent', 'late', 'leave', 'holiday'];

    /** Students enrolled in a batch on a date (includes students transferred later, excludes those who left before). */
    public static function roster(int $batchId, string $date): array
    {
        return DB::all("SELECT s.id, s.student_code, s.name, s.photo_path, m.status AS enrolment_status
                        FROM student_activity_mapping m JOIN students s ON s.id = m.student_id
                        WHERE m.batch_id = ? AND m.start_date <= ? AND (m.end_date IS NULL OR m.end_date >= ?) AND s.status = 'active'
                        GROUP BY s.id, s.student_code, s.name, s.photo_path, m.status ORDER BY s.name", [$batchId, $date, $date]);
    }

    public static function sheet(Ctx $ctx, int $batchId, string $date): array
    {
        $ctx->assertBatch($batchId);
        $session = DB::one('SELECT * FROM attendance_sessions WHERE batch_id = ? AND session_date = ?', [$batchId, $date]);
        $marks = $session ? array_column(DB::all('SELECT student_id, status, remarks FROM attendance WHERE session_id = ?', [$session['id']]), null, 'student_id') : [];
        $holiday = DB::one('SELECT title FROM holidays WHERE academy_id = ? AND holiday_date = ? AND (batch_id IS NULL OR batch_id = ?)', [$ctx->academy(), $date, $batchId]);
        $students = array_map(static function ($s) use ($marks) {
            $s['status'] = $marks[$s['id']]['status'] ?? null;
            $s['remarks'] = $marks[$s['id']]['remarks'] ?? null;
            $s['photo_url'] = $s['photo_path'] ? cnx_url('api/v1/files/student-photo/' . $s['id']) : null;
            unset($s['photo_path']);
            return $s;
        }, self::roster($batchId, $date));
        return ['session' => $session, 'date' => $date, 'holiday' => $holiday['title'] ?? null, 'students' => $students, 'already_marked' => (bool) $session];
    }

    /**
     * Saves attendance for a batch/date. One session per batch per day and one row per student
     * (unique keys), so re-submitting never duplicates. Changing an earlier day's mark needs the
     * attendance.correct permission and is written to attendance_corrections.
     */
    public static function save(Ctx $ctx, int $batchId, string $date, array $entries, ?string $reason = null): array
    {
        $aid = $ctx->academy();
        $ctx->assertBatch($batchId);
        if ($date > date('Y-m-d')) {
            throw ApiException::validation(['date' => 'Attendance cannot be marked for a future date.']);
        }
        if ($date < date('Y-m-d', strtotime('-1 day')) && !$ctx->can('attendance.correct')) {
            throw ApiException::forbidden('Only the Class Admin (or staff allowed to correct attendance) can change older attendance.');
        }
        $roster = array_map('intval', array_column(self::roster($batchId, $date), 'id'));
        $clean = [];
        foreach ($entries as $e) {
            $sid = (int) ($e['student_id'] ?? 0);
            $st = strtolower((string) ($e['status'] ?? ''));
            if (!in_array($sid, $roster, true)) {
                continue; // not in this batch on this date
            }
            if (!in_array($st, self::STATUSES, true)) {
                throw ApiException::validation(['status' => 'Invalid attendance status.']);
            }
            $clean[$sid] = ['status' => $st, 'remarks' => isset($e['remarks']) ? mb_substr((string) $e['remarks'], 0, 160) : null];
        }
        if (!$clean) {
            throw ApiException::validation(['entries' => 'Mark at least one student.']);
        }

        $notify = [];
        $summary = DB::tx(static function () use ($ctx, $aid, $batchId, $date, $clean, $reason, &$notify) {
            DB::exec('INSERT IGNORE INTO attendance_sessions (academy_id, batch_id, branch_id, session_date, marked_by) SELECT ?, id, branch_id, ?, ? FROM batches WHERE id = ?', [$aid, $date, $ctx->userId, $batchId]);
            $session = DB::one('SELECT * FROM attendance_sessions WHERE batch_id = ? AND session_date = ? FOR UPDATE', [$batchId, $date]);
            $existing = array_column(DB::all('SELECT id, student_id, status, notified_status FROM attendance WHERE session_id = ? FOR UPDATE', [$session['id']]), null, 'student_id');
            $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0];
            foreach ($clean as $sid => $e) {
                $old = $existing[$sid] ?? null;
                if (!$old) {
                    $attId = DB::insert('attendance', ['session_id' => $session['id'], 'student_id' => $sid, 'status' => $e['status'], 'remarks' => $e['remarks'], 'marked_by' => $ctx->userId]);
                    $counts['new']++;
                    $notify[] = ['id' => $attId, 'student_id' => $sid, 'status' => $e['status'], 'prev_notified' => null];
                    continue;
                }
                if ($old['status'] === $e['status']) {
                    if ($e['remarks'] !== null) {
                        DB::update('attendance', ['remarks' => $e['remarks']], 'id = ?', [$old['id']]);
                    }
                    $counts['unchanged']++;
                    continue;
                }
                DB::update('attendance', ['status' => $e['status'], 'remarks' => $e['remarks'], 'marked_by' => $ctx->userId], 'id = ?', [$old['id']]);
                DB::insert('attendance_corrections', ['attendance_id' => $old['id'], 'old_status' => $old['status'], 'new_status' => $e['status'], 'reason' => $reason, 'corrected_by' => $ctx->userId]);
                $counts['changed']++;
                $notify[] = ['id' => (int) $old['id'], 'student_id' => $sid, 'status' => $e['status'], 'prev_notified' => $old['notified_status']];
            }
            DB::update('attendance_sessions', ['marked_by' => $ctx->userId], 'id = ?', [$session['id']]);
            if ($counts['changed']) {
                Audit::log($ctx, 'attendance.correct', 'attendance_sessions', $session['id'], null, ['date' => $date, 'changed' => $counts['changed'], 'reason' => $reason]);
            }
            return $counts + ['session_id' => (int) $session['id']];
        });

        $summary['notifications'] = self::notify($aid, $date, $notify);
        return $summary;
    }

    /** Sends a parent update only when the status actually changed since the last update. */
    private static function notify(int $aid, string $date, array $items): int
    {
        if (!$items || !Feature::has($aid, 'attendance')) {
            return 0;
        }
        $prefs = Settings::academy($aid)['attendance_notify'];
        $sent = 0;
        foreach ($items as $it) {
            if (!in_array($it['status'], ['present', 'absent', 'late'], true) || $it['prev_notified'] === $it['status']) {
                continue;
            }
            $corrected = $it['prev_notified'] !== null;
            $event = $corrected ? 'attendance_corrected' : 'attendance_' . $it['status'];
            if (($corrected && empty($prefs['corrected'])) || (!$corrected && empty($prefs[$it['status']]))) {
                continue;
            }
            NotificationService::toStudents($aid, $event, [$it['student_id']], ['status' => ucfirst($it['status']), 'date' => date('d M Y', strtotime($date))], ['dedupe' => "att{$it['id']}:{$it['status']}", 'priority' => 4]);
            DB::update('attendance', ['notified_status' => $it['status']], 'id = ?', [$it['id']]);
            $sent++;
        }
        return $sent;
    }

    /** Monthly summary for one student: counts + percentage + day list. */
    public static function studentMonth(int $studentId, string $month): array
    {
        $from = "$month-01";
        $to = date('Y-m-t', strtotime($from));
        $rows = DB::all('SELECT s.session_date, a.status, b.name AS batch_name FROM attendance a JOIN attendance_sessions s ON s.id = a.session_id
                         JOIN batches b ON b.id = s.batch_id WHERE a.student_id = ? AND s.session_date BETWEEN ? AND ? ORDER BY s.session_date', [$studentId, $from, $to]);
        return ['month' => $month, 'days' => $rows] + self::stats($rows);
    }

    public static function stats(array $rows): array
    {
        $c = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $c[$r['status']]++;
        }
        $counted = $c['present'] + $c['late'] + $c['absent'];
        return ['counts' => $c, 'percentage' => $counted ? round(($c['present'] + $c['late']) * 100 / $counted, 1) : null];
    }

    // ------------------------------------------------------------------ history
    /**
     * Shared filter for attendance history (web, Android, exports).
     * Filters: date | from+to, branch_id (id or "none"), activity_id, batch_id, student_id, status.
     * The branch comes from the session (stamped when attendance was taken), so history stays with the
     * branch where the class happened even after a student or batch moves to another branch.
     */
    public static function historyWhere(Ctx $ctx, array $f): array
    {
        $aid = $ctx->academy();
        $w = 'se.academy_id = ?';
        $p = [$aid];
        $from = $f['date'] ?? null ?: ($f['from'] ?? null ?: date('Y-m-01'));
        $to = $f['date'] ?? null ?: ($f['to'] ?? null ?: date('Y-m-d'));
        foreach (['from' => $from, 'to' => $to] as $k => $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                throw ApiException::validation([$k => 'Invalid date.']);
            }
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $w .= ' AND se.session_date BETWEEN ? AND ?';
        array_push($p, $from, $to);
        $branch = $f['branch_id'] ?? '';
        if ($branch === 'none') {
            $w .= ' AND se.branch_id IS NULL';
        } elseif ($branch !== '' && $branch !== null) {
            if (!DB::val('SELECT 1 FROM academy_branches WHERE id = ? AND academy_id = ?', [(int) $branch, $aid])) {
                throw ApiException::notFound('Branch');
            }
            $w .= ' AND se.branch_id = ?';
            $p[] = (int) $branch;
        }
        if (!empty($f['activity_id'])) {
            $w .= ' AND b.activity_id = ?';
            $p[] = (int) $f['activity_id'];
        }
        if (!empty($f['batch_id'])) {
            $ctx->assertBatch((int) $f['batch_id']);
            $w .= ' AND se.batch_id = ?';
            $p[] = (int) $f['batch_id'];
        }
        $scope = $ctx->batchScope();
        if ($scope !== null) {
            $w .= $scope ? ' AND se.batch_id IN (' . DB::inList($scope) . ')' : ' AND 0';
            array_push($p, ...$scope);
        }
        // row-level filters (apply to attendance rows, joined as "at")
        $rw = '';
        $rp = [];
        if (!empty($f['student_id'])) {
            $ctx->assertStudent((int) $f['student_id']);
            $rw .= ' AND at.student_id = ?';
            $rp[] = (int) $f['student_id'];
        }
        if (!empty($f['status'])) {
            if (!in_array($f['status'], self::STATUSES, true)) {
                throw ApiException::validation(['status' => 'Unknown status.']);
            }
            $rw .= ' AND at.status = ?';
            $rp[] = $f['status'];
        }
        return [$w, $p, $rw, $rp, $from, $to];
    }

    private const H_FROM = 'FROM attendance_sessions se JOIN batches b ON b.id = se.batch_id JOIN activities a ON a.id = b.activity_id
                            LEFT JOIN academy_branches br ON br.id = se.branch_id';
    private const H_COUNTS = "COUNT(DISTINCT at.student_id) AS total_students, SUM(at.status = 'present') AS present, SUM(at.status = 'absent') AS absent,
                              SUM(at.status = 'late') AS late, SUM(at.status = 'leave') AS on_leave";

    /** Present + late over present + late + absent (leave and holidays are not counted against the student). */
    public static function pct(array $r): ?float
    {
        $counted = (int) $r['present'] + (int) $r['late'] + (int) $r['absent'];
        return $counted ? round(((int) $r['present'] + (int) $r['late']) * 100 / $counted, 1) : null;
    }

    private static function counts(array $r): array
    {
        $out = ['total_students' => (int) $r['total_students'], 'present' => (int) $r['present'], 'absent' => (int) $r['absent'], 'late' => (int) $r['late'], 'leave' => (int) $r['on_leave']];
        return $out + ['attendance_pct' => self::pct($r)];
    }

    /**
     * view = sessions (one row per class / day), students (one row per student per day), branches (branch totals).
     * Returns rows plus a summary and, for the combined academy view, a per-branch breakdown.
     */
    public static function history(Ctx $ctx, array $f, string $view, ?int $limit, int $offset = 0): array
    {
        [$w, $p, $rw, $rp, $from, $to] = self::historyWhere($ctx, $f);
        $pp = array_merge($rp, $p); // row filters sit in the JOIN, before the WHERE
        $join = " JOIN attendance at ON at.session_id = se.id AND at.status <> 'holiday'$rw";
        $lim = $limit ? " LIMIT $limit OFFSET $offset" : '';
        $sum = DB::one('SELECT ' . self::H_COUNTS . ', COUNT(DISTINCT se.id) AS sessions ' . self::H_FROM . $join . " WHERE $w", $pp);
        $summary = self::counts($sum) + ['sessions' => (int) $sum['sessions'], 'from' => $from, 'to' => $to];
        $branchName = static fn($n) => $n ?? 'Main';
        switch ($view) {
            case 'students':
                $total = (int) DB::val('SELECT COUNT(*) ' . self::H_FROM . $join . " WHERE $w", $pp);
                $rows = DB::all('SELECT se.session_date, at.status, at.remarks, s.id AS student_id, s.student_code, s.name AS student_name, b.name AS batch_name, a.name AS activity_name, br.name AS branch_name '
                    . self::H_FROM . $join . " JOIN students s ON s.id = at.student_id WHERE $w ORDER BY se.session_date DESC, br.name, b.name, s.name$lim", $pp);
                break;
            case 'branches':
                $rows = DB::all('SELECT se.branch_id, br.name AS branch_name, COUNT(DISTINCT se.id) AS sessions, ' . self::H_COUNTS . ' '
                    . self::H_FROM . $join . " WHERE $w GROUP BY se.branch_id, br.name ORDER BY br.name IS NULL, br.name", $pp);
                $rows = array_map(static fn($r) => ['branch_id' => $r['branch_id'], 'branch_name' => $branchName($r['branch_name']), 'sessions' => (int) $r['sessions']] + self::counts($r), $rows);
                $total = count($rows);
                break;
            default:
                $total = (int) DB::val('SELECT COUNT(DISTINCT se.id) ' . self::H_FROM . $join . " WHERE $w", $pp);
                $rows = DB::all('SELECT se.id, se.session_date, se.branch_id, br.name AS branch_name, b.name AS batch_name, a.name AS activity_name, u.name AS marked_by, ' . self::H_COUNTS . ' '
                    . self::H_FROM . $join . " LEFT JOIN users u ON u.id = se.marked_by WHERE $w GROUP BY se.id ORDER BY se.session_date DESC, br.name, b.name$lim", $pp);
                $rows = array_map(static fn($r) => array_merge($r, ['branch_name' => $branchName($r['branch_name'])], self::counts($r)), $rows);
        }
        return ['rows' => $rows, 'total' => $total, 'summary' => $summary];
    }

    /** Report-shaped history for PDF / Excel / CSV exports. */
    public static function historyReport(Ctx $ctx, array $f, string $view): array
    {
        $h = self::history($ctx, $f, $view, 50000);
        $s = $h['summary'];
        $branch = !empty($f['branch_id']) ? ($f['branch_id'] === 'none' ? 'Main' : DB::val('SELECT name FROM academy_branches WHERE id = ?', [(int) $f['branch_id']])) : 'All branches (combined)';
        $title = 'Attendance History – ' . $branch . ' (' . date('d M Y', strtotime($s['from'])) . ' – ' . date('d M Y', strtotime($s['to'])) . ')';
        $pct = static fn($v) => $v === null ? '-' : $v . '%';
        if ($view === 'students') {
            $cols = ['Date', 'Branch', 'Batch', 'Activity', 'Student ID', 'Student', 'Status', 'Remarks'];
            $rows = array_map(static fn($r) => [date('d-m-Y', strtotime($r['session_date'])), $r['branch_name'] ?? 'Main', $r['batch_name'], $r['activity_name'], $r['student_code'], $r['student_name'], ucfirst($r['status']), (string) $r['remarks']], $h['rows']);
        } elseif ($view === 'branches') {
            $cols = ['Branch', 'Classes', 'Students', 'Present', 'Absent', 'Late', 'Leave', 'Attendance %'];
            $rows = array_map(static fn($r) => [$r['branch_name'], $r['sessions'], $r['total_students'], $r['present'], $r['absent'], $r['late'], $r['leave'], $pct($r['attendance_pct'])], $h['rows']);
        } else {
            $cols = ['Date', 'Branch', 'Batch', 'Activity', 'Students', 'Present', 'Absent', 'Late', 'Leave', 'Attendance %', 'Marked by'];
            $rows = array_map(static fn($r) => [date('d-m-Y', strtotime($r['session_date'])), $r['branch_name'], $r['batch_name'], $r['activity_name'], $r['total_students'], $r['present'], $r['absent'], $r['late'], $r['leave'], $pct($r['attendance_pct']), (string) $r['marked_by']], $h['rows']);
        }
        return ['title' => $title, 'columns' => $cols, 'rows' => $rows, 'generated_at' => date('d M Y H:i'), 'totals' => [
            'Total students' => $s['total_students'], 'Present' => $s['present'], 'Absent' => $s['absent'], 'Late' => $s['late'], 'Leave' => $s['leave'], 'Attendance %' => $pct($s['attendance_pct']),
        ]];
    }
}
