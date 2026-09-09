<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Player Stats';
$currentPage = 'player_stats';
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$teamId = current_team_id();

/*
|--------------------------------------------------------------------------
| Game filter
|--------------------------------------------------------------------------
*/

$gamesParam = trim((string)($_GET['games'] ?? ''));

$selectedGameIds = [];
$explicitNoGames = false;

if ($gamesParam === 'none') {
    $explicitNoGames = true;
} elseif ($gamesParam !== '') {
    foreach (explode(',', $gamesParam) as $gameIdValue) {
        $gameIdValue = (int)$gameIdValue;

        if ($gameIdValue > 0) {
            $selectedGameIds[] = $gameIdValue;
        }
    }

    $selectedGameIds = array_values(
        array_unique($selectedGameIds)
    );
}

$stmt = db()->prepare("
    SELECT
        g.id,
        g.game_id,
        g.game_date,
        g.status
    FROM games g
    WHERE g.team_id = :team_id
      AND g.counts_toward_stats = 1
      AND g.status IN ('generated', 'locked', 'completed')
    ORDER BY g.game_date DESC, g.id DESC
");

$stmt->execute([
    'team_id' => $teamId,
]);

$availableGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableGameIds = array_map(
    static fn(array $game): int => (int)$game['id'],
    $availableGames
);
if ($gamesParam === '') {
    /*
     * No filter in the URL means all available games are selected.
     */
    $selectedGameIds = $availableGameIds;
} elseif (!$explicitNoGames) {
    /*
     * Remove any game IDs that do not belong to this team.
     */
    $selectedGameIds = array_values(
        array_intersect(
            $selectedGameIds,
            $availableGameIds
        )
    );
}

function build_selected_game_filter(
    array $gameIds,
    string $column,
    string $prefix
): array {
    if ($gameIds === []) {
        return [
            'sql' => '1 = 0',
            'params' => [],
        ];
    }

    $placeholders = [];
    $params = [];

    foreach (array_values($gameIds) as $index => $gameId) {
        $paramName = $prefix . '_' . $index;

        $placeholders[] = ':' . $paramName;
        $params[$paramName] = (int)$gameId;
    }

    return [
        'sql' => sprintf(
            '%s IN (%s)',
            $column,
            implode(', ', $placeholders)
        ),
        'params' => $params,
    ];
}
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

$error = '';
if (isset($_GET['err'])) {
    $error = trim((string)$_GET['err']);
}
$battingRows = [];
$pitchingRows = [];
$battingSort = (string)($_GET['batting_sort'] ?? 'ops');
$pitchingSort = (string)($_GET['pitching_sort'] ?? 'era');


try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }
    $battingGameFilter = build_selected_game_filter(
        $selectedGameIds,
        'bgs.game_db_id',
        'batting_game'
    );
    $stmt = db()->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,

            COALESCE(bs.games_played, 0) AS games_played,
            COALESCE(bs.at_bats, 0) AS at_bats,
            COALESCE(bs.runs, 0) AS runs,
            COALESCE(bs.hits, 0) AS hits,
            COALESCE(bs.doubles_hit, 0) AS doubles_hit,
            COALESCE(bs.triples_hit, 0) AS triples_hit,
            COALESCE(bs.home_runs, 0) AS home_runs,
            COALESCE(bs.rbi, 0) AS rbi,
            COALESCE(bs.walks, 0) AS walks,
            COALESCE(bs.strikeouts, 0) AS strikeouts,
            COALESCE(bs.hit_by_pitch, 0) AS hit_by_pitch,
            COALESCE(bs.sacrifice_flies, 0) AS sacrifice_flies,
            COALESCE(bs.stolen_bases, 0) AS stolen_bases

        FROM players p

        LEFT JOIN (
            SELECT
                bgs.team_id,
                bgs.player_id,

                COUNT(DISTINCT bgs.game_db_id) AS games_played,
                SUM(COALESCE(bgs.at_bats, 0)) AS at_bats,
                SUM(COALESCE(bgs.runs, 0)) AS runs,
                SUM(COALESCE(bgs.hits, 0)) AS hits,
                SUM(COALESCE(bgs.doubles_hit, 0)) AS doubles_hit,
                SUM(COALESCE(bgs.triples_hit, 0)) AS triples_hit,
                SUM(COALESCE(bgs.home_runs, 0)) AS home_runs,
                SUM(COALESCE(bgs.rbi, 0)) AS rbi,
                SUM(COALESCE(bgs.walks, 0)) AS walks,
                SUM(COALESCE(bgs.strikeouts, 0)) AS strikeouts,
                SUM(COALESCE(bgs.hit_by_pitch, 0)) AS hit_by_pitch,
                SUM(COALESCE(bgs.sacrifice_flies, 0)) AS sacrifice_flies,
                SUM(COALESCE(bgs.stolen_bases, 0)) AS stolen_bases

            FROM player_batting_game_stats bgs

            INNER JOIN games batting_game
                ON batting_game.id = bgs.game_db_id
               AND batting_game.team_id = bgs.team_id
               AND batting_game.counts_toward_stats = 1

            WHERE {$battingGameFilter['sql']}

            GROUP BY
                bgs.team_id,
                bgs.player_id
        ) bs
            ON bs.player_id = p.id
           AND bs.team_id = p.team_id

        WHERE p.team_id = :team_id

        GROUP BY
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,
            bs.games_played,
            bs.at_bats,
            bs.runs,
            bs.hits,
            bs.doubles_hit,
            bs.triples_hit,
            bs.home_runs,
            bs.rbi,
            bs.walks,
            bs.strikeouts,
            bs.hit_by_pitch,
            bs.sacrifice_flies,
            bs.stolen_bases

        ORDER BY
            p.last_name ASC,
            p.first_name ASC
    ");

    $stmt->execute(
        array_merge(
            [
                'team_id' => $teamId,
            ],
            $battingGameFilter['params']
        )
    );
    $battingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($battingRows, function (array $a, array $b) use ($battingSort): int {

        $calc = function (array $row, string $stat): float|int {

            $ab = (int)$row['at_bats'];
            $hits = (int)$row['hits'];
            $doubles = (int)$row['doubles_hit'];
            $triples = (int)$row['triples_hit'];
            $hr = (int)$row['home_runs'];
            $walks = (int)$row['walks'];
            $hbp = (int)$row['hit_by_pitch'];
            $sf = (int)$row['sacrifice_flies'];

            return match ($stat) {
                'avg' => calculate_batting_average($hits, $ab),
                'obp' => calculate_obp($hits, $walks, $hbp, $ab, $sf),
                'ops' => calculate_ops(
                    calculate_obp($hits, $walks, $hbp, $ab, $sf),
                    calculate_slugging($hits, $doubles, $triples, $hr, $ab)
                ),
                'slg' => calculate_slugging($hits, $doubles, $triples, $hr, $ab),
                'hits' => $hits,
                'doubles' => $doubles,
                'triples' => $triples,
                'hr' => $hr,

                'rbi' => (int)$row['rbi'],
                'runs' => (int)$row['runs'],
                'sb' => (int)$row['stolen_bases'],

                'walks' => $walks,
                'k' => (int)$row['strikeouts'],
                'hbp' => (int)$row['hit_by_pitch'],
                'sf' => (int)$row['sacrifice_flies'],

                'ab' => $ab,
                'games' => (int)$row['games_played'],
                default => 0,
            };
        };

        return $calc($b, $battingSort) <=> $calc($a, $battingSort);
    });
    $pitchingGameFilter = build_selected_game_filter(
        $selectedGameIds,
        'pps.game_id',
        'pitching_game'
    );
    $stmt = db()->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,

            COALESCE(ps.innings_pitched, 0) AS innings_pitched,
            COALESCE(ps.pitches_thrown, 0) AS pitches_thrown,
            COALESCE(ps.hits_allowed, 0) AS hits_allowed,
            COALESCE(ps.runs_allowed, 0) AS runs_allowed,
            COALESCE(ps.earned_runs, 0) AS earned_runs,
            COALESCE(ps.walks, 0) AS walks,
            COALESCE(ps.strikeouts, 0) AS strikeouts,
            COALESCE(ps.wins, 0) AS wins,
            COALESCE(ps.losses, 0) AS losses,
            COALESCE(ps.saves, 0) AS saves
        FROM players p
        LEFT JOIN (
            SELECT
                pps.team_id,
                pps.player_id,
                SUM(COALESCE(pps.innings_pitched, 0)) AS innings_pitched,
                SUM(COALESCE(pps.pitches_thrown, 0)) AS pitches_thrown,
                SUM(COALESCE(pps.hits_allowed, 0)) AS hits_allowed,
                SUM(COALESCE(pps.runs_allowed, 0)) AS runs_allowed,
                SUM(COALESCE(pps.earned_runs, 0)) AS earned_runs,
                SUM(COALESCE(pps.walks, 0)) AS walks,
                SUM(COALESCE(pps.strikeouts, 0)) AS strikeouts,
                SUM(COALESCE(pps.wins, 0)) AS wins,
                SUM(COALESCE(pps.losses, 0)) AS losses,
                SUM(COALESCE(pps.saves, 0)) AS saves
            FROM player_pitching_stats pps
            INNER JOIN games g
                ON g.id = pps.game_id
               AND g.team_id = pps.team_id
               AND g.counts_toward_stats = 1
               AND {$pitchingGameFilter['sql']}
            GROUP BY
                pps.team_id,
                pps.player_id
        ) ps
            ON ps.player_id = p.id
           AND ps.team_id = p.team_id
        WHERE p.team_id = :team_id
        HAVING innings_pitched > 0
            OR pitches_thrown > 0
            OR strikeouts > 0
            OR walks > 0
            OR earned_runs > 0
        ORDER BY p.last_name ASC, p.first_name ASC
    ");

    $stmt->execute(
        array_merge(
            [
                'team_id' => $teamId,
            ],
            $pitchingGameFilter['params']
        )
    );
    $pitchingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($pitchingRows, function (array $a, array $b) use ($pitchingSort): int {

        $calc = function (array $row, string $stat): float|int {

            $ip = (float)$row['innings_pitched'];
            $er = (int)$row['earned_runs'];
            $walks = (int)$row['walks'];
            $hits = (int)$row['hits_allowed'];

            return match ($stat) {
                'era' => calculate_era($er, $ip),
                'whip' => calculate_whip($walks, $hits, $ip),
                'k' => (int)$row['strikeouts'],
                'ip' => $ip,
                'wins' => (int)$row['wins'],
                'saves' => (int)$row['saves'],
                'pitches' => (int)$row['pitches_thrown'],
                'hits_allowed' => (int)$row['hits_allowed'],
                'runs_allowed' => (int)$row['runs_allowed'],
                'earned_runs' => (int)$row['earned_runs'],
                'walks' => (int)$row['walks'],
                'losses' => (int)$row['losses'],
                default => 0,
            };
        };

        $ascendingStats = [
            'era',
            'whip',
            'hits_allowed',
            'runs_allowed',
            'earned_runs',
            'walks',
            'losses',
        ];

        if (in_array($pitchingSort, $ascendingStats, true)) {
            return $calc($a, $pitchingSort) <=> $calc($b, $pitchingSort);
        }

        return $calc($b, $pitchingSort) <=> $calc($a, $pitchingSort);
    });
} catch (Throwable $e) {
    $error = $error !== ''
        ? $error
        : $e->getMessage();
}
if ($isAjax) {
    $section = (string)($_GET['section'] ?? '');

    if ($section === 'batting') {
        include __DIR__ . '/partials/player_stats_batting.php';
        exit;
    }

    if ($section === 'pitching') {
        include __DIR__ . '/partials/player_stats_pitching.php';
        exit;
    }

    exit;
}
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Player Stats</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>


