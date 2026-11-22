<?php
declare(strict_types=1);

/**
 * Final integration suite: registration approval, attendance history filters, large-academy paging and
 * bulk moves, duplicate detection, WhatsApp package modes, web ↔ Android synchronisation, unauthorized
 * access, subscription expiry and role terminology.
 *
 *   CNX_DB=classnex_test php tests/e2e_final.php [base_url]
 *
 * The test database is DROPPED and re-created. Writes docs/QA_FINAL_RUN.md.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8081', '/');
$dbName = getenv('CNX_DB') ?: 'classnex_test';
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "Refusing to run against a database whose name does not contain 'test'.\n");
    exit(1);
}
require __DIR__ . '/../includes/bootstrap.php';
if (Config::get('db.name') !== $dbName) {
    fwrite(STDERR, "Set CNX_DB=$dbName so the test and the configuration use the same test database.\n");
    exit(1);
}

$pass = $fail = 0;
$results = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $results;
    $cond ? $pass++ : $fail++;
    $results[] = [$cond, $name];
    echo ($cond ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $name . ($cond || $detail === '' ? '' : ' — ' . substr($detail, 0, 400)) . "\n";
}
function section(string $t): void
{
    global $results;
    $results[] = [null, $t];
    echo "\n\033[1m$t\033[0m\n";
}
/** @return array{status:int, json:?array, body:string, type:string, ms:float} */
function api(string $method, string $path, ?array $body = null, ?string $token = null, ?string $jar = null, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . '/api/v1' . $path);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h]);
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $t = microtime(true);
    $raw = (string) curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['status' => $st, 'json' => json_decode($raw, true), 'body' => $raw, 'type' => $type, 'ms' => $ms];
}
function q(string $sql, array $p = []): mixed
{
    return DB::val($sql, $p);
}
function login(string $id, string $pw): ?string
{
    return api('POST', '/auth/login', ['identifier' => $id, 'password' => $pw, 'client' => 'android'])['json']['data']['token'] ?? null;
}
function d(int $daysAgo): string
{
    return date('Y-m-d', strtotime("-$daysAgo days"));
}

section('Setup');
$root = new PDO('mysql:host=' . Config::get('db.host'), Config::get('db.user'), Config::get('db.pass'));
$root->exec("DROP DATABASE IF EXISTS `$dbName`");
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
DB::reset();
Migrator::run();
shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . CNX_ROOT . "/cli/create-superadmin.php 'QA Admin' qa-admin@classnex.test 9000000009 'Admin@12345'");
ok((int) q("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '004%'") === 1, 'Migration 004 applied on a clean database');
ok((int) q("SELECT COUNT(*) FROM packages WHERE whatsapp_mode NOT IN ('off','manual','api')") === 0, 'Every package uses one of the three WhatsApp modes');
$sa = login('qa-admin@classnex.test', 'Admin@12345');
$features = ['attendance', 'fees', 'inapp', 'push', 'whatsapp', 'manual_reminders', 'auto_reminders', 'reports', 'parent_access', 'online_admission'];
$pApi = (int) api('POST', '/admin/packages', ['name' => 'API 500', 'price' => '500', 'billing_cycle' => 'monthly', 'trial_days' => 14, 'student_limit' => 5000, 'whatsapp_mode' => 'api', 'features' => $features, 'status' => 'active'], $sa)['json']['data']['id'];
$pManual = (int) api('POST', '/admin/packages', ['name' => 'Manual 15', 'price' => '100', 'billing_cycle' => 'monthly', 'trial_days' => 0, 'student_limit' => 15, 'whatsapp_mode' => 'manual', 'features' => $features, 'status' => 'active'], $sa)['json']['data']['id'];
$pOff = (int) api('POST', '/admin/packages', ['name' => 'No WhatsApp', 'price' => '50', 'billing_cycle' => 'monthly', 'trial_days' => 0, 'student_limit' => 10, 'whatsapp_mode' => 'off', 'features' => $features, 'status' => 'active'], $sa)['json']['data']['id'];
ok($pApi && $pManual && $pOff, 'Super Admin creates packages in API, Manual and Disabled WhatsApp modes');
ok(api('POST', '/admin/packages', ['name' => 'Bad', 'price' => '1', 'billing_cycle' => 'monthly', 'whatsapp_mode' => 'full'], $sa)['status'] === 422, 'Old/unknown WhatsApp mode values are rejected');

