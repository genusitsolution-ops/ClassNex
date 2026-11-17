<?php
// Usage: php cli/create-superadmin.php "Full Name" email@example.com 9876543210 'StrongPass123'
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';

[$_, $name, $email, $mobile, $pass] = array_pad($argv, 5, null);
if (!$name || !$email || !$pass) {
    fwrite(STDERR, "Usage: php cli/create-superadmin.php \"Name\" email mobile password\n");
    exit(1);
}
Auth::validatePassword($pass);
$mobile = $mobile ? Phone::normalize($mobile) : null;
$exists = DB::val("SELECT id FROM users WHERE role = 'super_admin' AND email = ?", [strtolower($email)]);
if ($exists) {
    DB::update('users', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active'], 'id = ?', [$exists]);
    echo "Super admin #$exists password updated.\n";
    exit(0);
}
$id = DB::insert('users', [
    'role' => 'super_admin', 'name' => $name, 'email' => strtolower($email), 'mobile' => $mobile,
    'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active',
]);
echo "Super admin created (user #$id). Log in at " . cnx_url('login.php') . "\n";