<?php
$gamesByMonth = [];

foreach ($availableGames as $game) {
    $gameDate = (string)($game['game_date'] ?? '');

    $timestamp = strtotime($gameDate);

    $monthKey = $timestamp !== false
        ? date('Y-m', $timestamp)
        : 'unknown';

    $monthLabel = $timestamp !== false
        ? date('F Y', $timestamp)
        : 'Unknown Date';

    if (!isset($gamesByMonth[$monthKey])) {
        $gamesByMonth[$monthKey] = [
            'label' => $monthLabel,
            'games' => [],
        ];
    }

    $gamesByMonth[$monthKey]['games'][] = $game;
}
?>
<div class="card" style="margin-bottom:18px;">
  <div class="stats-summary-header">
    <div>
      <h2>Season Stats Summary</h2>

      <p class="muted">
        Lightweight team stats across saved games. Batting rates update from game stat entries.
      </p>
    </div>

    <button
      type="button"
      class="btn btn-secondary"
      id="open-game-filter"
    >
      Filter Games
    </button>
  </div>

  <div class="game-filter-summary">
    <?php if ($explicitNoGames): ?>
      <strong>No games selected</strong>
    <?php elseif (count($selectedGameIds) === count($availableGameIds)): ?>
      <strong>All <?= count($availableGameIds) ?> games selected</strong>
    <?php else: ?>
      <strong>
        <?= count($selectedGameIds) ?>
        of
        <?= count($availableGameIds) ?>
        games selected
      </strong>
    <?php endif; ?>
  </div>
