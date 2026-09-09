<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';


require_login();

$teamId = current_team_id();
$pageTitle = 'History';
$currentPage = 'history';
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';
$games = [];
$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'locked';

$error = '';
$message = '';
$archiveRecord = null;
$archiveData = null;
$selectedGame = null;

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_locked_game_date') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);
            $newGameDate = trim((string)($_POST['game_date'] ?? ''));
            $statusFilter = trim((string)($_POST['status'] ?? 'locked'));

            if ($gameDbId <= 0) {
                throw new RuntimeException('Invalid locked game selected.');
            }

            update_locked_game_date($teamId, $gameDbId, $newGameDate !== '' ? $newGameDate : null);

            $selectedGameId = $gameDbId;
            flash_redirect('ok', 'Locked game date updated successfully.', 'history.php');
        }
    }

    $games = get_history_games($teamId, $statusFilter);

    usort($games, static function (array $a, array $b): int {
        $dateA = trim((string)($a['game_date'] ?? ''));
        $dateB = trim((string)($b['game_date'] ?? ''));

        if ($dateA === '' && $dateB === '') {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        }

        if ($dateA === '') {
            return 1;
        }

        if ($dateB === '') {
            return -1;
        }

        $timestampA = strtotime($dateA) ?: 0;
        $timestampB = strtotime($dateB) ?: 0;

        if ($timestampA === $timestampB) {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        }

        return $timestampB <=> $timestampA;
    });
    $gameHistoryLimit = null;

    if (
        function_exists('billing_enforcement_enabled') &&
        billing_enforcement_enabled()
    ) {
        $gameHistoryLimit = team_game_history_limit($teamId);

        if ($gameHistoryLimit !== null) {
            $games = array_slice($games, 0, $gameHistoryLimit);
        }
    }

    if ($selectedGameId > 0) {
        $selectedGame = get_game_by_id($teamId, $selectedGameId);
        if (!$selectedGame) {
            throw new RuntimeException('Selected game not found for this team.');
        }

        $archiveRecord = get_archive_record_for_game($teamId, $selectedGameId);

        if ($archiveRecord) {
            $archiveData = json_decode((string)$archiveRecord['archive_json'], true);

            if (!is_array($archiveData)) {
                throw new RuntimeException('Archive JSON is invalid for this game.');
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine();
}

function archive_player_name($cell, string $labelMode): string
{
    if (!is_array($cell)) {
        return '';
    }

    return format_player_label($cell, $labelMode);
}

function render_archive_lineup_table(array $archiveData): string
{
    $positions = $archiveData['game']['positions'] ?? ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];
    $lineupGrid = $archiveData['lineup']['lineup_grid'] ?? [];
    $innings = (int)($archiveData['game']['innings'] ?? 7);

    ob_start();
    ?>
    <div class="table-wrap">
      <table class="grid-table">
        <thead>
          <tr>
            <th>Position</th>
            <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
              <th>Inning <?= $inning ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($positions as $position): ?>
            <tr>
              <th><?= h((string)$position) ?></th>
              <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
              <td><?= h(archive_player_name($lineupGrid[$position][$inning] ?? null, $GLOBALS['labelMode'] ?? 'both')) ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string)ob_get_clean();
}

function render_archive_bench_table(array $archiveData): string
{
    $benchGrid = $archiveData['lineup']['bench_grid'] ?? [];
    $innings = (int)($archiveData['game']['innings'] ?? 7);

    if (!is_array($benchGrid) || empty($benchGrid)) {
        return '<p class="muted">No bench players for this archived game.</p>';
    }

    $benchCount = count($benchGrid);

    ob_start();
    ?>
    <div class="table-wrap">
      <table class="grid-table">
        <thead>
          <tr>
            <th>Bench Slot</th>
            <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
              <th>Inning <?= $inning ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php for ($slot = 0; $slot < $benchCount; $slot++): ?>
            <tr>
              <th>Bench <?= $slot + 1 ?></th>
              <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
              <td><?= h(archive_player_name($benchGrid[$slot][$inning] ?? null, $GLOBALS['labelMode'] ?? 'both')) ?></td>
              <?php endfor; ?>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string)ob_get_clean();
}

