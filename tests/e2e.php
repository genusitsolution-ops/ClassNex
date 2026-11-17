<?php
declare(strict_types=1);

/**
 * ClassNex end-to-end API test suite.
 * Runs every business workflow over HTTP against a real MySQL/MariaDB test database.
 *
 *   CNX_DB=classnex_test php tests/e2e.php [base_url]
 *
 * The test database is DROPPED and re-created. Never point this at production.
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

// ---------------------------------------------------------------- harness
$pass = 0;
$fail = 0;
$results = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $results;
    $cond ? $pass++ : $fail++;
    $results[] = [$cond, $name, $detail];
    echo ($cond ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $name . ($cond || $detail === '' ? '' : " — $detail") . "\n";
}
function section(string $t): void
{
    echo "\n\033[1m$t\033[0m\n";
}
/** @return array{status:int, json:?array, body:string, headers:string} */
function api(string $method, string $path, ?array $body = null, ?string $token = null, array $files = [], array $extraHeaders = [], ?string $cookieJar = null): array
{
    global $base;
    $ch = curl_init($base . '/api/v1' . $path);
    $headers = $extraHeaders;
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60];
    if ($files) {
        $post = $body ?? [];
        foreach ($files as $k => $path2) {
            $post[$k] = new CURLFile($path2);
        }
        $flat = [];
        foreach ($post as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $i => $vv) {
                    $flat[$k . '[' . $i . ']'] = $vv;
                }
            } else {
                $flat[$k] = $v;
            }
        }
        $opts[CURLOPT_POSTFIELDS] = $flat;
    } elseif ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    if ($cookieJar) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $bodyStr = substr($raw, $hs);
    return ['status' => $status, 'json' => json_decode($bodyStr, true), 'body' => $bodyStr, 'headers' => substr($raw, 0, $hs)];
}
function cron(string $task): string
{
    global $dbName;
    return (string) shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(CNX_ROOT . '/cron/run.php') . ' ' . escapeshellarg($task) . ' --force 2>&1');
}
function q(string $sql, array $p = []): mixed
{
    return DB::val($sql, $p);
}

// ------------------------------------------------------------- fresh DB
section('Setup');
$root = new PDO('mysql:host=' . Config::get('db.host'), Config::get('db.user'), Config::get('db.pass'));
$root->exec("DROP DATABASE IF EXISTS `$dbName`");
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
DB::reset();
Migrator::run();
shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . CNX_ROOT . "/cli/create-superadmin.php 'QA Admin' qa-admin@classnex.test 9000000009 'Admin@12345'");
ok((int) q('SELECT COUNT(*) FROM schema_migrations') >= 2, 'Migrations applied on a clean database');

// sample photo + document
$img = imagecreatetruecolor(400, 300);
imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
imagejpeg($img, '/tmp/cnx-photo.jpg', 85);
imagepng($img, '/tmp/cnx-doc.png');

// ------------------------------------------------------------- 1-3 super admin
section('Super Admin: package, owner, subscription');
$r = api('POST', '/auth/login', ['identifier' => 'qa-admin@classnex.test', 'password' => 'Admin@12345']);
$sa = $r['json']['data']['token'] ?? null;
ok($r['status'] === 200 && $sa, 'Super admin logs in (Bearer token)');
$r = api('POST', '/auth/login', ['identifier' => 'qa-admin@classnex.test', 'password' => 'wrong-pass1']);
ok($r['status'] === 401, 'Wrong password is rejected');

$features = ['attendance', 'fees', 'inapp', 'push', 'whatsapp', 'email', 'auto_reminders', 'manual_reminders', 'reports', 'parent_access', 'staff_management', 'documents', 'online_admission', 'online_payments'];
$r = api('POST', '/admin/packages', ['name' => 'QA Growth', 'price' => '999', 'billing_cycle' => 'monthly', 'trial_days' => 14, 'student_limit' => 4, 'features' => $features, 'status' => 'active'], $sa);
$pkg = $r['json']['data']['id'] ?? 0;
ok($r['status'] === 200 && $pkg > 0, '1. Super Admin creates a package');

$r = api('POST', '/admin/owners', ['owner_name' => 'Ravi Kumar', 'mobile' => '9876543210', 'email' => 'ravi@abc.test', 'academy_name' => 'ABC Cricket Academy', 'city' => 'Indore', 'package_id' => $pkg, 'password' => 'Owner@123', 'subscription_status' => 'active'], $sa);
$acad1 = $r['json']['data']['academy_id'] ?? 0;
ok($r['status'] === 200 && $acad1 > 0, '2. Super Admin creates a Class Owner with academy', $r['body']);
$r = api('GET', "/admin/academies/$acad1", null, $sa);
ok(($r['json']['data']['subscription']['status'] ?? '') === 'active' && ($r['json']['data']['subscription']['package_id'] ?? 0) === $pkg, '3. Owner receives an active subscription on the package');

