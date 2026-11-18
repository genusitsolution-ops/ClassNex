<?php
declare(strict_types=1);
/**
 * Captures real API responses (as the Android app receives them, with a bearer token) into
 * android/app/src/test/resources/fixtures/. The Android unit tests parse every fixture with the
 * app's own models, so a server change that breaks the app fails the Android CI build.
 *
 *   CNX_DB=classnex_test php tests/e2e_branches.php http://127.0.0.1:8081   # creates the data
 *   CNX_DB=classnex_test php tests/android_fixtures.php http://127.0.0.1:8081
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8081', '/') . '/api/v1';
$dir = __DIR__ . '/../android/app/src/test/resources/fixtures';
@mkdir($dir, 0777, true);
function req(string $m, string $url, ?array $body = null, ?string $tok = null): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    if ($tok) {
        $h[] = "Authorization: Bearer $tok";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $raw, json_decode($raw, true)];
}
$tok = req('POST', "$base/auth/login", ['identifier' => '9811111111', 'password' => 'Owner@123', 'client' => 'android'])[2]['data']['token'] ?? null;
if (!$tok) {
    fwrite(STDERR, "Login failed – run tests/e2e_branches.php first.\n");
    exit(1);
}
$branchId = array_values(array_filter(req('GET', "$base/branches", null, $tok)[2]['data'], static fn($b) => $b['name'] === 'Vijay Nagar'))[0]['id'];
$student = req('GET', "$base/students?q=Student+1&per_page=1", null, $tok)[2]['data'][0];
$batch = array_values(array_filter(req('GET', "$base/batches", null, $tok)[2]['data'], static fn($b) => $b['name'] === 'VN Morning'))[0];
$from = date('Y-m-01');
$calls = [
    'me' => ['GET', '/auth/me'],
    'dashboard' => ['GET', '/dashboard'],
    'branches' => ['GET', '/branches'],
    'branch_summary' => ['GET', '/branches/summary'],
    'branch_detail' => ['GET', "/branches/$branchId"],
    'students_branch' => ['GET', "/students?branch_id=$branchId&per_page=5"],
    'student_detail' => ['GET', "/students/{$student['id']}"],
    'batches' => ['GET', '/batches'],
    'teachers' => ['GET', '/teachers'],
    'history_sessions' => ['GET', "/attendance/history?from=$from&view=sessions"],
    'history_students' => ['GET', "/attendance/history?from=$from&view=students&per_page=10"],
    'history_branches' => ['GET', "/attendance/history?from=$from&view=branches"],
    'report_branch_summary' => ['GET', '/reports/branch_summary'],
    'report_fee_collection' => ['GET', "/reports/fee_collection?branch_id=$branchId"],
    'bulk_preview' => ['POST', '/students/bulk-branch', ['branch_id' => $branchId, 'student_ids' => [$student['id']], 'preview' => 1]],
    'duplicate_error' => ['POST', '/students', ['name' => $student['name'], 'whatsapp' => $student['whatsapp'], 'activity_id' => $batch['activity_id'], 'batch_id' => $batch['id'], 'is_minor' => '0']],
    'whatsapp_log' => ['GET', '/whatsapp/manual/log'],
    'invoices' => ['GET', '/invoices?status=open&per_page=5'],
    'payments' => ['GET', '/payments?per_page=5'],
];
foreach ($calls as $name => $c) {
    [$st, $raw] = req($c[0], $base . $c[1], $c[2] ?? null, $tok);
    file_put_contents("$dir/$name.json", json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("%-24s HTTP %d\n", $name, $st);
}
// a fixture from an academy in manual WhatsApp mode (prepared click-to-send messages)
$tok2 = req('POST', "$base/auth/login", ['identifier' => '9833333333', 'password' => 'Owner@456', 'client' => 'android'])[2]['data']['token'] ?? null;
if ($tok2) {
    $sid = req('GET', "$base/students?per_page=1", null, $tok2)[2]['data'][0]['id'] ?? 0;
    [$st, $raw] = req('POST', "$base/whatsapp/manual/prepare", ['purpose' => 'admission', 'student_id' => $sid], $tok2);
    file_put_contents("$dir/whatsapp_prepare.json", json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("%-24s HTTP %d\n", 'whatsapp_prepare', $st);
    [$st, $raw] = req('GET', "$base/auth/me", null, $tok2);
    file_put_contents("$dir/me_manual_whatsapp.json", json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("%-24s HTTP %d\n", 'me_manual_whatsapp', $st);
}
