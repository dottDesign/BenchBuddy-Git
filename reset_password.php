<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Reset Password';
$message = '';
$error = '';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$resetRow = null;

try {
    if ($token === '') {
        throw new RuntimeException('Reset token is missing.');
    }

    $resetRow = get_valid_password_reset($token);
    if (!$resetRow) {
        throw new RuntimeException('This reset link is invalid or expired.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('Both password fields are required.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('Passwords do not match.');
        }

        update_user_password_by_id((int)$resetRow['user_id'], $newPassword);
        mark_password_reset_used((int)$resetRow['id']);
        flash_redirect('ok', 'Password reset successfully. You can now log in.', 'reset_password.php');
        $resetRow = null;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/css/app.css?v=1">
  <link rel="stylesheet" href="/assets/css/auth.css?v=1">
</head>
<body>
  <div class="auth-wrap">
    <div class="card">
      <h1>Reset Password</h1>

      <?php if ($message !== ''): ?>
        <div class="msg ok"><?= htmlspecialchars($message) ?></div>
        <div class="actions-row">
          <a class="btn" href="login.php">Go to Login</a>
        </div>
      <?php else: ?>

        <?php if ($error !== ''): ?>
          <div class="msg err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($resetRow): ?>
          <form method="post">
              <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">

            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

            <div class="actions-row">
              <button type="submit">Reset Password</button>
              <a class="btn btn-secondary" href="login.php">Cancel</a>
            </div>
          </form>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</body>
</html>