</div>

<div
  class="game-filter-modal"
  id="game-filter-modal"
  aria-hidden="true"
>
  <div
    class="game-filter-backdrop"
    data-close-game-filter
  ></div>

  <div
    class="game-filter-dialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="game-filter-title"
  >
    <div class="game-filter-header">
      <div>
        <h2 id="game-filter-title">Filter Games</h2>

        <p class="muted">
          Choose which games should be included in player statistics.
        </p>
      </div>

      <button
        type="button"
        class="game-filter-close"
        data-close-game-filter
        aria-label="Close game filter"
      >
        &times;
      </button>
    </div>

    <div class="game-filter-toolbar">
      <button
        type="button"
        class="btn btn-secondary"
        id="select-all-games"
      >
        Select All
      </button>

      <button
        type="button"
        class="btn btn-secondary"
        id="clear-all-games"
      >
        Clear All
      </button>
    </div>

    <div class="game-filter-body">
      <?php if (empty($gamesByMonth)): ?>
        <p class="muted">No completed games are available.</p>
      <?php else: ?>

        <?php foreach ($gamesByMonth as $monthGroup): ?>
          <section class="game-filter-month">
            <h3><?= h($monthGroup['label']) ?></h3>

            <div class="game-filter-list">
              <?php foreach ($monthGroup['games'] as $game): ?>
                <?php
                $gameDbId = (int)$game['id'];

                $gameTimestamp = strtotime(
                    (string)($game['game_date'] ?? '')
                );

                $formattedGameDate = $gameTimestamp !== false
                    ? date('D, M j, Y', $gameTimestamp)
                    : 'Date unavailable';

                $gameName = trim((string)($game['game_id'] ?? ''));

                if ($gameName === '') {
                    $gameName = 'Game ' . $gameDbId;
                }

                $isSelected = in_array(
                    $gameDbId,
                    $selectedGameIds,
                    true
                );
                ?>

                <label class="game-filter-option">
                  <span class="game-filter-option-text">
                    <strong><?= h($gameName) ?></strong>
                    <small><?= h($formattedGameDate) ?></small>
                  </span>

                  <span class="game-filter-switch">
                    <input
                      type="checkbox"
                      class="game-filter-checkbox"
                      value="<?= $gameDbId ?>"
                      <?= $isSelected ? 'checked' : '' ?>
                      aria-label="<?= h('Include ' . $gameName) ?>"
                    >

                    <span class="game-filter-switch-track" aria-hidden="true">
                      <span class="game-filter-switch-thumb"></span>
                    </span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>

    <div class="game-filter-footer">
      <button
        type="button"
        class="btn btn-secondary"
        data-close-game-filter
      >
        Cancel
      </button>

      <button
        type="button"
        class="btn"
        id="apply-game-filter"
      >
        Apply
      </button>
    </div>
  </div>
