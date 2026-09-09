<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tournaments/tournament_functions.php';

require_login();

$pageTitle = 'Tournament';
$currentPage = 'tournaments';

$teamId = current_team_id();
$tournamentId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

$error = '';
$tournament = null;
$games = [];
$availableGames = [];
$pitchingSnapshot = [];
$playerStatsOverview = [];

$allowedPlayerStatsSorts = [
    'player' => 'Player',
    'games_played' => 'Games',
    'at_bats' => 'AB',
    'hits' => 'Hits',
    'avg' => 'AVG',
    'runs' => 'Runs',
    'rbi' => 'RBI',
    'walks' => 'BB',
    'strikeouts' => 'K',
    'stolen_bases' => 'SB',
    'innings_pitched' => 'IP',
    'pitches_thrown' => 'Pitches',
    'era' => 'ERA',
    'whip' => 'WHIP',
];

$playerStatsSort = (string)($_GET['stats_sort'] ?? 'avg');
$playerStatsDir = strtolower((string)($_GET['stats_dir'] ?? 'desc'));

if (!array_key_exists($playerStatsSort, $allowedPlayerStatsSorts)) {
    $playerStatsSort = 'player';
}

if (!in_array($playerStatsDir, ['asc', 'desc'], true)) {
    $playerStatsDir = 'asc';
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    if ($tournamentId <= 0) {
        throw new RuntimeException('No tournament selected.');
    }

    $tournament = get_tournament_by_id($teamId, $tournamentId);

    if (!$tournament) {
        throw new RuntimeException('Tournament not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'attach_existing_game') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);

            if ($gameDbId <= 0) {
                throw new RuntimeException('Please select a game to attach.');
            }

            $game = get_game_by_id($teamId, $gameDbId);

            if (!$game) {
                throw new RuntimeException('Game not found for this team.');
            }

            $stmt = db()->prepare("
                UPDATE games
                SET tournament_id = :tournament_id
                WHERE id = :game_id
                  AND team_id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'tournament_id' => $tournamentId,
                'game_id' => $gameDbId,
                'team_id' => $teamId,
            ]);

            flash_redirect('ok', 'Game attached to tournament.', 'tournament.php?id=' . $tournamentId);
        }

        if ($action === 'remove_game') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);

            if ($gameDbId <= 0) {
                throw new RuntimeException('Please select a game to remove.');
            }

            $stmt = db()->prepare("
                UPDATE games
                SET tournament_id = NULL
                WHERE id = :game_id
                  AND team_id = :team_id
                  AND tournament_id = :tournament_id
                LIMIT 1
            ");

            $stmt->execute([
                'game_id' => $gameDbId,
                'team_id' => $teamId,
                'tournament_id' => $tournamentId,
            ]);

            flash_redirect('ok', 'Game removed from tournament.', 'tournament.php?id=' . $tournamentId);
        }

        if ($action === 'add_game') {
            $gameId = trim((string)($_POST['game_id'] ?? ''));
            $gameDate = trim((string)($_POST['game_date'] ?? ''));
            $homeAway = trim((string)($_POST['home_away'] ?? ''));
            $innings = (int)($_POST['innings'] ?? 7);
            $countsTowardStats = isset($_POST['counts_toward_stats']) ? 1 : 0;

            if ($gameId === '') {
                throw new RuntimeException('Game name/opponent is required.');
            }

            if ($gameDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)) {
                throw new RuntimeException('Game date must use YYYY-MM-DD format.');
            }

            if (!in_array($homeAway, ['', 'home', 'away'], true)) {
                throw new RuntimeException('Invalid home/away value.');
            }

            if ($innings <= 0) {
                $innings = 7;
            }

            $season = 'spring';

            $seasonStmt = db()->prepare("
                SELECT current_season
                FROM teams
                WHERE id = :team_id
                LIMIT 1
            ");

            $seasonStmt->execute([
                'team_id' => $teamId,
            ]);

            $teamSeason = trim((string)$seasonStmt->fetchColumn());

            if ($teamSeason !== '') {
                $season = $teamSeason;
            }

            $stmt = db()->prepare("
                INSERT INTO games (
                    team_id,
                    game_id,
                    game_date,
                    home_away,
                    innings,
                    actual_innings_played,
                    counts_for_pitching,
                    status,
                    roster_size,
                    bench_count,
                    tournament_id,
                    counts_toward_stats,
                    season,
                    created_at
                ) VALUES (
                    :team_id,
                    :game_id,
                    :game_date,
                    :home_away,
                    :innings,
                    NULL,
                    1,
                    'draft',
                    0,
                    0,
                    :tournament_id,
                    :counts_toward_stats,
                    :season,
                    NOW()
                )
            ");

            $stmt->execute([
                'team_id' => $teamId,
                'game_id' => $gameId,
                'game_date' => $gameDate !== '' ? $gameDate : null,
                'home_away' => $homeAway !== '' ? $homeAway : null,
                'innings' => $innings,
                'tournament_id' => $tournamentId,
                'counts_toward_stats' => $countsTowardStats,
                'season' => $season,
            ]);

            flash_redirect('ok', 'Tournament game added.', 'tournament.php?id=' . $tournamentId);
        }
    }

    $games = get_games_for_tournament($teamId, $tournamentId);
    $availableGames = get_games_available_for_tournament($teamId, $tournamentId);
    $pitchingSnapshot = get_tournament_pitching_snapshot($teamId, $tournamentId);
    $playerStatsOverview = get_tournament_player_stats_overview($teamId, $tournamentId);

    usort($playerStatsOverview, function (array $a, array $b) use ($playerStatsSort, $playerStatsDir): int {
        $getSortValue = function (array $row) use ($playerStatsSort): mixed {
            if ($playerStatsSort === 'player') {
                return strtolower(tournament_player_display_name($row['player']));
            }

            if ($playerStatsSort === 'avg') {
                return calculate_batting_average(
                    (int)$row['hits'],
                    (int)$row['at_bats']
                );
            }

            if ($playerStatsSort === 'era') {
                return calculate_era(
                    (int)$row['earned_runs'],
                    (float)$row['innings_pitched']
                );
            }

            if ($playerStatsSort === 'whip') {
                return calculate_whip(
                    (int)$row['pitching_walks'],
                    (int)$row['hits_allowed'],
                    (float)$row['innings_pitched']
                );
            }

            return $row[$playerStatsSort] ?? 0;
        };

        $aValue = $getSortValue($a);
        $bValue = $getSortValue($b);

        if (is_string($aValue) || is_string($bValue)) {
            $result = strnatcasecmp((string)$aValue, (string)$bValue);
        } else {
            $result = ((float)$aValue <=> (float)$bValue);
        }

        if ($result === 0) {
            $result = strnatcasecmp(
                tournament_player_display_name($a['player']),
                tournament_player_display_name($b['player'])
            );
        }

        return $playerStatsDir === 'desc' ? -$result : $result;
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Tournament</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($tournament): ?>
  <div class="card tournament-header-card">
    <h2><?= h((string)$tournament['name']) ?></h2>

    <div class="tournament-detail-grid">
      <div class="tournament-detail-box">
        <div class="tournament-detail-label">Dates</div>
        <div class="tournament-detail-value">
          <?= !empty($tournament['start_date']) ? h((string)$tournament['start_date']) : 'No start date' ?>
          <?php if (!empty($tournament['end_date'])): ?>
            to <?= h((string)$tournament['end_date']) ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="tournament-detail-box">
        <div class="tournament-detail-label">Location</div>
        <div class="tournament-detail-value">
          <?= h((string)($tournament['location'] ?? '')) ?>
        </div>
      </div>

      <div class="tournament-detail-box">
        <div class="tournament-detail-label">Rule Set</div>
        <div class="tournament-detail-value">
          <?= h((string)($tournament['rule_set_label'] ?? 'Team default')) ?>
        </div>
      </div>
    </div>

    <?php if (!empty($tournament['notes'])): ?>
      <p class="muted" style="margin-top:16px;">
        <?= nl2br(h((string)$tournament['notes'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Attach Existing Game</h2>

    <p class="muted">
      Use this if the game already exists in BenchBuddy and you want to group it under this tournament.
    </p>

    <?php if (empty($availableGames)): ?>
      <p class="muted">No available games found.</p>
    <?php else: ?>
      <form method="post" action="tournament.php?id=<?= (int)$tournament['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="attach_existing_game">
        <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">

        <label for="game_db_id">Existing Game</label>
        <select id="game_db_id" name="game_db_id" required>
          <option value="">-- Select Game --</option>

          <?php foreach ($availableGames as $availableGame): ?>
            <option value="<?= (int)$availableGame['id'] ?>">
              <?= h((string)$availableGame['game_id']) ?>
              <?php if (!empty($availableGame['game_date'])): ?>
                | <?= h((string)$availableGame['game_date']) ?>
              <?php endif; ?>
              <?php if ((int)($availableGame['tournament_id'] ?? 0) === (int)$tournament['id']): ?>
                | Already attached
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="actions-row" style="margin-top:18px;">
          <button type="submit">Attach Game</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Add Game</h2>

    <form method="post" action="tournament.php?id=<?= (int)$tournament['id'] ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_game">
      <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">

      <label for="game_id">Opponent / Game Label</label>
      <input type="text" id="game_id" name="game_id" placeholder="vs Abbotsford Angels" required>

      <div class="game-meta-grid" style="margin-top:12px;">
        <div class="form-field">
          <label for="game_date">Game Date</label>
          <input type="date" id="game_date" name="game_date">
        </div>

        <div class="form-field">
          <label for="home_away">Home / Away</label>
          <select id="home_away" name="home_away">
            <option value="">-- Select --</option>
            <option value="home">Home</option>
            <option value="away">Away</option>
          </select>
        </div>

        <div class="form-field">
          <label for="innings">Innings</label>
          <input type="number" id="innings" name="innings" min="1" max="20" value="7">
        </div>
      </div>

      <label class="toggle-row" style="margin-top:14px;">
        <span class="toggle-switch">
          <input type="checkbox" name="counts_toward_stats" value="1" checked>
          <span class="toggle-slider"></span>
        </span>
        <span>Counts toward stats</span>
      </label>

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit">Add Game</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Tournament Pitching Snapshot</h2>

    <p class="muted">
      Quick view of tournament pitch usage and current rest status.
    </p>

    <?php if (empty($pitchingSnapshot)): ?>
      <p class="muted">No active players found.</p>
    <?php else: ?>
      <div class="table-wrap tournament-table">
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>Status</th>
              <th>Tournament IP</th>
              <th>Tournament Pitches</th>
              <th>Last Outing</th>
              <th>Last Pitches</th>
              <th>Next Available</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pitchingSnapshot as $row): ?>
              <?php
                $player = $row['player'];
                $status = (string)$row['status'];
                $statusClass = $status === 'Resting' ? 'resting' : 'available';
              ?>
              <tr>
                <td data-label="Player"><?= h(tournament_player_display_name($player)) ?></td>

                <td data-label="Status">
                  <span class="tournament-status-pill <?= h($statusClass) ?>">
                    <?= h($status) ?>
                  </span>
                </td>

                <td data-label="Tournament IP">
                  <?= h(number_format((float)$row['innings_pitched'], 1)) ?>
                </td>

                <td data-label="Tournament Pitches">
                  <?= (int)$row['pitches_thrown'] ?>
                </td>

                <td data-label="Last Outing">
                  <?php if (!empty($row['last_game_date'])): ?>
                    <?= h((string)$row['last_game_date']) ?>
                    <?php if (!empty($row['last_game_label'])): ?>
                      <br>
                      <span class="tournament-stat-muted"><?= h((string)$row['last_game_label']) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="tournament-stat-muted">No outing</span>
                  <?php endif; ?>
                </td>

                <td data-label="Last Pitches">
                  <?= (int)$row['last_pitches'] ?>
                </td>

                <td data-label="Next Available">
                  <?php if (!empty($row['next_available_date'])): ?>
                    <?= h((string)$row['next_available_date']) ?>
                  <?php else: ?>
                    <span class="tournament-stat-muted">Now</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Tournament Player Stats</h2>

    <p class="muted">
      Quick batting and pitching overview for games attached to this tournament.
    </p>

    <form method="get" action="tournament.php" class="tournament-sort-row">
      <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">

      <label>
        <span>Sort By</span>
        <select name="stats_sort">
          <?php foreach ($allowedPlayerStatsSorts as $sortKey => $sortLabel): ?>
            <option value="<?= h($sortKey) ?>" <?= $playerStatsSort === $sortKey ? 'selected' : '' ?>>
              <?= h($sortLabel) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <span>Direction</span>
        <select name="stats_dir">
          <option value="asc" <?= $playerStatsDir === 'asc' ? 'selected' : '' ?>>Ascending</option>
          <option value="desc" <?= $playerStatsDir === 'desc' ? 'selected' : '' ?>>Descending</option>
        </select>
      </label>

      <button type="submit">Apply Sort</button>
    </form>

    <?php if (empty($playerStatsOverview)): ?>
      <p class="muted">No player stats found for this tournament.</p>
    <?php else: ?>
      <div class="table-wrap tournament-table">
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>G</th>
              <th>AB</th>
              <th>H</th>
              <th>AVG</th>
              <th>R</th>
              <th>RBI</th>
              <th>BB</th>
              <th>K</th>
              <th>SB</th>
              <th>IP</th>
              <th>Pitches</th>
              <th>ERA</th>
              <th>WHIP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($playerStatsOverview as $row): ?>
              <?php
                $ab = (int)$row['at_bats'];
                $hits = (int)$row['hits'];
                $walks = (int)$row['walks'];
                $hbp = (int)$row['hit_by_pitch'];
                $sf = (int)$row['sacrifice_flies'];

                $ip = (float)$row['innings_pitched'];
                $er = (int)$row['earned_runs'];
                $pitchingWalks = (int)$row['pitching_walks'];
                $hitsAllowed = (int)$row['hits_allowed'];

                $avg = calculate_batting_average($hits, $ab);
                $era = calculate_era($er, $ip);
                $whip = calculate_whip($pitchingWalks, $hitsAllowed, $ip);
              ?>
              <tr>
                <td data-label="Player"><?= h(tournament_player_display_name($row['player'])) ?></td>
                <td data-label="G"><?= (int)$row['games_played'] ?></td>
                <td data-label="AB"><?= $ab ?></td>
                <td data-label="H"><?= $hits ?></td>
                <td data-label="AVG"><?= h(format_baseball_rate($avg)) ?></td>
                <td data-label="R"><?= (int)$row['runs'] ?></td>
                <td data-label="RBI"><?= (int)$row['rbi'] ?></td>
                <td data-label="BB"><?= $walks ?></td>
                <td data-label="K"><?= (int)$row['strikeouts'] ?></td>
                <td data-label="SB"><?= (int)$row['stolen_bases'] ?></td>
                <td data-label="IP"><?= h(number_format($ip, 1)) ?></td>
                <td data-label="Pitches"><?= (int)$row['pitches_thrown'] ?></td>
                <td data-label="ERA"><?= h(number_format($era, 2)) ?></td>
                <td data-label="WHIP"><?= h(number_format($whip, 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Tournament Games</h2>

    <?php if (empty($games)): ?>
      <p class="muted">No games have been added to this tournament yet.</p>
    <?php else: ?>
      <div class="table-wrap tournament-table">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Game</th>
              <th>Status</th>
              <th>Innings</th>
              <th>Stats</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($games as $game): ?>
              <tr>
                <td data-label="Date">
                  <?= !empty($game['game_date']) ? h((string)$game['game_date']) : 'No date' ?>
                </td>

                <td data-label="Game">
                  <?= h((string)$game['game_id']) ?>
                </td>

                <td data-label="Status">
                  <?= h((string)$game['status']) ?>
                </td>

                <td data-label="Innings">
                  <?= (int)$game['innings'] ?>
                </td>

                <td data-label="Stats">
                  <?= !empty($game['counts_toward_stats']) ? 'Yes' : 'No' ?>
                </td>

                <td data-label="Actions">
                  <div class="tournament-actions">
                    <a class="btn btn-secondary" href="generate.php?game_id=<?= (int)$game['id'] ?>">Lineup</a>
                    <a class="btn btn-secondary" href="game_stats.php?game_id=<?= (int)$game['id'] ?>">Stats</a>
                    <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$game['id'] ?>">Finalize</a>

                    <form method="post" action="tournament.php?id=<?= (int)$tournament['id'] ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_game">
                      <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">
                      <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">

                      <button
                        type="submit"
                        class="btn btn-secondary"
                        onclick="return confirm('Remove this game from the tournament? The game and stats will not be deleted.');"
                      >
                        Remove
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="actions-row">
    <a class="btn btn-secondary" href="tournaments.php">Back to Tournaments</a>
    <a class="btn" href="tournament_edit.php?id=<?= (int)$tournament['id'] ?>">Edit Tournament</a>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