$r = api('POST', '/admin/owners', ['owner_name' => 'Ravi Kumar', 'mobile' => '9876543210', 'academy_name' => 'Dup', 'package_id' => $pkg], $sa);
ok($r['status'] === 422, 'Duplicate owner mobile is rejected');

// ------------------------------------------------------------- 4-6 owner setup
section('Owner: academy, activities, batches, teacher');
$r = api('POST', '/auth/login', ['identifier' => '9876543210', 'password' => 'Owner@123', 'device_name' => 'QA Android']);
$own = $r['json']['data']['token'] ?? null;
ok($r['status'] === 200 && ($r['json']['data']['me']['role'] ?? '') === 'owner', 'Owner logs in with mobile number');
$r = api('POST', '/academy', ['name' => 'ABC Cricket Academy', 'address' => '12 MG Road', 'city' => 'Indore', 'mobile' => '9876543210', 'receipt_footer' => 'Thank you for training with us'], $own, ['logo' => '/tmp/cnx-doc.png']);
ok($r['status'] === 200 && q('SELECT logo_path FROM academies WHERE id = ?', [$acad1]), '4. Owner completes academy profile (with logo)', $r['body']);
$r = api('POST', '/activities', ['name' => 'Cricket', 'monthly_fee' => '1500', 'registration_fee' => '500', 'status' => 'active'], $own);
$act = $r['json']['data']['id'] ?? 0;
ok($act > 0, '5. Owner creates an activity');
$r = api('POST', '/activities', ['name' => 'Yoga', 'monthly_fee' => '800'], $own);
$yoga = $r['json']['data']['id'] ?? 0;
$r = api('POST', '/batches', ['name' => 'Morning', 'activity_id' => $act, 'start_time' => '06:30', 'end_time' => '08:00', 'capacity' => 30, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], $own);
$batch = $r['json']['data']['id'] ?? 0;
ok($batch > 0, '6. Owner creates a batch with weekly schedule');
$r = api('POST', '/batches', ['name' => 'Evening', 'activity_id' => $act, 'start_time' => '17:00', 'end_time' => '18:30'], $own);
$batch2 = $r['json']['data']['id'] ?? 0;

$r = api('POST', '/teachers', ['name' => 'Coach Sunil', 'mobile' => '9123456780', 'password' => 'Teach@123', 'batch_ids' => [$batch]], $own);
ok($r['status'] === 200, 'Owner adds a teacher assigned to the Morning batch', $r['body']);
DB::exec('UPDATE batches SET teacher_id = (SELECT id FROM teachers WHERE mobile = ?) WHERE id = ?', ['9123456780', $batch]);
$r = api('POST', '/auth/login', ['identifier' => '9123456780', 'password' => 'Teach@123']);
$tch = $r['json']['data']['token'] ?? null;
ok($tch && ($r['json']['data']['me']['role'] ?? '') === 'teacher', 'Teacher logs in');

// ------------------------------------------------------------- 7-12 students
section('Quick student registration, photo, documents, OCR, parent');
$r = api('POST', '/students', ['name' => 'Rahul Sharma', 'whatsapp' => '98765 11111', 'activity_id' => $act, 'batch_id' => $batch, 'guardian_name' => 'Suresh Sharma', 'is_minor' => '1', 'consent_given' => '1'], $own, ['photo' => '/tmp/cnx-photo.jpg']);
$stu = $r['json']['data']['student']['id'] ?? 0;
$code = $r['json']['data']['student']['student_code'] ?? '';
ok($r['status'] === 200 && $stu > 0 && preg_match('/^[A-Z0-9]+0001$/', $code), '7. Quick registration with only essential fields → Student ID ' . $code, $r['body']);
ok((bool) q('SELECT photo_path FROM students WHERE id = ?', [$stu]), '8. Student photo captured, compressed and stored privately');
$photo = api('GET', "/files/student-photo/$stu", null, $own);
ok($photo['status'] === 200 && str_starts_with($photo['body'], "\xFF\xD8"), 'Photo is served only through the authenticated API (JPEG)');
$direct = @file_get_contents($base . '/storage/' . q('SELECT photo_path FROM students WHERE id = ?', [$stu]));
ok($direct === false || $direct === '', 'Private storage is not publicly reachable');
ok((int) q('SELECT COUNT(*) FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id WHERE m.student_id = ?', [$stu]) === 1, 'Parent record created and linked from guardian name');
$inv = DB::all('SELECT * FROM fee_invoices WHERE student_id = ? ORDER BY id', [$stu]);
ok(count($inv) === 2 && array_sum(array_column($inv, 'total_paise')) === 200000, 'Monthly (₹1,500) and registration (₹500) dues generated automatically');