</div>
<?php
$leadoffRows = [];

foreach ($battingRows as $row) {
    $atBats = (int)$row['at_bats'];
    $hits = (int)$row['hits'];
    $walks = (int)$row['walks'];
    $hbp = (int)$row['hit_by_pitch'];
    $sf = (int)$row['sacrifice_flies'];
    $strikeouts = (int)$row['strikeouts'];
    $stolenBases = (int)$row['stolen_bases'];

    $plateAppearances = $atBats + $walks + $hbp + $sf;

    if ($plateAppearances < 5) {
        continue;
    }

    $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
    $avg = calculate_batting_average($hits, $atBats);

    $contactScore = max(0, $plateAppearances - $strikeouts);
    $contactRate = $plateAppearances > 0
        ? $contactScore / $plateAppearances
        : 0;

    $leadoffScore =
        ($obp * 70) +
        ($contactRate * 20) +
        (min($stolenBases, 10) * 1);

    $leadoffRows[] = [
        'player' => player_full_name($row),
        'jersey_number' => (string)($row['jersey_number'] ?? ''),
        'plate_appearances' => $plateAppearances,
        'avg' => $avg,
        'obp' => $obp,
        'strikeouts' => $strikeouts,
        'stolen_bases' => $stolenBases,
        'score' => $leadoffScore,
    ];
}

usort($leadoffRows, function (array $a, array $b): int {
    return $b['score'] <=> $a['score'];
});

$leadoffRows = array_slice($leadoffRows, 0, 5);
?>

<div class="card" style="margin-bottom:18px;">
  <h2>Best Leadoff Hitters</h2>
  <p class="muted">
    Ranked by on-base ability, contact rate, and speed. This is a coaching suggestion, not an automatic lineup rule.
  </p>

  <?php if (empty($leadoffRows)): ?>
    <p class="muted">Add batting stats to see leadoff recommendations.</p>
  <?php else: ?>
    <div class="leadoff-grid">
      <?php foreach ($leadoffRows as $index => $row): ?>
        <div class="leadoff-card">
          <div class="leadoff-rank">#<?= $index + 1 ?></div>

          <div class="leadoff-player">
            <?= h($row['player']) ?>
            <?php if ($row['jersey_number'] !== ''): ?>
              <span>#<?= h($row['jersey_number']) ?></span>
            <?php endif; ?>
          </div>

          <div class="leadoff-stats">
            <div>
              <strong><?= h(format_baseball_rate((float)$row['obp'])) ?></strong>
              <span>OBP</span>
            </div>

            <div>
              <strong><?= h(format_baseball_rate((float)$row['avg'])) ?></strong>
              <span>AVG</span>
            </div>

            <div>
              <strong><?= (int)$row['stolen_bases'] ?></strong>
              <span>SB</span>
            </div>

            <div>
              <strong><?= (int)$row['strikeouts'] ?></strong>
              <span>K</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>



