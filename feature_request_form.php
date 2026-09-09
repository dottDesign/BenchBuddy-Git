<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/features/feature_requests.php';

require_login();

$pageTitle = 'Feature Request';
$currentPage = 'feature_request';

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        create_feature_request( current_user_id(),$title, $description);
        flash_set('ok', 'Request submitted successfully.');
        header('Location: feature_request.php');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Feature Request</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Submit a Feature Request</h2>

  <p class="muted">
    Share an idea that would make BenchBuddy better for coaches, teams, or game-day lineup management.
  </p>

  <form method="post">
      <?= csrf_field() ?>
    <label for="title">Feature title</label>
    <input
      type="text"
      id="title"
      name="title"
      maxlength="150"
      required
    >

    <label for="description">Details</label>
    <textarea
      id="description"
      name="description"
      rows="7"
      required
    ></textarea>

    <div class="actions-row">
      <button type="submit" class="btn">Submit Request</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
