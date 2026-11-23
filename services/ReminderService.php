<?php
declare(strict_types=1);

final class ReminderService
{
    /** Offsets (days relative to due date) that apply to an academy. */
    public static function rules(int $academyId): array
    {
        $own = DB::all('SELECT offset_days, is_enabled FROM reminder_rules WHERE academy_id = ?', [$academyId]);
        $rows = $own ?: DB::all('SELECT offset_days, is_enabled FROM reminder_rules WHERE academy_id IS NULL');
        return array_map('intval', array_column(array_filter($rows, static fn($r) => (int) $r['is_enabled'] === 1), 'offset_days'));
    }

    /** 'default' (ClassNex timings), 'custom' (own days) or 'off'. */
    public static function mode(int $academyId): string
    {
        $own = DB::all('SELECT is_enabled FROM reminder_rules WHERE academy_id = ?', [$academyId]);
        if (!$own) {
            return 'default';
        }
        return array_filter($own, static fn($r) => (int) $r['is_enabled'] === 1) ? 'custom' : 'off';
    }

    public static function scheduleForInvoice(int $invoiceId): void
    {
        $inv = DB::one('SELECT id, academy_id, due_date, issue_date, status FROM fee_invoices WHERE id = ?', [$invoiceId]);
        if (!$inv || !in_array($inv['status'], ['unpaid', 'partial'], true)) {
            return;
        }
        $today = date('Y-m-d');
        foreach (self::rules((int) $inv['academy_id']) as $off) {
            $date = date('Y-m-d', strtotime($inv['due_date'] . " $off days"));
            // only future reminders: a fee created today is not chased on the same day
            if ($date <= $today) {
                continue;
            }
            DB::exec('INSERT IGNORE INTO scheduled_reminders (academy_id, invoice_id, offset_days, scheduled_for) VALUES (?,?,?,?)', [$inv['academy_id'], $invoiceId, $off, $date]);
        }
    }

    /** Re-applies an academy's rule set to all of its open invoices (after the owner edits rules). */
    public static function reschedule(int $academyId): int
    {
        DB::exec("DELETE FROM scheduled_reminders WHERE academy_id = ? AND status = 'pending' AND scheduled_for >= CURDATE()", [$academyId]);
        $ids = DB::col("SELECT id FROM fee_invoices WHERE academy_id = ? AND status IN ('unpaid','partial') AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", [$academyId]);
        foreach ($ids as $id) {
            self::scheduleForInvoice((int) $id);
        }
        return count($ids);
    }

    /** Cron: sends reminders that are due today. Safe to run repeatedly. */
    public static function processDue(string $today): array
    {
        $sent = $skipped = 0;
        // reminders older than 2 days are stale (e.g. cron was down) — skip rather than spam
        $skipped += DB::exec("UPDATE scheduled_reminders SET status = 'skipped', processed_at = NOW() WHERE status = 'pending' AND scheduled_for < DATE_SUB(?, INTERVAL 2 DAY)", [$today]);
        $due = DB::all("SELECT id, offset_days, invoice_id, academy_id FROM scheduled_reminders
                        WHERE status = 'pending' AND scheduled_for <= ? ORDER BY scheduled_for, id LIMIT 2000", [$today]);
        foreach ($due as $row) {
            // claim atomically so overlapping cron runs never double-send
            $claimed = DB::exec("UPDATE scheduled_reminders SET status = 'sent', processed_at = NOW() WHERE id = ? AND status = 'pending'", [$row['id']]);
            if (!$claimed) {
                continue;
            }
            $academyId = (int) $row['academy_id'];
            $inv = DB::one('SELECT * FROM fee_invoices WHERE id = ?', [$row['invoice_id']]);
            $ok = $inv && in_array($inv['status'], ['unpaid', 'partial'], true)
                && DB::val("SELECT 1 FROM students WHERE id = ? AND status = 'active'", [$inv['student_id']])
                && Subscription::state($academyId)['writable']
                && Feature::has($academyId, 'auto_reminders');
            if (!$ok) {
                DB::exec("UPDATE scheduled_reminders SET status = 'skipped' WHERE id = ?", [$row['id']]);
                $skipped++;
                continue;
            }
            NotificationService::feeReminder((int) $row['id'], $inv, (int) $row['offset_days']);
            $sent++;
        }
        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
