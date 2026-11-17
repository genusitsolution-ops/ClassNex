<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require CNX_ROOT . '/includes/auth_layout.php';
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
$reset = isset($_GET['reset']);
$valid = $token && (Auth::consumeToken($token, 'activation') || Auth::consumeToken($token, 'password_reset'));
auth_page_start($reset ? 'Reset password' : 'Activate account');
?>
<div class="form-card auth-card">
  <h1 class="h4 fw-bold text-center mb-1"><?= $reset ? 'Set a new password' : 'Activate your account' ?></h1>
  <?php if (!$valid): ?>
    <div class="alert alert-warning mt-3">This link has expired or was already used. Please ask your academy to send a new invitation, or use "Forgot password" on the login page.</div><a href="login.php" class="btn btn-primary w-100">Go to login</a>
  <?php else: ?>
  <p class="text-center text-muted mb-4">Choose a password for ClassNex. You'll use your mobile number and this password on the website and the Android app.</p>
  <form data-api="/auth/activate" data-success-html='<a class="btn btn-primary mt-3" href="login.php">Log in now</a>' novalidate>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="mb-3"><label class="form-label">New password</label><input class="form-control form-control-lg" type="password" name="password" autocomplete="new-password" required><div class="invalid-feedback"></div><div class="form-text">At least 8 characters with letters and numbers.</div></div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Save password</button><div data-result hidden></div>
  </form>
  <?php endif; ?>
</div>
<?php auth_page_end();