<?php
$suggestedLineupRows = [];

foreach ($battingRows as $row) {
    $atBats = (int)$row['at_bats'];
    $hits = (int)$row['hits'];
    $doubles = (int)$row['doubles_hit'];
    $triples = (int)$row['triples_hit'];
    $homeRuns = (int)$row['home_runs'];
    $walks = (int)$row['walks'];
    $hbp = (int)$row['hit_by_pitch'];
    $sf = (int)$row['sacrifice_flies'];
    $strikeouts = (int)$row['strikeouts'];
    $stolenBases = (int)$row['stolen_bases'];
    $rbi = (int)$row['rbi'];

    $plateAppearances = $atBats + $walks + $hbp + $sf;

    if ($plateAppearances < 5) {
        continue;
    }

    $avg = calculate_batting_average($hits, $atBats);
    $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
    $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
    $ops = calculate_ops($obp, $slg);
    $ops = min($ops, 2.500);
    $ballsInPlay = max(1, $atBats);

    $contactRate = max(0, $atBats - $strikeouts) / $ballsInPlay;

    $speedScore = min($stolenBases, 10) / 10;

    $powerScore = ($slg * 60) + ($homeRuns * 2) + ($doubles * 0.75) + ($triples * 1.25);
    $tableSetterScore = ($obp * 70) + ($contactRate * 20) + ($speedScore * 10);
    $runProducerScore = ($ops * 60) + ($rbi * 1.5) + ($homeRuns * 3);
    $overallScore = ($ops * 50) + ($obp * 25) + ($contactRate * 15) + ($speedScore * 10);

    $suggestedLineupRows[] = [
        'player_id' => (int)$row['id'],
        'player' => player_full_name($row),
        'jersey_number' => (string)($row['jersey_number'] ?? ''),
        'avg' => $avg,
        'obp' => $obp,
        'slg' => $slg,
        'ops' => $ops,
        'plate_appearances' => $plateAppearances,
        'contact_rate' => $contactRate,
        'speed_score' => $speedScore,
        'power_score' => $powerScore,
        'table_setter_score' => $tableSetterScore,
        'run_producer_score' => $runProducerScore,
        'overall_score' => $overallScore,
        'rbi' => $rbi,
        'home_runs' => $homeRuns,
        'stolen_bases' => $stolenBases,
    ];
}

usort($suggestedLineupRows, function (array $a, array $b): int {
    return $b['overall_score'] <=> $a['overall_score'];
});

$lineupPool = $suggestedLineupRows;
$suggestedBattingOrder = [];

$pickBest = function (array &$pool, callable $scoreFunction): ?array {
    if (empty($pool)) {
        return null;
    }

    usort($pool, function (array $a, array $b) use ($scoreFunction): int {
        return $scoreFunction($b) <=> $scoreFunction($a);
    });

    return array_shift($pool);
};

$lineupSize = count($lineupPool);

$lineupSlots = [];

if ($lineupSize >= 1) {
    $lineupSlots[1] = 'table_setter';
}

if ($lineupSize >= 2) {
    $lineupSlots[2] = 'contact';
}

if ($lineupSize >= 3) {
    $lineupSlots[3] = 'best_overall';
}

if ($lineupSize >= 4) {
    $lineupSlots[4] = 'power';
}

if ($lineupSize >= 5) {
    $lineupSlots[5] = 'run_producer';
}

for ($slot = 6; $slot <= $lineupSize; $slot++) {
    if (!isset($lineupSlots[$slot])) {
        $lineupSlots[$slot] = 'overall';
    }
}

if ($lineupSize >= 9) {
    $lineupSlots[9] = 'second_leadoff';
}

if ($lineupSize >= 10) {
    $lineupSlots[10] = 'contact_depth';
}

if ($lineupSize >= 11) {
    $lineupSlots[11] = 'power_depth';
}

if ($lineupSize >= 12) {
    $lineupSlots[12] = 'overall';
}

if ($lineupSize >= 13) {
    $lineupSlots[13] = 'contact_depth';
}

if ($lineupSize >= 14) {
    $lineupSlots[14] = 'second_leadoff';
}