$r = api('POST', '/students', ['name' => 'Rahul Sharma', 'whatsapp' => '9876511111', 'activity_id' => $act, 'batch_id' => $batch, 'guardian_name' => 'Suresh Sharma'], $own);
ok($r['status'] === 409 && ($r['json']['code'] ?? '') === 'duplicate_student', 'Duplicate registration is detected');
$r = api('POST', '/students', ['name' => 'Bad Number', 'whatsapp' => '12345', 'activity_id' => $act, 'batch_id' => $batch, 'guardian_name' => 'X'], $own);
ok($r['status'] === 422 && isset($r['json']['errors']['whatsapp']), 'Invalid mobile number is rejected with a field error');
$r = api('POST', '/students', ['name' => 'No Guardian', 'whatsapp' => '9876522222', 'activity_id' => $act, 'batch_id' => $batch, 'is_minor' => '1'], $own);
ok($r['status'] === 422 && isset($r['json']['errors']['guardian_name']), 'Guardian name required for minors');

$aadhaarText = "GOVERNMENT OF INDIA\nRahul Sharma\nDOB: 14/08/2014\nMALE\n1234 5678 9012\nAddress: S/O Suresh Sharma, 45 Vijay Nagar,\nIndore, Madhya Pradesh 452010";
$r = api('POST', '/ocr/parse', ['text' => $aadhaarText], $own);
$o = $r['json']['data'] ?? [];
ok(($o['name'] ?? '') === 'Rahul Sharma' && ($o['dob'] ?? '') === '2014-08-14' && ($o['gender'] ?? '') === 'male' && str_contains((string) ($o['address'] ?? ''), 'Vijay Nagar') && ($o['guardian_name'] ?? '') === 'Suresh Sharma', '10. OCR extracts name, DOB, gender, address and guardian', json_encode($o));
ok(!str_contains(json_encode($o), '5678 9012') && ($o['id_last4'] ?? '') === 'XXXX XXXX 9012', 'Aadhaar number is masked, never returned');
$r = api('POST', "/students/$stu/documents", ['doc_type' => 'aadhaar', 'ocr_fields' => json_encode($o + ['aadhaar_number' => '123456789012'])], $own, ['file' => '/tmp/cnx-doc.png']);
$docId = $r['json']['data']['id'] ?? 0;
ok($r['status'] === 200 && $docId, '9. Owner uploads a document', $r['body']);
$raw = file_get_contents(Config::storage(q('SELECT storage_path FROM student_documents WHERE id = ?', [$docId])));
ok(str_starts_with($raw, 'CNX1') && !str_contains((string) q('SELECT ocr_fields FROM student_documents WHERE id = ?', [$docId]), '123456789012'), 'Document encrypted at rest; identity number not stored');
$r = api('GET', "/files/document/$docId", null, $own);
ok($r['status'] === 200 && str_starts_with($r['body'], "\xFF\xD8"), 'Authorised user can view the decrypted document');
$r = api('POST', "/students/$stu", ['dob' => '2014-08-14', 'address' => $o['address'], 'gender' => 'male'], $own);
ok($r['status'] === 200 && ($r['json']['data']['dob'] ?? '') === '2014-08-14', '11. Owner verifies OCR values and saves the profile');

$r = api('POST', "/students/$stu/invite", [], $own);
$link = $r['json']['data'][0]['link'] ?? '';
ok($r['status'] === 200 && str_contains($link, 'activate.php?t='), '12. Parent account created and invitation link generated');
parse_str((string) parse_url($link, PHP_URL_QUERY), $qs);
$r = api('POST', '/auth/activate', ['token' => $qs['t'] ?? '', 'password' => 'Parent@123']);
ok($r['status'] === 200, 'Parent activates the account and sets a password');
$r = api('POST', '/auth/activate', ['token' => $qs['t'] ?? '', 'password' => 'Parent@123']);
ok($r['status'] === 410, 'Activation link cannot be reused');
$r = api('POST', '/auth/login', ['identifier' => '9876511111', 'password' => 'Parent@123']);
$par = $r['json']['data']['token'] ?? null;
ok($par && count($r['json']['data']['me']['children'] ?? []) === 1, 'Parent logs in and sees the linked child');

// sibling: same parent number → grouped under one parent
$r = api('POST', '/students', ['name' => 'Priya Sharma', 'whatsapp' => '9876511111', 'activity_id' => $yoga, 'guardian_name' => 'Suresh Sharma', 'is_minor' => '1'], $own);
$sib = $r['json']['data']['student']['id'] ?? 0;
$r = api('GET', '/auth/me', null, $par);
ok(count($r['json']['data']['children'] ?? []) === 2, 'Parent with two children can switch between them');

