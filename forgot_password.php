<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Forgot Password';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    try {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if ($email === '') {
            throw new RuntimeException('Email is required.');
        }

        $reset = create_password_reset($email);

        if ($reset) {
            send_password_reset_email(
                (string)$reset['email'],
                (string)($reset['full_name'] ?? ''),
                (string)$reset['reset_token']
            );
        }

        $message = 'If that email exists in the system, a reset link has been sent.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

  <main class="auth-wrap">
    <div class="auth-grid">
      <section class="card">
        <h2>Reset your password</h2>
        <p class="muted">
          Use the email address tied to your account. If the account exists, you’ll receive a reset link shortly.
        </p>

        <?php if ($message !== ''): ?>
          <div class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="forgot_password.php">
            <?= csrf_field() ?>
          <label for="email">Email</label>
          <input
            type="email"
            id="email"
            name="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
            value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          >

          <div class="actions-row">
            <button type="submit">Send Reset Link</button>
            <a class="btn btn-secondary" href="login.php">Back to Login</a>
          </div>
        </form>
      </section>

      <aside class="card">
        <h2>What happens next</h2>
        <ul class="feature-list">
          <li>We email a secure reset link to your address.</li>
          <li>The link should expire after 1 hour.</li>
          <li>You choose a new password on the reset page.</li>
          <li>Your old password stops working immediately after reset.</li>
        </ul>
      </aside>
    </div>
  </main>
</body>
</html>