// ------------------------------------------------------------------ 1. registration + approval
section('Academy registration → Super Admin approval → activation');
$reg = api('POST', '/public/register-owner', ['owner_name' => 'Asha Rao', 'mobile' => '9845000001', 'academy_name' => 'Rao Dance Studio', 'city' => 'Indore', 'password' => 'Asha@1234', 'accept_terms' => 1, 'package_id' => $pApi, 'client' => 'web']);
$rid = (int) q("SELECT id FROM academies WHERE name = 'Rao Dance Studio'");
ok($reg['status'] === 200 && ($reg['json']['data']['pending'] ?? false) === true && !isset($reg['json']['data']['redirect']), 'Website registration is received and waits for approval (no auto-login)', $reg['body']);
ok(q('SELECT status FROM academies WHERE id = ?', [$rid]) === 'pending' && !q('SELECT 1 FROM owner_subscriptions WHERE academy_id = ?', [$rid]), 'Academy is pending; the trial has not started yet');
$r = api('POST', '/auth/login', ['identifier' => '9845000001', 'password' => 'Asha@1234']);
ok($r['status'] === 403 && ($r['json']['code'] ?? '') === 'pending_approval', 'Login explains that the registration is awaiting approval', $r['body']);
ok((int) q("SELECT COUNT(*) FROM notifications WHERE event_key = 'registration_pending'") >= 1, 'Super Admin is notified in the app of the new registration');
$list = api('GET', '/admin/registrations', null, $sa)['json']['data'] ?? [];
ok(count($list) === 1 && $list[0]['academy_name'] === 'Rao Dance Studio' && (int) $list[0]['package_id'] === $pApi, 'Registration request listed for the Super Admin with the requested package', json_encode($list) . " pApi=$pApi");
ok((api('GET', '/admin/dashboard', null, $sa)['json']['data']['pending_registrations'] ?? 0) === 1, 'Dashboard shows the pending registration count');
$r = api('POST', "/admin/academies/$rid/approve", ['package_id' => $pApi], $sa);
ok($r['status'] === 200 && q('SELECT status FROM academies WHERE id = ?', [$rid]) === 'active', 'Super Admin approves the academy', $r['body']);
ok(q('SELECT status FROM owner_subscriptions WHERE academy_id = ? AND is_current = 1', [$rid]) === 'trial' && q('SELECT starts_on FROM owner_subscriptions WHERE academy_id = ? AND is_current = 1', [$rid]) === date('Y-m-d'), 'Trial starts on the approval date');
ok((int) q("SELECT COUNT(*) FROM audit_logs WHERE action = 'academy.approve'") === 1, 'Approval is audited');
$own = login('9845000001', 'Asha@1234');
ok((bool) $own, 'Class Admin logs in after approval');
ok(api('POST', "/admin/academies/$rid/approve", [], $own)['status'] === 403, 'A Class Admin cannot call Super Admin approval endpoints');
api('POST', '/public/register-owner', ['owner_name' => 'Spam', 'mobile' => '9845000002', 'academy_name' => 'Spam Academy', 'password' => 'Spam@1234', 'accept_terms' => 1, 'client' => 'android']);
$sid2 = (int) q("SELECT id FROM academies WHERE name = 'Spam Academy'");
ok(api('POST', "/admin/academies/$sid2/reject", ['reason' => ''], $sa)['status'] === 422, 'Rejection needs a reason');
ok(api('POST', "/admin/academies/$sid2/reject", ['reason' => 'Duplicate request'], $sa)['status'] === 200 && api('POST', '/auth/login', ['identifier' => '9845000002', 'password' => 'Spam@1234'])['json']['code'] === 'registration_rejected', 'Rejected registration cannot log in and is told why');
$me = api('GET', '/auth/me', null, $own)['json']['data'];
ok($me['role'] === 'owner' && $me['whatsapp_mode'] === 'api', 'Role key stays "owner" for API compatibility; package WhatsApp mode is exposed to the apps');