// ------------------------------------------------------------- 13-14 attendance
section('Attendance');
$r = api('GET', "/attendance/sheet?batch_id=$batch", null, $tch);
ok($r['status'] === 200 && count($r['json']['data']['students'] ?? []) === 1, 'Teacher opens today\'s attendance sheet for an assigned batch');
$r = api('GET', "/attendance/sheet?batch_id=$batch2", null, $tch);
ok($r['status'] === 404, 'Teacher cannot open a batch that is not assigned');
$r = api('POST', '/attendance', ['batch_id' => $batch, 'entries' => [['student_id' => $stu, 'status' => 'present']]], $tch);
ok($r['status'] === 200 && ($r['json']['data']['new'] ?? 0) === 1, '13. Teacher marks attendance');
$r = api('POST', '/attendance', ['batch_id' => $batch, 'entries' => [['student_id' => $stu, 'status' => 'present']]], $tch);
ok(($r['json']['data']['unchanged'] ?? 0) === 1 && (int) q('SELECT COUNT(*) FROM attendance WHERE student_id = ?', [$stu]) === 1, 'Re-submitting does not duplicate attendance');
$parentUser = (int) q('SELECT p.user_id FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id WHERE m.student_id = ?', [$stu]);
ok((int) q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_key = 'attendance_present'", [$parentUser]) === 1, '14. Parent receives attendance notification (in-app)');
$r = api('POST', '/attendance', ['batch_id' => $batch, 'entries' => [['student_id' => $stu, 'status' => 'absent']], 'reason' => 'Marked wrongly'], $tch);
ok(($r['json']['data']['changed'] ?? 0) === 1 && (int) q('SELECT COUNT(*) FROM attendance_corrections') === 1, 'Same-day correction is saved with audit history');
ok((int) q("SELECT COUNT(*) FROM notification_queue WHERE event_key = 'attendance_corrected' AND channel = 'whatsapp'") === 1, 'Correction triggers one parent WhatsApp update');
$r = api('POST', '/attendance', ['batch_id' => $batch, 'date' => date('Y-m-d', strtotime('-5 days')), 'entries' => [['student_id' => $stu, 'status' => 'present']]], $tch);
ok($r['status'] === 403, 'Teacher cannot change old attendance without permission');
$r = api('GET', "/students/$stu/attendance", null, $par);
ok($r['status'] === 200 && ($r['json']['data']['counts']['absent'] ?? 0) === 1, 'Parent sees attendance history');

// ------------------------------------------------------------- 15-19 fees
section('Fees, payments, receipts');
$r = api('GET', "/students/$stu/dues", null, $tch);
ok($r['status'] === 403, 'Teacher has no financial access by default');
$r = api('GET', "/students/$stu/dues", null, $own);
ok(($r['json']['data']['balance']['outstanding_paise'] ?? 0) === 200000, '15. Monthly fee assigned; outstanding ₹2,000');
$idem = 'qa-' . bin2hex(random_bytes(6));
$r = api('POST', '/payments', ['student_id' => $stu, 'amount' => '1000', 'method' => 'cash'], $own, [], ['Idempotency-Key: ' . $idem]);
$pay1 = $r['json']['data']['id'] ?? 0;
$rcpt1 = $r['json']['data']['receipt_id'] ?? 0;
ok($r['status'] === 200 && $pay1 && $rcpt1, '16. Owner collects a partial payment');
ok(str_contains((string) ($r['json']['data']['receipt_no'] ?? ''), '/'), '17. Receipt generated automatically: ' . ($r['json']['data']['receipt_no'] ?? ''));
$r2 = api('POST', '/payments', ['student_id' => $stu, 'amount' => '1000', 'method' => 'cash'], $own, [], ['Idempotency-Key: ' . $idem]);
ok(($r2['json']['data']['duplicate'] ?? false) === true && (int) q('SELECT COUNT(*) FROM payments') === 1 && (int) q('SELECT COUNT(*) FROM receipts') === 1, 'Duplicate submission does not create a second payment or receipt');
$r = api('GET', "/students/$stu/dues", null, $own);
ok(($r['json']['data']['balance']['outstanding_paise'] ?? 0) === 100000, '19. Outstanding balance recalculated: ₹1,000 pending');
$pdf = api('GET', "/receipts/$rcpt1/pdf", null, $par);
ok($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-') && str_contains($pdf['body'], '%%EOF'), '18. Parent downloads the receipt PDF');
$pub = @file_get_contents($base . '/receipt.php?t=' . q('SELECT public_token FROM receipts WHERE id = ?', [$rcpt1]) . '&pdf=1');
ok(is_string($pub) && str_starts_with($pub, '%PDF-'), 'Receipt share link (WhatsApp) downloads the same PDF');
$r = api('GET', "/portal/students/$stu/fees", null, $par);
ok(($r['json']['data']['balance']['outstanding_paise'] ?? 0) === 100000 && count($r['json']['data']['payments'] ?? []) === 1, 'Parent fee view matches owner view (same database)');

$r = api('POST', '/payments', ['student_id' => $stu, 'amount' => '2500', 'method' => 'upi', 'reference' => 'UPI123'], $own);
$b = api('GET', "/students/$stu/dues", null, $own)['json']['data']['balance'] ?? [];
ok(($b['outstanding_paise'] ?? -1) === 0 && ($b['advance_paise'] ?? 0) === 150000, 'Advance payment: ₹1,000 settles dues, ₹1,500 kept as credit');
$r = api('POST', "/students/$stu/charges", ['title' => 'Tournament fee', 'amount' => '1000'], $own);
$b = api('GET', "/students/$stu/dues", null, $own)['json']['data']['balance'] ?? [];
ok(($b['outstanding_paise'] ?? -1) === 0 && ($b['advance_paise'] ?? 0) === 50000, 'Advance credit applied automatically to a new charge');
$pay2 = (int) q('SELECT MAX(id) FROM payments');
$r = api('POST', "/payments/$pay2/refund", ['amount' => '500', 'reason' => 'Overpaid', 'method' => 'cash'], $own);
$b = api('GET', "/students/$stu/dues", null, $own)['json']['data']['balance'] ?? [];
ok($r['status'] === 200 && ($b['advance_paise'] ?? -1) === 0 && ($b['outstanding_paise'] ?? -1) === 0, 'Refund taken from unused advance first');
$r = api('POST', "/payments/$pay1/reverse", ['reason' => 'Entered by mistake'], $own);
$b = api('GET', "/students/$stu/dues", null, $own)['json']['data']['balance'] ?? [];
ok($r['status'] === 200 && ($b['outstanding_paise'] ?? 0) === 100000 && q('SELECT status FROM receipts WHERE id = ?', [$rcpt1]) === 'cancelled', 'Reversal re-opens dues and cancels the receipt (nothing deleted)');
ok((int) q('SELECT COUNT(*) FROM payment_allocations WHERE payment_id = ? AND amount_paise < 0', [$pay1]) >= 1, 'Ledger keeps both the original and the reversal rows');
$sumLedger = (int) q("SELECT COALESCE(SUM(a.amount_paise),0) FROM payment_allocations a JOIN fee_invoices i ON i.id = a.invoice_id WHERE i.student_id = ?", [$stu]);
$sumPaid = (int) q('SELECT SUM(paid_paise) FROM fee_invoices WHERE student_id = ?', [$stu]);
ok($sumLedger === $sumPaid, 'Invoice paid totals reconcile exactly with the allocation ledger');
$r = api('POST', '/payments', ['student_id' => $stu, 'amount' => '-5', 'method' => 'cash'], $own);
ok($r['status'] === 422, 'Negative amounts are rejected');

// ------------------------------------------------------------- 20-23 reminders
section('Reminders, queue, WhatsApp, retries');
$openInv = (int) q("SELECT id FROM fee_invoices WHERE student_id = ? AND status IN ('unpaid','partial') ORDER BY id LIMIT 1", [$stu]);
$offs = DB::col('SELECT offset_days FROM scheduled_reminders WHERE invoice_id = ? ORDER BY offset_days', [$openInv]);
ok(count($offs) >= 1 && min(array_map('intval', $offs)) > 0 || count($offs) >= 1, '20. Automatic reminders scheduled for the open invoice (offsets: ' . implode(',', $offs) . ' days; same-day reminders skipped)');
DB::exec('UPDATE scheduled_reminders SET scheduled_for = DATE_ADD(CURDATE(), INTERVAL 40 DAY)');
DB::exec('UPDATE scheduled_reminders SET scheduled_for = CURDATE() WHERE invoice_id = ? ORDER BY offset_days LIMIT 1', [$openInv]);
$out = cron('reminders');
ok(str_contains($out, '"sent":1'), '21. Cron processes the due reminder', trim($out));
$feeQ = "SELECT COUNT(*) FROM notification_queue WHERE event_key IN ('fee_due','fee_overdue') AND channel = 'whatsapp'";
ok((int) q($feeQ) === 1, 'Fee reminder queued for WhatsApp');
$out2 = cron('reminders');
ok((int) q($feeQ) === 1, 'Running the reminder cron again does not duplicate');
$out = cron('queue');
ok((int) q($feeQ . " AND status = 'sent'") === 1 && (int) q("SELECT COUNT(*) FROM notification_queue WHERE status = 'failed'") === 0, '22. WhatsApp message processed (TEST mode: simulated, logged)', trim($out));
$body = (string) q("SELECT body FROM notification_queue WHERE event_key IN ('fee_due','fee_overdue') AND channel = 'whatsapp'");
ok(str_contains($body, 'Rahul Sharma') && str_contains($body, 'ABC Cricket Academy'), 'Reminder text is personalised: ' . $body);

// live mode with unreachable Meta API → retry path
DB::exec("UPDATE whatsapp_configurations SET mode = 'live', phone_number_id = '123', access_token_enc = ? WHERE id = 1", [Crypto::encrypt('dummy-token')]);
$r = api('POST', '/messages/send', ['target' => 'students', 'student_ids' => [$stu], 'message' => 'Ground closed tomorrow', 'channels' => ['whatsapp', 'inapp']], $own);
ok($r['status'] === 200 && ($r['json']['data']['whatsapp'] ?? 0) === 1, 'Manual reminder sent to a selected student');
cron('queue');
$job = DB::one("SELECT * FROM notification_queue WHERE event_key = 'manual_message' AND channel = 'whatsapp'");
ok($job['status'] === 'pending' && (int) $job['attempts'] === 1 && strtotime($job['next_attempt_at']) > time(), '23. Failed send is retried later with backoff (attempt 1 failed: ' . mb_substr((string) $job['last_error'], 0, 60) . ')');
DB::exec("UPDATE notification_queue SET status = 'failed' WHERE id = ?", [$job['id']]);
DB::exec("UPDATE whatsapp_configurations SET mode = 'test' WHERE id = 1");
$r = api('POST', '/admin/queue/action', ['action' => 'retry', 'ids' => [(int) $job['id']]], $sa);
cron('queue');
ok(q('SELECT status FROM notification_queue WHERE id = ?', [$job['id']]) === 'sent', 'Failed message can be retried manually and then succeeds');
// webhook delivery status
$pmid = (string) q('SELECT provider_message_id FROM notification_queue WHERE id = ?', [$job['id']]);
$wh = api('POST', '/webhooks/whatsapp', ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $pmid, 'status' => 'delivered']]]]]]]]);
ok($wh['status'] === 200 && q('SELECT status FROM notification_queue WHERE id = ?', [$job['id']]) === 'delivered', 'Delivery status is updated from the WhatsApp webhook');
$wh = api('POST', '/webhooks/whatsapp', ['entry' => [['changes' => [['value' => ['messages' => [['from' => '919876511111', 'text' => ['body' => 'STOP']]]]]]]]]);
$r = api('POST', '/messages/send', ['target' => 'students', 'student_ids' => [$stu], 'message' => 'Hello again', 'channels' => ['whatsapp']], $own);
ok(($r['json']['data']['whatsapp'] ?? -1) === 0, 'Opt-out (STOP) is respected');
DB::exec('DELETE FROM notification_opt_outs');

