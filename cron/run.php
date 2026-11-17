<?php
declare(strict_types=1);

/**
 * ClassNex cron runner. One cPanel cron line is enough:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron/run.php all >/dev/null 2>&1
 * Or run individual tasks: php cron/run.php queue | invoices | reminders | subscriptions | announcements | maintenance | backup
 *
 * Every task takes a MySQL named lock (GET_LOCK), so overlapping runs are skipped, and every
 * task is idempotent, so running it twice never duplicates invoices, reminders or messages.
 */
$isCli = PHP_SAPI === 'cli';
require __DIR__ . '/../includes/bootstrap.php';

if (!$isCli) {
    $key = (string) Config::get('cron_http_key', '');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain');
}
set_time_limit(280);

$tasks = [
    // name => [minimum minutes between runs, callable]
    'queue'         => [1, static fn() => QueueProcessor::run(Settings::int('queue_batch_size', 50))],
    'announcements' => [1, static fn() => ['published' => AnnouncementService::publishDue()]],
    'invoices'      => [60, static fn() => BillingService::generateAll(date('Y-m-d'))],
    'reminders'     => [30, static fn() => ReminderService::processDue(date('Y-m-d'))],
    'subscriptions' => [60, static fn() => SubscriptionCron::run(date('Y-m-d'))],
    'maintenance'   => [720, static function () {
        $a = DB::exec('DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $b = DB::exec('DELETE FROM api_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY) OR revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $c = DB::exec('DELETE FROM user_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $d = DB::exec('DELETE FROM notification_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
        $e = DB::exec('DELETE FROM cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        // document retention: delete expired documents
        $docs = DB::all("SELECT id, storage_path FROM student_documents WHERE deleted_at IS NULL AND retention_until IS NOT NULL AND retention_until < CURDATE() LIMIT 500");
        foreach ($docs as $doc) {
            Upload::delete($doc['storage_path']);
            DB::update('student_documents', ['deleted_at' => date('Y-m-d H:i:s'), 'storage_path' => 'deleted'], 'id = ?', [$doc['id']]);
        }
        return ['rate_limits' => $a, 'tokens' => $b + $c, 'logs' => $d + $e, 'documents_expired' => count($docs)];
    }],
    'backup'        => [1440, static fn() => ['file' => basename(BackupService::create())]],
];

$want = $isCli ? ($argv[1] ?? 'all') : ($_GET['task'] ?? 'all');
$force = $isCli ? in_array('--force', $argv, true) : false;
$names = $want === 'all' ? array_keys($tasks) : [$want];

foreach ($names as $name) {
    if (!isset($tasks[$name])) {
        echo "Unknown task: $name\n";
        continue;
    }
    [$every, $fn] = $tasks[$name];
    if (!$force && $want === 'all') {
        $last = DB::val("SELECT MAX(started_at) FROM cron_runs WHERE task = ? AND status IN ('ok','running')", [$name]);
        if ($last && strtotime($last) > time() - $every * 60 + 15) {
            continue;
        }
    }
    $lock = 'classnex_cron_' . $name;
    if ((int) DB::val('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        echo "[$name] already running, skipped\n";
        continue;
    }
    $runId = DB::insert('cron_runs', ['task' => $name, 'started_at' => date('Y-m-d H:i:s'), 'status' => 'running']);
    try {
        $res = $fn();
        $summary = json_encode($res);
        DB::update('cron_runs', ['finished_at' => date('Y-m-d H:i:s'), 'status' => 'ok', 'summary' => mb_substr((string) $summary, 0, 500)], 'id = ?', [$runId]);
        echo "[$name] ok $summary\n";
    } catch (Throwable $e) {
        error_log("[cron:$name] " . $e->getMessage() . "\n" . $e->getTraceAsString());
        DB::update('cron_runs', ['finished_at' => date('Y-m-d H:i:s'), 'status' => 'error', 'summary' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [$runId]);
        echo "[$name] ERROR " . $e->getMessage() . "\n";
    } finally {
        DB::val('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
Settings::set('cron_last_run', date('Y-m-d H:i:s'));
