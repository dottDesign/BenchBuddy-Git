<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Cancelled Games';
$currentPage = 'cancelled';

$error = '';
$games = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $action = $_POST['action'] ?? '';

    if ($action === 'restore_game') {

        $gameDbId = (int)($_POST['game_db_id'] ?? 0);

        if ($gameDbId <= 0) {
            throw new RuntimeException('Invalid game.');
        }

        restore_cancelled_game($teamId, $gameDbId);

        header('Location: cancelled_games.php');
        exit;
    }
}
try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
          AND deleted_at IS NOT NULL
        ORDER BY deleted_at DESC, id DESC
    ");
    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $games = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Cancelled Games</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Cancelled / Rainout History</h2>
  <p class="muted">
    These games were removed from active scheduling but kept for recordkeeping.
  </p>

  <div class="actions-row">
    <a class="btn btn-secondary" href="games.php">Back to Games</a>
  </div>
</div>

<div class="card">
  <h2>All Cancelled Games</h2>


  <div class="mobile-cards">
    <?php if (empty($games)): ?>
      <p class="muted">No cancelled games found.</p>
    <?php else: ?>
      <?php foreach ($games as $game): ?>
        <div class="mobile-card">
          <div class="mobile-card-title">
            <?= h((string)$game['game_id']) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Status</span>
            <span class="pill default">Cancelled</span>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Game Date</span>
            <?= !empty($game['game_date']) ? h((string)$game['game_date']) : 'Not set' ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Innings</span>
            <?= (int)($game['innings'] ?? 0) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Roster</span>
            <?= (int)($game['roster_size'] ?? 0) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Bench</span>
            <?= (int)($game['bench_count'] ?? 0) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Reason</span>
            <?= h((string)($game['delete_reason'] ?? 'Cancelled')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Cancelled</span>
            <?= h((string)($game['deleted_at'] ?? '')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Created</span>
            <?= h((string)($game['created_at'] ?? '')) ?>
          </div>

          <div class="stack-actions">

<form method="post" action="cancelled_games.php"
      onsubmit="return confirm('Restore this game?');">
          <?= csrf_field() ?>

    <input type="hidden" name="action" value="restore_game">
    <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">

    <button type="submit" class="btn">
        Restore Game
    </button>

</form>

</div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
