<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Create Team';
$currentPage = 'create_team';

$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $teamName = (string)($_POST['team_name'] ?? '');
        $seasonLabel = (string)($_POST['season_label'] ?? '');

        $teamId = create_team_for_current_user($teamName, $seasonLabel);
        set_current_team($teamId);

        header('Location: index.php?msg=' . urlencode('Team created successfully.'));
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .create-team-wrap {
    max-width: 760px;
  }
</style>

<h1 class="page-title brand-title-font">Create Team</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="create-team-wrap">
  <div class="card">
    <h2>New Team</h2>

    <form method="post">
        <?= csrf_field() ?>
      <label for="team_name">Team Name</label>
      <input
        type="text"
        id="team_name"
        name="team_name"
        required
        maxlength="150"
        placeholder="Example: Adler Falcons"
      >

      <label for="season_label">Season Label</label>
      <input
        type="text"
        id="season_label"
        name="season_label"
        maxlength="50"
        placeholder="Example: 2026 Spring"
      >

      <div class="actions-row">
        <button type="submit">Create Team</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>What Happens Next</h2>
    <p class="muted">
      When you create a new team, you will automatically be added to it and switched into that team space.
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
