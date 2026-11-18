<?php
declare(strict_types=1);

/**
 * Central notification dispatcher.
 * Resolves recipients → checks package features, academy preferences, opt-outs and limits →
 * renders templates → writes in-app notifications immediately and enqueues WhatsApp / push /
 * email / SMS jobs with idempotency keys. Delivery happens in cron (QueueProcessor).
 */
final class NotificationService
{
    public const CHANNELS = ['whatsapp', 'push', 'inapp', 'email', 'sms'];
    public const EVENTS = [
        'admission_confirmation' => 'Admission Confirmation',
        'attendance_present'     => 'Attendance – Present',
        'attendance_absent'      => 'Attendance – Absent',
        'attendance_late'        => 'Attendance – Late',
        'attendance_corrected'   => 'Attendance Correction',
        'fee_due'                => 'Fee Due Reminder',
        'fee_overdue'            => 'Fee Overdue Reminder',
        'payment_success'        => 'Payment Successful / Receipt',
        'batch_change'           => 'Batch Change',
        'class_cancelled'        => 'Class Cancellation',
        'holiday'                => 'Holiday',
        'announcement'           => 'General Announcement',
        'manual_message'         => 'Manual Message',
    ];
    /** Channels on by default when an academy has not saved preferences. */
    private const DEFAULT_ON = ['whatsapp' => true, 'push' => true, 'inapp' => true, 'email' => true, 'sms' => false];

    public static function channelEnabled(int $academyId, string $event, string $channel): bool
    {
        if (!Feature::has($academyId, $channel)) {
            return false;
        }
        $pref = DB::val('SELECT enabled FROM notification_preferences WHERE academy_id = ? AND event_key = ? AND channel = ?', [$academyId, $event, $channel]);
        if ($pref === null) {
            if ($event === 'attendance_present' && $channel === 'whatsapp') {
                return false; // avoid unnecessary daily WhatsApp messages unless the owner turns it on
            }
            return self::DEFAULT_ON[$channel] ?? false;
        }
        return (bool) $pref;
    }

    public static function template(?int $academyId, string $event, string $channel): ?array
    {
        $t = null;
        if ($academyId && Feature::has($academyId, 'custom_templates')) {
            $t = DB::one('SELECT * FROM notification_templates WHERE academy_id = ? AND event_key = ? AND channel = ? AND is_active = 1', [$academyId, $event, $channel]);
        }
        return $t ?: DB::one('SELECT * FROM notification_templates WHERE academy_id IS NULL AND event_key = ? AND channel = ? AND is_active = 1', [$event, $channel]);
    }

