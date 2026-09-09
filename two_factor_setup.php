<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/two_factor.php';

require_login();

$pageTitle = 'Two-Factor Authentication';
$currentPage = 'account';

$user = get_current_user_record();

if (!$user) {
    header('Location: login.php');
    exit;
}

$error = '';
$message = '';

$userId = (int)$user['id'];
$email = (string)$user['email'];

$secret = (string)($user['two_factor_secret'] ?? '');

if ($secret === '') {
    $secret = two_factor_generate_secret();

    $stmt = db()->prepare("
        UPDATE users
        SET two_factor_secret = :secret
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'secret' => $secret,
        'id' => $userId,
    ]);

    $user['two_factor_secret'] = $secret;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    try {
        $code = trim((string)($_POST['code'] ?? ''));

        if (!two_factor_verify_code($secret, $code)) {
            throw new RuntimeException('Invalid authentication code. Please try again.');
        }

        $stmt = db()->prepare("
            UPDATE users
            SET two_factor_enabled = 1,
                two_factor_confirmed_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $userId,
        ]);

        $message = 'Two-factor authentication has been enabled.';
        $user['two_factor_enabled'] = 1;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$qrUrl = two_factor_qr_url($email, $secret);

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Two-Factor Authentication</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:680px;">
  <h2>Authenticator App Setup</h2>

  <?php if ((int)($user['two_factor_enabled'] ?? 0) === 1): ?>
    <p class="muted">
      Two-factor authentication is currently enabled for your account.
    </p>

    <div class="actions-row">
      <a class="btn btn-secondary" href="account.php">Back to Account</a>
    </div>
  <?php else: ?>
    <p class="muted">
      Scan this QR code with Google Authenticator, Microsoft Authenticator, 1Password, Authy, or another TOTP app.
    </p>

    <div style="margin:20px 0; text-align:center;">
      <img
        src="<?= h($qrUrl) ?>"
        alt="Two-factor authentication QR code"
        style="width:220px; height:220px; max-width:100%;"
      >
    </div>

    <p class="muted">
      If you cannot scan the QR code, manually enter this setup key:
    </p>

    <div class="card" style="background:#f8fafc; margin-bottom:18px;">
      <strong style="word-break:break-all; letter-spacing:1px;">
        <?= h($secret) ?>
      </strong>
    </div>

    <form method="post">
        <?= csrf_field() ?>
      <label for="code">Enter 6-digit code</label>
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
        <button type="submit">Enable 2FA</button>
        <a class="btn btn-secondary" href="account.php">Cancel</a>
      </div>
    </form>


  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
