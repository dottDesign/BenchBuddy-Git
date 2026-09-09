<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Game Stats';
$currentPage = 'game_stats';

$teamId = current_team_id();

if (!team_stats_enabled($teamId)) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <h1 class="page-title brand-title-font">Game Stats</h1>
    <div class="card">
      <h2>Stats Recording Is Turned Off</h2>
      <p class="muted">
        Player stat recording is disabled for this team. Pitch counts are still available.
      </p>
      <div class="actions-row">
        <a class="btn btn-secondary" href="account.php">Open Account Settings</a>
        <a class="btn" href="games.php">Back to Games</a>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : (int)($_POST['game_id'] ?? 0);

if ($gameId <= 0) {
    $stmt = db()->prepare("
        SELECT id, game_id, game_date, status
        FROM games
        WHERE team_id = :team_id
          AND deleted_at IS NULL
          AND status <> 'locked'
        ORDER BY
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date DESC,
            id DESC
    ");

    $stmt->execute(['team_id' => $teamId]);
    $availableGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/includes/header.php';
    ?>

    <h1 class="page-title brand-title-font">Game Stats</h1>

    <div class="card" style="max-width:520px;">
      <h2>Select Game</h2>

      <?php if (empty($availableGames)): ?>
        <p class="muted">No active games available for stat entry.</p>

        <div class="actions-row">
          <a class="btn btn-secondary" href="games.php">Back to Games</a>
        </div>
      <?php else: ?>
        <form method="get">
            <?= csrf_field() ?>
          <label style="display:block; margin-bottom:18px;">
            <span>Select Game</span>

            <select name="game_id" required style="width:100%; margin-top:8px;">
              <option value="">Choose a game</option>

              <?php foreach ($availableGames as $game): ?>
                <option value="<?= (int)$game['id'] ?>">
                  <?= h((string)$game['game_id']) ?>
                  <?php if (!empty($game['game_date'])): ?>
                    | <?= h((string)$game['game_date']) ?>
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="actions-row">
            <button type="submit">Open Game Stats</button>
            <a class="btn btn-secondary" href="games.php">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$error = '';
$message = '';
$game = null;
$roster = [];
$battingStats = [];
$pitchingStats = [];
$pitchLogTotals = [];

$stmt = db()->prepare("
    SELECT
        player_id,
        COALESCE(SUM(innings_pitched), 0) AS total_innings,
        COALESCE(SUM(pitches_thrown), 0) AS total_pitches
    FROM pitch_log
    WHERE team_id = :team_id
      AND game_db_id = :game_id
    GROUP BY player_id
");

$stmt->execute([
    'team_id' => $teamId,
    'game_id' => $gameId,
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pitchLogTotals[(int)$row['player_id']] = [
        'innings_pitched' => (float)$row['total_innings'],
        'pitches_thrown' => (int)$row['total_pitches'],
    ];
}

function int_post_stat(array $source, int $playerId, string $key): int
{
    return max(0, (int)($source[$playerId][$key] ?? 0));
}

function float_post_stat(array $source, int $playerId, string $key): float
{
    return max(0, (float)($source[$playerId][$key] ?? 0));
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    if ($gameId <= 0) {
        throw new RuntimeException('No game selected.');
    }

    $game = get_game_by_id($teamId, $gameId);

    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $roster = get_game_roster($teamId, $gameId);

    if (empty($roster)) {
        $stmt = db()->prepare("
            SELECT DISTINCT
                p.*
            FROM players p
            INNER JOIN player_batting_stats pbs
                ON pbs.player_id = p.id
               AND pbs.team_id = p.team_id
            WHERE p.team_id = :team_id
              AND pbs.game_id = :game_id
            ORDER BY
                p.last_name ASC,
                p.first_name ASC,
                p.id ASC
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($roster)) {
        $stmt = db()->prepare("
            SELECT DISTINCT
                p.*
            FROM players p
            INNER JOIN player_batting_game_stats pbgs
                ON pbgs.player_id = p.id
               AND pbgs.team_id = p.team_id
            WHERE p.team_id = :team_id
              AND pbgs.game_db_id = :game_id
            ORDER BY
                p.last_name ASC,
                p.first_name ASC,
                p.id ASC
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($roster)) {
        $roster = get_active_players($teamId);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();

        $action = (string)($_POST['action'] ?? 'save_game_stats');

        if ($action === 'clear_game_stats') {
            $pdo = db();
            $pdo->beginTransaction();

            try {
                $pdo->prepare("
                    DELETE FROM player_batting_stats
                    WHERE team_id = :team_id
                      AND game_id = :game_id
                ")->execute([
                    'team_id' => $teamId,
                    'game_id' => $gameId,
                ]);

                $pdo->prepare("
                    DELETE FROM player_batting_game_stats
                    WHERE team_id = :team_id
                      AND game_db_id = :game_id
                ")->execute([
                    'team_id' => $teamId,
                    'game_id' => $gameId,
                ]);

                $pdo->prepare("
                    DELETE FROM player_pitching_stats
                    WHERE team_id = :team_id
                      AND game_id = :game_id
                ")->execute([
                    'team_id' => $teamId,
                    'game_id' => $gameId,
                ]);

                $pdo->prepare("
                    DELETE FROM pitch_log
                    WHERE team_id = :team_id
                      AND game_db_id = :game_id
                ")->execute([
                    'team_id' => $teamId,
                    'game_id' => $gameId,
                ]);

                $pdo->commit();

                header('Location: game_stats.php?game_id=' . $gameId . '&msg=cleared');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $postedBatting = $_POST['batting'] ?? [];
        $postedPitching = $_POST['pitching'] ?? [];

        if (!is_array($postedBatting)) {
            $postedBatting = [];
        }

        if (!is_array($postedPitching)) {
            $postedPitching = [];
        }

        $pdo = db();
        $pdo->beginTransaction();

        $pdo->prepare("
            DELETE FROM player_batting_stats
            WHERE team_id = :team_id
              AND game_id = :game_id
        ")->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $pdo->prepare("
            DELETE FROM player_batting_game_stats
            WHERE team_id = :team_id
              AND game_db_id = :game_id
        ")->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $pdo->prepare("
            DELETE FROM player_pitching_stats
            WHERE team_id = :team_id
              AND game_id = :game_id
        ")->execute([
            'team_id' => $teamId,
            'game_id' => $gameId,
        ]);

        $battingInsert = $pdo->prepare("
            INSERT INTO player_batting_stats (
                player_id,
                team_id,
                game_id,
                games_played,
                at_bats,
                runs,
                hits,
                doubles_hit,
                triples_hit,
                home_runs,
                rbi,
                walks,
                strikeouts,
                hit_by_pitch,
                sacrifice_flies,
                stolen_bases
            ) VALUES (
                :player_id,
                :team_id,
                :game_id,
                :games_played,
                :at_bats,
                :runs,
                :hits,
                :doubles_hit,
                :triples_hit,
                :home_runs,
                :rbi,
                :walks,
                :strikeouts,
                :hit_by_pitch,
                :sacrifice_flies,
                :stolen_bases
            )
        ");

        $battingGameInsert = $pdo->prepare("
            INSERT INTO player_batting_game_stats (
                player_id,
                team_id,
                game_db_id,
                at_bats,
                runs,
                hits,
                doubles_hit,
                triples_hit,
                home_runs,
                rbi,
                walks,
                strikeouts,
                hit_by_pitch,
                sacrifice_flies,
                stolen_bases
            ) VALUES (
                :player_id,
                :team_id,
                :game_db_id,
                :at_bats,
                :runs,
                :hits,
                :doubles_hit,
                :triples_hit,
                :home_runs,
                :rbi,
                :walks,
                :strikeouts,
                :hit_by_pitch,
                :sacrifice_flies,
                :stolen_bases
            )
        ");

        $pitchingInsert = $pdo->prepare("
            INSERT INTO player_pitching_stats (
                player_id,
                team_id,
                game_id,
                innings_pitched,
                pitches_thrown,
                hits_allowed,
                runs_allowed,
                earned_runs,
                walks,
                strikeouts,
                wins,
                losses,
                saves
            ) VALUES (
                :player_id,
                :team_id,
                :game_id,
                :innings_pitched,
                :pitches_thrown,
                :hits_allowed,
                :runs_allowed,
                :earned_runs,
                :walks,
                :strikeouts,
                :wins,
                :losses,
                :saves
            )
        ");

        foreach ($roster as $player) {
            $playerId = (int)$player['id'];

            $ab = int_post_stat($postedBatting, $playerId, 'at_bats');
            $runs = int_post_stat($postedBatting, $playerId, 'runs');
            $hits = int_post_stat($postedBatting, $playerId, 'hits');
            $doubles = int_post_stat($postedBatting, $playerId, 'doubles_hit');
            $triples = int_post_stat($postedBatting, $playerId, 'triples_hit');
            $hr = int_post_stat($postedBatting, $playerId, 'home_runs');
            $rbi = int_post_stat($postedBatting, $playerId, 'rbi');
            $walks = int_post_stat($postedBatting, $playerId, 'walks');
            $strikeouts = int_post_stat($postedBatting, $playerId, 'strikeouts');
            $hbp = int_post_stat($postedBatting, $playerId, 'hit_by_pitch');
            $sf = int_post_stat($postedBatting, $playerId, 'sacrifice_flies');
            $sb = int_post_stat($postedBatting, $playerId, 'stolen_bases');

            if (
                $ab > 0 ||
                $runs > 0 ||
                $hits > 0 ||
                $doubles > 0 ||
                $triples > 0 ||
                $hr > 0 ||
                $walks > 0 ||
                $strikeouts > 0 ||
                $rbi > 0 ||
                $hbp > 0 ||
                $sf > 0 ||
                $sb > 0
            ) {
                $battingPayload = [
                    'player_id' => $playerId,
                    'team_id' => $teamId,
                    'at_bats' => $ab,
                    'runs' => $runs,
                    'hits' => $hits,
                    'doubles_hit' => $doubles,
                    'triples_hit' => $triples,
                    'home_runs' => $hr,
                    'rbi' => $rbi,
                    'walks' => $walks,
                    'strikeouts' => $strikeouts,
                    'hit_by_pitch' => $hbp,
                    'sacrifice_flies' => $sf,
                    'stolen_bases' => $sb,
                ];

                $battingInsert->execute($battingPayload + [
                    'game_id' => $gameId,
                    'games_played' => 1,
                ]);

                $battingGameInsert->execute($battingPayload + [
                    'game_db_id' => $gameId,
                ]);
            }

            $ip = float_post_stat($postedPitching, $playerId, 'innings_pitched');
            $pitches = int_post_stat($postedPitching, $playerId, 'pitches_thrown');
            $ha = int_post_stat($postedPitching, $playerId, 'hits_allowed');
            $ra = int_post_stat($postedPitching, $playerId, 'runs_allowed');
            $er = int_post_stat($postedPitching, $playerId, 'earned_runs');
            $pwalks = int_post_stat($postedPitching, $playerId, 'walks');
            $pks = int_post_stat($postedPitching, $playerId, 'strikeouts');
            $wins = int_post_stat($postedPitching, $playerId, 'wins');
            $losses = int_post_stat($postedPitching, $playerId, 'losses');
            $saves = int_post_stat($postedPitching, $playerId, 'saves');

            if (
                $ip > 0 ||
                $pitches > 0 ||
                $ha > 0 ||
                $ra > 0 ||
                $er > 0 ||
                $pwalks > 0 ||
                $pks > 0 ||
                $wins > 0 ||
                $losses > 0 ||
                $saves > 0
            ) {
                $pitchingInsert->execute([
                    'player_id' => $playerId,
                    'team_id' => $teamId,
                    'game_id' => $gameId,
                    'innings_pitched' => $ip,
                    'pitches_thrown' => $pitches,
                    'hits_allowed' => $ha,
                    'runs_allowed' => $ra,
                    'earned_runs' => $er,
                    'walks' => $pwalks,
                    'strikeouts' => $pks,
                    'wins' => $wins,
                    'losses' => $losses,
                    'saves' => $saves,
                ]);
            }
        }

        $pdo->commit();

        header('Location: game_stats.php?game_id=' . $gameId . '&msg=saved');
        exit;
    }

    $msg = (string)($_GET['msg'] ?? '');

    if ($msg === 'saved') {
        $message = 'Game stats saved successfully.';
    }

    if ($msg === 'cleared') {
        $message = 'Game stats cleared successfully.';
    }

    $stmt = db()->prepare("
        SELECT *
        FROM player_batting_game_stats
        WHERE team_id = :team_id
          AND game_db_id = :game_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $battingStats[(int)$row['player_id']] = $row;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM player_pitching_stats
        WHERE team_id = :team_id
          AND game_id = :game_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pitchingStats[(int)$row['player_id']] = $row;
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Game Stats</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($game && !empty($roster)): ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>
      <?= h((string)$game['game_id']) ?>
      <?php if (!empty($game['game_date'])): ?>
        | <?= h((string)$game['game_date']) ?>
      <?php endif; ?>
    </h2>

    <p class="muted">
      Enter lightweight game stats for this roster. These totals will feed player stat cards and future lineup intelligence.
    </p>
  </div>

  <form method="post">
      <?= csrf_field() ?>
    <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">
    <input type="hidden" name="action" value="save_game_stats">

    <div class="card" style="margin-bottom:18px;">
      <h2>Batting Stats</h2>

      <div class="game-stats-card-list">
        <?php foreach ($roster as $player): ?>
          <?php
            $playerId = (int)$player['id'];
            $row = $battingStats[$playerId] ?? [];

            $ab = (int)($row['at_bats'] ?? 0);
            $hits = (int)($row['hits'] ?? 0);
            $doubles = (int)($row['doubles_hit'] ?? 0);
            $triples = (int)($row['triples_hit'] ?? 0);
            $hr = (int)($row['home_runs'] ?? 0);
            $bb = (int)($row['walks'] ?? 0);
            $hbp = (int)($row['hit_by_pitch'] ?? 0);
            $sf = (int)($row['sacrifice_flies'] ?? 0);

            $avg = calculate_batting_average($hits, $ab);
            $obp = calculate_obp($hits, $bb, $hbp, $ab, $sf);
            $slg = calculate_slugging($hits, $doubles, $triples, $hr, $ab);
            $ops = calculate_ops($obp, $slg);
          ?>

          <article class="game-stat-player-card">
            <div class="game-stat-player-header">
              <h3><?= h(player_full_name($player)) ?></h3>

              <div class="game-rate-row">
                <span>AVG <strong><?= h(format_baseball_rate($avg)) ?></strong></span>
                <span>OBP <strong><?= h(format_baseball_rate($obp)) ?></strong></span>
                <span>SLG <strong><?= h(format_baseball_rate($slg)) ?></strong></span>
                <span>OPS <strong><?= h(format_baseball_rate($ops)) ?></strong></span>
              </div>
            </div>

            <div class="game-stat-input-grid">
              <?php
                $battingFields = [
                  'at_bats' => 'AB',
                  'runs' => 'R',
                  'hits' => 'H',
                  'doubles_hit' => '2B',
                  'triples_hit' => '3B',
                  'home_runs' => 'HR',
                  'rbi' => 'RBI',
                  'walks' => 'BB',
                  'strikeouts' => 'K',
                  'hit_by_pitch' => 'HBP',
                  'sacrifice_flies' => 'SF',
                  'stolen_bases' => 'SB',
                ];
              ?>

              <?php foreach ($battingFields as $field => $label): ?>
                <label class="game-stat-input">
                  <span><?= h($label) ?></span>
                  <input
                    type="number"
                    min="0"
                    name="batting[<?= $playerId ?>][<?= h($field) ?>]"
                    value="<?= (int)($row[$field] ?? 0) ?>"
                  >
                </label>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2>Pitching Stats</h2>

      <div class="game-stats-card-list">
        <?php foreach ($roster as $player): ?>
          <?php
            $playerId = (int)$player['id'];
            $row = $pitchingStats[$playerId] ?? [];

            $recordedInningsPitched = (float)($pitchLogTotals[$playerId]['innings_pitched'] ?? 0);
            $recordedPitchCount = (int)($pitchLogTotals[$playerId]['pitches_thrown'] ?? 0);

            $savedIp = (float)($row['innings_pitched'] ?? 0);
            $ip = $savedIp > 0 ? $savedIp : $recordedInningsPitched;

            $er = (int)($row['earned_runs'] ?? 0);
            $walks = (int)($row['walks'] ?? 0);
            $hitsAllowed = (int)($row['hits_allowed'] ?? 0);

            $era = calculate_era($er, $ip);
            $whip = calculate_whip($walks, $hitsAllowed, $ip);
          ?>

          <article class="game-stat-player-card">
            <div class="game-stat-player-header">
              <h3><?= h(player_full_name($player)) ?></h3>

              <div class="game-rate-row">
                <span>IP <strong><?= h(number_format($ip, 1)) ?></strong></span>
                <span>Pitches <strong><?= (int)($row['pitches_thrown'] ?? $recordedPitchCount) ?></strong></span>
                <span>ERA <strong><?= h(number_format($era, 2)) ?></strong></span>
                <span>WHIP <strong><?= h(number_format($whip, 2)) ?></strong></span>
              </div>
            </div>

            <div class="game-stat-input-grid">
              <label class="game-stat-input">
                <span>IP</span>
                <input
                  type="number"
                  min="0"
                  step="0.1"
                  name="pitching[<?= $playerId ?>][innings_pitched]"
                  value="<?= h((string)$ip) ?>"
                >
              </label>

              <label class="game-stat-input">
                <span>Pitches</span>
                <input
                  type="number"
                  min="0"
                  name="pitching[<?= $playerId ?>][pitches_thrown]"
                  value="<?= (int)($row['pitches_thrown'] ?? $recordedPitchCount) ?>"
                >
              </label>

              <?php
                $pitchingFields = [
                  'hits_allowed' => 'H',
                  'runs_allowed' => 'R',
                  'earned_runs' => 'ER',
                  'walks' => 'BB',
                  'strikeouts' => 'K',
                  'wins' => 'W',
                  'losses' => 'L',
                  'saves' => 'SV',
                ];
              ?>

              <?php foreach ($pitchingFields as $field => $label): ?>
                <label class="game-stat-input">
                  <span><?= h($label) ?></span>
                  <input
                    type="number"
                    min="0"
                    name="pitching[<?= $playerId ?>][<?= h($field) ?>]"
                    value="<?= (int)($row[$field] ?? 0) ?>"
                  >
                </label>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="actions-row">
      <button type="submit">Save Game Stats</button>
      <a class="btn btn-secondary" href="games.php">Back to Games</a>
      <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$gameId ?>">Finalize Game</a>

      <button
        type="submit"
        name="action"
        value="clear_game_stats"
        class="btn btn-secondary"
        onclick="return confirm('Clear all batting, pitching, and pitch count stats for this game? This cannot be undone.');"
      >
        Clear Game Stats
      </button>
    </div>
  </form>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
