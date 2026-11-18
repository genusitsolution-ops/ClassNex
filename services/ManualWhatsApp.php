<?php
declare(strict_types=1);

/**
 * WhatsApp "click to send" (package WhatsApp mode = manual, also available in api mode).
 *
 * ClassNex prepares the text and a wa.me link for each recipient; the user taps Send and the message
 * opens in WhatsApp / WhatsApp Business on their phone or computer, where they press send themselves.
 * Nothing is sent automatically and no unofficial automation is used. Every prepared message is kept in
 * manual_messages (history), and marked "opened" when the user taps Send.
 */
final class ManualWhatsApp
{
    public const PURPOSES = ['fee_reminder', 'receipt', 'attendance', 'admission', 'announcement', 'custom'];

    public static function assertEnabled(int $academyId): void
    {
        if (!Feature::has($academyId, 'whatsapp_manual')) {
            throw new ApiException('WhatsApp is not included in your package.', 402, [], 'feature_locked');
        }
    }

    public static function link(string $phone, string $text): string
    {
        return 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . rawurlencode($text);
    }

    /** Text from the academy's (or default) WhatsApp template for an event. */
    private static function text(int $academyId, string $event, array $vars, string $fallback): string
    {
        $tpl = NotificationService::template($academyId, $event, 'whatsapp') ?? NotificationService::template($academyId, $event, 'inapp');
        return trim(NotificationService::render($tpl['body'] ?? $fallback, $vars));
    }

    private static function academyVars(int $academyId): array
    {
        $a = DB::one('SELECT name, mobile FROM academies WHERE id = ?', [$academyId]);
        return ['academy_name' => $a['name'] ?? 'Academy', 'academy_phone' => $a['mobile'] ?? '', 'date' => date('d M Y')];
    }

