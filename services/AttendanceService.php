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
            throw ApiException::forbidden('Only the owner (or staff allowed to correct attendance) can change older attendance.');
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
            DB::exec('INSERT IGNORE INTO attendance_sessions (academy_id, batch_id, session_date, marked_by) VALUES (?,?,?,?)', [$aid, $batchId, $date, $ctx->userId]);
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
}