foreach ($lineupSlots as $slot => $role) {
    $picked = $pickBest($lineupPool, function (array $player) use ($role): float {
        return match ($role) {
            'table_setter' => $player['table_setter_score'],
            'contact' => ($player['obp'] * 50) + ($player['contact_rate'] * 40) + ($player['speed_score'] * 10),
            'best_overall' => $player['overall_score'],
            'power' => $player['power_score'],
            'run_producer' => $player['run_producer_score'],
            'second_leadoff' => ($player['obp'] * 60) + ($player['speed_score'] * 25) + ($player['contact_rate'] * 15),
            'contact_depth' => ($player['contact_rate'] * 50) + ($player['obp'] * 35) + ($player['speed_score'] * 15),
            'power_depth' => ($player['power_score'] * 0.75) + ($player['run_producer_score'] * 0.25),
            default => $player['overall_score'],
        };
    });

    if ($picked !== null) {
        $picked['lineup_spot'] = $slot;
        $picked['lineup_role'] = match ($slot) {
            1 => 'Leadoff',
            2 => 'Contact',
            3 => 'Best Overall',
            4 => 'Power',
            5 => 'Run Producer',
            9 => 'Second Leadoff',
            10, 13 => 'Contact Depth',
            11 => 'Power Depth',
            14 => 'Second Leadoff',
            default => 'Depth Bat',
        };

        $suggestedBattingOrder[] = $picked;
    }
}
?>


<div class="card" style="margin-bottom:18px;">

  <details class="suggested-lineup-toggle">

    <summary>
      <span>Suggested Batting Lineup</span>
      <small><?= count($suggestedBattingOrder) ?> Players</small>
    </summary>

    <div class="suggested-lineup-content">

        <div class="suggested-lineup-intro">
          <p class="muted">
            Suggested order based on OBP, OPS, contact rate, speed, power, and run production.
          </p>

          <button
            type="button"
            class="btn btn-secondary suggested-lineup-rules-button"
            data-open-lineup-rules
          >
            How is this calculated?
          </button>
        </div>

        <div
          class="lineup-rules-modal"
          id="lineupRulesModal"
          aria-hidden="true"
        >
          <div class="lineup-rules-backdrop" data-close-lineup-rules></div>

          <div
            class="lineup-rules-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="lineupRulesTitle"
          >
            <div class="lineup-rules-header">
              <h2 id="lineupRulesTitle">Suggested Lineup Rules</h2>

              <button
                type="button"
                class="lineup-rules-close"
                data-close-lineup-rules
                aria-label="Close lineup rules"
              >
                &times;
              </button>
            </div>

            <div class="lineup-rules-body">
              <p>
                BenchBuddy assigns each batting-order position a role, then selects the
                highest-rated available player for that role.
              </p>

              <div class="lineup-rule-list">
                <div class="lineup-rule-row">
                  <strong>1. Leadoff</strong>
                  <span>Prioritizes getting on base, contact, and speed.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>2. Contact Hitter</strong>
                  <span>50% OBP, 40% contact rate, and 10% speed.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>3. Best Overall Hitter</strong>
                  <span>Uses the highest overall offensive score.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>4. Power Hitter</strong>
                  <span>Prioritizes power and extra-base production.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>5. Run Producer</strong>
                  <span>Prioritizes RBI and run-production potential.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>6–8. Overall Offense</strong>
                  <span>Orders the remaining players by overall offensive score.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>9. Second Leadoff</strong>
                  <span>60% OBP, 25% speed, and 15% contact rate.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>10 Contact Depth</strong>
                  <span>50% contact rate, 35% OBP, and 15% speed.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>11. Power Depth</strong>
                  <span>75% power score and 25% run-production score.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>12. Best Remaining Hitter</strong>
                  <span>Uses the highest remaining overall offensive score.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>13. Contact Depth</strong>
                  <span>50% contact rate, 35% OBP, and 15% speed.</span>
                </div>

                <div class="lineup-rule-row">
                  <strong>14. Second Leadoff</strong>
                  <span>Uses the same on-base, speed, and contact weighting as spot 9.</span>
                </div>
              </div>

              <p class="muted" style="margin-bottom:0;">
                The suggestion is a starting point. Coaches can adjust the order based
                on availability, recent performance, handedness, development goals, or
                game strategy.
              </p>
            </div>
          </div>
        </div>



      <?php if (empty($suggestedBattingOrder)): ?>

        <p class="muted">
          Add batting stats to generate a suggested lineup.
        </p>

      <?php else: ?>

        <div class="suggested-lineup-compact">

          <?php foreach ($suggestedBattingOrder as $row): ?>

            <div class="suggested-lineup-compact-row">

              <div class="sl-slot">
                <?= (int)$row['lineup_spot'] ?>
              </div>

              <div class="sl-player">
                <?= h($row['player']) ?>

                <?php if ($row['jersey_number'] !== ''): ?>
                  <span>#<?= h($row['jersey_number']) ?></span>
                <?php endif; ?>
              </div>

              <div class="sl-stats">

                <span>
                  OPS <?= h(format_baseball_rate($row['ops'])) ?>
                </span>

                <?php if ((int)$row['home_runs'] > 0): ?>
                  <span>HR <?= (int)$row['home_runs'] ?></span>
                <?php endif; ?>

                <?php if ((int)$row['rbi'] > 0): ?>
                  <span>RBI <?= (int)$row['rbi'] ?></span>
                <?php endif; ?>

                <?php if ((int)$row['stolen_bases'] > 0): ?>
                  <span>SB <?= (int)$row['stolen_bases'] ?></span>
                <?php endif; ?>

              </div>

            </div>

          <?php endforeach; ?>

        </div>
        <form method="post" action="suggested_lineup_game_setup.php" style="margin-top:14px;">
            <?= csrf_field() ?>
          <?php foreach ($suggestedBattingOrder as $row): ?>
            <input
              type="hidden"
              name="player_ids[]"
              value="<?= (int)$row['player_id'] ?>"
            >
          <?php endforeach; ?>

          <button type="submit" class="btn">
            Create Game From Suggested Lineup
          </button>
        </form>
      <?php endif; ?>

    </div>

  </details>

