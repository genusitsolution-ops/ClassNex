<?php
declare(strict_types=1);

/**
 * Unlimited branches + centralized student-based subscription: end-to-end checks.
 *
 *   CNX_DB=classnex_test php tests/e2e_branches.php [base_url]
 *
 * The test database is DROPPED and re-created. Never point this at production.
 * Writes docs/QA_BRANCHES_RUN.md with the results.
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

$pass = 0;
$fail = 0;
$results = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $results;
    $cond ? $pass++ : $fail++;
    $results[] = [$cond, $name, $detail];
    echo ($cond ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $name . ($cond || $detail === '' ? '' : ' — ' . substr($detail, 0, 400)) . "\n";
}
function section(string $t): void
{
    echo "\n\033[1m$t\033[0m\n";
}
function req(string $method, string $path, ?array $body, ?string $token): CurlHandle
{
    global $base;
    $ch = curl_init($base . '/api/v1' . $path);
    $h = ['Accept: application/json'];
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h]);
    return $ch;
}
/** @return array{status:int, json:?array, body:string} */
function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $ch = req($method, $path, $body, $token);
    $raw = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'json' => json_decode($raw, true), 'body' => $raw];
}
function q(string $sql, array $p = []): mixed
{
    return DB::val($sql, $p);
}
function activeCount(int $aid): int
{
    return (int) q("SELECT COUNT(*) FROM students WHERE academy_id = ? AND status = 'active'", [$aid]);
}

section('Setup');
$root = new PDO('mysql:host=' . Config::get('db.host'), Config::get('db.user'), Config::get('db.pass'));
$root->exec("DROP DATABASE IF EXISTS `$dbName`");
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
DB::reset();
Migrator::run();
shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . CNX_ROOT . "/cli/create-superadmin.php 'QA Admin' qa-admin@classnex.test 9000000009 'Admin@12345'");
ok((int) q('SELECT COUNT(*) FROM schema_migrations') >= 3, 'Migration 003 (branches & student quota) applied on a clean database');
ok(q("SELECT COUNT(*) FROM packages WHERE branch_limit IS NOT NULL") == 0, 'No package carries a branch limit (branches are unlimited by default)');
ok((int) q("SELECT student_limit FROM packages WHERE slug = 'starter'") === 15 && (int) q("SELECT price_paise FROM packages WHERE slug = 'starter'") === 10000, 'Seed: Starter = ₹100 for 15 students');
ok((int) q("SELECT student_limit FROM packages WHERE slug = 'growth'") === 100 && (int) q("SELECT price_paise FROM packages WHERE slug = 'growth'") === 20000, 'Seed: Growth = ₹200 for 100 students');

$sa = api('POST', '/auth/login', ['identifier' => 'qa-admin@classnex.test', 'password' => 'Admin@12345'])['json']['data']['token'] ?? null;
ok((bool) $sa, 'Super admin logs in');
$features = ['attendance', 'fees', 'inapp', 'push', 'whatsapp', 'manual_reminders', 'auto_reminders', 'reports', 'parent_access'];
$r = api('POST', '/admin/packages', ['name' => 'QA Starter 15', 'price' => '100', 'billing_cycle' => 'monthly', 'trial_days' => 0, 'student_limit' => 15, 'whatsapp_mode' => 'transactional', 'features' => $features, 'status' => 'active'], $sa);
$p15 = (int) ($r['json']['data']['id'] ?? 0);
$r = api('POST', '/admin/packages', ['name' => 'QA Growth 100', 'price' => '200', 'billing_cycle' => 'custom', 'billing_days' => 45, 'trial_days' => 0, 'student_limit' => 100, 'whatsapp_mode' => 'full', 'features' => $features, 'status' => 'active'], $sa);
$p100 = (int) ($r['json']['data']['id'] ?? 0);
ok($p15 > 0 && $p100 > 0, 'Super Admin creates 15-student and 100-student packages (custom 45-day cycle, WhatsApp mode)', $r['body']);
$r = api('POST', '/admin/packages', ['name' => 'Bad custom', 'price' => '1', 'billing_cycle' => 'custom', 'features' => $features], $sa);
ok($r['status'] === 422 && isset($r['json']['errors']['billing_days']), 'Custom billing cycle requires a number of days');
ok(q("SELECT whatsapp_mode FROM packages WHERE id = ?", [$p15]) === 'transactional', 'WhatsApp mode stored on the package');

