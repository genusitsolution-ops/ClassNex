<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/auth_layout.php';

Auth::startSession();
if (!empty($_SESSION['uid']) && ($_SESSION['exp'] ?? 0) > time()) {
    $u = DB::one("SELECT role FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]);
    if ($u) {
        header('Location: ' . Auth::homeFor($u['role']));
        exit;
    }
}
auth_page_start('Login');
?>
<div class="form-card auth-card">
  <h1 class="h4 fw-bold text-center mb-1">Welcome back</h1>
  <p class="text-center text-muted mb-4">Class Admins, teachers, parents and students — one login.</p>
  <form id="lf" data-api="/auth/login" data-on-success="accounts" data-next="<?= e((string) ($_GET['next'] ?? '')) ?>" novalidate>
    <input type="hidden" name="client" value="web">
    <div class="mb-3"><label class="form-label">Mobile number or email</label><input class="form-control form-control-lg" name="identifier" autocomplete="username" required autofocus inputmode="email"><div class="invalid-feedback"></div></div>
    <div class="mb-2"><label class="form-label">Password</label><div class="input-group"><input class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" required><button class="btn btn-light border" type="button" onclick="const i=this.previousElementSibling;i.type=i.type==='password'?'text':'password'"><i class="bi bi-eye"></i></button></div></div>
    <div class="text-end mb-3"><a href="#" data-bs-toggle="collapse" data-bs-target="#fp" class="small">Forgot password?</a></div>
    <div data-accounts hidden class="mb-3"></div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Log in</button>
    <div data-result hidden></div>
  </form>
  <div class="collapse mt-3" id="fp"><form data-api="/auth/forgot-password" class="border rounded-3 p-3 bg-light" novalidate>
    <label class="form-label small">Enter your mobile number or email. We'll send a reset link on WhatsApp / email.</label>
    <div class="input-group"><input class="form-control" name="identifier" required><button class="btn btn-primary" type="submit">Send link</button></div><div data-result hidden></div></form></div>
  <p class="text-center mt-4 mb-0 small">New academy? <a href="register.php" class="fw-bold">Start your free trial</a></p>
</div>
<?php auth_page_end();