</div>

<form method="get" style="margin-bottom:16px;">
  <input type="hidden" name="pitching_sort" value="<?= h($pitchingSort) ?>">
      <?php if ($gamesParam !== ''): ?>
        <input
          type="hidden"
          name="games"
          value="<?= h($gamesParam) ?>"
        >
      <?php endif; ?>
  <label>
    Sort Batting By

    <select name="batting_sort" id="batting-sort-select">

      <optgroup label="Rate Stats">
        <option value="ops" <?= $battingSort === 'ops' ? 'selected' : '' ?>>OPS</option>
        <option value="avg" <?= $battingSort === 'avg' ? 'selected' : '' ?>>AVG</option>
        <option value="obp" <?= $battingSort === 'obp' ? 'selected' : '' ?>>OBP</option>
        <option value="slg" <?= $battingSort === 'slg' ? 'selected' : '' ?>>SLG</option>
      </optgroup>

      <optgroup label="Hits">
        <option value="hits" <?= $battingSort === 'hits' ? 'selected' : '' ?>>Hits</option>
        <option value="doubles" <?= $battingSort === 'doubles' ? 'selected' : '' ?>>Doubles</option>
        <option value="triples" <?= $battingSort === 'triples' ? 'selected' : '' ?>>Triples</option>
        <option value="hr" <?= $battingSort === 'hr' ? 'selected' : '' ?>>Home Runs</option>
      </optgroup>

      <optgroup label="Production">
        <option value="rbi" <?= $battingSort === 'rbi' ? 'selected' : '' ?>>RBI</option>
        <option value="runs" <?= $battingSort === 'runs' ? 'selected' : '' ?>>Runs</option>
        <option value="sb" <?= $battingSort === 'sb' ? 'selected' : '' ?>>Stolen Bases</option>
      </optgroup>

      <optgroup label="Plate Discipline">
        <option value="walks" <?= $battingSort === 'walks' ? 'selected' : '' ?>>Walks</option>
        <option value="k" <?= $battingSort === 'k' ? 'selected' : '' ?>>Strikeouts</option>
        <option value="hbp" <?= $battingSort === 'hbp' ? 'selected' : '' ?>>Hit By Pitch</option>
        <option value="sf" <?= $battingSort === 'sf' ? 'selected' : '' ?>>Sacrifice Flies</option>
      </optgroup>

      <optgroup label="Volume">
        <option value="ab" <?= $battingSort === 'ab' ? 'selected' : '' ?>>At Bats</option>
        <option value="games" <?= $battingSort === 'games' ? 'selected' : '' ?>>Games Played</option>
      </optgroup>

    </select>
  </label>
