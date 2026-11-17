<?php
declare(strict_types=1);

return static function (Router $r): void {
    $staff = ['roles' => ['owner', 'teacher'], 'feature' => 'attendance'];

    // attendance sheet for a batch on a date (defaults to today)
    $r->get('/attendance/sheet', static function (Ctx $c) {
        $date = Http::query('date') ?: date('Y-m-d');
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            throw ApiException::validation(['date' => 'Invalid date.']);
        }
        return Http::ok(AttendanceService::sheet($c, (int) Http::query('batch_id'), $date));
    }, $staff + ['perm' => 'attendance.mark']);

    $r->post('/attendance', static function (Ctx $c) {
        $in = Http::input();
        $date = (string) ($in['date'] ?? date('Y-m-d'));
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            throw ApiException::validation(['date' => 'Invalid date.']);
        }
        $res = AttendanceService::save($c, (int) ($in['batch_id'] ?? 0), $date, (array) ($in['entries'] ?? []), $in['reason'] ?? null);
        $msg = $res['changed'] ? "Attendance updated ({$res['changed']} corrected)." : 'Attendance saved.';
        return Http::ok($res, $msg);
    }, $staff + ['perm' => 'attendance.mark']);

    // today's overview across batches
    $r->get('/attendance/today', static function (Ctx $c) {
        $scope = $c->batchScope();
        $date = Http::query('date') ?: date('Y-m-d');
        $wd = (int) date('N', strtotime($date));
        $w = 'b.academy_id = ? AND b.status = \'active\'';
        $p = [$date, $wd, $c->academy()];
        if ($scope !== null) {
            $w .= ' AND b.id IN (' . DB::inList($scope) . ')';
            array_push($p, ...$scope);
        }
        $rows = DB::all("SELECT b.id, b.name, a.name AS activity_name, b.start_time, b.end_time, se.id AS session_id,
                           (SELECT COUNT(*) FROM attendance x WHERE x.session_id = se.id AND x.status IN ('present','late')) AS present,
                           (SELECT COUNT(*) FROM attendance x WHERE x.session_id = se.id AND x.status = 'absent') AS absent,
                           (SELECT COUNT(*) FROM student_activity_mapping m WHERE m.batch_id = b.id AND m.status = 'active') AS enrolled,
                           EXISTS(SELECT 1 FROM batch_schedules bs WHERE bs.batch_id = b.id AND bs.weekday = ?) OR NOT EXISTS(SELECT 1 FROM batch_schedules bs2 WHERE bs2.batch_id = b.id) AS scheduled
                         FROM batches b JOIN activities a ON a.id = b.activity_id LEFT JOIN attendance_sessions se ON se.batch_id = b.id AND se.session_date = ?
                         WHERE $w ORDER BY b.start_time, b.name", array_merge([$wd, $date], array_slice($p, 2)));
        return Http::ok(['date' => $date, 'batches' => $rows]);
    }, $staff);

    $r->get('/attendance/history', static function (Ctx $c) {
        $w = 'se.academy_id = ?';
        $p = [$c->academy()];
        if ($b = (int) Http::query('batch_id')) {
            $c->assertBatch($b);
            $w .= ' AND se.batch_id = ?';
            $p[] = $b;
        }
        $scope = $c->batchScope();
        if ($scope !== null) {
            $w .= ' AND se.batch_id IN (' . DB::inList($scope) . ')';
            array_push($p, ...$scope);
        }
        $w .= ' AND se.session_date BETWEEN ? AND ?';
        array_push($p, Http::query('from') ?: date('Y-m-01'), Http::query('to') ?: date('Y-m-d'));
        return Http::paged("SELECT se.id, se.session_date, b.name AS batch_name, a.name AS activity_name, u.name AS marked_by,
                              SUM(at.status='present') AS present, SUM(at.status='absent') AS absent, SUM(at.status='late') AS late, SUM(at.status='leave') AS on_leave, COUNT(at.id) AS total
                            FROM attendance_sessions se JOIN batches b ON b.id = se.batch_id JOIN activities a ON a.id = b.activity_id
                            LEFT JOIN attendance at ON at.session_id = se.id LEFT JOIN users u ON u.id = se.marked_by
                            WHERE $w GROUP BY se.id ORDER BY se.session_date DESC, b.name", $p, "SELECT COUNT(*) FROM attendance_sessions se WHERE $w");
    }, $staff);

    $r->get('/students/{id}/attendance', static function (Ctx $c, array $p) {
        $c->assertStudent((int) $p['id']);
        $month = Http::query('month') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ApiException::validation(['month' => 'Invalid month.']);
        }
        $data = AttendanceService::studentMonth((int) $p['id'], $month);
        $data['corrections'] = DB::all('SELECT c.old_status, c.new_status, c.reason, c.created_at, se.session_date, u.name AS by_name FROM attendance_corrections c JOIN attendance a ON a.id = c.attendance_id
                                        JOIN attendance_sessions se ON se.id = a.session_id LEFT JOIN users u ON u.id = c.corrected_by WHERE a.student_id = ? ORDER BY c.id DESC LIMIT 50', [(int) $p['id']]);
        return Http::ok($data);
    }, ['roles' => ['owner', 'teacher', 'parent', 'student']]);

    // holidays
    $r->get('/holidays', static function (Ctx $c) {
        return Http::ok(DB::all('SELECT h.*, b.name AS batch_name FROM holidays h LEFT JOIN batches b ON b.id = h.batch_id WHERE h.academy_id = ? AND h.holiday_date >= ? ORDER BY h.holiday_date', [$c->academy(), Http::query('from') ?: date('Y-01-01')]));
    }, ['roles' => ['owner', 'teacher', 'parent', 'student']]);
    $r->post('/holidays', static function (Ctx $c) {
        $aid = $c->academy();
        $in = Http::input();
        $d = Validator::make($in, ['holiday_date' => 'required|date', 'title' => 'required|max:120', 'batch_id' => 'int'])->validated();
        if (!empty($d['batch_id'])) {
            $c->assertBatch((int) $d['batch_id']);
        }
        $id = DB::insert('holidays', $d + ['academy_id' => $aid]);
        if (!empty($in['notify'])) {
            $ids = !empty($d['batch_id'])
                ? DB::col("SELECT student_id FROM student_activity_mapping WHERE batch_id = ? AND status = 'active'", [$d['batch_id']])
                : DB::col("SELECT id FROM students WHERE academy_id = ? AND status = 'active'", [$aid]);
            $msg = "Holiday on " . date('d M Y', strtotime($d['holiday_date'])) . ": {$d['title']}. No classes.";
            NotificationService::toStudents($aid, 'holiday', $ids, ['title' => $d['title'], 'message' => $msg], ['dedupe' => "hol$id"]);
        }
        Audit::log($c, 'holiday.create', 'holidays', $id, null, $d);
        return Http::ok(['id' => $id], 'Holiday added.');
    }, ['roles' => ['owner'], 'perm' => 'settings.manage']);
    $r->delete('/holidays/{id}', static function (Ctx $c, array $p) {
        Crud::find('holidays', (int) $p['id'], $c->academy(), 'Holiday');
        DB::exec('DELETE FROM holidays WHERE id = ?', [(int) $p['id']]);
        return Http::ok(null, 'Holiday removed.');
    }, ['roles' => ['owner'], 'perm' => 'settings.manage']);

    // class cancellation (notifies the batch)
    $r->post('/batches/{id}/cancel-class', static function (Ctx $c, array $p) {
        $bid = (int) $p['id'];
        $c->assertBatch($bid);
        $in = Http::input();
        $date = $in['date'] ?? date('Y-m-d');
        $b = DB::one('SELECT name FROM batches WHERE id = ?', [$bid]);
        $msg = trim((string) ($in['message'] ?? '')) ?: "Class for {$b['name']} on " . date('d M Y', strtotime($date)) . ' is cancelled.';
        $ids = DB::col("SELECT student_id FROM student_activity_mapping WHERE batch_id = ? AND status = 'active'", [$bid]);
        $n = NotificationService::toStudents($c->academy(), 'class_cancelled', $ids, ['message' => $msg, 'title' => 'Class cancelled'], ['dedupe' => "cc$bid-$date", 'created_by' => $c->userId]);
        Audit::log($c, 'batch.cancel_class', 'batches', $bid, null, ['date' => $date]);
        return Http::ok($n, 'Students have been informed.');
    }, ['roles' => ['owner', 'teacher'], 'perm' => 'attendance.mark']);
};