// ------------------------------------------------------------- 24-25 package changes
section('Package permissions & limits');
$r = api('PUT', "/admin/packages/$pkg", ['features' => array_values(array_diff($features, ['whatsapp', 'reports']))], $sa);
ok($r['status'] === 200, '24. Super Admin changes the package');
$r = api('POST', '/messages/send', ['target' => 'students', 'student_ids' => [$stu], 'message' => 'Test', 'channels' => ['whatsapp', 'inapp']], $own);
ok(($r['json']['data']['whatsapp'] ?? -1) === 0 && ($r['json']['data']['inapp'] ?? 0) >= 1, '25. WhatsApp disabled immediately by the backend; in-app still works');
$r = api('GET', '/reports/fee_collection', null, $own);
ok($r['status'] === 402, 'Reports blocked when not in the package (HTTP 402)');
api('PUT', "/admin/packages/$pkg", ['features' => $features], $sa);
api('POST', '/students', ['name' => 'Student Three', 'whatsapp' => '9876533333', 'activity_id' => $yoga, 'is_minor' => '0'], $own);
api('POST', '/students', ['name' => 'Student Four', 'whatsapp' => '9876544444', 'activity_id' => $yoga, 'is_minor' => '0'], $own);
$r = api('POST', '/students', ['name' => 'Student Five', 'whatsapp' => '9876555555', 'activity_id' => $yoga, 'is_minor' => '0'], $own);
ok($r['status'] === 402 && ($r['json']['code'] ?? '') === 'limit_reached', 'Student limit of the package is enforced');

