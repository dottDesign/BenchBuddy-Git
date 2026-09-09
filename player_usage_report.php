<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';

require_login();

$pageTitle = 'Player Usage Report';
$currentPage = 'player_usage_report';

$teamId = current_team_id();

$preferredPositionOrder = [
    'P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF',
];
function usage_table_columns(string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    $columns = [];

    foreach ($rows as $row) {
        $columns[(string)$row['Field']] = true;
    }

    $cache[$table] = $columns;

    return $columns;
}

function usage_has_column(string $table, string $column): bool
{
    $columns = usage_table_columns($table);

    return isset($columns[$column]);
}

function usage_sort_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');

    return $value !== '' ? $value : 'unknown';
}
function usage_player_label(array $player, string $labelMode): string
{
    if (function_exists('format_player_label')) {
        return format_player_label($player, $labelMode);
    }

    if (function_exists('player_full_name')) {
        return player_full_name($player);
    }

    $first = trim((string)($player['first_name'] ?? ''));
    $last = trim((string)($player['last_name'] ?? ''));

    return trim($first . ' ' . $last) ?: 'Player #' . (int)($player['id'] ?? 0);
}

function usage_normalize_cell(mixed $cell): ?array
{
    if ($cell === null || $cell === '' || $cell === false) {
        return null;
    }

    if (is_object($cell)) {
        $cell = (array)$cell;
    }

    if (is_numeric($cell)) {
        $playerId = (int)$cell;

        return $playerId > 0 ? ['id' => $playerId] : null;
    }

    if (!is_array($cell)) {
        return null;
    }

    $playerId = (int)(
        $cell['id']
        ?? $cell['player_id']
        ?? $cell['player_db_id']
        ?? $cell['playerId']
        ?? 0
    );

    if ($playerId <= 0) {
        return null;
    }

    $cell['id'] = $playerId;

    return $cell;
}

function usage_position_sort(array $positions, array $preferredOrder): array
{
    $positions = array_values(array_unique(array_filter(
        array_map('strval', $positions),
        static fn(string $position): bool => trim($position) !== ''
    )));

    usort($positions, static function (string $a, string $b) use ($preferredOrder): int {
        $aIndex = array_search($a, $preferredOrder, true);
        $bIndex = array_search($b, $preferredOrder, true);

        $aIndex = $aIndex === false ? 999 : (int)$aIndex;
        $bIndex = $bIndex === false ? 999 : (int)$bIndex;

        if ($aIndex === $bIndex) {
            return strcmp($a, $b);
        }

        return $aIndex <=> $bIndex;
    });

    return $positions;
}

function usage_percent(int $part, int $whole): string
{
    if ($whole <= 0) {
        return '0%';
    }

    return number_format(($part / $whole) * 100, 1) . '%';
}
function usage_game_innings_played(array $game, array $lineupGrid, array $benchGrid): int
{
    return max(0, (int)($game['innings'] ?? 0));
}

function usage_inning_number_from_key(mixed $inningKey, int $counter, array $cells): int
{
    $keys = array_keys($cells);
    $usesZeroBasedKeys = in_array(0, $keys, true) || in_array('0', $keys, true);

    if (is_numeric($inningKey)) {
        $inningNumber = (int)$inningKey;

        if ($usesZeroBasedKeys) {
            return $inningNumber + 1;
        }

        return $inningNumber > 0 ? $inningNumber : $counter;
    }

    return $counter;
}
function usage_empty_player_row(array $player, string $labelMode): array
{
    return [
        'id' => (int)$player['id'],
        'label' => usage_player_label($player, $labelMode),
        'jersey_number' => (string)($player['jersey_number'] ?? ''),
        'positions' => [],
        'field_innings' => 0,
        'bench_innings' => 0,
        'total_innings' => 0,
        'games_with_lineup' => [],
        'longest_bench_streak' => 0,
        'current_bench_streak' => 0,
    ];
}

$error = '';
$usageRows = [];
$games = [];
$allPositions = $preferredPositionOrder;

