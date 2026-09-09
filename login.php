<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$emailValue = '';
$sessionExpired = isset($_GET['expired']) && $_GET['expired'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $emailValue = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    try {
        if (login_user($emailValue, $password)) {
            if (!empty($_SESSION['pending_2fa_user_id'])) {
                header('Location: verify_2fa.php');
                exit;
            }

            header('Location: index.php');
            exit;
        }

        $error = 'Invalid email or password.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
require_once __DIR__ . '/includes/header.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Coach Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>

  </style>
</head>
<body>


  <main class="auth-wrap">
    <div class="auth-grid">
      <section class="card">
        <h2>Coach Login</h2>
        <p class="muted">Sign in to manage your team workspace.</p>

        <?php if ($error !== ''): ?>
          <div class="msg"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
          <label for="email">Email</label>
          <input
            type="email"
            id="email"
            name="email"
            required
            value="<?= h($emailValue) ?>"
          >

          <label for="password">Password</label>
          <input
            type="password"
            id="password"
            name="password"
            required
          >

          <button type="submit">Log In</button>
        </form>

        <div class="auth-links">
          <a href="signup.php">Need an account? Sign up</a>
        </div>

        <div class="auth-links">
  <a href="forgot_password.php">Forgot your password?</a>
</div>
      </section>

      <aside class="card">
        <h2>What you can do</h2>
        <ul class="feature-list">
          <li>Manage players and jersey numbers</li>
          <li>Set game rosters and batting order</li>
          <li>Build inning-by-inning lineups</li>
          <li>Track innings pitched and pitch counts</li>
          <li>Finalize and review game history</li>
        </ul>
      </aside>
    </div>
  </main>


  <?php if ($sessionExpired): ?>
    <div id="loggedOutModal" class="help-modal-backdrop" style="display:flex;">
      <div class="help-modal">
        <div class="help-modal-header">
          <h2>You were logged out</h2>
        </div>

        <div class="help-modal-body">
          <div class="help-step">
            <strong>Your session expired due to inactivity.</strong>
            <p>Please log in again to continue using BenchBuddy.</p>
          </div>
        </div>

        <div class="help-modal-footer">
          <button type="button" class="btn" onclick="closeLoggedOutModal()">
            Log In
          </button>
        </div>
      </div>
    </div>

    <script>
    function closeLoggedOutModal() {
      const modal = document.getElementById('loggedOutModal');

      if (modal) {
        modal.style.display = 'none';
        modal.classList.add('hide');
      }

      const url = new URL(window.location.href);
      url.searchParams.delete('expired');
      window.history.replaceState({}, document.title, url.toString());
    }
    </script>
  <?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