// ------------------------------------------------------------------ structure for the rest
$act = (int) api('POST', '/activities', ['name' => 'Kathak', 'monthly_fee' => '1000'], $own)['json']['data']['id'];
$act2 = (int) api('POST', '/activities', ['name' => 'Yoga', 'monthly_fee' => '800'], $own)['json']['data']['id'];
$brA = (int) api('POST', '/branches', ['name' => 'North'], $own)['json']['data']['id'];
$brB = (int) api('POST', '/branches', ['name' => 'South'], $own)['json']['data']['id'];
$bA = (int) api('POST', '/batches', ['name' => 'North Kathak', 'activity_id' => $act, 'branch_id' => $brA, 'start_time' => '07:00', 'end_time' => '08:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own)['json']['data']['id'];
$bB = (int) api('POST', '/batches', ['name' => 'South Kathak', 'activity_id' => $act, 'branch_id' => $brB, 'start_time' => '17:00', 'end_time' => '18:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own)['json']['data']['id'];
$bY = (int) api('POST', '/batches', ['name' => 'South Yoga', 'activity_id' => $act2, 'branch_id' => $brB, 'start_time' => '06:00', 'end_time' => '07:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own)['json']['data']['id'];
api('POST', '/teachers', ['name' => 'Guru North', 'mobile' => '9845100001', 'password' => 'Teach@123', 'branch_id' => $brA, 'batch_ids' => [$bA]], $own);
$tch = login('9845100001', 'Teach@123');
$stu = [];
foreach (range(1, 6) as $i) {
    $b = $i <= 3 ? $bA : $bB;
    $stu[$i] = (int) (api('POST', '/students', ['name' => "Dancer $i", 'whatsapp' => '98451' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'activity_id' => $act, 'batch_id' => $b, 'is_minor' => '0', 'send_message' => '0'], $own)['json']['data']['student']['id'] ?? 0);
}
$yogi = (int) (api('POST', '/students', ['name' => 'Yogi', 'whatsapp' => '9845199999', 'activity_id' => $act2, 'batch_id' => $bY, 'is_minor' => '0'], $own)['json']['data']['student']['id'] ?? 0);
ok(count(array_filter($stu)) === 6 && $yogi && $tch, 'Two branches, three batches, a teacher and seven students set up');
// these students joined a month ago (so earlier classes can be marked for them)
DB::exec('UPDATE student_activity_mapping SET start_date = ? WHERE academy_id = ?', [d(30), $rid]);
DB::exec('UPDATE students SET admission_date = ? WHERE academy_id = ?', [d(30), $rid]);

// ------------------------------------------------------------------ 2. attendance history
section('Attendance history: filters, totals, branch & combined views, exports');
$mark = static function (int $batch, string $date, array $st) use ($own) {
    return api('POST', '/attendance', ['batch_id' => $batch, 'date' => $date, 'entries' => array_map(static fn($k, $v) => ['student_id' => $k, 'status' => $v], array_keys($st), $st)], $own);
};
$mark($bA, d(2), [$stu[1] => 'present', $stu[2] => 'absent', $stu[3] => 'late']);
$mark($bA, d(1), [$stu[1] => 'present', $stu[2] => 'present', $stu[3] => 'leave']);
$mark($bB, d(2), [$stu[4] => 'present', $stu[5] => 'present', $stu[6] => 'absent']);
$mark($bY, d(1), [$yogi => 'present']);
$h = api('GET', '/attendance/history?from=' . d(7) . '&to=' . date('Y-m-d'), null, $own)['json'];
$s = $h['meta']['summary'] ?? [];
ok($s['present'] === 6 && $s['absent'] === 2 && $s['late'] === 1 && $s['leave'] === 1 && $s['total_students'] === 7, 'Combined totals: students, present, absent, late, leave', json_encode($s));
ok(abs(($s['attendance_pct'] ?? 0) - 77.8) < 0.1, 'Attendance % = (present + late) / (present + late + absent) = 77.8%');
ok(count($h['meta']['by_branch'] ?? []) === 2, 'Combined academy view includes a branch-wise breakdown');
$n = api('GET', "/attendance/history?from=" . d(7) . "&branch_id=$brA", null, $own)['json'];
ok(($n['meta']['summary']['total_students'] ?? 0) === 3 && count($n['data']) === 2 && !isset($n['meta']['by_branch']), 'Individual branch history (North: 2 classes, 3 students)');
ok((api('GET', '/attendance/history?date=' . d(2), null, $own)['json']['meta']['summary']['sessions'] ?? 0) === 2, 'Single-date filter');
ok((api('GET', "/attendance/history?from=" . d(7) . "&activity_id=$act2", null, $own)['json']['meta']['summary']['total_students'] ?? 0) === 1, 'Activity filter');
ok((api('GET', "/attendance/history?from=" . d(7) . "&batch_id=$bB", null, $own)['json']['meta']['summary']['absent'] ?? 0) === 1, 'Batch filter');
$st = api('GET', "/attendance/history?from=" . d(7) . "&student_id={$stu[2]}&view=students", null, $own)['json'];
ok(count($st['data']) === 2 && $st['meta']['summary']['absent'] === 1 && $st['data'][0]['student_name'] === 'Dancer 2', 'Student filter (student view lists each day)');
$ab = api('GET', "/attendance/history?from=" . d(7) . "&status=absent&view=students", null, $own)['json'];
ok(count($ab['data']) === 2 && $ab['meta']['summary']['present'] === 0, 'Status filter (absent only)');
$bv = api('GET', '/attendance/history?from=' . d(7) . '&view=branches', null, $own)['json']['data'];
ok(count($bv) === 2 && array_sum(array_column($bv, 'present')) === 6, 'Branch view rows add up to the combined totals');
// transfer: history stays with the branch where the class happened
api('POST', "/students/{$stu[1]}/branch", ['branch_id' => $brB, 'batch_id' => $bB], $own);
$mark($bB, date('Y-m-d'), [$stu[1] => 'present']);
$north = api('GET', "/attendance/history?from=" . d(7) . "&branch_id=$brA&student_id={$stu[1]}&view=students", null, $own)['json'];
$south = api('GET', "/attendance/history?from=" . d(7) . "&branch_id=$brB&student_id={$stu[1]}&view=students", null, $own)['json'];
ok(count($north['data']) === 2 && count($south['data']) === 1, 'After a branch transfer, old attendance stays in the old branch history and new attendance goes to the new branch');
api('POST', "/branches/$brB/assign", ['batch_ids' => [$bA]], $own); // the whole batch moves to South
ok((int) q('SELECT branch_id FROM attendance_sessions WHERE batch_id = ? AND session_date = ?', [$bA, d(2)]) === $brA, 'Moving a batch to another branch does not rewrite past attendance');
api('POST', "/branches/$brA/assign", ['batch_ids' => [$bA]], $own);
foreach (['pdf' => '%PDF', 'xlsx' => "PK\x03\x04", 'csv' => "\xEF\xBB\xBFDate"] as $fmt => $magic) {
    $x = api('GET', "/attendance/history?from=" . d(7) . "&branch_id=$brA&format=$fmt", null, $own);
    ok($x['status'] === 200 && str_starts_with($x['body'], $magic), 'Attendance history exports as ' . strtoupper($fmt));
}
$csv = api('GET', '/attendance/history?from=' . d(7) . '&view=students&format=csv', null, $own)['body'];
ok(str_contains($csv, 'Attendance %') && substr_count($csv, "\n") >= 10, 'CSV export contains the rows and the totals');
$t = api('GET', '/attendance/history?from=' . d(7), null, $tch)['json'];
ok(($t['meta']['summary']['total_students'] ?? 0) === 3 && count($t['data']) === 2, 'Teacher sees only the history of their own batches');

// ------------------------------------------------------------------ 3. duplicates
section('Duplicate detection');
$sib = api('POST', '/students', ['name' => 'Meera Rao', 'whatsapp' => '9845200001', 'guardian_name' => 'Asha Rao', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1'], $own);
$sib2 = api('POST', '/students', ['name' => 'Kabir Rao', 'whatsapp' => '9845200001', 'guardian_name' => 'Asha Rao', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1'], $own);
ok($sib['status'] === 200 && $sib2['status'] === 200, 'Brother and sister sharing the parent\'s WhatsApp number are both admitted', $sib2['body']);
$dup = api('POST', '/students', ['name' => 'meera  rao', 'whatsapp' => '9845200001', 'guardian_name' => 'Asha Rao', 'activity_id' => $act2, 'batch_id' => $bY, 'is_minor' => '1'], $own);
$c = $dup['json']['errors']['candidates'][0] ?? [];
ok($dup['status'] === 409 && ($c['name'] ?? '') === 'Meera Rao' && $c['level'] === 'high' && in_array('same parent/guardian', $c['reasons'], true), 'Same name + same WhatsApp + same guardian → possible duplicate shown with reasons', $dup['body']);
ok(($dup['json']['errors']['can_override'] ?? null) === true && isset($c['whatsapp_last4']) && !isset($c['whatsapp']), 'Candidate details are shown with the number masked; Class Admin may override');
$dupT = api('POST', '/students', ['name' => 'Meera Rao', 'whatsapp' => '9845200001', 'guardian_name' => 'Asha Rao', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1', 'confirm_duplicate' => 1, 'duplicate_reason' => 'Twin'], $tch);
ok($dupT['status'] === 403, 'A teacher cannot override a duplicate warning');
ok(api('POST', '/students', ['name' => 'Ravi Kumar', 'whatsapp' => '9845300001', 'dob' => '2015-01-10', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1', 'guardian_name' => 'X'], $own)['status'] === 200
    && api('POST', '/students', ['name' => 'Ravi Kumar', 'whatsapp' => '9845300001', 'dob' => '2017-05-02', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1', 'guardian_name' => 'X'], $own)['status'] === 200, 'Same name and number but different dates of birth are different people (not blocked)');
$g = api('POST', '/students', ['name' => 'Kabir Rao', 'whatsapp' => '9845299999', 'guardian_name' => 'Asha Rao', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1'], $own);
ok($g['status'] === 409 && ($g['json']['errors']['candidates'][0]['level'] ?? '') === 'medium', 'Same name + same guardian with another number → medium-confidence match');
$before = (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$rid]);
$ov = api('POST', '/students', ['name' => 'Kabir Rao', 'whatsapp' => '9845299999', 'guardian_name' => 'Asha Rao', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '1', 'confirm_duplicate' => 1, 'duplicate_reason' => 'Cousin with the same name, checked'], $own);
ok($ov['status'] === 200 && (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$rid]) === $before + 1, 'Class Admin creates a separate student after verification');
ok((int) q("SELECT COUNT(*) FROM audit_logs WHERE action = 'student.duplicate_override' AND new_values LIKE '%Cousin%'") === 1, 'Duplicate override is written to the audit log with the reason');
$meera = (int) $sib['json']['data']['student']['id'];
$before = (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$rid]);
$ln = api('POST', "/students/$meera/link", ['activity_id' => $act2, 'batch_id' => $bY], $own);
ok($ln['status'] === 200 && (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$rid]) === $before && (int) q("SELECT COUNT(*) FROM student_activity_mapping WHERE student_id = ? AND status = 'active'", [$meera]) === 2, 'Link existing student: enrols the existing profile, no new student');
ok(api('GET', '/students/duplicates?name=Meera+Rao&whatsapp=9845200001', null, $own)['json']['data'][0]['id'] === $meera, 'Live duplicate check while typing');

// ------------------------------------------------------------------ 4. paging & large data
section('Large academy: server-side search, paging and bulk branch moves');
$aid = $rid;
$t0 = microtime(true);
DB::tx(static function () use ($aid) {
    $rows = [];
    for ($i = 1; $i <= 3000; $i++) {
        $rows[] = sprintf("(%d,'BULK%05d','Bulk Student %d','+91','97%08d','active',CURDATE(),0)", $aid, $i, $i, $i);
        if (count($rows) === 500) {
            DB::exec('INSERT INTO students (academy_id, student_code, name, country_code, whatsapp, status, admission_date, is_minor) VALUES ' . implode(',', $rows));
            $rows = [];
        }
    }
});
ok((int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND student_code LIKE 'BULK%'", [$aid]) === 3000, sprintf('3,000 extra students loaded (%.1fs)', microtime(true) - $t0));
$p = api('GET', '/students?per_page=50&page=40', null, $own);
ok($p['status'] === 200 && count($p['json']['data']) === 50 && $p['json']['meta']['total'] >= 3000 && $p['json']['meta']['pages'] >= 61, sprintf('Page 40 of the student list (50 per page, %d ms)', $p['ms']));
ok(api('GET', '/students?per_page=1000', null, $own)['json']['meta']['per_page'] === 200, 'Page size is capped by the server (never loads everyone)');
$sr = api('GET', '/students?q=BULK02999', null, $own);
ok(count($sr['json']['data']) === 1 && $sr['ms'] < 2000, sprintf('Search by Student ID (%d ms)', $sr['ms']));
ok(count(api('GET', '/students?q=9700002999', null, $own)['json']['data']) === 1, 'Search by WhatsApp number');
ok(api('GET', '/students?q=Bulk+Student+2999', null, $own)['json']['meta']['total'] === 1, 'Search by name');
ok(api('GET', "/students?not_branch_id=$brA&branch_id=none&per_page=5", null, $own)['json']['meta']['total'] >= 3000, 'Filter: students not yet in a branch');
$active0 = (int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$aid]);
$pv = api('POST', '/students/bulk-branch', ['branch_id' => $brB, 'filter' => ['q' => 'Bulk Student'], 'preview' => 1], $own);
ok(($pv['json']['data']['count'] ?? 0) === 3000 && (int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND branch_id = ?", [$aid, $brB]) < 3000, 'Preview shows how many students will move (nothing changed yet)');
$mv = api('POST', '/students/bulk-branch', ['branch_id' => $brB, 'filter' => ['q' => 'Bulk Student']], $own);
ok(($mv['json']['data']['moved'] ?? 0) === 3000 && $mv['ms'] < 15000, sprintf('“All matching” bulk move of 3,000 students (%d ms)', $mv['ms']));
ok((int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$aid]) === $active0 && (int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND student_code LIKE 'BULK%' AND branch_id = ?", [$aid, $brB]) === 3000, 'Bulk move keeps the same profiles: active count unchanged');
$ids = array_map('intval', DB::col("SELECT id FROM students WHERE academy_id = ? AND student_code LIKE 'BULK0000%' ORDER BY id", [$aid]));
$mv = api('POST', '/students/bulk-branch', ['branch_id' => $brA, 'batch_id' => $bA, 'student_ids' => $ids], $own);
ok(($mv['json']['data']['moved'] ?? 0) === count($ids) && (int) q("SELECT COUNT(*) FROM student_activity_mapping WHERE batch_id = ? AND student_id IN (" . implode(',', $ids) . ") AND status = 'active'", [$bA]) === count($ids), 'Bulk transfer of selected students into a batch at the new branch');
ok(api('POST', '/students/bulk-branch', ['branch_id' => $brA, 'batch_id' => $bB, 'student_ids' => $ids], $own)['status'] === 422, 'Bulk transfer rejects a batch from another branch');
ok(api('POST', '/students/bulk-branch', ['branch_id' => $brA, 'student_ids' => $ids], $tch)['status'] === 403, 'Teachers cannot bulk-move students');
ok(in_array(api('GET', '/students/bulk-branch', null, $own)['status'], [404, 405], true) && api('GET', "/students/$meera", null, $own)['status'] === 200, 'Router: fixed paths never collide with /students/{id}');
DB::exec("DELETE FROM student_activity_mapping WHERE student_id IN (SELECT id FROM students WHERE student_code LIKE 'BULK%')");
DB::exec("UPDATE students SET status = 'inactive' WHERE student_code LIKE 'BULK%'");

// ------------------------------------------------------------------ 5. WhatsApp modes
section('WhatsApp package modes: Disabled / Manual click-to-send / Official API');
// academy in manual mode
$m = api('POST', '/admin/owners', ['owner_name' => 'Manual Owner', 'mobile' => '9845400001', 'academy_name' => 'Manual Academy', 'package_id' => $pManual, 'password' => 'Owner@123', 'subscription_status' => 'active'], $sa);
$mid = (int) $m['json']['data']['academy_id'];
$mo = login('9845400001', 'Owner@123');
$mme = api('GET', '/auth/me', null, $mo)['json']['data'];
ok($mme['whatsapp_mode'] === 'manual' && in_array('whatsapp_manual', $mme['features'], true) && !in_array('whatsapp', $mme['features'], true), 'Manual mode: click-to-send available, official API not');
$ma = (int) api('POST', '/activities', ['name' => 'Chess', 'monthly_fee' => '700'], $mo)['json']['data']['id'];
$mb = (int) api('POST', '/batches', ['name' => 'Chess A', 'activity_id' => $ma, 'start_time' => '10:00', 'end_time' => '11:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $mo)['json']['data']['id'];
$ms = (int) api('POST', '/students', ['name' => 'Pawn', 'whatsapp' => '9845400011', 'activity_id' => $ma, 'batch_id' => $mb, 'is_minor' => '0'], $mo)['json']['data']['student']['id'];
$ms2 = (int) api('POST', '/students', ['name' => 'Rook', 'whatsapp' => '9845400012', 'activity_id' => $ma, 'batch_id' => $mb, 'is_minor' => '0'], $mo)['json']['data']['student']['id'];
ok((int) q("SELECT COUNT(*) FROM notification_queue WHERE academy_id = ? AND channel = 'whatsapp'", [$mid]) === 0, 'Manual mode never sends WhatsApp in the background');
$pay = (int) api('POST', '/payments', ['student_id' => $ms, 'amount' => '200', 'method' => 'cash'], $mo)['json']['data']['id'];
api('POST', '/attendance', ['batch_id' => $mb, 'entries' => [['student_id' => $ms, 'status' => 'absent'], ['student_id' => $ms2, 'status' => 'present']]], $mo);
$an = (int) api('POST', '/announcements', ['title' => 'Holiday', 'body' => 'Closed on Friday', 'audience' => 'all'], $mo)['json']['data']['id'];
$purposes = [
    'fee_reminder' => ['purpose' => 'fee_reminder', 'target' => 'dues'],
    'receipt'      => ['purpose' => 'receipt', 'payment_id' => $pay],
    'attendance'   => ['purpose' => 'attendance', 'batch_id' => $mb, 'date' => date('Y-m-d')],
    'admission'    => ['purpose' => 'admission', 'student_id' => $ms2],
    'announcement' => ['purpose' => 'announcement', 'announcement_id' => $an],
    'custom'       => ['purpose' => 'custom', 'target' => 'batch', 'batch_id' => $mb, 'message' => 'Dear {student_name}, bring your board tomorrow.'],
];
$counts = ['fee_reminder' => 2, 'receipt' => 1, 'attendance' => 1, 'admission' => 1, 'announcement' => 2, 'custom' => 2];
foreach ($purposes as $k => $body) {
    $r = api('POST', '/whatsapp/manual/prepare', $body, $mo);
    $items = $r['json']['data']['items'] ?? [];
    ok($r['status'] === 200 && count($items) === $counts[$k] && str_starts_with($items[0]['link'] ?? '', 'https://wa.me/91') && str_contains(urldecode($items[0]['link']), substr($items[0]['text'], 0, 20)), "Manual: $k prepared with a click-to-send link (" . count($items) . ')', $r['body']);
}
ok(str_contains($items[0]['text'] ?? '', 'Dear Pawn') || str_contains($items[0]['text'] ?? '', 'Dear Rook'), 'Custom message is personalised per student');
$first = (int) q('SELECT MIN(id) FROM manual_messages WHERE academy_id = ?', [$mid]);
api('POST', "/whatsapp/manual/$first/opened", [], $mo);
$log = api('GET', '/whatsapp/manual/log', null, $mo)['json'];
ok($log['meta']['total'] === 9 && (int) q("SELECT COUNT(*) FROM manual_messages WHERE academy_id = ? AND status = 'opened'", [$mid]) === 1, 'Manual message history: 9 prepared, the opened one is marked');
ok(api('GET', '/whatsapp/manual/log?purpose=receipt', null, $mo)['json']['meta']['total'] === 1, 'History filter by purpose');
$share = api('POST', "/payments/$pay/share", ['channel' => 'link'], $mo);
ok(str_starts_with($share['json']['data']['whatsapp_link'] ?? '', 'https://wa.me/') && (int) q("SELECT COUNT(*) FROM manual_messages WHERE academy_id = ? AND purpose = 'receipt'", [$mid]) === 2, 'Receipt share link is logged in the history too');
ok(api('GET', '/whatsapp/manual/log', null, $own)['json']['meta']['total'] === 0, 'Manual history is private to each academy');
// disabled mode
$o = api('POST', '/admin/owners', ['owner_name' => 'Off Owner', 'mobile' => '9845500001', 'academy_name' => 'Quiet Academy', 'package_id' => $pOff, 'password' => 'Owner@123', 'subscription_status' => 'active'], $sa);
$oo = login('9845500001', 'Owner@123');
$ome = api('GET', '/auth/me', null, $oo)['json']['data'];
ok($ome['whatsapp_mode'] === 'off' && !array_intersect(['whatsapp', 'whatsapp_manual'], $ome['features']), 'Disabled mode: no WhatsApp features');
ok(api('POST', '/whatsapp/manual/prepare', ['purpose' => 'custom', 'target' => 'all', 'message' => 'Hi'], $oo)['status'] === 402, 'Disabled mode: click-to-send is refused');
// API mode (the main academy)
$req = ['target' => 'batch', 'batch_id' => $bA, 'message' => 'Practice at 7', 'channels' => ['whatsapp'], 'client_ref' => 'abc-1'];
$r1 = api('POST', '/messages/send', $req, $own);
$n1 = (int) q("SELECT COUNT(*) FROM notification_queue WHERE academy_id = ? AND channel = 'whatsapp' AND event_key = 'manual_message'", [$rid]);
api('POST', '/messages/send', $req, $own);
ok($r1['status'] === 200 && $n1 > 0 && (int) q("SELECT COUNT(*) FROM notification_queue WHERE academy_id = ? AND channel = 'whatsapp' AND event_key = 'manual_message'", [$rid]) === $n1, 'API mode: messages queued for background sending; the same request twice creates no duplicates');
$at = date('Y-m-d H:i:s', time() + 3 * 86400);
$r = api('POST', '/messages/send', ['target' => 'batch', 'batch_id' => $bA, 'message' => 'Exam reminder', 'channels' => ['whatsapp'], 'client_ref' => 'sched-1', 'send_at' => $at], $own);
ok($r['status'] === 200 && (int) q("SELECT COUNT(*) FROM notification_queue WHERE body = 'Exam reminder' AND next_attempt_at >= ?", [date('Y-m-d H:i:s', time() + 2 * 86400)]) > 0, 'API mode: scheduled message waits in the queue until its time');
shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . CNX_ROOT . '/cron/run.php queue --force 2>&1');
ok((int) q("SELECT COUNT(*) FROM notification_queue WHERE body = 'Exam reminder' AND status = 'pending'") > 0, 'Queue run does not send a scheduled message early');
ok(api('POST', '/messages/send', ['target' => 'batch', 'batch_id' => $bA, 'message' => 'x', 'channels' => ['whatsapp'], 'client_ref' => 'old', 'send_at' => '2020-01-01 10:00:00'], $own)['status'] === 422, 'Scheduling in the past is rejected');

// ------------------------------------------------------------------ 6. web ↔ Android
section('Web and Android use the same API and rules');
$jar = tempnam(sys_get_temp_dir(), 'cnx');
$web = api('POST', '/auth/login', ['identifier' => '9845000001', 'password' => 'Asha@1234', 'client' => 'web'], null, $jar);
$csrf = $web['json']['data']['csrf'] ?? '';
$wb = api('POST', '/branches', ['name' => 'East (web)'], null, $jar, ["X-CSRF-Token: $csrf"]);
ok($wb['status'] === 200 && in_array('East (web)', array_column(api('GET', '/branches', null, $own)['json']['data'], 'name'), true), 'Branch created on the web appears in the Android API');
$as = api('POST', '/students', ['name' => 'Android Kid', 'whatsapp' => '9845600001', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '0'], $own);
$aid2 = (int) ($as['json']['data']['student']['id'] ?? 0);
ok(api('GET', "/students?q=Android+Kid", null, null, $jar)['json']['data'][0]['id'] === $aid2, 'Student registered on Android appears on the web');
api('POST', "/students/$aid2/branch", ['branch_id' => $brB], null, $jar, ["X-CSRF-Token: $csrf"]);
ok(api('GET', "/students/$aid2", null, $own)['json']['data']['branch_name'] === 'South', 'Branch transfer on the web is visible on Android (same Student ID)');
$wh = api('GET', '/attendance/history?from=' . d(7), null, null, $jar)['json']['meta']['summary'];
$ah = api('GET', '/attendance/history?from=' . d(7), null, $own)['json']['meta']['summary'];
ok($wh === $ah, 'Attendance history totals identical on web and Android');
DB::exec("UPDATE owner_subscriptions SET custom_limits = ? WHERE academy_id = ? AND is_current = 1", [json_encode(['students' => (int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$rid])]), $rid]);
Feature::flush();
$qa = api('POST', '/students', ['name' => 'Over Limit A', 'whatsapp' => '9845600002', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '0'], $own);
$qw = api('POST', '/students', ['name' => 'Over Limit W', 'whatsapp' => '9845600003', 'activity_id' => $act, 'batch_id' => $bA, 'is_minor' => '0'], null, $jar, ["X-CSRF-Token: $csrf"]);
ok($qa['status'] === 402 && $qw['status'] === 402 && $qa['json']['message'] === $qw['json']['message'], 'Student quota enforced identically for web and Android');
DB::exec('UPDATE owner_subscriptions SET custom_limits = NULL WHERE academy_id = ?', [$rid]);

// ------------------------------------------------------------------ 7. unauthorized access
section('Unauthorized access');
ok(api('GET', '/branches')['status'] === 401 && api('GET', '/attendance/history')['status'] === 401, 'No login → 401');
ok(api('POST', '/branches', ['name' => 'X'], $tch)['status'] === 403, 'Teacher cannot create branches');
ok(api('GET', '/admin/registrations', null, $own)['status'] === 403, 'Class Admin cannot see platform registration requests');
ok(api('GET', "/attendance/history?branch_id=$brA", null, $mo)['status'] === 404, 'Another academy cannot filter by a foreign branch');
ok(api('POST', '/whatsapp/manual/prepare', ['purpose' => 'admission', 'student_id' => $meera], $mo)['status'] === 404, 'Another academy cannot prepare WhatsApp for a foreign student');
ok(api('POST', "/whatsapp/manual/$first/opened", [], $own)['status'] === 200 && q('SELECT status FROM manual_messages WHERE id = ?', [$first]) === 'opened' && (int) q("SELECT COUNT(*) FROM manual_messages WHERE academy_id = ? AND status = 'opened'", [$rid]) === 0, 'Marking another academy\'s message has no effect');
ok(api('POST', '/students', ['name' => 'X'], null, $jar)['status'] === 403, 'Web write without CSRF token is refused (403)');

// ------------------------------------------------------------------ 7b. Android redesign API additions
section('Android app redesign: dashboard branch filter, registration status, teacher batches');
$dAll = api('GET', '/dashboard', null, $own)['json']['data'];
$dN = api('GET', "/dashboard?branch_id=$brA", null, $own)['json']['data'];
ok(isset($dAll['total_branches'], $dAll['today_attendance']['late'], $dAll['today_attendance']['leave'], $dAll['scheduled_messages']) && $dAll['total_branches'] >= 2, 'Dashboard gives total branches, late / leave counts and scheduled reminders');
ok((int) $dN['branch_id'] === $brA && $dN['active_students'] < $dAll['active_students'] && $dN['active_students'] === (int) q("SELECT COUNT(*) FROM students WHERE branch_id = ? AND status = 'active'", [$brA]), 'Dashboard can be filtered to one branch (figures match the branch)');
ok(($dN['student_quota']['limit'] ?? null) === ($dAll['student_quota']['limit'] ?? null), 'Branch filter keeps the one academy-wide student quota');
ok(api('GET', '/dashboard?branch_id=999999', null, $own)['status'] === 404, 'Dashboard rejects a branch of another academy');
$st = api('POST', '/public/registration-status', ['identifier' => '9845000001', 'password' => 'Asha@1234'])['json'];
$st2 = api('POST', '/public/registration-status', ['identifier' => '9845000002', 'password' => 'Spam@1234'])['json'];
ok(($st['data']['status'] ?? '') === 'approved' && ($st2['data']['status'] ?? '') === 'rejected' && ($st2['data']['reason'] ?? '') === 'Duplicate request', 'Track Registration Status shows approved / rejected with the reason');
ok(api('POST', '/public/registration-status', ['identifier' => '9845000001', 'password' => 'wrong'])['status'] === 404, 'Registration status needs the correct password (no information leak)');
$tl = api('GET', '/teachers', null, $own)['json']['data'][0] ?? [];
ok(($tl['batch_ids'] ?? null) === [$bA], 'Teacher list includes assigned batch ids so the app can edit them safely');

// ------------------------------------------------------------------ 8. subscription expiry
section('Subscription expiry');
api('POST', "/admin/academies/$mid/subscription", ['action' => 'assign', 'package_id' => $pManual, 'starts_on' => d(60), 'ends_on' => d(30)], $sa);
$r = api('POST', '/whatsapp/manual/prepare', ['purpose' => 'admission', 'student_id' => $ms], $mo);
ok(api('POST', '/branches', ['name' => 'Late'], $mo)['json']['code'] === 'subscription_expired' && api('GET', '/attendance/history', null, $mo)['status'] === 200, 'Expired: changes blocked, history still readable');
ok(api('POST', '/attendance', ['batch_id' => $mb, 'entries' => [['student_id' => $ms, 'status' => 'present']]], $mo)['status'] === 402, 'Expired: attendance cannot be marked until renewal');

// ------------------------------------------------------------------ 9. names & paths
section('Role names and installation paths');
$acc = api('POST', '/auth/login', ['identifier' => 'qa-admin@classnex.test', 'password' => 'Admin@12345', 'client' => 'web'])['json']['data']['redirect'] ?? '';
ok(str_ends_with($acc, '/admin/'), 'Super Admin goes to /admin/');
$cj = tempnam(sys_get_temp_dir(), 'cnx');
ok(str_ends_with(api('POST', '/auth/login', ['identifier' => '9845000001', 'password' => 'Asha@1234', 'client' => 'web'], null, $cj)['json']['data']['redirect'] ?? '', '/class-admin/'), 'Class Admin goes to /class-admin/');
$ch = curl_init($base . '/owner/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$hdr = (string) curl_exec($ch);
ok((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 301 && str_contains($hdr, 'class-admin'), 'Old /owner/ address redirects to /class-admin/');
$page = (string) file_get_contents($base . '/class-admin/', false, stream_context_create(['http' => ['header' => 'Cookie: ' . trim((string) preg_replace('/.*\t(\S+)\t(\S+)$/m', '$1=$2', (string) shell_exec('grep CNXSESS ' . escapeshellarg($cj))))]]));
ok(str_contains($page, 'Class Admin') && !preg_match('/>\s*Owner\s*</', $page), 'Class Admin panel shows “Class Admin”, not “Owner”');

$total = $pass + $fail;
echo "\n\033[1mResult: $pass passed, $fail failed\033[0m\n";
$md = "# Final integration test run\n\nRun: " . date('Y-m-d H:i:s') . " · Database: $dbName · PHP " . PHP_VERSION . " · Server: $base\n\n**$pass / $total passed**\n\n| # | Check | Result |\n|---|---|---|\n";
$i = 0;
foreach ($results as [$c, $n]) {
    $md .= $c === null ? "| | **" . str_replace('|', '\\|', $n) . "** | |\n" : '| ' . (++$i) . ' | ' . str_replace('|', '\\|', $n) . ' | ' . ($c ? 'PASS' : 'FAIL') . " |\n";
}
file_put_contents(CNX_ROOT . '/docs/QA_FINAL_RUN.md', $md);
exit($fail ? 1 : 0);
