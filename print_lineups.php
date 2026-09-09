<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Print Lineups';
$currentPage = 'print_lineups';

$error = '';
$printableGames = [];
$selectedGameId = (int)($_GET['game_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Printable lineup destination
|--------------------------------------------------------------------------
|
| Change this to the existing page that displays one completed lineup.
|
| Examples:
|   lineup_print.php
|   print_lineup.php
|   view_lineup.php
|
*/
$lineupPrintPage = 'print_lineup.php';

/*
|--------------------------------------------------------------------------
| Current team
|--------------------------------------------------------------------------
*/

$teamId = 0;

if (function_exists('get_current_team_id')) {
    $teamId = (int)get_current_team_id();
} elseif (function_exists('current_team_id')) {
    $teamId = (int)current_team_id();
} else {
    $teamId = (int)($_SESSION['current_team_id'] ?? 0);
}

if ($teamId <= 0) {
    http_response_code(400);
    exit('No active team selected.');
}

/*
|--------------------------------------------------------------------------
| Database helpers
|--------------------------------------------------------------------------
*/

function print_lineups_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    static $cache = [];

    $cacheKey = $table . '.' . $column;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    $cache[$cacheKey] = (int)$stmt->fetchColumn() > 0;

    return $cache[$cacheKey];
}

function print_lineups_first_existing_column(
    PDO $pdo,
    string $table,
    array $columns
): ?string {
    foreach ($columns as $column) {
        if (print_lineups_column_exists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Load printable games
|--------------------------------------------------------------------------
*/

try {
    $pdo = db();

    $dateColumn = print_lineups_first_existing_column(
        $pdo,
        'games',
        [
            'game_date',
            'scheduled_at',
            'starts_at',
            'start_at',
            'date',
        ]
    );

    $gameNameColumn = print_lineups_first_existing_column(
        $pdo,
        'games',
        [
            'game_name',
            'name',
            'title',
            'opponent_name',
            'opponent',
            'away_team',
        ]
    );

    $locationColumn = print_lineups_first_existing_column(
        $pdo,
        'games',
        [
            'location',
            'venue',
            'field_name',
            'park_name',
        ]
    );

    $readyColumn = print_lineups_first_existing_column(
        $pdo,
        'games',
        [
            'lineup_status',
            'status',
            'ready_for_print',
            'is_ready_for_print',
            'lineup_locked',
        ]
    );

    if ($dateColumn === null) {
        throw new RuntimeException(
            'The games table does not contain a recognized game-date column.'
        );
    }

    if ($readyColumn === null) {
        throw new RuntimeException(
            'The games table does not contain a recognized Ready for Print column.'
        );
    }

    $gameNameColumn = print_lineups_first_existing_column(
        $pdo,
        'games',
        [
            'game_name',
            'title',
            'label',
            'description',
            'opponent',
            'opponent_name',
        ]
    );

    $selectParts = [
        'id',
        'game_id',
        'game_date',
    ];

    if ($gameNameColumn !== null) {
        $selectParts[] = $gameNameColumn . ' AS game_name';
    } else {
        $selectParts[] = "'' AS game_name";
    }

    if ($gameNameColumn !== null) {
        $selectParts[] = $gameNameColumn . ' AS game_name';
    } else {
        $selectParts[] = "'' AS game_name";
    }

    if ($locationColumn !== null) {
        $selectParts[] = $locationColumn . ' AS location';
    } else {
        $selectParts[] = "'' AS location";
    }

    /*
    |--------------------------------------------------------------------------
    | Ready-for-print condition
    |--------------------------------------------------------------------------
    |
    | The page supports several common status formats:
    |
    | lineup_status/status:
    |   ready_for_print
    |   ready
    |   locked
    |
    | Boolean fields:
    |   ready_for_print = 1
    |   is_ready_for_print = 1
    |   lineup_locked = 1
    |
    */

    if (in_array($readyColumn, ['lineup_status', 'status'], true)) {
        $readyCondition = "
            LOWER(COALESCE({$readyColumn}, '')) IN (
                'ready_for_print',
                'ready for print',
                'ready',
                'locked'
            )
        ";
    } else {
        $readyCondition = "{$readyColumn} = 1";
    }

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM games
        WHERE team_id = :team_id
          AND {$readyCondition}
        ORDER BY {$dateColumn} DESC, id DESC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $printableGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Validate the requested game
    |--------------------------------------------------------------------------
    */

    if ($selectedGameId > 0) {
        $validGameIds = array_map(
            static fn(array $game): int => (int)$game['id'],
            $printableGames
        );

        if (!in_array($selectedGameId, $validGameIds, true)) {
            throw new RuntimeException(
                'The selected game is not available or is not ready for print.'
            );
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Print Lineups</h1>

<?php if ($error !== ''): ?>
  <div class="msg err">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<div class="layout-grid print-lineups-layout">

  <div class="card">
    <h2>Select a Game</h2>

    <p class="muted">
      Only games whose lineups are marked Ready for Print are shown.
    </p>

    <?php if (empty($printableGames)): ?>

      <div class="msg info">
        No lineups are currently ready for print.
      </div>

    <?php else: ?>

      <form
        method="get"
        action="print_lineups.php"
        id="printLineupSelector"
      >
        <div class="form-field">
          <label for="game_id">Game</label>

          <select
            id="game_id"
            name="game_id"
            required
          >
            <option value="">-- Select Game --</option>

            <?php foreach ($printableGames as $game): ?>
              <?php
                $databaseId = (int)($game['id'] ?? 0);
                $gameId = trim((string)($game['game_id'] ?? ''));
                $gameDateRaw = trim((string)($game['game_date'] ?? ''));

                if ($gameDateRaw !== '') {
                    $timestamp = strtotime($gameDateRaw);

                    $gameDateLabel = $timestamp !== false
                        ? date('M j, Y', $timestamp)
                        : $gameDateRaw;
                } else {
                    $gameDateLabel = 'No date';
                }

                if ($gameId === '') {
                    $gameId = 'Game #' . $databaseId;
                }

                $gameLabel = $gameDateLabel . ' - ' . $gameId;
              ?>

              <option
                value="<?= $databaseId ?>"
                <?= $selectedGameId === $databaseId ? 'selected' : '' ?>
              >
                <?= h($gameLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>


      </form>

    <?php endif; ?>
  </div>

</div>

<?php if ($selectedGameId > 0 && $error === ''): ?>

  <div class="card selected-lineup-card">
    <div class="selected-lineup-header">
      <div>
        <h2 style="margin-bottom:4px;">Selected Lineup</h2>

        <p class="muted" style="margin:0;">
          Open the complete print layout for this game.
        </p>
      </div>

      <div class="actions-row">
        <a
          class="btn"
          href="<?= h($lineupPrintPage) ?>?game_id=<?= $selectedGameId ?>"
          target="_blank"
          rel="noopener"
        >
          Open Printable Lineup
        </a>
      </div>
    </div>
  </div>

<?php endif; ?>

<style>
.print-lineups-layout {
  align-items: start;
}

.selected-lineup-card {
  margin-top: 18px;
}

.selected-lineup-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
}

@media (max-width: 700px) {
  .selected-lineup-header {
    align-items: stretch;
    flex-direction: column;
  }

  .selected-lineup-header .actions-row,
  .selected-lineup-header .btn {
    width: 100%;
  }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const selector = document.getElementById('game_id');
  const form = document.getElementById('printLineupSelector');

  if (!selector || !form) {
    return;
  }

  selector.addEventListener('change', function () {
    if (selector.value !== '') {
      form.submit();
    }
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