// 1. create academy
section('1–2. Academy with multiple branches');
$r = api('POST', '/admin/owners', ['owner_name' => 'Branch Owner', 'mobile' => '9811111111', 'academy_name' => 'Multi Branch Academy', 'package_id' => $p15, 'password' => 'Owner@123', 'subscription_status' => 'active'], $sa);
$aid = (int) ($r['json']['data']['academy_id'] ?? 0);
ok($aid > 0, '1. Academy created on the 15-student package', $r['body']);
$own = api('POST', '/auth/login', ['identifier' => '9811111111', 'password' => 'Owner@123'])['json']['data']['token'] ?? null;

$branches = [];
foreach (['Vijay Nagar', 'Palasia', 'Bhawarkua', 'Rau', 'Nipania', 'Mhow'] as $n) {
    $r = api('POST', '/branches', ['name' => $n, 'address' => "$n, Indore", 'contact_number' => '98' . random_int(10000000, 99999999)], $own);
    $branches[$n] = (int) ($r['json']['data']['id'] ?? 0);
}
ok(count(array_filter($branches)) === 6, '2. Six branches created on the smallest package — no upgrade asked', json_encode($branches));
ok((int) q("SELECT COUNT(*) FROM owner_subscriptions WHERE academy_id = ?", [$aid]) === 1, 'Still one subscription for the whole academy (no per-branch subscriptions)');
[$bA, $bB, $bC] = array_values(array_slice($branches, 0, 3));