    /**
     * Prepares messages. $in: purpose, and depending on it
     *   fee_reminder : student_ids[] | target=dues (+overdue_only) | batch_id | activity_id | all
     *   receipt      : payment_id
     *   attendance   : batch_id + date (absent / late students; statuses[] optional)
     *   admission    : student_id
     *   announcement : announcement_id (its audience) or student targets + message
     *   custom       : message + student targets
     * Returns ['batch_ref', 'items' => [id, student_id, name, phone, text, link]].
     */
    public static function prepare(Ctx $c, array $in): array
    {
        $aid = $c->academy();
        self::assertEnabled($aid);
        $purpose = in_array($in['purpose'] ?? '', self::PURPOSES, true) ? $in['purpose'] : 'custom';
        $base = self::academyVars($aid);
        $items = []; // [student_id, text, ref_type, ref_id]
        switch ($purpose) {
            case 'receipt':
                $pay = PaymentService::get($aid, (int) ($in['payment_id'] ?? 0));
                $items[] = [(int) $pay['student_id'], self::text($aid, 'payment_success', $base + [
                    'student_name' => $pay['student_name'], 'amount' => Money::format((int) $pay['amount_paise']), 'receipt_no' => $pay['receipt_no'],
                    'balance' => Money::format((int) $pay['balance_after_paise']), 'receipt_link' => $pay['receipt_url'],
                ], 'Payment of {amount} received for {student_name}. Receipt {receipt_no}: {receipt_link}'), 'payment', (int) $pay['id']];
                break;
            case 'admission':
                $s = $c->assertStudent((int) ($in['student_id'] ?? 0));
                $act = DB::val("SELECT a.name FROM student_activity_mapping m JOIN activities a ON a.id = m.activity_id WHERE m.student_id = ? AND m.status = 'active' ORDER BY m.id DESC LIMIT 1", [$s['id']]);
                $items[] = [(int) $s['id'], self::text($aid, 'admission_confirmation', $base + ['student_name' => $s['name'], 'student_code' => $s['student_code'], 'activity_name' => $act ?: ''], 'Welcome {student_name}! Admission confirmed. Student ID: {student_code}.'), 'student', (int) $s['id']];
                break;
            case 'attendance':
                $batchId = (int) ($in['batch_id'] ?? 0);
                $c->assertBatch($batchId);
                $date = $in['date'] ?? date('Y-m-d');
                $statuses = array_values(array_intersect((array) ($in['statuses'] ?? ['absent', 'late']), ['present', 'absent', 'late', 'leave']));
                $rows = DB::all('SELECT a.student_id, a.status, s.name FROM attendance a JOIN attendance_sessions se ON se.id = a.session_id JOIN students s ON s.id = a.student_id
                                 WHERE se.batch_id = ? AND se.session_date = ? AND a.status IN (' . DB::inList($statuses ?: ['absent']) . ')', array_merge([$batchId, $date], $statuses ?: ['absent']));
                foreach ($rows as $r) {
                    $items[] = [(int) $r['student_id'], self::text($aid, 'attendance_' . ($r['status'] === 'leave' ? 'absent' : $r['status']), $base + ['student_name' => $r['name'], 'status' => ucfirst($r['status']), 'date' => date('d M Y', strtotime($date))], '{student_name} was marked {status} on {date}.'), 'attendance', $batchId];
                }
                break;
            case 'fee_reminder':
                foreach (self::targetStudents($c, $in + ['target' => $in['target'] ?? 'dues']) as $sid) {
                    $inv = DB::one("SELECT * FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY due_date LIMIT 1", [$sid]);
                    if (!$inv) {
                        continue;
                    }
                    $pending = (int) DB::val("SELECT SUM(total_paise - paid_paise) FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial')", [$sid]);
                    $name = DB::val('SELECT name FROM students WHERE id = ?', [$sid]);
                    $items[] = [$sid, self::text($aid, $inv['due_date'] < date('Y-m-d') ? 'fee_overdue' : 'fee_due', $base + [
                        'student_name' => $name, 'fee_title' => $inv['title'], 'amount' => Money::format($pending), 'due_date' => date('j M Y', strtotime($inv['due_date'])),
                    ], 'Fee of {amount} for {student_name} is due on {due_date}.'), 'invoice', (int) $inv['id']];
                }
                break;
            case 'announcement':
            case 'custom':
                $msg = trim((string) ($in['message'] ?? ''));
                $refId = null;
                if ($purpose === 'announcement' && !empty($in['announcement_id'])) {
                    $an = DB::one('SELECT * FROM announcements WHERE id = ? AND academy_id = ?', [(int) $in['announcement_id'], $aid]);
                    if (!$an) {
                        throw ApiException::notFound('Announcement');
                    }
                    $msg = $msg ?: trim($an['title'] . "\n" . $an['body']);
                    $refId = (int) $an['id'];
                    $in += ['target' => $an['batch_id'] ? 'batch' : 'all', 'batch_id' => $an['batch_id']];
                }
                if (mb_strlen($msg) < 2) {
                    throw ApiException::validation(['message' => 'Type a message.']);
                }
                foreach (self::targetStudents($c, $in) as $sid) {
                    $name = DB::val('SELECT name FROM students WHERE id = ?', [$sid]);
                    $items[] = [$sid, NotificationService::render($msg, $base + ['student_name' => $name]), $purpose === 'announcement' ? 'announcement' : null, $refId];
                }
                break;
        }
        if (!$items) {
            throw ApiException::validation(['target' => 'Nobody to message for this selection.']);
        }
        $ref = date('ymdHis') . '-' . bin2hex(random_bytes(3));
        $out = [];
        DB::tx(static function () use ($c, $aid, $purpose, $items, $ref, &$out) {
            foreach ($items as [$sid, $text, $refType, $refId]) {
                $s = DB::one('SELECT id, name, branch_id FROM students WHERE id = ? AND academy_id = ?', [$sid, $aid]);
                if (!$s) {
                    continue;
                }
                foreach (NotificationService::studentRecipients($sid)['phones'] ?: self::fallbackPhone($sid) as $phone => $who) {
                    $id = DB::insert('manual_messages', [
                        'academy_id' => $aid, 'branch_id' => $s['branch_id'], 'student_id' => $sid, 'phone' => (string) $phone, 'recipient_name' => mb_substr((string) $who, 0, 120),
                        'purpose' => $purpose, 'ref_type' => $refType, 'ref_id' => $refId, 'message' => $text, 'batch_ref' => $ref, 'created_by' => $c->userId,
                    ]);
                    $out[] = ['id' => $id, 'student_id' => $sid, 'student_name' => $s['name'], 'name' => $who, 'phone' => '+' . $phone, 'text' => $text, 'link' => self::link((string) $phone, $text)];
                }
            }
            Audit::log($c, 'whatsapp.manual_prepare', null, null, null, ['purpose' => $purpose, 'messages' => count($out), 'batch_ref' => $ref]);
        });
        return ['batch_ref' => $ref, 'purpose' => $purpose, 'items' => $out];
    }

    /** Opt-out does not apply to a number the user chooses to message by hand, but we still need one. */
    private static function fallbackPhone(int $sid): array
    {
        $s = DB::one('SELECT country_code, whatsapp, name, guardian_name, is_minor FROM students WHERE id = ?', [$sid]);
        return $s && $s['whatsapp'] ? [Phone::wa($s['country_code'], $s['whatsapp']) => $s['is_minor'] ? ($s['guardian_name'] ?: $s['name']) : $s['name']] : [];
    }

    /** Students for target = students | batch | activity | all | dues (same rules as the message screen). */
    public static function targetStudents(Ctx $c, array $in): array
    {
        $aid = $c->academy();
        switch ($in['target'] ?? 'students') {
            case 'batch':
                $c->assertBatch((int) ($in['batch_id'] ?? 0));
                return array_map('intval', DB::col("SELECT m.student_id FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.batch_id = ? AND m.status = 'active' AND s.status = 'active'", [(int) $in['batch_id']]));
            case 'activity':
                return array_map('intval', DB::col("SELECT DISTINCT m.student_id FROM student_activity_mapping m JOIN students s ON s.id = m.student_id WHERE m.activity_id = ? AND m.academy_id = ? AND m.status = 'active' AND s.status = 'active'", [(int) ($in['activity_id'] ?? 0), $aid]));
            case 'all':
                if ($c->role !== 'owner') {
                    throw ApiException::forbidden();
                }
                $br = !empty($in['branch_id']) ? ' AND branch_id = ' . (int) $in['branch_id'] : '';
                return array_map('intval', DB::col("SELECT id FROM students WHERE academy_id = ? AND status = 'active'$br", [$aid]));
            case 'dues':
                $br = !empty($in['branch_id']) ? ' AND s.branch_id = ' . (int) $in['branch_id'] : '';
                $ids = array_map('intval', DB::col("SELECT DISTINCT i.student_id FROM fee_invoices i JOIN students s ON s.id = i.student_id WHERE i.academy_id = ? AND i.status IN ('unpaid','partial') AND s.status = 'active'$br" . (!empty($in['overdue_only']) ? ' AND i.due_date < CURDATE()' : ''), [$aid]));
                $scope = $c->batchScope();
                return $scope === null ? $ids : array_values(array_intersect($ids, array_map('intval', $scope ? DB::col("SELECT student_id FROM student_activity_mapping WHERE status = 'active' AND batch_id IN (" . DB::inList($scope) . ')', $scope) : [])));
            default:
                $ids = array_map('intval', (array) ($in['student_ids'] ?? []));
                $ok = [];
                foreach ($ids as $id) {
                    try {
                        $c->assertStudent($id);
                        $ok[] = $id;
                    } catch (ApiException) {
                    }
                }
                return $ok;
        }
    }

    public static function markOpened(int $academyId, int $id): void
    {
        DB::exec("UPDATE manual_messages SET status = 'opened', opened_at = COALESCE(opened_at, NOW()) WHERE id = ? AND academy_id = ?", [$id, $academyId]);
    }
}
