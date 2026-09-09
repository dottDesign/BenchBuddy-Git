<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/two_factor.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Verify Login';
$currentPage = 'login';

$error = '';

$pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
$startedAt = (int)($_SESSION['pending_2fa_started_at'] ?? 0);

if ($pendingUserId <= 0) {
    header('Location: login.php');
    exit;
}

if ($startedAt <= 0 || time() - $startedAt > 600) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
    header('Location: login.php?expired=1');
    exit;
}

$user = get_user_by_id($pendingUserId);

if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
    header('Location: login.php');
    exit;
}

if ((int)($user['two_factor_enabled'] ?? 0) !== 1) {
    complete_user_login($user);
    header('Location: index.php');
    exit;
}

$secret = (string)($user['two_factor_secret'] ?? '');

if ($secret === '') {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    try {
        $code = trim((string)($_POST['code'] ?? ''));

        if (!two_factor_verify_code($secret, $code)) {
            throw new RuntimeException('Invalid authentication code.');
        }

        complete_user_login($user);

        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="auth-wrap">
  <div class="auth-grid">
    <section class="card">
      <h2>Verify Login</h2>

      <p class="muted">
        Enter the 6-digit code from your authenticator app.
      </p>

      <?php if ($error !== ''): ?>
        <div class="msg err"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post">
          <?= csrf_field() ?>
        <label for="code">Authentication Code</label>

        <input
          type="text"
          id="code"
          name="code"
          inputmode="numeric"
          pattern="[0-9]{6}"
          maxlength="6"
          required
          autocomplete="one-time-code"
          placeholder="123456"
        >

        <div class="actions-row">
          <button type="submit">Verify</button>
          <a class="btn btn-secondary" href="logout.php">Cancel</a>
        </div>
      </form>
    </section>

    <aside class="card">
      <h2>Authenticator App</h2>
      <ul class="feature-list">
        <li>Open your authenticator app.</li>
        <li>Find your BenchBuddy code.</li>
        <li>Enter the current 6-digit code.</li>
        <li>Codes refresh about every 30 seconds.</li>
      </ul>
    </aside>
  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
