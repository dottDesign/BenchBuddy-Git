<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/coach/coach_dashboard.php';

require_login();

$pageTitle = 'Coach Dashboard';
$currentPage = 'coach_dashboard';

$teamId = current_team_id();

$error = '';
$nextGame = null;
$recentGames = [];
$pitchingAvailability = [];
$recentBatting = [];
$attentionItems = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    $nextGame = get_next_coach_game($teamId);
    $nextGameDate = $nextGame && !empty($nextGame['game_date']) ? (string)$nextGame['game_date'] : null;

    $recentGames = get_recent_coach_games($teamId, 5);
    $pitchingAvailability = get_coach_pitching_availability($teamId, $nextGameDate);
    $recentBatting = get_coach_recent_batting_snapshot($teamId, 8);
    $attentionItems = get_coach_attention_items($teamId, $nextGame);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Coach Dashboard</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($error === ''): ?>
  <div class="coach-dashboard-grid">
    <div class="card coach-dashboard-card coach-dashboard-next-game">
      <h2>Next Game</h2>

      <?php if (!$nextGame): ?>
        <p class="muted">No upcoming games found.</p>

        <div class="actions-row">
          <a class="btn" href="games.php">Add Game</a>
        </div>
      <?php else: ?>
        <div class="coach-next-game-title">
          <?= h((string)$nextGame['game_id']) ?>
        </div>

        <div class="coach-next-game-meta">
          <?php if (!empty($nextGame['game_date'])): ?>
            <span><?= h((string)$nextGame['game_date']) ?></span>
          <?php else: ?>
            <span>No date set</span>
          <?php endif; ?>

          <?php if (!empty($nextGame['home_away'])): ?>
            <span><?= h(ucfirst((string)$nextGame['home_away'])) ?></span>
          <?php endif; ?>

          <?php if (!empty($nextGame['tournament_name'])): ?>
            <span>Tournament: <?= h((string)$nextGame['tournament_name']) ?></span>
          <?php endif; ?>
        </div>

        <div class="actions-row" style="margin-top:16px;">
          <a class="btn" href="generate.php?game_id=<?= (int)$nextGame['id'] ?>">Build Lineup</a>
          <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$nextGame['id'] ?>">Finalize</a>
          <a class="btn btn-secondary" href="game_stats.php?game_id=<?= (int)$nextGame['id'] ?>">Stats</a>
          <?php if (!empty($nextGame['tournament_id'])): ?>
            <a class="btn btn-secondary" href="tournament.php?id=<?= (int)$nextGame['tournament_id'] ?>">Tournament</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card coach-dashboard-card">
      <h2>Coach Alerts</h2>

      <?php foreach ($attentionItems as $item): ?>
        <?php
          $level = (string)($item['level'] ?? 'info');
          $levelClass = 'coach-alert-info';

          if ($level === 'warning') {
              $levelClass = 'coach-alert-warning';
          } elseif ($level === 'ok') {
              $levelClass = 'coach-alert-ok';
          }
        ?>
        <div class="coach-alert <?= h($levelClass) ?>">
          <strong><?= h((string)$item['title']) ?></strong>
          <p><?= h((string)$item['body']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>Pitching Availability</h2>

    <p class="muted">
      Based on pitch logs and the next game date.
    </p>

    <?php if (empty($pitchingAvailability)): ?>
      <p class="muted">No players found.</p>
    <?php else: ?>
      <div class="table-wrap coach-table">
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>Status</th>
              <th>Pitch Role</th>
              <th>Last Outing</th>
              <th>Last Pitches</th>
              <th>Next Available</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pitchingAvailability as $row): ?>
              <?php
                $statusKey = (string)$row['status_key'];
                $statusClass = $statusKey === 'resting' ? 'resting' : 'available';
              ?>
              <tr>
                <td data-label="Player"><?= h((string)$row['name']) ?></td>
                <td data-label="Status">
                  <span class="coach-status-pill <?= h($statusClass) ?>">
                    <?= h((string)$row['status']) ?>
                  </span>
                </td>
                <td data-label="Pitch Role"><?= h(ucfirst((string)$row['pitching_role'])) ?></td>
                <td data-label="Last Outing">
                  <?php if (!empty($row['last_game_date'])): ?>
                    <?= h((string)$row['last_game_date']) ?>
                    <?php if (!empty($row['last_game_label'])): ?>
                      <br><span class="muted"><?= h((string)$row['last_game_label']) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">No outing</span>
                  <?php endif; ?>
                </td>
                <td data-label="Last Pitches"><?= (int)$row['last_pitches'] ?></td>
                <td data-label="Next Available">
                  <?= !empty($row['next_available_date']) ? h((string)$row['next_available_date']) : 'Now' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="coach-dashboard-grid">
    <div class="card coach-dashboard-card">
      <h2>Recent Batting Leaders</h2>

      <?php if (empty($recentBatting)): ?>
        <p class="muted">No batting stats imported yet.</p>
      <?php else: ?>
        <div class="table-wrap coach-table">
          <table>
            <thead>
              <tr>
                <th>Player</th>
                <th>G</th>
                <th>AB</th>
                <th>H</th>
                <th>AVG</th>
                <th>RBI</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentBatting as $row): ?>
                <tr>
                  <td data-label="Player"><?= h((string)$row['display_name']) ?></td>
                  <td data-label="G"><?= (int)$row['games_played'] ?></td>
                  <td data-label="AB"><?= (int)$row['at_bats'] ?></td>
                  <td data-label="H"><?= (int)$row['hits'] ?></td>
                  <td data-label="AVG"><?= h(format_baseball_rate((float)$row['avg'])) ?></td>
                  <td data-label="RBI"><?= (int)$row['rbi'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card coach-dashboard-card">
      <h2>Recent Games</h2>

      <?php if (empty($recentGames)): ?>
        <p class="muted">No recent games found.</p>
      <?php else: ?>
        <div class="coach-recent-list">
          <?php foreach ($recentGames as $game): ?>
            <div class="coach-recent-game">
              <div>
                <strong><?= h((string)$game['game_id']) ?></strong>
                <div class="muted">
                  <?= !empty($game['game_date']) ? h((string)$game['game_date']) : 'No date' ?>
                  <?php if (!empty($game['tournament_name'])): ?>
                    · <?= h((string)$game['tournament_name']) ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="coach-recent-actions">
                <a class="btn btn-secondary" href="game_stats.php?game_id=<?= (int)$game['id'] ?>">Stats</a>
                <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$game['id'] ?>">Pitching</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Quick Actions</h2>

    <div class="actions-row">
      <a class="btn" href="games.php">Games</a>
      <a class="btn btn-secondary" href="players.php">Players</a>
      <a class="btn btn-secondary" href="player_stats.php">Player Stats</a>
      <a class="btn btn-secondary" href="import_game_stats.php">Import Stats</a>
      <a class="btn btn-secondary" href="tournaments.php">Tournaments</a>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