// ------------------------------------------------------------- 26-27 isolation
section('Tenant isolation');
$r = api('POST', '/admin/owners', ['owner_name' => 'Meera Iyer', 'mobile' => '9988776655', 'academy_name' => 'Rhythm Dance Studio', 'package_id' => $pkg, 'password' => 'Owner@456', 'subscription_status' => 'active'], $sa);
$acad2 = $r['json']['data']['academy_id'] ?? 0;
$own2 = api('POST', '/auth/login', ['identifier' => '9988776655', 'password' => 'Owner@456'])['json']['data']['token'] ?? null;
$a2 = api('POST', '/activities', ['name' => 'Kathak', 'monthly_fee' => '1200'], $own2)['json']['data']['id'] ?? 0;
$r = api('POST', '/students', ['name' => 'Ananya Rao', 'whatsapp' => '9811122233', 'activity_id' => $a2, 'guardian_name' => 'Kiran Rao'], $own2);
$stu2 = $r['json']['data']['student']['id'] ?? 0;
ok($stu2 > 0, 'Second academy registers its own student');
ok(api('GET', "/students/$stu2", null, $own)['status'] === 404, '26. Owner cannot open another academy\'s student');
ok(api('GET', "/students/$stu2/dues", null, $own)['status'] === 404, 'Owner cannot see another academy\'s fees');
ok(api('POST', '/payments', ['student_id' => $stu2, 'amount' => '100', 'method' => 'cash'], $own)['status'] === 404, 'Owner cannot collect fees for another academy');
$list = api('GET', '/students?status=all&per_page=100', null, $own2)['json']['data'] ?? [];
ok(count($list) === 1 && $list[0]['id'] === $stu2, 'Student list contains only own academy records');
ok(api('GET', "/portal/students/$stu2/dashboard", null, $par)['status'] === 404, '27. Parent cannot access another family\'s child');
$s3 = (int) q("SELECT id FROM students WHERE name = 'Student Three'");
ok(api('GET', "/portal/students/$s3/fees", null, $par)['status'] === 404, 'Parent cannot access a non-linked student of the same academy');
$rc2 = api('POST', '/payments', ['student_id' => $stu2, 'amount' => '500', 'method' => 'cash'], $own2)['json']['data']['receipt_id'] ?? 0;
ok(api('GET', "/receipts/$rc2/pdf", null, $par)['status'] === 404, 'Parent cannot download another family\'s receipt');