</form>
<?php include __DIR__ . '/partials/player_stats_batting.php'; ?>
<form method="get" style="margin-bottom:16px;">
  <input type="hidden" name="batting_sort" value="<?= h($battingSort) ?>">
      <?php if ($gamesParam !== ''): ?>
        <input
          type="hidden"
          name="games"
          value="<?= h($gamesParam) ?>"
        >
      <?php endif; ?>
  <label>
    Sort Pitching By
    <select name="pitching_sort" id="pitching-sort-select">
      <option value="era" <?= $pitchingSort === 'era' ? 'selected' : '' ?>>ERA</option>
      <option value="whip" <?= $pitchingSort === 'whip' ? 'selected' : '' ?>>WHIP</option>
      <option value="k" <?= $pitchingSort === 'k' ? 'selected' : '' ?>>Strikeouts</option>
      <option value="ip" <?= $pitchingSort === 'ip' ? 'selected' : '' ?>>IP</option>
      <option value="wins" <?= $pitchingSort === 'wins' ? 'selected' : '' ?>>Wins</option>
      <option value="saves" <?= $pitchingSort === 'saves' ? 'selected' : '' ?>>Saves</option>
      <option value="pitches" <?= $pitchingSort === 'pitches' ? 'selected' : '' ?>>Pitches</option>
    </select>
  </label>
</form>
<?php include __DIR__ . '/partials/player_stats_pitching.php'; ?>
<div class="actions-row">
  <a class="btn btn-secondary" href="players.php">Back to Players</a>
  <a class="btn btn-secondary" href="games.php">Back to Games</a>
  <a class="btn" href="print_player_stats.php" target="_blank">Print Player Stats</a>
  <a class="btn btn-secondary" href="import_game_stats.php">Import Game Stats</a>
  <a class="btn btn-secondary" href="download_stats_template.php">Download CSV Template</a>
</div>

<script>

async function updatePlayerStats(section, value) {

    const url = new URL(window.location.href);

    url.searchParams.set('ajax', '1');
    url.searchParams.set('section', section);

    if (section === 'batting') {
        url.searchParams.set('batting_sort', value);
    }

    if (section === 'pitching') {
        url.searchParams.set('pitching_sort', value);
    }

    const response = await fetch(url);

    const html = await response.text();

    if (section === 'batting') {
        document.getElementById('batting-stats-container').outerHTML = html;
    }

    if (section === 'pitching') {
        document.getElementById('pitching-stats-container').outerHTML = html;
    }
}

document.getElementById('batting-sort-select')?.addEventListener('change', function () {
    updatePlayerStats('batting', this.value);
});

document.getElementById('pitching-sort-select')?.addEventListener('change', function () {
    updatePlayerStats('pitching', this.value);
});

</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('lineupRulesModal');
  const openButton = document.querySelector('[data-open-lineup-rules]');
  const closeButtons = document.querySelectorAll('[data-close-lineup-rules]');

  if (!modal || !openButton) {
    return;
  }

  function openModal() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('lineup-rules-open');

    const closeButton = modal.querySelector('.lineup-rules-close');

    if (closeButton) {
      closeButton.focus();
    }
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('lineup-rules-open');
    openButton.focus();
  }

  openButton.addEventListener('click', openModal);

  closeButtons.forEach(function (button) {
    button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('game-filter-modal');
    const openButton = document.getElementById('open-game-filter');
    const closeButtons = document.querySelectorAll(
        '[data-close-game-filter]'
    );

    const selectAllButton = document.getElementById(
        'select-all-games'
    );

    const clearAllButton = document.getElementById(
        'clear-all-games'
    );

    const applyButton = document.getElementById(
        'apply-game-filter'
    );

    if (!modal || !openButton) {
        return;
    }

    function getCheckboxes() {
        return Array.from(
            modal.querySelectorAll('.game-filter-checkbox')
        );
    }

    function openGameFilter() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('game-filter-open');

        const closeButton = modal.querySelector(
            '.game-filter-close'
        );

        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeGameFilter() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('game-filter-open');

        openButton.focus();
    }

    function applyGameFilter() {
        const selectedIds = getCheckboxes()
            .filter(function (checkbox) {
                return checkbox.checked;
            })
            .map(function (checkbox) {
                return checkbox.value;
            });

        const url = new URL(window.location.href);

        url.searchParams.delete('ajax');
        url.searchParams.delete('section');

        if (selectedIds.length === 0) {
            url.searchParams.set('games', 'none');
        } else {
            url.searchParams.set(
                'games',
                selectedIds.join(',')
            );
        }

        window.location.href = url.toString();
    }

    openButton.addEventListener(
        'click',
        openGameFilter
    );

    closeButtons.forEach(function (button) {
        button.addEventListener(
            'click',
            closeGameFilter
        );
    });

    selectAllButton?.addEventListener('click', function () {
        getCheckboxes().forEach(function (checkbox) {
            checkbox.checked = true;
        });
    });

    clearAllButton?.addEventListener('click', function () {
        getCheckboxes().forEach(function (checkbox) {
            checkbox.checked = false;
        });
    });

    applyButton?.addEventListener(
        'click',
        applyGameFilter
    );

    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape' &&
            modal.classList.contains('is-open')
        ) {
            closeGameFilter();
        }
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