$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$gamesHaveDeletedAt = usage_has_column('games', 'deleted_at');
$playersHaveDeletedAt = usage_has_column('players', 'deleted_at');
$playersHaveIsActive = usage_has_column('players', 'is_active');
$playersHaveJerseyNumber = usage_has_column('players', 'jersey_number');
$includeInactive = (string)($_GET['include_inactive'] ?? '') === '1';

$labelMode = function_exists('get_team_player_label_mode')
    ? get_team_player_label_mode($teamId)
    : 'both';

if ($labelMode === '') {
    $labelMode = 'both';
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No active team selected.');
    }

    $gameOptionsSql = "
    SELECT id, game_id, game_date, status, counts_toward_stats, innings
        FROM games
        WHERE team_id = :team_id
          AND counts_toward_stats = 1
    ";

    if ($gamesHaveDeletedAt) {
        $gameOptionsSql .= " AND deleted_at IS NULL";
    }

    $gameOptionsSql .= "
        ORDER BY
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date DESC,
            id DESC
    ";

    $gameOptionsStmt = db()->prepare($gameOptionsSql);
    $gameOptionsStmt->execute([
        'team_id' => $teamId,
    ]);
    $gameOptions = $gameOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $gameSql = "
    SELECT id, game_id, game_date, status, counts_toward_stats, innings
        FROM games
        WHERE team_id = :team_id
          AND counts_toward_stats = 1
    ";

    if ($gamesHaveDeletedAt) {
        $gameSql .= " AND deleted_at IS NULL";
    }

    $gameParams = [
        'team_id' => $teamId,
    ];

    if ($selectedGameId > 0) {
        $gameSql .= " AND id = :game_id";
        $gameParams['game_id'] = $selectedGameId;
    }

    if ($dateFrom !== '') {
        $gameSql .= " AND (game_date IS NULL OR game_date >= :date_from)";
        $gameParams['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $gameSql .= " AND (game_date IS NULL OR game_date <= :date_to)";
        $gameParams['date_to'] = $dateTo;
    }

    $gameSql .= "
        ORDER BY
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date DESC,
            id DESC
    ";

    $stmt = db()->prepare($gameSql);
    $stmt->execute($gameParams);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $playerSql = "
        SELECT id, first_name, last_name, jersey_number
        FROM players
        WHERE team_id = :team_id
    ";

    $playerSql .= " ORDER BY last_name, first_name, jersey_number";
    $stmt = db()->prepare($playerSql);
    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($players as $player) {
        $playerId = (int)$player['id'];

        if ($playerId <= 0) {
            continue;
        }

        $usageRows[$playerId] = usage_empty_player_row($player, $labelMode);
    }

    foreach ($games as $game) {
        $gameId = (int)$game['id'];

        if ($gameId <= 0) {
            continue;
        }

        $lineupResult = get_generated_lineup_result($teamId, $gameId);

        if (!is_array($lineupResult)) {
            continue;
        }

        $lineupGrid = $lineupResult['lineup_grid'] ?? [];
        $benchGrid = $lineupResult['bench_grid'] ?? [];
        $positions = $lineupResult['positions'] ?? array_keys(is_array($lineupGrid) ? $lineupGrid : []);

        if (!is_array($lineupGrid)) {
            $lineupGrid = [];
        }

        if (!is_array($benchGrid)) {
            $benchGrid = [];
        }

        $inningsPlayed = usage_game_innings_played($game, $lineupGrid, $benchGrid);

        $positions = usage_position_sort(is_array($positions) ? $positions : [], $preferredPositionOrder);
        $allPositions = usage_position_sort(array_merge($allPositions, $positions), $preferredPositionOrder);

        foreach ($positions as $position) {
            if ($position === 'P') {
                continue;
            }

            $positionCells = $lineupGrid[$position] ?? [];

            if (!is_array($positionCells)) {
                continue;
            }

            $inningCounter = 0;

            foreach ($positionCells as $inningKey => $cell) {
                $inningCounter++;

                $inningNumber = usage_inning_number_from_key($inningKey, $inningCounter, $positionCells);

                if ($inningsPlayed > 0 && $inningNumber > $inningsPlayed) {
                    continue;
                }

                $normalized = usage_normalize_cell($cell);

                if ($normalized === null) {
                    continue;
                }

                $playerId = (int)$normalized['id'];

                if (!isset($usageRows[$playerId])) {
                    continue;
                }

                $usageRows[$playerId]['positions'][$position] = ((int)($usageRows[$playerId]['positions'][$position] ?? 0)) + 1;
                $usageRows[$playerId]['field_innings']++;
                $usageRows[$playerId]['total_innings']++;
                $usageRows[$playerId]['games_with_lineup'][$gameId] = true;
                $usageRows[$playerId]['current_bench_streak'] = 0;
            }
        }

        foreach ($benchGrid as $benchCells) {
            if (!is_array($benchCells)) {
                continue;
            }

            $inningCounter = 0;

            foreach ($benchCells as $inningKey => $cell) {
                $inningCounter++;

                $inningNumber = usage_inning_number_from_key($inningKey, $inningCounter, $benchCells);

                if ($inningsPlayed > 0 && $inningNumber > $inningsPlayed) {
                    continue;
                }

                $normalized = usage_normalize_cell($cell);

                if ($normalized === null) {
                    continue;
                }

                $playerId = (int)$normalized['id'];

                if (!isset($usageRows[$playerId])) {
                    continue;
                }

                $usageRows[$playerId]['bench_innings']++;
                $usageRows[$playerId]['total_innings']++;
                $usageRows[$playerId]['games_with_lineup'][$gameId] = true;
                $usageRows[$playerId]['current_bench_streak']++;

                $usageRows[$playerId]['longest_bench_streak'] = max(
                    (int)$usageRows[$playerId]['longest_bench_streak'],
                    (int)$usageRows[$playerId]['current_bench_streak']
                );
            }
        }
    }
    $pitchingSql = "
        SELECT
            pl.player_id,
            SUM(COALESCE(pl.innings_pitched, 0)) AS innings_pitched
        FROM pitch_log pl
        INNER JOIN games g
            ON g.id = pl.game_db_id
           AND g.team_id = pl.team_id
           AND g.counts_toward_stats = 1
        WHERE pl.team_id = :team_id
    ";

    $pitchingParams = [
        'team_id' => $teamId,
    ];

    if ($gamesHaveDeletedAt) {
        $pitchingSql .= " AND g.deleted_at IS NULL";
    }

    if ($selectedGameId > 0) {
        $pitchingSql .= " AND g.id = :game_id";
        $pitchingParams['game_id'] = $selectedGameId;
    }

    if ($dateFrom !== '') {
        $pitchingSql .= " AND (g.game_date IS NULL OR g.game_date >= :date_from)";
        $pitchingParams['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $pitchingSql .= " AND (g.game_date IS NULL OR g.game_date <= :date_to)";
        $pitchingParams['date_to'] = $dateTo;
    }

    $pitchingSql .= "
        GROUP BY pl.player_id
    ";

    $pitchingStmt = db()->prepare($pitchingSql);
    $pitchingStmt->execute($pitchingParams);
    $pitchingRows = $pitchingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pitchingRows as $pitchingRow) {
        $playerId = (int)($pitchingRow['player_id'] ?? 0);
        $inningsPitched = (float)($pitchingRow['innings_pitched'] ?? 0);

        if ($playerId <= 0 || $inningsPitched <= 0 || !isset($usageRows[$playerId])) {
            continue;
        }

        $usageRows[$playerId]['positions']['P'] = $inningsPitched;
        $usageRows[$playerId]['field_innings'] += $inningsPitched;
        $usageRows[$playerId]['total_innings'] += $inningsPitched;
    }
    foreach ($usageRows as &$row) {
        $totalInnings = (int)$row['total_innings'];
        $benchInnings = (int)$row['bench_innings'];
        $fieldInnings = (int)$row['field_innings'];

        $row['games_count'] = count($row['games_with_lineup']);
        $row['bench_percent_value'] = $totalInnings > 0 ? ($benchInnings / $totalInnings) * 100 : 0;
        $row['field_percent_value'] = $totalInnings > 0 ? ($fieldInnings / $totalInnings) * 100 : 0;
        $row['bench_percent'] = usage_percent($benchInnings, $totalInnings);
        $row['field_percent'] = usage_percent($fieldInnings, $totalInnings);
    }
    unset($row);

    uasort($usageRows, static function (array $a, array $b): int {
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
    $gameOptions = $gameOptions ?? [];
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.usage-desktop-scroll {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.usage-table {
    min-width: 980px;
}

.usage-sort-button {
    appearance: none;
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font: inherit;
    font-weight: 700;
    padding: 0;
    text-align: left;
    white-space: nowrap;
}
.usage-sort-button:hover {
    background: transparent;

}
.usage-sort-button::after {
    content: " ↕";
    color: #94a3b8;
    font-weight: 400;
}

.usage-sort-button.is-asc::after {
    content: " ↑";
    color: #2563eb;
}

.usage-sort-button.is-desc::after {
    content: " ↓";
    color: #2563eb;
}
</style>
<h1 class="page-title brand-title-font">Player Usage Report</h1>

<?php if ($error !== ''): ?>
    <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h2>Filters</h2>

    <form method="get">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
            <label>
                <span>Game</span>
                <select name="game_id">
                    <option value="0">All stat-counting games</option>

                    <?php foreach ($gameOptions as $gameOption): ?>
                        <option value="<?= (int)$gameOption['id'] ?>" <?= $selectedGameId === (int)$gameOption['id'] ? 'selected' : '' ?>>
                            <?= h((string)$gameOption['game_id']) ?>
                            <?php if (!empty($gameOption['game_date'])): ?>
                                | <?= h((string)$gameOption['game_date']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Date from</span>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
            </label>

            <label>
                <span>Date to</span>
                <input type="date" name="date_to" value="<?= h($dateTo) ?>">
            </label>


            <div class="actions-row">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="player_usage_report.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="meta" style="margin-bottom:16px;">
    <div class="meta-box">
        <div class="meta-label">Games Included</div>
        <div class="meta-value"><?= (int)count($games) ?></div>
    </div>

    <div class="meta-box">
        <div class="meta-label">Players</div>
        <div class="meta-value"><?= (int)count($usageRows) ?></div>
    </div>

    <div class="meta-box">
        <div class="meta-label">Report</div>
        <div class="meta-value">Position + Bench</div>
    </div>
</div>

<div class="card">
    <h2>All Player Usage</h2>
    <p class="muted">
        Only games marked “Count Toward Player Stats” are included. Position and bench counts only include innings actually played for each game, based on the game’s Innings value.
    </p>

    <?php if (empty($usageRows)): ?>
        <p class="muted">No player usage found for the selected filters.</p>
    <?php else: ?>
    <div class="table-wrap desktop-only usage-desktop-scroll">
        <table class="usage-table" id="usageReportTable">
            <thead>
                <tr>
                    <th><button type="button" class="usage-sort-button" data-sort="player" data-type="text">Player</button></th>
                    <th><button type="button" class="usage-sort-button" data-sort="games" data-type="number">Games</button></th>

                    <?php foreach ($allPositions as $position): ?>
                        <?php $positionSortKey = 'pos_' . usage_sort_key($position); ?>
                        <th>
                            <button type="button" class="usage-sort-button" data-sort="<?= h($positionSortKey) ?>" data-type="number">
                                <?= h($position) ?>
                            </button>
                        </th>
                    <?php endforeach; ?>

                    <th><button type="button" class="usage-sort-button" data-sort="field" data-type="number">Played Inn.</button></th>
                    <th><button type="button" class="usage-sort-button" data-sort="bench" data-type="number">Bench Inn.</button></th>
                    <th><button type="button" class="usage-sort-button" data-sort="bench_percent" data-type="number">Bench %</button></th>
                    <th><button type="button" class="usage-sort-button" data-sort="total" data-type="number">Total Inn.</button></th>
                </tr>
            </thead>
                <tbody>
                    <?php foreach ($usageRows as $row): ?>
                    <tr
                        data-player="<?= h((string)$row['label']) ?>"
                        data-games="<?= (int)$row['games_count'] ?>"
                        data-field="<?= (int)$row['field_innings'] ?>"
                        data-bench="<?= (int)$row['bench_innings'] ?>"
                        data-bench_percent="<?= h((string)($row['bench_percent_value'] ?? 0)) ?>"
                        data-streak="<?= (int)$row['longest_bench_streak'] ?>"
                        data-total="<?= (int)$row['total_innings'] ?>"
                        <?php foreach ($allPositions as $position): ?>
                            <?php $positionSortKey = 'pos_' . usage_sort_key($position); ?>
                            data-<?= h($positionSortKey) ?>="<?= (int)($row['positions'][$position] ?? 0) ?>"
                        <?php endforeach; ?>
                    >
                            <td>
                                <strong><?= h((string)$row['label']) ?></strong>
                                <?php if (!empty($row['jersey_number'])): ?>
                                    <span class="muted">#<?= h((string)$row['jersey_number']) ?></span>
                                <?php endif; ?>
                            </td>

                            <td><?= (int)$row['games_count'] ?></td>

                            <?php foreach ($allPositions as $position): ?>
                            <td><?= h(number_format((float)($row['positions'][$position] ?? 0), 1)) ?></td>
                            <?php endforeach; ?>

                            <td><?= (int)$row['field_innings'] ?></td>
                            <td><?= (int)$row['bench_innings'] ?></td>
                            <td><?= h((string)$row['bench_percent']) ?></td>
                            <td><?= (int)$row['total_innings'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="usage-mobile-controls mobile-only" style="display:grid;grid-template-columns:1fr 130px;gap:10px;margin:12px 0 16px;">
            <label>
                <span>Sort by</span>
                <select id="usageMobileSort">
                    <option value="player" data-type="text">Player</option>
                    <option value="games" data-type="number">Games</option>

                    <?php foreach ($allPositions as $position): ?>
                        <?php $positionSortKey = 'pos_' . usage_sort_key($position); ?>
                        <option value="<?= h($positionSortKey) ?>" data-type="number"><?= h($position) ?></option>
                    <?php endforeach; ?>

                    <option value="field" data-type="number">Field Inn.</option>
                    <option value="bench" data-type="number">Bench Inn.</option>
                    <option value="bench_percent" data-type="number">Bench %</option>
                    <option value="streak" data-type="number">Longest Streak</option>
                    <option value="total" data-type="number">Total Inn.</option>
                </select>
            </label>

            <label>
                <span>Direction</span>
                <select id="usageMobileDirection">
                    <option value="asc">Asc</option>
                    <option value="desc">Desc</option>
                </select>
            </label>
        </div>

        <div class="mobile-only mobile-card-list" id="usageMobileCards">
            <?php foreach ($usageRows as $row): ?>
                <article
                    class="mobile-card"
                    data-player="<?= h((string)$row['label']) ?>"
                    data-games="<?= (int)$row['games_count'] ?>"
                    data-field="<?= (int)$row['field_innings'] ?>"
                    data-bench="<?= (int)$row['bench_innings'] ?>"
                    data-bench_percent="<?= h((string)($row['bench_percent_value'] ?? 0)) ?>"
                    data-streak="<?= (int)$row['longest_bench_streak'] ?>"
                    data-total="<?= (int)$row['total_innings'] ?>"
                    <?php foreach ($allPositions as $position): ?>
                        <?php $positionSortKey = 'pos_' . usage_sort_key($position); ?>
                        data-<?= h($positionSortKey) ?>="<?= h((string)((float)($row['positions'][$position] ?? 0))) ?>"
                    <?php endforeach; ?>
                >
                    <div class="mobile-card-header">
                        <div>
                            <h3><?= h((string)$row['label']) ?></h3>

                            <?php if (!empty($row['jersey_number'])): ?>
                                <p>#<?= h((string)$row['jersey_number']) ?></p>
                            <?php endif; ?>
                        </div>

                        <span class="fairness-score">
                            <?= h((string)$row['bench_percent']) ?> bench
                        </span>
                    </div>

                    <div class="mobile-stat-grid">
                        <div><strong>Games</strong><span><?= (int)$row['games_count'] ?></span></div>
                        <div><strong>Field</strong><span><?= (int)$row['field_innings'] ?></span></div>
                        <div><strong>Bench</strong><span><?= (int)$row['bench_innings'] ?></span></div>
                        <div><strong>Streak</strong><span><?= (int)$row['longest_bench_streak'] ?></span></div>
                    </div>

                    <div style="margin-top:10px;">
                        <strong>Positions</strong>
                        <p class="muted" style="margin-top:4px;">
                            <?php
                            $positionBits = [];

                            foreach ($allPositions as $position) {
                                $count = (int)($row['positions'][$position] ?? 0);

                                if ($count > 0) {
                                    $positionBits[] = $position . ': ' . $count;
                                }
                            }
                            ?>

                            <?= h(empty($positionBits) ? 'No field assignments yet.' : implode(' | ', $positionBits)) ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
  function getValue(element, key, type) {
    const raw = element.dataset[key] || '';

    if (type === 'number') {
      const parsed = parseFloat(raw);
      return Number.isNaN(parsed) ? 0 : parsed;
    }

    return raw.toLowerCase();
  }

  function compare(a, b, key, type, direction) {
    const aValue = getValue(a, key, type);
    const bValue = getValue(b, key, type);

    let result = 0;

    if (type === 'number') {
      result = aValue - bValue;
    } else {
      result = String(aValue).localeCompare(String(bValue));
    }

    return direction === 'desc' ? -result : result;
  }

  function sortChildren(container, selector, key, type, direction) {
    if (!container) {
      return;
    }

    const rows = Array.from(container.querySelectorAll(selector));

    rows.sort(function (a, b) {
      return compare(a, b, key, type, direction);
    });

    rows.forEach(function (row) {
      container.appendChild(row);
    });
  }

  const table = document.getElementById('usageReportTable');

  if (table) {
    const tbody = table.querySelector('tbody');
    const buttons = table.querySelectorAll('.usage-sort-button');

    let currentSort = {
      key: 'player',
      direction: 'asc'
    };

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        const key = button.dataset.sort || 'player';
        const type = button.dataset.type || 'text';

        if (currentSort.key === key) {
          currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort.key = key;
          currentSort.direction = type === 'number' ? 'desc' : 'asc';
        }

        buttons.forEach(function (otherButton) {
          otherButton.classList.remove('is-asc', 'is-desc');
        });

        button.classList.add(currentSort.direction === 'asc' ? 'is-asc' : 'is-desc');

        sortChildren(tbody, 'tr', key, type, currentSort.direction);
      });
    });
  }

  const mobileCards = document.getElementById('usageMobileCards');
  const mobileSort = document.getElementById('usageMobileSort');
  const mobileDirection = document.getElementById('usageMobileDirection');

  function sortMobileCards() {
    if (!mobileCards || !mobileSort || !mobileDirection) {
      return;
    }

    const selectedOption = mobileSort.options[mobileSort.selectedIndex];
    const key = mobileSort.value || 'player';
    const type = selectedOption ? (selectedOption.dataset.type || 'text') : 'text';
    const direction = mobileDirection.value || 'asc';

    sortChildren(mobileCards, '.mobile-card', key, type, direction);
  }

  if (mobileSort) {
    mobileSort.addEventListener('change', sortMobileCards);
  }

  if (mobileDirection) {
    mobileDirection.addEventListener('change', sortMobileCards);
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
