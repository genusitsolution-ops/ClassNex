<?php
declare(strict_types=1);

/**
 * Branch-wise view of one academy. Branches are unlimited and share the academy's single
 * subscription and student quota; every figure here is a slice of the same academy data.
 */
final class BranchService
{
    /**
     * Per-branch figures for a period, plus an "Unassigned / main" row for records without a branch,
     * and combined academy totals. Students are counted once (unique profiles).
     */
    public static function summary(int $academyId, string $from, string $to): array
    {
        $branches = DB::all('SELECT id, name, status FROM academy_branches WHERE academy_id = ? ORDER BY status, name', [$academyId]);
        $rows = [];
        foreach (array_merge($branches, [['id' => null, 'name' => 'Main / unassigned', 'status' => 'active']]) as $b) {
            $row = self::figures($academyId, $b['id'] === null ? null : (int) $b['id'], $from, $to) + ['branch_id' => $b['id'], 'name' => $b['name'], 'status' => $b['status']];
            if ($b['id'] === null && !$row['students'] && !$row['collected_paise'] && !$row['pending_paise'] && !$row['teachers'] && !$row['batches']) {
                continue; // nothing outside branches: hide the row
            }
            $rows[] = $row;
        }
        $total = self::figures($academyId, false, $from, $to);
        return ['branches' => $rows, 'total' => $total + ['branch_count' => count($branches), 'name' => 'All branches'], 'from' => $from, 'to' => $to];
    }

    /** $branchId: int = one branch, null = records without a branch, false = whole academy. */
    public static function figures(int $academyId, int|null|false $branchId, string $from, string $to): array
    {
        $w = static function (string $col) use ($branchId): string {
            return $branchId === false ? '' : ($branchId === null ? " AND $col IS NULL" : " AND $col = " . (int) $branchId);
        };
        $att = DB::one("SELECT SUM(a.status IN ('present','late')) AS present, SUM(a.status IN ('present','late','absent')) AS counted
                        FROM attendance a JOIN attendance_sessions se ON se.id = a.session_id JOIN batches b ON b.id = se.batch_id
                        WHERE se.academy_id = ? AND se.session_date BETWEEN ? AND ?" . $w('b.branch_id'), [$academyId, $from, $to]);
        return [
            'students'        => (int) DB::val("SELECT COUNT(*) FROM students s WHERE s.academy_id = ? AND s.status = 'active'" . $w('s.branch_id'), [$academyId]),
            'new_admissions'  => (int) DB::val('SELECT COUNT(*) FROM students s WHERE s.academy_id = ? AND s.admission_date BETWEEN ? AND ?' . $w('s.branch_id'), [$academyId, $from, $to]),
            'teachers'        => (int) DB::val("SELECT COUNT(*) FROM teachers t WHERE t.academy_id = ? AND t.status = 'active'" . $w('t.branch_id'), [$academyId]),
            'batches'         => (int) DB::val("SELECT COUNT(*) FROM batches b WHERE b.academy_id = ? AND b.status = 'active'" . $w('b.branch_id'), [$academyId]),
            'collected_paise' => (int) DB::val("SELECT COALESCE(SUM(p.amount_paise - p.refunded_paise),0) FROM payments p WHERE p.academy_id = ? AND p.status = 'success' AND p.paid_on BETWEEN ? AND ?" . $w('p.branch_id'), [$academyId, $from, $to]),
            'payments'        => (int) DB::val("SELECT COUNT(*) FROM payments p WHERE p.academy_id = ? AND p.status = 'success' AND p.paid_on BETWEEN ? AND ?" . $w('p.branch_id'), [$academyId, $from, $to]),
            'pending_paise'   => (int) DB::val("SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active'" . $w('i.branch_id'), [$academyId]),
            'overdue_paise'   => (int) DB::val("SELECT COALESCE(SUM(i.total_paise - i.paid_paise),0) FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE() AND s.status = 'active'" . $w('i.branch_id'), [$academyId]),
            'attendance_pct'  => (int) ($att['counted'] ?? 0) ? round((int) $att['present'] * 100 / (int) $att['counted'], 1) : null,
        ];
    }

    /** One branch with its people and batches (Branch details screen). */
    public static function detail(int $academyId, int $branchId, string $from, string $to): array
    {
        $b = DB::one('SELECT br.*, t.name AS manager_teacher_name FROM academy_branches br LEFT JOIN teachers t ON t.id = br.manager_teacher_id WHERE br.id = ? AND br.academy_id = ?', [$branchId, $academyId]);
        if (!$b) {
            throw ApiException::notFound('Branch');
        }
        $b['figures'] = self::figures($academyId, $branchId, $from, $to);
        $b['batches'] = DB::all("SELECT b.id, b.name, a.name AS activity_name, b.start_time, b.end_time, t.name AS teacher_name,
                                  (SELECT COUNT(*) FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = b.id AND m.status = 'active' AND s.status = 'active') AS students,
                                  (SELECT ROUND(100 * SUM(x.status IN ('present','late')) / NULLIF(SUM(x.status IN ('present','late','absent')),0), 1) FROM attendance x JOIN attendance_sessions se ON se.id = x.session_id WHERE se.batch_id = b.id AND se.session_date BETWEEN ? AND ?) AS attendance_pct
                                 FROM batches b JOIN activities a ON a.id = b.activity_id LEFT JOIN teachers t ON t.id = b.teacher_id
                                 WHERE b.academy_id = ? AND b.branch_id = ? AND b.status = 'active' ORDER BY a.name, b.start_time", [$from, $to, $academyId, $branchId]);
        $b['teachers'] = DB::all("SELECT id, name, mobile, status FROM teachers WHERE academy_id = ? AND branch_id = ? ORDER BY status, name", [$academyId, $branchId]);
        return $b;
    }
}
