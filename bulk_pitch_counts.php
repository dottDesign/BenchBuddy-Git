<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Bulk Pitch Counts';
$currentPage = 'bulk_pitch_counts';

$message = '';
$error = '';

$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$allowedStatuses = ['all', 'generated', 'locked'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$games = [];
$gamePitchLogs = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $postedStatus = trim((string)($_POST['status_filter'] ?? 'all'));
        if (in_array($postedStatus, $allowedStatuses, true)) {
            $statusFilter = $postedStatus;
        }

        $allPitchCounts = $_POST['pitch_counts'] ?? null;

        if (!is_array($allPitchCounts) || empty($allPitchCounts)) {
            throw new RuntimeException('No pitch counts were submitted.');
        }

        $savedGames = 0;

        foreach ($allPitchCounts as $gameDbId => $counts) {
            $gameDbId = (int)$gameDbId;

            if ($gameDbId <= 0 || !is_array($counts)) {
                continue;
            }

            $game = get_game_by_id($teamId, $gameDbId);
            if (!$game) {
                continue;
            }

            if (!in_array((string)($game['status'] ?? ''), ['generated', 'locked'], true)) {
                continue;
            }

            save_pitch_counts_for_game($teamId, $gameDbId, $counts);
            $savedGames++;
        }

        $message = $savedGames > 0
            ? 'Pitch counts updated successfully across ' . $savedGames . ' game(s).'
            : 'No eligible games were updated.';
    }

    $games = $statusFilter === 'all'
        ? get_history_games($teamId, 'all')
        : get_history_games($teamId, $statusFilter);

    $games = array_values(array_filter($games, function (array $game): bool {
        return in_array((string)($game['status'] ?? ''), ['generated', 'locked'], true);
    }));

    foreach ($games as $game) {
        $gameId = (int)$game['id'];

        $stmt = db()->prepare("
            SELECT
                pl.player_id,
                p.first_name,
                p.last_name,
                p.jersey_number,
                TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name,
                SUM(COALESCE(pl.innings_pitched, 0)) AS innings_pitched,
                SUM(COALESCE(pl.pitches_thrown, 0)) AS pitches_thrown
            FROM pitch_log pl
            INNER JOIN players p
                ON p.id = pl.player_id
               AND p.team_id = pl.team_id
            WHERE pl.team_id = :team_id
              AND pl.game_db_id = :game_id
            GROUP BY
                pl.player_id,
                p.first_name,
                p.last_name,
                p.jersey_number
            HAVING innings_pitched > 0
                OR pitches_thrown > 0
            ORDER BY
                p.last_name ASC,
                p.first_name ASC
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $pitchLog = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($pitchLog)) {
            $gamePitchLogs[$gameId] = $pitchLog;
        }
    }

    $games = array_values(array_filter($games, function (array $game) use ($gamePitchLogs): bool {
        return !empty($gamePitchLogs[(int)$game['id']] ?? []);
    }));
} catch (Throwable $e) {
    $error = $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Bulk Pitch Counts</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Filter Games</h2>

  <form method="get" action="bulk_pitch_counts.php">
      <?= csrf_field() ?>
    <div class="actions-row">
      <div>
        <label for="status">Status</label>
        <select name="status" id="status">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
          <option value="generated" <?= $statusFilter === 'generated' ? 'selected' : '' ?>>Generated</option>
          <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Locked</option>
        </select>
      </div>

      <div style="align-self:end;">
        <button type="submit">Load Games</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Update Pitch Counts</h2>

  <?php if (empty($games)): ?>
    <p class="muted">No generated or locked games with pitch logs were found.</p>
  <?php else: ?>
    <form method="post" action="bulk_pitch_counts.php?status=<?= urlencode($statusFilter) ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>">

      <div class="actions-row" style="margin-bottom:18px;">
        <button type="submit">Save All Pitch Counts</button>
      </div>

      <?php foreach ($games as $game): ?>
        <?php
          $gameId = (int)$game['id'];
          $pitchLog = $gamePitchLogs[$gameId] ?? [];
        ?>
        <div class="card" style="margin-bottom:18px; background:#fafafa;">
          <div class="meta">
            <div class="meta-box">
              <div class="meta-label">Game ID</div>
              <div class="meta-value"><?= h((string)$game['game_id']) ?></div>
            </div>

            <div class="meta-box">
              <div class="meta-label">Status</div>
              <div class="meta-value">
                <span class="pill <?= h(status_class((string)$game['status'])) ?>">
                  <?= h((string)$game['status']) ?>
                </span>
              </div>
            </div>

            <div class="meta-box">
              <div class="meta-label">Innings</div>
              <div class="meta-value"><?= (int)$game['innings'] ?></div>
            </div>

            <div class="meta-box">
              <div class="meta-label">Locked At</div>
              <div class="meta-value">
                <?= ($game['locked_at'] ?? '') !== '' ? h((string)$game['locked_at']) : 'Not locked' ?>
              </div>
            </div>
          </div>

          <div class="actions-row" style="margin-top:14px;">
            <a class="btn btn-secondary" href="history_pitch_counts.php?game_id=<?= $gameId ?>">Open Single Game Editor</a>
            <a class="btn btn-secondary" href="lock.php?game_id=<?= $gameId ?>">Open Finalize Page</a>
          </div>

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
                <?php foreach ($pitchLog as $row): ?>
                  <tr>
                    <td>
                      <?php if (!empty($row['jersey_number'])): ?>
                        #<?= h((string)$row['jersey_number']) ?>
                      <?php endif; ?>
                      <?= h((string)$row['name']) ?>
                    </td>
                    <td><?= h(number_format((float)$row['innings_pitched'], 1)) ?></td>
                    <td style="width:180px;">
                      <input
                        type="number"
                        name="pitch_counts[<?= $gameId ?>][<?= (int)$row['player_id'] ?>]"
                        min="0"
                        value="<?= (int)($row['pitches_thrown'] ?? 0) ?>"
                        style="max-width:140px;"
                      >
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="mobile-cards">
            <?php foreach ($pitchLog as $row): ?>
              <div class="mobile-card">
                <div class="mobile-card-title">
                  <?php if (!empty($row['jersey_number'])): ?>
                    #<?= h((string)$row['jersey_number']) ?>
                  <?php endif; ?>
                  <?= h((string)$row['name']) ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Game</span>
                  <?= h((string)$game['game_id']) ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Innings</span>
                  <?= h(number_format((float)$row['innings_pitched'], 1)) ?>
                </div>

                <div class="lock-mobile-input">
                  <label for="pitch_count_<?= $gameId ?>_<?= (int)$row['player_id'] ?>">Pitches Thrown</label>
                  <input
                    type="number"
                    id="pitch_count_<?= $gameId ?>_<?= (int)$row['player_id'] ?>"
                    name="pitch_counts[<?= $gameId ?>][<?= (int)$row['player_id'] ?>]"
                    min="0"
                    value="<?= (int)($row['pitches_thrown'] ?? 0) ?>"
                  >
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="actions-row">
        <button type="submit">Save All Pitch Counts</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
