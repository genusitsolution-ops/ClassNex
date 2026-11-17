<?php
declare(strict_types=1);

/**
 * Processes notification_queue. Rows are claimed atomically (UPDATE ... locked_by) so two cron
 * processes never send the same message. Failures retry with exponential backoff.
 */
final class QueueProcessor
{
    public static function run(int $limit = 50): array
    {
        $worker = substr(gethostname() ?: 'w', 0, 12) . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
        // release rows stuck in "processing" (crashed worker)
        DB::exec("UPDATE notification_queue SET status = 'pending', locked_by = NULL WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

        $paused = [];
        foreach (['whatsapp', 'push', 'email', 'sms'] as $ch) {
            if (Settings::get("queue_paused_$ch") === '1') {
                $paused[] = $ch;
            }
        }
        $wa = WhatsAppChannel::config();
        $waBudget = max(1, (int) ($wa['rate_per_minute'] ?? 60));
        $where = "status = 'pending' AND next_attempt_at <= NOW()" . ($paused ? ' AND channel NOT IN (' . DB::inList($paused) . ')' : '');
        DB::exec("UPDATE notification_queue SET status = 'processing', locked_by = ?, locked_at = NOW() WHERE $where ORDER BY priority, id LIMIT $limit", array_merge([$worker], $paused));
        $jobs = DB::all("SELECT * FROM notification_queue WHERE locked_by = ? AND status = 'processing' ORDER BY priority, id", [$worker]);

        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'deferred' => 0];
        $waSent = 0;
        foreach ($jobs as $job) {
            if ($job['channel'] === 'whatsapp' && $waSent >= $waBudget) {
                DB::exec("UPDATE notification_queue SET status = 'pending', locked_by = NULL WHERE id = ?", [$job['id']]);
                $stats['deferred']++;
                continue;
            }
            $res = self::deliver($job);
            if ($job['channel'] === 'whatsapp') {
                $waSent++;
            }
            self::record($job, $res, $stats);
        }
        return $stats;
    }

    public static function deliver(array $job): array
    {
        try {
            return match ($job['channel']) {
                'whatsapp' => WhatsAppChannel::send($job),
                'push'     => FcmChannel::send($job),
                'email'    => EmailChannel::send($job),
                'sms'      => SmsChannel::send($job),
                default    => ['ok' => false, 'error' => 'Unknown channel', 'retry' => false],
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'retry' => true];
        }
    }

    private static function record(array $job, array $res, array &$stats): void
    {
        $attempts = (int) $job['attempts'] + 1;
        DB::insert('notification_logs', [
            'queue_id'        => $job['id'],
            'academy_id'      => $job['academy_id'],
            'channel'         => $job['channel'],
            'action'          => $res['ok'] ? (!empty($res['simulated']) ? 'simulated' : 'success') : 'error',
            'http_status'     => $res['http'] ?? null,
            'request_summary' => mb_substr($job['event_key'] . ' → ' . $job['recipient'], 0, 500),
            'response_body'   => isset($res['response']) ? mb_substr((string) $res['response'], 0, 4000) : ($res['error'] ?? null),
        ]);
        if ($res['ok']) {
            DB::update('notification_queue', [
                'status'              => 'sent',
                'attempts'            => $attempts,
                'provider_message_id' => $res['id'] ?? null,
                'sent_at'             => date('Y-m-d H:i:s'),
                'last_error'          => !empty($res['simulated']) ? 'Test mode – not actually sent' : null,
                'locked_by'           => null,
            ], 'id = ?', [$job['id']]);
            $stats['sent']++;
            return;
        }
        $canRetry = ($res['retry'] ?? false) && $attempts < (int) $job['max_attempts'];
        $delay = min(3600 * 6, 60 * (2 ** $attempts)); // 2m, 4m, 8m, 16m ... capped at 6h
        DB::update('notification_queue', [
            'status'          => $canRetry ? 'pending' : 'failed',
            'attempts'        => $attempts,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + $delay),
            'last_error'      => mb_substr((string) ($res['error'] ?? 'Unknown error'), 0, 500),
            'locked_by'       => null,
        ], 'id = ?', [$job['id']]);
        $stats[$canRetry ? 'retry' : 'failed']++;
    }

    /** Manual retry of failed rows (from the admin / owner screens). */
    public static function retry(array $ids, ?int $academyId = null): int
    {
        if (!$ids) {
            return 0;
        }
        $sql = "UPDATE notification_queue SET status = 'pending', next_attempt_at = NOW(), max_attempts = GREATEST(max_attempts, attempts + 1), last_error = CONCAT('Manual retry. ', COALESCE(last_error,''))
                WHERE id IN (" . DB::inList($ids) . ") AND status IN ('failed','cancelled')";
        $params = array_map('intval', $ids);
        if ($academyId) {
            $sql .= ' AND academy_id = ?';
            $params[] = $academyId;
        }
        return DB::exec($sql, $params);
    }
}