    public static function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $tpl) ?? $tpl;
    }

    /** Parents (and adult students) who should hear about a student. */
    public static function studentRecipients(int $studentId): array
    {
        $s = DB::one('SELECT * FROM students WHERE id = ?', [$studentId]);
        if (!$s) {
            return ['phones' => [], 'emails' => [], 'users' => [], 'student' => null];
        }
        $parents = DB::all('SELECT p.* FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id WHERE m.student_id = ? AND p.status = \'active\'', [$studentId]);
        $phones = $emails = $users = [];
        foreach ($parents as $p) {
            if ($p['whatsapp_opt_in']) {
                $phones[Phone::wa($p['country_code'], $p['whatsapp'])] = $p['name'];
            }
            if ($p['email']) {
                $emails[$p['email']] = $p['name'];
            }
            if ($p['user_id']) {
                $users[(int) $p['user_id']] = $p['name'];
            }
        }
        if ((!$s['is_minor'] || !$phones) && $s['whatsapp_opt_in']) {
            $phones[Phone::wa($s['country_code'], $s['whatsapp'])] = $s['is_minor'] ? ($s['guardian_name'] ?: $s['name']) : $s['name'];
        }
        if ($s['email'] && (!$s['is_minor'] || !$emails)) {
            $emails[$s['email']] = $s['name'];
        }
        if ($s['user_id']) {
            $users[(int) $s['user_id']] = $s['name'];
        }
        return ['phones' => $phones, 'emails' => $emails, 'users' => $users, 'student' => $s];
    }

    private static function academyVars(int $academyId): array
    {
        $a = DB::one('SELECT name, mobile, whatsapp FROM academies WHERE id = ?', [$academyId]);
        return ['academy_name' => $a['name'] ?? 'Academy', 'academy_phone' => $a['mobile'] ?? ''];
    }

    /**
     * Notifies the family of each student.
     * $opts: channels (restrict), dedupe (string unique to this business event), created_by, priority
     * Returns counts per channel.
     */
    public static function toStudents(int $academyId, string $event, array $studentIds, array $vars = [], array $opts = []): array
    {
        $counts = array_fill_keys(self::CHANNELS, 0);
        $base = self::academyVars($academyId) + ['date' => date('d M Y')];
        foreach (array_unique(array_map('intval', $studentIds)) as $sid) {
            $r = self::studentRecipients($sid);
            if (!$r['student']) {
                continue;
            }
            $v = array_merge($base, ['student_name' => $r['student']['name'], 'student_code' => $r['student']['student_code']], $vars[$sid] ?? $vars['*'] ?? $vars);
            $res = self::send($academyId, $event, $v, $r, $opts + ['student_id' => $sid]);
            foreach ($res as $ch => $n) {
                $counts[$ch] += $n;
            }
        }
        return $counts;
    }

    /** Notifies specific users (staff / owner). */
    public static function toUsers(?int $academyId, string $event, array $userIds, array $vars = [], array $opts = []): array
    {
        $counts = array_fill_keys(self::CHANNELS, 0);
        $base = $academyId ? self::academyVars($academyId) : ['academy_name' => 'ClassNex'];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            $u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
            if (!$u) {
                continue;
            }
            $r = ['phones' => $u['mobile'] ? [Phone::wa($u['country_code'], $u['mobile']) => $u['name']] : [], 'emails' => $u['email'] ? [$u['email'] => $u['name']] : [], 'users' => [$uid => $u['name']], 'student' => null];
            $res = self::send($academyId, $event, array_merge($base, ['recipient_name' => $u['name']], $vars), $r, $opts);
            foreach ($res as $ch => $n) {
                $counts[$ch] += $n;
            }
        }
        return $counts;
    }

    /** Sends to a raw phone / email (activation & password-reset links, before a user can log in). */
    public static function direct(?int $academyId, string $event, array $vars, ?string $waPhone, ?string $email, ?int $userId = null): array
    {
        $r = ['phones' => $waPhone ? [$waPhone => $vars['recipient_name'] ?? ''] : [], 'emails' => $email ? [$email => $vars['recipient_name'] ?? ''] : [], 'users' => [], 'student' => null];
        $base = $academyId ? self::academyVars($academyId) : ['academy_name' => 'ClassNex'];
        return self::send($academyId, $event, $base + $vars, $r, ['platform' => true, 'dedupe' => $vars['dedupe'] ?? bin2hex(random_bytes(6)), 'user_id' => $userId, 'priority' => 1]);
    }

    private static function send(?int $academyId, string $event, array $vars, array $r, array $opts): array
    {
        $counts = array_fill_keys(self::CHANNELS, 0);
        $only = $opts['channels'] ?? null;
        $dedupe = $opts['dedupe'] ?? bin2hex(random_bytes(8));
        $studentId = $opts['student_id'] ?? null;
        foreach (self::CHANNELS as $ch) {
            if ($only !== null && !in_array($ch, $only, true)) {
                continue;
            }
            // platform messages (invites, password reset) are not limited by the academy package
            $platform = !empty($opts['platform']);
            if (!$platform && $academyId && !self::channelEnabled($academyId, $event, $ch)) {
                continue;
            }
            if ($platform && in_array($ch, ['whatsapp', 'email', 'sms'], true) && self::globalMode($ch) === 'off') {
                continue;
            }
            $tpl = self::template($academyId, $event, $ch);
            if (!$tpl) {
                continue;
            }
            $title = self::render((string) ($vars['title'] ?? $tpl['title'] ?? ''), $vars);
            if ($tpl['title'] && !isset($vars['title'])) {
                $title = self::render($tpl['title'], $vars);
            }
            switch ($ch) {
                case 'inapp':
                    foreach ($r['users'] as $uid => $name) {
                        $body = self::render($tpl['body'], $vars + ['recipient_name' => $name]);
                        $n = DB::exec('INSERT IGNORE INTO notifications (academy_id, user_id, student_id, event_key, title, body, data, dedupe_key) VALUES (?,?,?,?,?,?,?,?)', [
                            $academyId, $uid, $studentId, $event, mb_substr($title, 0, 160), $body, json_encode($opts['data'] ?? null), mb_substr("$event:$dedupe", 0, 120),
                        ]);
                        $counts['inapp'] += $n;
                    }
                    break;
                case 'push':
                    foreach ($r['users'] as $uid => $name) {
                        if (!DB::val('SELECT 1 FROM device_tokens WHERE user_id = ? LIMIT 1', [$uid])) {
                            continue; // app not installed / logged in: the in-app inbox still has it
                        }
                        $counts['push'] += self::enqueue($academyId, $event, 'push', (string) $uid, $name, $title, self::render($tpl['body'], $vars + ['recipient_name' => $name]), ['data' => ($opts['data'] ?? []) + ['event' => $event, 'student_id' => (string) $studentId]], $dedupe, $opts + ['user_id' => $uid]);
                    }
                    break;
                case 'whatsapp':
                    foreach ($r['phones'] as $phone => $name) {
                        if (DB::val("SELECT 1 FROM notification_opt_outs WHERE phone = ? AND channel = 'whatsapp'", [$phone])) {
                            continue;
                        }
                        if (!$platform && $academyId) {
                            $lim = Feature::forAcademy($academyId)['limits']['whatsapp_month'] ?? null;
                            if ($lim !== null && Feature::usage($academyId, 'whatsapp_month') >= $lim) {
                                continue;
                            }
                        }
                        $vv = $vars + ['recipient_name' => $name];
                        $body = self::render($tpl['body'], $vv);
                        $params = [];
                        foreach (json_decode((string) $tpl['wa_params'], true) ?: [] as $p) {
                            $params[] = (string) ($p === 'message' && !isset($vv['message']) ? $body : ($vv[$p] ?? ''));
                        }
                        $counts['whatsapp'] += self::enqueue($academyId, $event, 'whatsapp', (string) $phone, $name, $title, $body, [
                            'template' => $tpl['wa_template_name'], 'language' => $tpl['wa_language'] ?: 'en', 'params' => $params,
                        ], $dedupe, $opts);
                    }
                    break;
                case 'email':
                    foreach ($r['emails'] as $email => $name) {
                        $vv = $vars + ['recipient_name' => $name ?: 'Parent'];
                        $counts['email'] += self::enqueue($academyId, $event, 'email', (string) $email, $name, self::render($tpl['title'] ?? $title, $vv), self::render($tpl['body'], $vv), [], $dedupe, $opts);
                    }
                    break;
                case 'sms':
                    foreach ($r['phones'] as $phone => $name) {
                        $counts['sms'] += self::enqueue($academyId, $event, 'sms', '+' . $phone, $name, $title, self::render($tpl['body'], $vars + ['recipient_name' => $name]), [], $dedupe, $opts);
                    }
                    break;
            }
        }
        return $counts;
    }

    public static function globalMode(string $channel): string
    {
        $table = ['whatsapp' => 'whatsapp_configurations', 'push' => 'firebase_configurations', 'email' => 'email_configurations', 'sms' => 'sms_configurations'][$channel] ?? null;
        return $table ? (string) (DB::val("SELECT mode FROM $table WHERE id = 1") ?? 'off') : 'live';
    }

    private static function enqueue(?int $academyId, string $event, string $channel, string $recipient, ?string $name, ?string $subject, string $body, array $payload, string $dedupe, array $opts): int
    {
        $key = "$event:$dedupe:$channel:$recipient";
        if (strlen($key) > 120) {
            $key = substr($event, 0, 40) . ':' . hash('sha256', $key);
        }
        // scheduled messages: the queue only picks a row once next_attempt_at has passed
        $at = !empty($opts['send_at']) && strtotime((string) $opts['send_at']) > time() ? date('Y-m-d H:i:s', strtotime((string) $opts['send_at'])) : date('Y-m-d H:i:s');
        return DB::exec('INSERT IGNORE INTO notification_queue (academy_id, event_key, channel, recipient, recipient_name, user_id, student_id, subject, body, payload, priority, idempotency_key, created_by, next_attempt_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $academyId, $event, $channel, $recipient, $name ? mb_substr($name, 0, 120) : null, $opts['user_id'] ?? null, $opts['student_id'] ?? null,
            $subject ? mb_substr($subject, 0, 200) : null, $body, json_encode($payload, JSON_UNESCAPED_UNICODE), (int) ($opts['priority'] ?? 5), $key, $opts['created_by'] ?? null, $at,
        ]);
    }

    // ------------------------------------------------------------- business events

    public static function admissionConfirmed(int $academyId, int $studentId): void
    {
        $act = DB::one("SELECT a.name AS activity_name, b.name AS batch_name FROM student_activity_mapping m JOIN activities a ON a.id = m.activity_id
                        LEFT JOIN batches b ON b.id = m.batch_id WHERE m.student_id = ? AND m.status = 'active' ORDER BY m.id DESC LIMIT 1", [$studentId]);
        self::toStudents($academyId, 'admission_confirmation', [$studentId], ['activity_name' => $act['activity_name'] ?? 'classes', 'batch_name' => $act['batch_name'] ?? ''], ['dedupe' => "s$studentId", 'priority' => 3]);
    }

    public static function paymentReceived(int $academyId, int $paymentId): void
    {
        $p = DB::one('SELECT p.student_id, p.amount_paise, r.receipt_no, r.public_token, r.balance_after_paise FROM payments p JOIN receipts r ON r.payment_id = p.id WHERE p.id = ?', [$paymentId]);
        if (!$p) {
            return;
        }
        self::toStudents($academyId, 'payment_success', [(int) $p['student_id']], [
            'amount'       => Money::format((int) $p['amount_paise']),
            'receipt_no'   => $p['receipt_no'],
            'balance'      => Money::format((int) $p['balance_after_paise']),
            'receipt_link' => cnx_url('receipt.php?t=' . $p['public_token']),
        ], ['dedupe' => "p$paymentId", 'priority' => 2, 'data' => ['type' => 'receipt', 'payment_id' => (string) $paymentId]]);
    }

    public static function feeReminder(int $reminderId, array $inv, int $offset): void
    {
        $event = $offset <= 0 ? 'fee_due' : 'fee_overdue';
        self::toStudents((int) $inv['academy_id'], $event, [(int) $inv['student_id']], [
            'fee_title' => $inv['title'],
            'amount'    => Money::format((int) $inv['total_paise'] - (int) $inv['paid_paise']),
            'due_date'  => date('j M Y', strtotime($inv['due_date'])),
        ], ['dedupe' => "r$reminderId", 'priority' => 6, 'data' => ['type' => 'invoice', 'invoice_id' => (string) $inv['id']]]);
    }
}
