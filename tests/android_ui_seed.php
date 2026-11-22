<?php
declare(strict_types=1);

/**
 * Extra demo data for the Android emulator UI tests, run AFTER tests/e2e_final.php on the same test database.
 * Adds fee plans, a payment, an announcement, a holiday, a support ticket and a parent login so every
 * redesigned screen has real data from the API. Test data only – never run on production.
 *
 *   CNX_DB=classnex_test php tests/android_ui_seed.php http://127.0.0.1:8081
 *
 * Logins afterwards: Class Admin 9845000001 / Asha@1234 · Teacher 9845100001 / Teach@123
 *                    Parent 9845200001 / Parent@123   · Super Admin qa-admin@classnex.test / Admin@12345
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8081', '/');
$dbName = getenv('CNX_DB') ?: 'classnex_test';
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "Refusing to seed a database whose name does not contain 'test'.\n");
    exit(1);
}
require __DIR__ . '/../includes/bootstrap.php';

function call(string $method, string $path, ?array $body, ?string $token): array
{
    global $base;
    $ch = curl_init($base . '/api/v1' . $path);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = json_decode($raw, true) ?? [];
    echo str_pad("$method $path", 48) . " → $st " . ($j['message'] ?? '') . "\n";
    return $j;
}

$own = call('POST', '/auth/login', ['identifier' => '9845000001', 'password' => 'Asha@1234', 'client' => 'android'], null)['data']['token'] ?? null;
if (!$own) {
    fwrite(STDERR, "Class Admin login failed – run tests/e2e_final.php first.\n");
    exit(1);
}
$aid = (int) DB::val("SELECT id FROM academies WHERE name = 'Rao Dance Studio'");
$act = (int) DB::val("SELECT id FROM activities WHERE academy_id = ? AND name = 'Kathak'", [$aid]);

call('POST', '/fee-structures', ['name' => 'Kathak monthly fee', 'fee_type' => 'recurring', 'frequency_months' => 1, 'amount' => '1000', 'due_day' => 5, 'activity_id' => $act, 'auto_assign' => 1], $own);
call('POST', '/discounts', ['name' => 'Sibling discount', 'kind' => 'discount', 'value_type' => 'percent', 'value' => '10'], $own);
$meera = (int) DB::val("SELECT id FROM students WHERE academy_id = ? AND name = 'Meera Rao' ORDER BY id LIMIT 1", [$aid]);
$dancer = (int) DB::val("SELECT id FROM students WHERE academy_id = ? AND name = 'Dancer 1'", [$aid]);
foreach ([$meera, $dancer] as $sid) {
    call('POST', "/students/$sid/charges", ['title' => 'Annual day costume', 'amount' => '1500', 'due_date' => date('Y-m-d', strtotime('+3 days'))], $own);
}
call('POST', "/students/$dancer/fees", ['name' => 'Monthly fee', 'fee_type' => 'recurring', 'frequency_months' => 1, 'amount' => '1000', 'due_day' => 5, 'start_date' => date('Y-m-01', strtotime('-1 month'))], $own);
$ch = curl_init($base . '/api/v1/payments');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $own, 'Idempotency-Key: ui-seed-1'],
    CURLOPT_POSTFIELDS => json_encode(['student_id' => $dancer, 'amount' => '1200', 'method' => 'upi', 'reference' => 'UPI-UI-SEED'])]);
echo 'POST /payments → ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . (json_decode((string) curl_exec($ch), true)['message'] ?? '') . "\n";
call('POST', '/announcements', ['title' => 'Annual day rehearsal', 'body' => 'Rehearsal for the annual day starts next Monday at 5 PM. Please be on time.', 'audience' => 'all', 'notify' => 1], $own);
call('POST', '/holidays', ['holiday_date' => date('Y-m-d', strtotime('+10 days')), 'title' => 'Diwali'], $own);
call('POST', '/support', ['subject' => 'How do I print receipts?', 'message' => 'Please guide us on printing receipts from the app.', 'priority' => 'normal'], $own);

// parent login for Meera and Kabir (Asha Rao, 9845200001)
call('POST', "/students/$meera/invite", [], $own);
$n = DB::exec("UPDATE users SET password_hash = ?, status = 'active', must_change_password = 0 WHERE role = 'parent' AND mobile = '9845200001' AND academy_id = ?", [password_hash('Parent@123', PASSWORD_DEFAULT), $aid]);
echo "parent login prepared: " . ($n ? 'yes' : 'no') . "\n";
// the teacher may view fees and collect them (shows the permission-based menu)
$tid = (int) DB::val("SELECT id FROM teachers WHERE academy_id = ? AND mobile = '9845100001'", [$aid]);
call('PUT', "/teachers/$tid", ['permissions' => ['fees.view', 'fees.collect', 'notifications.send']], $own);
echo "UI seed done.\n";