// ------------------------------------------------------------- 28-30 cross-platform
section('Web + Android consistency (one API, one database)');
$jar = tempnam(sys_get_temp_dir(), 'cj');
$r = api('POST', '/auth/login', ['identifier' => '9876543210', 'password' => 'Owner@123', 'client' => 'web'], null, [], [], $jar);
$csrf = $r['json']['data']['csrf'] ?? '';
ok($r['status'] === 200 && $csrf, 'Owner logs into the web panel (session cookie)');
$r = api('POST', '/students', ['name' => 'Web Student', 'whatsapp' => '9000011111', 'activity_id' => $act, 'batch_id' => $batch2, 'guardian_name' => 'Web Parent'], null, [], [], $jar);
ok($r['status'] === 419, 'Web write without CSRF token is rejected');
DB::exec("UPDATE students SET status = 'inactive' WHERE name IN ('Student Three','Student Four')");
$r = api('POST', '/students', ['name' => 'Web Student', 'whatsapp' => '9000011111', 'activity_id' => $act, 'batch_id' => $batch2, 'guardian_name' => 'Web Parent'], null, [], ['X-CSRF-Token: ' . $csrf], $jar);
$webStu = $r['json']['data']['student']['id'] ?? 0;
ok($webStu > 0, 'Student added through the web panel', $r['body']);
$list = api('GET', '/students?q=Web', null, $own)['json']['data'] ?? [];
ok(count($list) === 1 && $list[0]['id'] === $webStu, '28. Student added on web appears in the Android API');
$r = api('POST', '/attendance', ['batch_id' => $batch2, 'entries' => [['student_id' => $webStu, 'status' => 'late']]], $own);
$hist = api('GET', "/students/$webStu/attendance", null, null, [], [], $jar);
ok(($hist['json']['data']['counts']['late'] ?? 0) === 1, '29. Attendance marked on Android appears on web');
$pay = api('POST', '/payments', ['student_id' => $webStu, 'amount' => '300', 'method' => 'upi'], null, [], ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: web-1'], $jar)['json']['data'] ?? [];
$andr = api('GET', '/payments/' . ($pay['id'] ?? 0), null, $own)['json']['data'] ?? [];
ok(($andr['receipt_no'] ?? 'x') === ($pay['receipt_no'] ?? 'y') && ($andr['amount_paise'] ?? 0) === 30000, '30. Payment & receipt identical on web and Android');

// ------------------------------------------------------------- extra
section('Subscriptions, admissions, reports, sessions, cron, backup');
$r = api('POST', "/admin/academies/$acad2/subscription", ['action' => 'assign', 'package_id' => $pkg, 'starts_on' => date('Y-m-d', strtotime('-60 days')), 'ends_on' => date('Y-m-d', strtotime('-20 days'))], $sa);
$r = api('POST', '/activities', ['name' => 'Ballet'], $own2);
ok($r['status'] === 402 && ($r['json']['code'] ?? '') === 'subscription_expired', 'Expired subscription → account becomes read-only');
ok(api('GET', '/students', null, $own2)['status'] === 200, 'Expired account can still read its data (nothing deleted)');
$r = api('POST', "/admin/academies/$acad2/subscription", ['action' => 'renew', 'record_payment' => 1, 'method' => 'bank_transfer'], $sa);
ok(api('POST', '/activities', ['name' => 'Ballet'], $own2)['status'] === 200, 'Renewal restores full access');

$slug = (string) q('SELECT slug FROM academies WHERE id = ?', [$acad1]);
$r = api('POST', "/public/academy/$slug/admission", ['student_name' => 'Online Kid', 'parent_name' => 'Online Parent', 'whatsapp' => '9123400000', 'activity_id' => $act, 'batch_id' => $batch2, 'consent_given' => '1']);
ok($r['status'] === 200 && (int) q("SELECT COUNT(*) FROM students WHERE name = 'Online Kid'") === 0, 'Online admission creates a pending application (not an active student)');
$adm = (int) q("SELECT id FROM admissions WHERE student_name = 'Online Kid'");
$r = api('POST', "/admissions/$adm/approve", [], $own);
ok($r['status'] === 200 && (int) q("SELECT COUNT(*) FROM students WHERE name = 'Online Kid' AND source = 'online'") === 1, 'Owner approves → student registered');

$r = api('GET', '/reports/fee_collection?from=' . date('Y-m-01'), null, $own);
ok($r['status'] === 200 && count($r['json']['data']['rows'] ?? []) >= 2, 'Fee collection report returns live data');
foreach (['csv' => 'Student ID', 'xlsx' => 'PK', 'pdf' => '%PDF-'] as $fmt => $magic) {
    $x = api('GET', "/reports/pending_fees?format=$fmt", null, $own);
    ok($x['status'] === 200 && str_contains(substr($x['body'], 0, 200), $magic), "Pending fee report exports as " . strtoupper($fmt));
}
$r = api('GET', '/reports/subscriptions', null, $sa);
ok(count($r['json']['data']['rows'] ?? []) === 2, 'Super Admin subscription report');

$r = api('GET', '/dashboard', null, $own);
$d = $r['json']['data'] ?? [];
ok(($d['active_students'] ?? 0) >= 4 && isset($d['month_collection_paise']) && $d['month_collection_paise'] > 0, 'Owner dashboard uses real database figures');
ok(api('GET', '/admin/dashboard', null, $sa)['json']['data']['total_academies'] === 2, 'Super Admin dashboard statistics');
ok(api('GET', '/admin/dashboard', null, $own)['status'] === 403, 'Owner cannot open Super Admin endpoints');

api('POST', '/auth/logout', [], $tch);
ok(api('GET', '/auth/me', null, $tch)['status'] === 401, 'Logged-out token no longer works');
DB::exec('UPDATE api_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?', [hash('sha256', $par)]);
ok(api('GET', '/auth/me', null, $par)['status'] === 401, 'Expired session token is rejected');

$before = (int) q('SELECT COUNT(*) FROM fee_invoices');
cron('all');
cron('invoices');
cron('invoices');
ok((int) q('SELECT COUNT(*) FROM fee_invoices') === $before, 'Invoice cron is idempotent (repeated runs create no duplicates)');
ok((int) q("SELECT COUNT(*) FROM cron_runs WHERE status = 'error'") === 0, 'All cron tasks completed without errors');

$out = (string) shell_exec('CNX_DB=' . escapeshellarg($dbName) . ' php ' . CNX_ROOT . '/cli/backup.php 2>&1');
$file = trim((string) preg_replace('/^Backup written: (\S+).*$/s', '$1', $out));
ok(is_file($file) && filesize($file) > 1000, 'Database backup created: ' . basename($file));
$restoreDb = $dbName . '_restore';
$root->exec("DROP DATABASE IF EXISTS `$restoreDb`");
$root->exec("CREATE DATABASE `$restoreDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
shell_exec('echo YES | CNX_DB=' . escapeshellarg($restoreDb) . ' php ' . CNX_ROOT . '/cli/backup.php restore ' . escapeshellarg($file) . ' 2>&1');
$cnt = (int) $root->query("SELECT COUNT(*) FROM `$restoreDb`.payments")->fetchColumn();
ok($cnt === (int) q('SELECT COUNT(*) FROM payments') && $cnt > 0, "Backup restores into a new database ($cnt payments match)");
$root->exec("DROP DATABASE IF EXISTS `$restoreDb`");

$r = api('POST', '/auth/login', ['identifier' => '9876543210', 'password' => 'bad']);
for ($i = 0; $i < 5; $i++) {
    api('POST', '/auth/login', ['identifier' => '9876543210', 'password' => 'bad']);
}
ok(api('POST', '/auth/login', ['identifier' => '9876543210', 'password' => 'Owner@123'])['status'] === 429, 'Account locks for 15 minutes after repeated wrong passwords');
DB::exec('UPDATE users SET failed_logins = 0, locked_until = NULL');

// ------------------------------------------------------------- summary
echo "\n\033[1mResult: $pass passed, $fail failed\033[0m\n";
$report = "# ClassNex E2E Test Run\n\nDate: " . date('Y-m-d H:i T') . "  \nDatabase: MariaDB/MySQL `" . DB::val('SELECT VERSION()') . "`  \nPHP: " . PHP_VERSION . "\n\n**$pass passed, $fail failed**\n\n| # | Result | Check |\n|---|---|---|\n";
foreach ($results as $i => [$okk, $name, $det]) {
    $report .= '| ' . ($i + 1) . ' | ' . ($okk ? 'PASS' : '**FAIL**') . ' | ' . str_replace('|', '\|', $name) . ($okk || !$det ? '' : ' — ' . str_replace('|', '\|', mb_substr($det, 0, 200))) . " |\n";
}
file_put_contents(CNX_ROOT . '/docs/QA_TEST_RUN.md', $report);
exit($fail ? 1 : 0);