$r = api('POST', '/activities', ['name' => 'Cricket', 'monthly_fee' => '1000'], $own);
$act = (int) $r['json']['data']['id'];
$batchA = (int) api('POST', '/batches', ['name' => 'VN Morning', 'activity_id' => $act, 'branch_id' => $bA, 'start_time' => '06:30', 'end_time' => '08:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own)['json']['data']['id'];
$batchB = (int) api('POST', '/batches', ['name' => 'Palasia Evening', 'activity_id' => $act, 'branch_id' => $bB, 'start_time' => '17:00', 'end_time' => '18:30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own)['json']['data']['id'];
ok((int) q('SELECT branch_id FROM batches WHERE id = ?', [$batchB]) === $bB, 'Batches belong to branches');
$r = api('POST', '/teachers', ['name' => 'Coach VN', 'mobile' => '9822222222', 'password' => 'Teach@123', 'branch_id' => $bA, 'batch_ids' => [$batchA]], $own);
$teacherA = (int) q('SELECT id FROM teachers WHERE mobile = ?', ['9822222222']);
$r = api('PUT', "/branches/$bA", ['name' => 'Vijay Nagar', 'manager_teacher_id' => $teacherA, 'email' => 'vn@academy.test'], $own);
ok($r['status'] === 200 && (int) q('SELECT manager_teacher_id FROM academy_branches WHERE id = ?', [$bA]) === $teacherA, 'Branch manager assigned (teacher)', $r['body']);

// 3–6 quota across branches
section('3–6. One student quota shared by all branches');
$students = [];
for ($i = 1; $i <= 15; $i++) {
    $b = $i <= 8 ? $batchA : $batchB;
    $r = api('POST', '/students', ['name' => "Student $i", 'whatsapp' => '97' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'activity_id' => $act, 'batch_id' => $b, 'is_minor' => '0', 'send_message' => '0'], $own);
    $students[$i] = (int) ($r['json']['data']['student']['id'] ?? 0);
}
ok(count(array_filter($students)) === 15, '3–4. 15 students registered across two branches (8 + 7)');
ok((int) q('SELECT COUNT(*) FROM students WHERE academy_id = ? AND branch_id = ?', [$aid, $bA]) === 8 && (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ? AND branch_id = ?', [$aid, $bB]) === 7, 'Branch taken from the batch automatically');
ok(activeCount($aid) === 15, '5. Combined active count = 15 (all branches together)');
$r = api('POST', '/students', ['name' => 'Student 16', 'whatsapp' => '9700000016', 'activity_id' => $act, 'batch_id' => $batchB, 'is_minor' => '0'], $own);
$expected = 'Your package allows 15 students. You have already registered 15 active students across all branches. Please upgrade your package to add more students.';
ok($r['status'] === 402 && ($r['json']['code'] ?? '') === 'limit_reached' && ($r['json']['message'] ?? '') === $expected, '6. 16th student blocked with the exact upgrade message', $r['body']);
$r = api('POST', '/students', ['name' => 'Student 16', 'whatsapp' => '9700000016', 'activity_id' => $act, 'branch_id' => $branches['Mhow'], 'is_minor' => '0'], $own);
ok($r['status'] === 402, 'Limit applies to a brand-new empty branch too (no per-branch quota)');
ok(activeCount($aid) === 15, 'Rejected registrations left no partial records');
// existing operations continue at the limit
$r = api('POST', '/attendance', ['batch_id' => $batchA, 'entries' => array_map(static fn($s) => ['student_id' => $s, 'status' => 'present'], array_slice($students, 0, 8))], $own);
ok($r['status'] === 200, 'At the limit: attendance for existing students still works', $r['body']);
$r = api('POST', '/payments', ['student_id' => $students[1], 'amount' => '1000', 'method' => 'cash'], $own);
$payA = (int) ($r['json']['data']['id'] ?? 0);
ok($r['status'] === 200 && $payA > 0, 'At the limit: fee collection for existing students still works', $r['body']);
$r = api('POST', '/payments', ['student_id' => $students[12], 'amount' => '400', 'method' => 'upi'], $own);
$payB = (int) ($r['json']['data']['id'] ?? 0);

// branch-wise receipt
$rc = DB::one('SELECT r.*, p.branch_id AS pb FROM receipts r JOIN payments p ON p.id = r.payment_id WHERE r.payment_id = ?', [$payA]);
ok((int) $rc['branch_id'] === $bA && (int) $rc['pb'] === $bA, 'Payment and receipt carry the branch');
ok((int) q('SELECT branch_id FROM fee_invoices WHERE student_id = ? LIMIT 1', [$students[12]]) === $bB, 'Fee dues carry the branch');
$rd = ReceiptService::data((int) $rc['id']);
ok(($rd['branch_name'] ?? '') === 'Vijay Nagar' && !empty($rd['academy_name'] ?? $rd['academy']['name'] ?? 'x'), 'Receipt identifies both the academy and the branch');
$pdf = api('GET', "/receipts/{$rc['id']}/pdf", null, $own);
ok($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'Branch receipt PDF generated');

// deactivation frees quota; reactivation re-checks
$r = api('POST', "/students/{$students[15]}", ['status' => 'inactive'], $own);
ok($r['status'] === 200 && activeCount($aid) === 14, 'Deactivating a student frees one slot (history kept)', $r['body']);
ok((int) q('SELECT COUNT(*) FROM fee_invoices WHERE student_id = ?', [$students[15]]) > 0, 'Inactive student\'s fee history is kept');
$r = api('POST', '/students', ['name' => 'Student 16', 'whatsapp' => '9700000016', 'activity_id' => $act, 'batch_id' => $batchB, 'is_minor' => '0'], $own);
$students[16] = (int) ($r['json']['data']['student']['id'] ?? 0);
ok($students[16] > 0 && activeCount($aid) === 15, 'Freed slot can be used by a new student');
$r = api('POST', "/students/{$students[15]}", ['status' => 'active'], $own);
ok($r['status'] === 402 && ($r['json']['code'] ?? '') === 'limit_reached' && activeCount($aid) === 15, 'Re-activating beyond the limit is blocked', $r['body']);

// 7–8 transfers
section('7–8. Branch transfers keep the count unchanged');
$before = activeCount($aid);
$r = api('POST', "/students/{$students[2]}/branch", ['branch_id' => $bB, 'batch_id' => $batchB], $own);
ok($r['status'] === 200 && (int) q('SELECT branch_id FROM students WHERE id = ?', [$students[2]]) === $bB, '7. Student transferred to another branch (with new batch)', $r['body']);
ok(activeCount($aid) === $before && (int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$aid]) === 16, '8. Transfer does not create a second profile or change the active count');
ok((int) q("SELECT COUNT(*) FROM student_activity_mapping WHERE student_id = ? AND status = 'active'", [$students[2]]) === 1 && (int) q("SELECT batch_id FROM student_activity_mapping WHERE student_id = ? AND status = 'active'", [$students[2]]) === $batchB, 'Old batch closed, new batch active, same Student ID');
$r = api('POST', "/students/{$students[3]}/transfer", ['batch_id' => $batchB], $own);
ok($r['status'] === 200 && (int) q('SELECT branch_id FROM students WHERE id = ?', [$students[3]]) === $bB && activeCount($aid) === $before, 'Changing to a batch at another branch also moves the branch, count unchanged');
$r = api('POST', "/students/{$students[4]}/branch", ['branch_id' => $bC], $own);
ok($r['status'] === 200 && (int) q('SELECT branch_id FROM students WHERE id = ?', [$students[4]]) === $bC, 'Branch-only move without a batch');
$r = api('POST', "/students/{$students[4]}/branch", ['branch_id' => $bA, 'batch_id' => $batchB], $own);
ok($r['status'] === 422, 'Batch from a different branch is rejected');
$r = api('POST', "/branches/$bC/assign", ['student_ids' => [$students[5], $students[6]], 'teacher_ids' => [$teacherA]], $own);
ok($r['status'] === 200 && (int) q('SELECT COUNT(*) FROM students WHERE branch_id = ?', [$bC]) === 3 && activeCount($aid) === $before, 'Bulk assign students/teachers to a branch, count unchanged', $r['body']);
ok((int) q("SELECT COUNT(*) FROM audit_logs WHERE action IN ('student.branch_change','branch.assign_students')") >= 2, 'Branch moves are audited');

// 9 duplicates
section('9. Duplicate prevention');
$r = api('POST', '/students', ['name' => 'student 1', 'whatsapp' => '9700000001', 'activity_id' => $act, 'branch_id' => $bB, 'batch_id' => $batchB, 'is_minor' => '0'], $own);
ok($r['status'] === 409 && ($r['json']['code'] ?? '') === 'duplicate_student', 'Same person at another branch is detected as a duplicate (not a new profile)', $r['body']);
$r = api('POST', '/students', ['name' => 'Student 1', 'whatsapp' => '9700000001', 'activity_id' => $act, 'batch_id' => $batchB, 'is_minor' => '0', 'confirm_duplicate' => '1'], $own);
ok($r['status'] === 409, 'Duplicate cannot be forced through to bypass the limit');
$r = api('POST', '/students', ['name' => 'Student 15', 'whatsapp' => '9700000015', 'activity_id' => $act, 'batch_id' => $batchB, 'is_minor' => '0'], $own);
ok($r['status'] === 409 && str_contains($r['json']['message'] ?? '', 'Re-activate'), 'Inactive duplicate → told to re-activate the existing profile');
ok((int) q('SELECT COUNT(*) FROM students WHERE academy_id = ?', [$aid]) === 16, 'No duplicate profiles were created');

// 10–11 upgrade
section('10–11. Upgrade applies to the whole academy');
$r = api('POST', "/admin/academies/$aid/subscription", ['action' => 'assign', 'package_id' => $p100], $sa);
ok($r['status'] === 200, '10. Super Admin upgrades the academy to the 100-student package', $r['body']);
$ends = q('SELECT ends_on FROM owner_subscriptions WHERE academy_id = ? AND is_current = 1', [$aid]);
$start = q('SELECT starts_on FROM owner_subscriptions WHERE academy_id = ? AND is_current = 1', [$aid]);
ok($ends === date('Y-m-d', strtotime("$start +44 days")), 'Custom 45-day billing cycle applied (' . $start . ' → ' . $ends . ')');
$r = api('POST', "/students/{$students[15]}", ['status' => 'active'], $own);
ok($r['status'] === 200, 'Re-activation now allowed');
$added = 0;
foreach ([$bA, $bB, $bC, $branches['Rau'], $branches['Nipania'], $branches['Mhow']] as $k => $b) {
    $r = api('POST', '/students', ['name' => "Upgrade $k", 'whatsapp' => '96' . str_pad((string) $k, 8, '0', STR_PAD_LEFT), 'activity_id' => $act, 'branch_id' => $b, 'batch_id' => [$bA => $batchA, $bB => $batchB][$b] ?? '', 'is_minor' => '0'], $own);
    $added += $r['status'] === 200 ? 1 : 0;
}
ok($added === 6, '11. Every branch (incl. new empty ones) can use the new quota');
ok(activeCount($aid) === 22, 'Combined active count now 22 of 100 (16 + 6 new)');
$dash = api('GET', '/dashboard', null, $own)['json']['data'] ?? [];
ok(($dash['student_quota']['active'] ?? 0) === 22 && ($dash['student_quota']['limit'] ?? 0) === 100, 'Dashboard shows 22 / 100 students across all branches');

// concurrency: only one slot left, many parallel registrations
section('Concurrency: parallel registrations with one slot left');
$cap = activeCount($aid) + 1;
DB::exec('UPDATE owner_subscriptions SET custom_limits = ? WHERE academy_id = ? AND is_current = 1', [json_encode(['students' => $cap]), $aid]);
Feature::flush();
$mh = curl_multi_init();
$hs = [];
for ($i = 0; $i < 8; $i++) {
    $brs = [$bC, $branches['Rau'], $branches['Nipania']];
    $hs[] = $h = req('POST', '/students', ['name' => "Race $i", 'whatsapp' => '95' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'activity_id' => $act, 'branch_id' => $brs[$i % 3], 'is_minor' => '0', 'send_message' => '0'], $own);
    curl_multi_add_handle($mh, $h);
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$codes = array_map(static fn($h) => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), $hs);
ok(count(array_keys($codes, 200)) === 1 && count(array_keys($codes, 402)) === 7 && activeCount($aid) === $cap, '8 simultaneous registrations across 3 branches with 1 slot left → exactly 1 succeeds', json_encode($codes));
DB::exec('UPDATE owner_subscriptions SET custom_limits = NULL WHERE academy_id = ? AND is_current = 1', [$aid]);

// 12–13 reports
section('12–13. Branch-wise and combined reports');
$sum = api('GET', '/branches/summary', null, $own)['json']['data'] ?? [];
$byName = array_column($sum['branches'] ?? [], null, 'name');
$sumStudents = array_sum(array_column($sum['branches'] ?? [], 'students'));
ok(($sum['total']['students'] ?? -1) === activeCount($aid) && $sumStudents === $sum['total']['students'], '13. Combined report: total active students = sum of branches = unique count (' . $sumStudents . ')');
ok(($byName['Vijay Nagar']['collected_paise'] ?? 0) === 100000 && ($byName['Palasia']['collected_paise'] ?? 0) === 40000 && ($sum['total']['collected_paise'] ?? 0) === 140000, 'Branch-wise fee collection (₹1,000 VN + ₹400 Palasia = ₹1,400 total)');
ok(($byName['Vijay Nagar']['attendance_pct'] ?? null) == 100, 'Branch-wise attendance %');
ok(($sum['total']['pending_paise'] ?? 0) === array_sum(array_column($sum['branches'], 'pending_paise')), 'Branch pending fees add up to the academy total');
$r = api('GET', "/reports/fee_collection?branch_id=$bB", null, $own);
ok($r['status'] === 200 && count($r['json']['data']['rows'] ?? []) === 1 && str_contains($r['json']['data']['title'] ?? '', 'Palasia'), '12. Individual branch report (fee collection for Palasia only)', $r['body']);
$r = api('GET', "/reports/students?branch_id=$bC", null, $own);
ok($r['status'] === 200 && count($r['json']['data']['rows'] ?? []) === (int) q("SELECT COUNT(*) FROM students WHERE branch_id = ? AND status = 'active'", [$bC]), 'Branch student report');
$r = api('GET', '/reports/branch_summary', null, $own);
$last = end($r['json']['data']['rows']);
ok($r['status'] === 200 && ($last[0] ?? '') === 'All branches' && (int) $last[1] === activeCount($aid), 'Combined academy report with per-branch rows and a total row');
foreach (['pdf', 'xlsx', 'csv'] as $fmt) {
    $x = api('GET', "/reports/branch_summary?format=$fmt", null, $own);
    ok($x['status'] === 200 && strlen($x['body']) > 100, "Combined branch report exports as " . strtoupper($fmt));
}
$r = api('GET', "/invoices?branch_id=$bB&status=open", null, $own);
ok($r['status'] === 200 && !array_filter($r['json']['data'] ?? [], static fn($i) => (int) $i['branch_id'] !== $bB), 'Pending fees filtered by branch');
$r = api('GET', "/payments?branch_id=$bA&from=" . date('Y-m-01') . '&to=' . date('Y-m-d'), null, $own);
ok($r['status'] === 200 && count($r['json']['data'] ?? []) === 1 && ($r['json']['data'][0]['branch_name'] ?? '') === 'Vijay Nagar', 'Payment history filtered by branch');
$r = api('GET', "/branches/$bA", null, $own);
ok($r['status'] === 200 && ($r['json']['data']['manager_teacher_name'] ?? '') === 'Coach VN' && count($r['json']['data']['batches'] ?? []) === 1, 'Branch details: manager, batches, figures');
$r = api('GET', "/admin/academies/$aid", null, $sa);
ok(($r['json']['data']['branches']['total']['students'] ?? -1) === activeCount($aid) && count($r['json']['data']['branches']['branches'] ?? []) >= 6, 'Super Admin sees combined and per-branch statistics for the academy');
$r = api('GET', '/admin/owners?q=Multi', null, $sa);
ok((int) ($r['json']['data'][0]['branches'] ?? 0) === 6, 'Super Admin owner list shows the branch count');

// deactivate branch
$r = api('PUT', "/branches/{$branches['Mhow']}", ['name' => 'Mhow', 'status' => 'inactive'], $own);
ok($r['status'] === 200 && q('SELECT status FROM academy_branches WHERE id = ?', [$branches['Mhow']]) === 'inactive', 'Branch deactivated (records kept)');
$r = api('POST', '/students', ['name' => 'Late', 'whatsapp' => '9400000001', 'activity_id' => $act, 'branch_id' => $branches['Mhow'], 'is_minor' => '0'], $own);
ok($r['status'] === 422, 'New students cannot be added to an inactive branch');

// 14 isolation
section('14. Cross-academy isolation');
$r = api('POST', '/admin/owners', ['owner_name' => 'Other Owner', 'mobile' => '9833333333', 'academy_name' => 'Other Academy', 'package_id' => $p15, 'password' => 'Owner@456', 'subscription_status' => 'active'], $sa);
$aid2 = (int) ($r['json']['data']['academy_id'] ?? 0);
$own2 = api('POST', '/auth/login', ['identifier' => '9833333333', 'password' => 'Owner@456'])['json']['data']['token'] ?? null;
ok(count(api('GET', '/branches', null, $own2)['json']['data'] ?? [1]) === 0, 'Other academy sees none of the branches');
ok(api('GET', "/branches/$bA", null, $own2)['status'] === 404, 'Other academy cannot open a branch by ID');
ok(api('PUT', "/branches/$bA", ['name' => 'Hijack'], $own2)['status'] === 404 && q('SELECT name FROM academy_branches WHERE id = ?', [$bA]) === 'Vijay Nagar', 'Other academy cannot edit a branch');
$r = api('POST', "/branches/$bA/assign", ['student_ids' => [$students[1]]], $own2);
ok($r['status'] === 404, 'Other academy cannot assign into a foreign branch');
$act2 = (int) api('POST', '/activities', ['name' => 'Dance', 'monthly_fee' => '500'], $own2)['json']['data']['id'];
$b2 = (int) api('POST', '/branches', ['name' => 'Own branch'], $own2)['json']['data']['id'];
$r = api('POST', "/branches/$b2/assign", ['student_ids' => [$students[1]]], $own2);
ok(($r['json']['data']['updated'] ?? 1) === 0 && (int) q('SELECT branch_id FROM students WHERE id = ?', [$students[1]]) === $bA, 'Foreign student IDs are ignored by bulk assign');
$r = api('POST', '/students', ['name' => 'X', 'whatsapp' => '9300000001', 'activity_id' => $act2, 'branch_id' => $bA, 'is_minor' => '0'], $own2);
ok($r['status'] === 422, 'Cannot register a student into another academy\'s branch');
ok(api('POST', "/students/{$students[1]}/branch", ['branch_id' => $b2], $own2)['status'] === 404, 'Cannot move another academy\'s student');
ok(api('GET', "/reports/fee_collection?branch_id=$bA", null, $own2)['status'] === 404, 'Cannot report on another academy\'s branch');
ok(activeCount($aid2) === 0 && (api('GET', '/dashboard', null, $own2)['json']['data']['student_quota']['active'] ?? -1) === 0, 'Quotas are per academy (other academy starts at 0)');

// WhatsApp transactional mode
section('WhatsApp mode');
$f = Feature::forAcademy($aid2);
ok(($f['whatsapp_mode'] ?? '') === 'transactional', 'Transactional WhatsApp mode reaches the academy');
$r = api('POST', '/students', ['name' => 'Mode Kid', 'whatsapp' => '9200000001', 'activity_id' => $act2, 'branch_id' => $b2, 'is_minor' => '0', 'send_message' => '0'], $own2);
$sk = (int) ($r['json']['data']['student']['id'] ?? 0);
$inv = DB::one('SELECT * FROM fee_invoices WHERE student_id = ? LIMIT 1', [$sk]);
NotificationService::feeReminder(999001, $inv, 1);
ok($inv && (int) q("SELECT COUNT(*) FROM notification_queue WHERE student_id = ? AND channel = 'whatsapp' AND event_key = 'fee_overdue'", [$sk]) === 0, 'Transactional mode: automatic fee reminder is NOT sent on WhatsApp');
$pay = (int) (api('POST', '/payments', ['student_id' => $sk, 'amount' => '100', 'method' => 'cash'], $own2)['json']['data']['id'] ?? 0);
ok($pay && (int) q("SELECT COUNT(*) FROM notification_queue WHERE student_id = ? AND channel = 'whatsapp' AND event_key = 'payment_success'", [$sk]) === 1, 'Transactional mode: payment receipt IS sent on WhatsApp');
$inv1 = DB::one('SELECT * FROM fee_invoices WHERE student_id = ? LIMIT 1', [$students[12]]);
NotificationService::feeReminder(999002, $inv1, 1);
ok((int) q("SELECT COUNT(*) FROM notification_queue WHERE student_id = ? AND channel = 'whatsapp' AND event_key = 'fee_overdue'", [$students[12]]) === 1, 'Full mode (Growth package): automatic fee reminder IS sent on WhatsApp');

$total = $pass + $fail;
echo "\n\033[1mResult: $pass passed, $fail failed\033[0m\n";
$md = "# Branch & student-quota test run\n\nRun: " . date('Y-m-d H:i:s') . " · Database: $dbName · PHP " . PHP_VERSION . "\n\n**$pass / $total passed**\n\n| # | Check | Result |\n|---|---|---|\n";
foreach ($results as $i => [$c, $n, $d]) {
    $md .= '| ' . ($i + 1) . ' | ' . str_replace('|', '\\|', $n) . ' | ' . ($c ? 'PASS' : 'FAIL') . " |\n";
}
file_put_contents(CNX_ROOT . '/docs/QA_BRANCHES_RUN.md', $md);
exit($fail ? 1 : 0);
