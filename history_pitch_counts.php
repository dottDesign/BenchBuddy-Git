<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Edit Pitch Counts';
$currentPage = 'history';

$message = '';
$error = '';
$game = null;
$pitchLog = [];
$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : (int)($_POST['game_db_id'] ?? 0);

function refresh_archive_after_pitch_update(int $teamId, int $gameDbId): void
{
    $archivePayload = build_archive_payload($teamId, $gameDbId);
    $archiveJson = json_encode($archivePayload, JSON_UNESCAPED_UNICODE);

    if ($archiveJson === false) {
        throw new RuntimeException('Failed to rebuild archive JSON.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO archived_games (team_id, game_db_id, archive_json)
        VALUES (:team_id, :game_db_id, :archive_json)
        ON DUPLICATE KEY UPDATE archive_json = VALUES(archive_json)
    ");
    $stmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
        'archive_json' => $archiveJson,
    ]);
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($selectedGameId <= 0) {
        throw new RuntimeException('Please select a game from History first.');
    }

    $game = get_game_by_id($teamId, $selectedGameId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    if (!in_array((string)($game['status'] ?? ''), ['generated', 'locked'], true)) {
        throw new RuntimeException('Pitch counts can only be edited for built or finalized games.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_pitch_counts') {
            if (!isset($_POST['pitch_counts']) || !is_array($_POST['pitch_counts'])) {
                throw new RuntimeException('No pitch counts were submitted.');
            }

            save_pitch_counts_for_game($teamId, $selectedGameId, $_POST['pitch_counts']);
            refresh_archive_after_pitch_update($teamId, $selectedGameId);
            flash_redirect('ok', 'Pitch counts updated successfully.', 'history_pitch_counts.php');
            $game = get_game_by_id($teamId, $selectedGameId);
        }
    }

    $pitchLog = get_pitch_log_for_game($teamId, $selectedGameId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Edit Pitch Counts</h1>

<div class="workflow">
  <div class="workflow-step">History</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step active">Edit Pitch Counts</div>
</div>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($game): ?>
  <div class="card">
    <h2>Game Details</h2>

    <div class="meta">
      <div class="meta-box">
        <div class="meta-label">Game ID</div>
        <div class="meta-value"><?= h((string)$game['game_id']) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Status</div>
        <div class="meta-value"><?= h((string)$game['status']) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Innings</div>
        <div class="meta-value"><?= (int)$game['innings'] ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Locked At</div>
        <div class="meta-value">
          <?= !empty($game['locked_at']) ? h((string)$game['locked_at']) : 'Not finalized' ?>
        </div>
      </div>
    </div>

    <div class="stack-actions" style="margin-top:18px;">
      <a class="btn btn-secondary" href="history.php">Back to History</a>
      <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$game['id'] ?>">Open Finalize Page</a>
    </div>
  </div>

  <div class="card">
    <h2>Pitch Log</h2>

    <form method="post" action="history_pitch_counts.php?game_id=<?= (int)$game['id'] ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_pitch_counts">
      <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">

      <div class="table-wrap desktop-table">
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>Innings Pitched</th>
              <th>Pitches Thrown</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pitchLog)): ?>
              <tr>
                <td colspan="3">No pitch log recorded for this game.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($pitchLog as $row): ?>
                <tr>
                  <td>
                    <?php if (!empty($row['jersey_number'])): ?>
                      #<?= h((string)$row['jersey_number']) ?>
                    <?php endif; ?>
                    <?= h((string)$row['name']) ?>
                  </td>
                  <td><?= (int)($row['innings_pitched'] ?? 0) ?></td>
                  <td style="width:180px;">
                    <input
                      type="number"
                      name="pitch_counts[<?= (int)$row['player_id'] ?>]"
                      min="0"
                      value="<?= (int)($row['pitches_thrown'] ?? 0) ?>"
                      style="max-width:140px;"
                    >
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-cards">
        <?php if (empty($pitchLog)): ?>
          <p class="muted">No pitch log recorded for this game.</p>
        <?php else: ?>
          <?php foreach ($pitchLog as $row): ?>
            <div class="mobile-card">
              <div class="mobile-card-title">
                <?php if (!empty($row['jersey_number'])): ?>
                  #<?= h((string)$row['jersey_number']) ?>
                <?php endif; ?>
                <?= h((string)$row['name']) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Innings</span>
                <?= (int)($row['innings_pitched'] ?? 0) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Pitches</span>
                <?= (int)($row['pitches_thrown'] ?? 0) ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if (!empty($pitchLog)): ?>
        <div class="actions-row">
          <button type="submit">Save Pitch Counts</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <?php if ((string)($game['status'] ?? '') === 'locked'): ?>
    <div class="card">
      <h2>Note</h2>
      <p>
        This game is finalized. Updating pitch counts here will also refresh the archived game record so History stays accurate.
      </p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