function get_archive_bench_summary_rows(array $archiveData): array
{
    $benchGrid = $archiveData['lineup']['bench_grid'] ?? [];
    $counts = [];

    if (is_array($benchGrid)) {
        foreach ($benchGrid as $slotRows) {
            if (!is_array($slotRows)) {
                continue;
            }

            foreach ($slotRows as $cell) {
                if (is_array($cell) && isset($cell['id'], $cell['name'])) {
                    $playerId = (int)$cell['id'];
                    $playerName = (string)$cell['name'];
                    $jersey = trim((string)($cell['jersey_number'] ?? ''));

                    if (!isset($counts[$playerId])) {
                        $counts[$playerId] = [
                            'name' => $playerName,
                            'jersey_number' => $jersey,
                            'first_name' => (string)($cell['first_name'] ?? ''),
                            'last_name' => (string)($cell['last_name'] ?? ''),
                            'bench_times' => 0,
                        ];
                    }

                    $counts[$playerId]['bench_times']++;
                }
            }
        }
    }

    if (empty($counts)) {
        return [];
    }

    uasort($counts, function (array $a, array $b): int {
        if ($b['bench_times'] === $a['bench_times']) {
            return strcmp($a['name'], $b['name']);
        }
        return $b['bench_times'] <=> $a['bench_times'];
    });

    return array_values($counts);
}

function render_archive_bench_summary_table(array $archiveData): string
{
    $rows = get_archive_bench_summary_rows($archiveData);

    if (empty($rows)) {
        return '<p class="muted">No bench appearances recorded.</p>';
    }

    ob_start();
    ?>
    <div class="table-wrap desktop-table">
      <table class="grid-table narrow-table">
        <thead>
          <tr>
            <th>Player</th>
            <th>Times Benched</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                  <?= h(format_player_label($row, $GLOBALS['labelMode'] ?? 'both')) ?>
              </td>
              <td><?= (int)$row['bench_times'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mobile-cards">
      <?php foreach ($rows as $row): ?>
        <div class="mobile-card">
          <div class="mobile-card-title">
              <?= h(format_player_label($row, $GLOBALS['labelMode'] ?? 'both')) ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Benched</span>
            <?= (int)$row['bench_times'] ?> times
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function get_archive_pitch_log_rows(array $archiveData): array
{
    $pitchLog = $archiveData['pitch_log'] ?? [];
    $nameMap = [];

    if (!empty($archiveData['roster']) && is_array($archiveData['roster'])) {
        foreach ($archiveData['roster'] as $player) {
           $fullName = trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? ''));

if (isset($player['id']) && $fullName !== '') {
                $nameMap[(int)$player['id']] = [
                'name' => (string)trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
                'first_name' => (string)($player['first_name'] ?? ''),
                'last_name' => (string)($player['last_name'] ?? ''),
                'jersey_number' => trim((string)($player['jersey_number'] ?? '')),
                ];
            }
        }
    }

    if (!is_array($pitchLog)) {
        return [];
    }

    $rows = [];

    foreach ($pitchLog as $key => $value) {
        if (is_array($value) && isset($value['player_id'])) {
            $playerId = (int)$value['player_id'];

            $rows[] = [
                'player_id' => $playerId,
                'name' => (string)($value['name'] ?? ($nameMap[$playerId]['name'] ?? ('Player #' . $playerId))),
                'first_name' => (string)($value['first_name'] ?? ($nameMap[$playerId]['first_name'] ?? '')),
                'last_name' => (string)($value['last_name'] ?? ($nameMap[$playerId]['last_name'] ?? '')),
                'jersey_number' => trim((string)($value['jersey_number'] ?? ($nameMap[$playerId]['jersey_number'] ?? ''))),
                'innings_pitched' => (int)($value['innings_pitched'] ?? 0),
                'pitches_thrown' => (int)($value['pitches_thrown'] ?? 0),
            ];

            continue;
        }

        if (is_array($value)) {
            $playerId = (int)$key;

            $rows[] = [
                'player_id' => $playerId,
                'name' => $nameMap[$playerId]['name'] ?? ('Player #' . $playerId),
                'first_name' => (string)($value['first_name'] ?? ($nameMap[$playerId]['first_name'] ?? '')),
                'last_name' => (string)($value['last_name'] ?? ($nameMap[$playerId]['last_name'] ?? '')),
                'jersey_number' => $nameMap[$playerId]['jersey_number'] ?? '',
                'innings_pitched' => (int)($value['innings_pitched'] ?? 0),
                'pitches_thrown' => (int)($value['pitches_thrown'] ?? 0),
            ];

            continue;
        }

        $playerId = (int)$key;

        $rows[] = [
            'player_id' => $playerId,
            'name' => $nameMap[$playerId]['name'] ?? ('Player #' . $playerId),
            'first_name' => (string)($value['first_name'] ?? ($nameMap[$playerId]['first_name'] ?? '')),
            'last_name' => (string)($value['last_name'] ?? ($nameMap[$playerId]['last_name'] ?? '')),
            'jersey_number' => $nameMap[$playerId]['jersey_number'] ?? '',
            'innings_pitched' => (int)$value,
            'pitches_thrown' => 0,
        ];
    }

    usort($rows, function (array $a, array $b): int {
        if ($b['innings_pitched'] === $a['innings_pitched']) {
            if (($b['pitches_thrown'] ?? 0) === ($a['pitches_thrown'] ?? 0)) {
                return strcmp($a['name'], $b['name']);
            }
            return ($b['pitches_thrown'] ?? 0) <=> ($a['pitches_thrown'] ?? 0);
        }
        return $b['innings_pitched'] <=> $a['innings_pitched'];
    });

    return $rows;
}

function render_archive_pitch_log_table(array $archiveData): string
{
    $rows = get_archive_pitch_log_rows($archiveData);

    ob_start();
    ?>
    <div class="table-wrap desktop-table">
      <table class="grid-table narrow-table">
        <thead>
          <tr>
            <th>Player</th>
            <th>Innings Pitched</th>
            <th>Pitches Thrown</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="3">No pitching recorded.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                    <?= h(format_player_label($row, $GLOBALS['labelMode'] ?? 'both')) ?>
                </td>
                <td><?= (int)$row['innings_pitched'] ?></td>
                <td><?= (int)($row['pitches_thrown'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="mobile-cards">
      <?php if (empty($rows)): ?>
        <p class="muted">No pitching recorded.</p>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <div class="mobile-card">
            <div class="mobile-card-title">
              <?php if ($row['jersey_number'] !== ''): ?>
                #<?= h($row['jersey_number']) ?>
              <?php endif; ?>
              <?= h($row['name']) ?>
            </div>
            <div class="mobile-card-row">
              <span class="mobile-card-label">Innings</span>
              <?= (int)$row['innings_pitched'] ?>
            </div>
            <div class="mobile-card-row">
              <span class="mobile-card-label">Pitches</span>
              <?= (int)($row['pitches_thrown'] ?? 0) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function render_archive_roster(array $archiveData): string
{
    $roster = $archiveData['roster'] ?? [];

    if (!is_array($roster) || empty($roster)) {
        return '<p class="muted">No archived roster found.</p>';
    }

    ob_start();
    ?>
    <ol class="roster-list">
      <?php foreach ($roster as $player): ?>
        <li>
            <?= h(format_player_label($player, $GLOBALS['labelMode'] ?? 'both')) ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php
    return (string)ob_get_clean();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">History</h1>

<?php if (isset($gameHistoryLimit) && $gameHistoryLimit !== null): ?>
  <div class="msg info" style="margin-bottom:16px;">
    Your current plan shows your latest <?= (int)$gameHistoryLimit ?> game history item<?= (int)$gameHistoryLimit === 1 ? '' : 's' ?>.
    <a href="billing.php?upgrade_reason=game_history_items">Upgrade</a>
    for full game history.
  </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Browse History</h2>

  <form method="get" action="history.php">
      <?= csrf_field() ?>
    <div class="actions-row">
      <div>
        <label for="status">Status Filter</label>
        <select name="status" id="status">
          <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Locked</option>
          <option value="generated" <?= $statusFilter === 'generated' ? 'selected' : '' ?>>Generated</option>
          <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
        </select>
      </div>

      <div>
        <label for="game_id">Game</label>
        <select name="game_id" id="game_id">
          <option value="">-- Select a Game --</option>
          <?php foreach ($games as $g): ?>
            <option
              value="<?= (int)$g['id'] ?>"
              <?= $selectedGameId === (int)$g['id'] ? 'selected' : '' ?>
            >
                <?= h((string)$g['game_id']) ?>
                |
                <?= h((string)$g['status']) ?>
                |
                <?= !empty($g['game_date'])
                    ? h(date('M j, Y', strtotime((string)$g['game_date'])))
                    : 'No game date' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="align-self:end;">
        <button type="submit">Load History</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Games</h2>

  <div class="history-game-grid">
    <?php if (empty($games)): ?>
      <p class="muted">No games found for this filter.</p>
    <?php else: ?>
      <?php foreach ($games as $g): ?>
        <div class="mobile-card">
          <div class="mobile-card-title"><?= h((string)$g['game_id']) ?></div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Status</span>
            <span class="pill <?= h(status_class((string)$g['status'])) ?>"><?= h((string)$g['status']) ?></span>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Innings</span>
            <?= (int)$g['innings'] ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Roster</span>
            <?= (int)$g['roster_size'] ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Bench</span>
            <?= (int)$g['bench_count'] ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Game Date</span>
            <?= !empty($g['game_date'])
                ? h(date('M j, Y', strtotime((string)$g['game_date'])))
                : 'Not set' ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Locked</span>
            <?= format_datetime_12h((string)($g['locked_at'] ?? ''), 'Not locked') ?>
          </div>

          <div class="stack-actions">
            <a class="btn" href="history.php?status=<?= urlencode($statusFilter) ?>&game_id=<?= (int)$g['id'] ?>">
              View
            </a>

            <?php if (in_array((string)($g['status'] ?? ''), ['generated', 'locked'], true)): ?>
              <a class="btn btn-secondary" href="print_lineup.php?game_id=<?= (int)$g['id'] ?>">
                Print Dugout Sheet
              </a>

              <a class="btn btn-secondary" href="history_pitch_counts.php?game_id=<?= (int)$g['id'] ?>">
                Edit Pitch Counts
              </a>
            <?php endif; ?>

            <?php if (team_stats_enabled($teamId)): ?>
              <a class="btn btn-secondary" href="game_stats.php?game_id=<?= (int)$g['id'] ?>">
                Game Stats
              </a>

            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedGame): ?>
  <div class="card">
    <h2>Selected Game</h2>

    <div class="meta">
      <div class="meta-box">
        <div class="meta-label">Game ID</div>
        <div class="meta-value"><?= h((string)$selectedGame['game_id']) ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Status</div>
        <div class="meta-value"><?= h((string)$selectedGame['status']) ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Game Date</div>
        <div class="meta-value"><?= !empty($selectedGame['game_date']) ? h((string)$selectedGame['game_date']) : 'Not set' ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Innings</div>
        <div class="meta-value"><?= (int)$selectedGame['innings'] ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Roster Size</div>
        <div class="meta-value"><?= (int)$selectedGame['roster_size'] ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Bench Count</div>
        <div class="meta-value"><?= (int)$selectedGame['bench_count'] ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Locked At</div>
        <div class="meta-value"><?= format_datetime_12h((string)($selectedGame['locked_at'] ?? ''), 'Not locked') ?></div>
      </div>
    </div>

    <div class="stack-actions" style="margin-top:18px;">
      <?php if (in_array((string)($selectedGame['status'] ?? ''), ['generated', 'locked'], true)): ?>
        <a class="btn btn-secondary" href="print_lineup.php?game_id=<?= (int)$selectedGame['id'] ?>">
          Print Dugout Sheet
        </a>

        <a class="btn btn-secondary" href="history_pitch_counts.php?game_id=<?= (int)$selectedGame['id'] ?>">
          Edit Pitch Counts
        </a>
      <?php endif; ?>
      <?php if (team_stats_enabled($teamId)): ?>
        <a class="btn btn-secondary" href="game_stats.php?game_id=<?= (int)$selectedGame['id'] ?>">
          Game Stats
        </a>
      <?php endif; ?>

    </div>

    <?php if ((string)($selectedGame['status'] ?? '') === 'locked'): ?>
      <div style="margin-top:18px;">
        <h3 style="margin-bottom:10px;">Update Locked Game Date</h3>

        <form method="post" action="history.php">
            <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_locked_game_date">
          <input type="hidden" name="game_db_id" value="<?= (int)$selectedGame['id'] ?>">
          <input type="hidden" name="status" value="<?= h($statusFilter) ?>">

          <label for="locked_game_date">Game Date</label>
          <input
            type="date"
            id="locked_game_date"
            name="game_date"
            value="<?= h((string)($selectedGame['game_date'] ?? '')) ?>"
          >

          <div class="actions-row" style="margin-top:12px;">
            <button type="submit">Update Date</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($selectedGame && !$archiveRecord): ?>
  <div class="card">
    <h2>Archive Missing</h2>
    <p>No archive record exists yet for this game.</p>
  </div>
<?php endif; ?>

<?php if ($archiveData): ?>
  <div class="card">
    <h2>Archived Batting Order</h2>
    <?= render_archive_roster($archiveData) ?>
  </div>

  <div class="card">
    <h2>Archived Lineup</h2>
    <?= render_archive_lineup_table($archiveData) ?>
  </div>

  <div class="card">
    <h2>Archived Bench Assignments</h2>
    <?= render_archive_bench_table($archiveData) ?>
  </div>

  <div class="card">
    <h2>Bench Summary</h2>
    <?= render_archive_bench_summary_table($archiveData) ?>
  </div>

  <div class="card">
    <h2>Archived Pitch Log</h2>
    <?= render_archive_pitch_log_table($archiveData) ?>
  </div>

  <div class="card hide">
    <h2>Archive JSON Preview</h2>
    <pre><?= h(substr((string)$archiveRecord['archive_json'], 0, 5000)) ?></pre>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
