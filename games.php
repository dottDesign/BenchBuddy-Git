<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Games';
$currentPage = 'games';
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';
$message = '';
$error = '';

$games = [];
$editGameId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingGame = null;

$activePlayers = [];
$defaultRosterIds = [];

function game_roster_ids(array $game): array
{
    $ids = [];

    foreach (($game['roster'] ?? []) as $player) {
        $ids[] = (int)$player['id'];
    }

    return $ids;
}

function build_selected_roster_from_post(array $playerIds): array
{
    $ordered = [];

    foreach ($playerIds as $playerId) {
        $playerId = (int)$playerId;

        if ($playerId > 0 && !in_array($playerId, $ordered, true)) {
            $ordered[] = $playerId;
        }
    }

    return $ordered;
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    $activePlayers = get_active_players($teamId);
    $defaultRosterIds = build_default_batting_order_from_previous_game($teamId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_game' || $action === 'update_game') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);
            $gameId = trim((string)($_POST['game_id'] ?? ''));
            $innings = (int)($_POST['innings'] ?? 7);
            $gameDate = trim((string)($_POST['game_date'] ?? ''));
            $playerIdsRaw = $_POST['player_ids'] ?? [];
            $countsForPitching = !empty($_POST['counts_for_pitching']) ? 1 : 0;
            $countsTowardStats = !empty($_POST['counts_toward_stats']) ? 1 : 0;
            $homeAway = (string)($_POST['home_away'] ?? '');

            if (!in_array($homeAway, ['', 'home', 'away'], true)) {
                throw new RuntimeException('Invalid home/away value.');
            }


            $actualInningsPlayedRaw = trim((string)($_POST['actual_innings_played'] ?? ''));
            $actualInningsPlayed = $actualInningsPlayedRaw !== ''
                ? (float)$actualInningsPlayedRaw
                : null;
            if ($actualInningsPlayed !== null && $actualInningsPlayed < 0) {
                throw new RuntimeException('Actual innings played cannot be negative.');
            }
            if ($gameId === '') {
                throw new RuntimeException('Game ID is required.');
            }
            if ($innings <= 0) {
                throw new RuntimeException('Innings must be greater than 0.');
            }
            if (!is_array($playerIdsRaw)) {
                $playerIdsRaw = [];
            }
            $playerIds = build_selected_roster_from_post($playerIdsRaw);

            if (count($playerIds) < 8) {
                throw new RuntimeException('Please select at least 8 players for the roster.');
            }
            if (count($playerIds) > 14) {
                throw new RuntimeException('Maximum supported roster is 14 players.');
            }
            if ($gameDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)) {
                throw new RuntimeException('Game date must be a valid date.');
            }
            if ($action === 'create_game') {
                assert_team_limit_available(
                    $teamId,
                    'games_per_team',
                    count_team_games_for_limit($teamId),
                    'Your current plan has reached its game limit. Upgrade to create more games.'
                );

                $newGameId = create_game($teamId, $gameId, $innings);

                $pdo = db();
                $stmt = $pdo->prepare("
                UPDATE games
                SET game_date = :game_date,
                    home_away = :home_away,
                    actual_innings_played = :actual_innings_played,
                    counts_for_pitching = :counts_for_pitching,
                    counts_toward_stats = :counts_toward_stats
                    WHERE id = :id
                      AND team_id = :team_id
                ");
                $stmt->execute([
                    'game_date' => ($gameDate !== '' ? $gameDate : null),
                    'counts_for_pitching' => $countsForPitching,
                    'id' => $newGameId,
                    'team_id' => $teamId,
                    'home_away' => $homeAway !== '' ? $homeAway : null,
                    'actual_innings_played' => $actualInningsPlayed,
                    'counts_toward_stats' => $countsTowardStats,
                ]);

                save_game_roster($teamId, $newGameId, $playerIds);

                $stmt = db()->prepare("
                    SELECT onboarding_first_game_shown
                    FROM teams
                    WHERE id = :team_id
                    LIMIT 1
                ");
                $stmt->execute([
                    'team_id' => $teamId,
                ]);

                $shown = (int)$stmt->fetchColumn();

                if (!$shown && !onboarding_dismissed($teamId)) {

                    set_next_step_modal(
                        'Game Created',
                        'Your game is ready. Next, let BenchBuddy build the lineup.',
                        'Build Lineup',
                        'generate.php?game_id=' . $newGameId,
                        'Back to Games',
                        'games.php'
                    );

                    db()->prepare("
                        UPDATE teams
                        SET onboarding_first_game_shown = 1
                        WHERE id = :team_id
                        LIMIT 1
                    ")->execute([
                        'team_id' => $teamId,
                    ]);
                }

                $_SESSION['flash'] = [
                    'type' => 'ok',
                    'message' => 'Game created successfully.',
                ];

                session_write_close();

                header('Location: generate.php?game_id=' . $newGameId);
                exit;
            }

            if ($gameDbId <= 0) {
                throw new RuntimeException('Invalid game selected.');
            }

            $game = get_game_by_id($teamId, $gameDbId);
            if (!$game) {
                throw new RuntimeException('Game not found for this team.');
            }

            if (($game['status'] ?? '') === 'locked') {
                throw new RuntimeException('Locked games cannot be edited. Cancel it instead if needed.');
            }

            $pdo = db();
            $stmt = $pdo->prepare("
                UPDATE games
                SET game_id = :game_id,
                    game_date = :game_date,
                    innings = :innings,
                    home_away = :home_away,
                    actual_innings_played = :actual_innings_played,
                    counts_for_pitching = :counts_for_pitching,
                    counts_toward_stats = :counts_toward_stats
                WHERE id = :id
                  AND team_id = :team_id
            ");
            $stmt->execute([
                'game_id' => $gameId,
                'game_date' => ($gameDate !== '' ? $gameDate : null),
                'innings' => $innings,
                'counts_for_pitching' => $countsForPitching,
                'id' => $gameDbId,
                'team_id' => $teamId,
                'home_away' => $homeAway !== '' ? $homeAway : null,
                'actual_innings_played' => $actualInningsPlayed,
                'counts_toward_stats' => $countsTowardStats,
            ]);

            save_game_roster($teamId, $gameDbId, $playerIds);

            if (($game['status'] ?? '') === 'generated') {
                update_game_status($teamId, $gameDbId, 'draft');
            }
            flash_redirect('ok', 'Game updated successfully.', 'games.php');
            $editGameId = $gameDbId;
        }

        if ($action === 'cancel_game') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);
            $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));

            if ($gameDbId <= 0) {
                throw new RuntimeException('Invalid game selected.');
            }

            cancel_game($teamId, $gameDbId, $cancelReason !== '' ? $cancelReason : 'cancelled');
            flash_redirect('ok', 'Game cancelled successfully.', 'games.php');
            $editGameId = 0;
            $editingGame = null;
        }

        if ($action === 'permanently_delete_game') {
            $gameDbId = (int)($_POST['game_db_id'] ?? 0);

            if ($gameDbId <= 0) {
                throw new RuntimeException('Invalid game selected.');
            }

            permanently_delete_game($teamId, $gameDbId);
            flash_redirect('ok', 'Game permanently deleted.', 'games.php');
            $editGameId = 0;
            $editingGame = null;
        }
    }

    $games = get_all_games($teamId);

    if ($editGameId > 0) {
        $editingGame = get_game_by_id($teamId, $editGameId);
        if ($editingGame) {
            $editingGame['roster'] = get_game_roster($teamId, $editGameId);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();

    if ($teamId > 0) {
        $games = get_all_games($teamId);
        $activePlayers = get_active_players($teamId);
        $defaultRosterIds = build_default_batting_order_from_previous_game($teamId);

        if ($editGameId > 0) {
            $editingGame = get_game_by_id($teamId, $editGameId);
            if ($editingGame) {
                $editingGame['roster'] = get_game_roster($teamId, $editGameId);
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

$selectedRosterIds = $editingGame ? game_roster_ids($editingGame) : $defaultRosterIds;

if (empty($selectedRosterIds)) {
    $selectedRosterIds = array_map(
        fn(array $player): int => (int)$player['id'],
        array_slice($activePlayers, 0, 9)
    );
}

$activePlayerMap = [];
foreach ($activePlayers as $player) {
    $activePlayerMap[(int)$player['id']] = $player;
}

$dragRoster = [];

foreach ($selectedRosterIds as $playerId) {
    if (isset($activePlayerMap[(int)$playerId])) {
        $dragRoster[] = $activePlayerMap[(int)$playerId];
    }
}

foreach ($activePlayers as $player) {
    $playerId = (int)$player['id'];
    if (!in_array($playerId, $selectedRosterIds, true)) {
        $dragRoster[] = $player;
    }
}
?>

<h1 class="page-title brand-title-font">Games</h1>

<div class="workflow">
  <div class="workflow-step active">1 Set Roster</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step">2 Build Lineup</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step">3 Finalize Game</div>
</div>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="games-layout">
  <div class="card">
    <h2><?= $editingGame ? 'Edit Game' : 'Create Game' ?></h2>

    <form method="post" action="games.php<?= $editingGame ? '?edit=' . (int)$editingGame['id'] : '' ?>" id="game-form">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editingGame ? 'update_game' : 'create_game' ?>">

      <?php if ($editingGame): ?>
        <input type="hidden" name="game_db_id" value="<?= (int)$editingGame['id'] ?>">
      <?php endif; ?>

        <div class="create-game-grid">

          <div class="form-field game-name-field">
            <label for="game_id">Game ID</label>

            <input
              type="text"
              id="game_id"
              name="game_id"
              required
              value="<?= h((string)($editingGame['game_id'] ?? '')) ?>"
              placeholder="Game 1"
            >
          </div>

          <div class="form-field">
            <label for="game_date">Game Date</label>

            <input
              type="date"
              id="game_date"
              name="game_date"
              value="<?= h((string)($editingGame['game_date'] ?? '')) ?>"
            >
          </div>

          <div class="form-field">
            <label for="home_away">Home / Away</label>

            <select id="home_away" name="home_away">
              <option value="">-- Select --</option>

              <option value="home" <?= (string)($editingGame['home_away'] ?? '') === 'home' ? 'selected' : '' ?>>
                Home
              </option>

              <option value="away" <?= (string)($editingGame['home_away'] ?? '') === 'away' ? 'selected' : '' ?>>
                Away
              </option>
            </select>
          </div>

          <div class="form-field innings-field">
            <label for="innings">Innings</label>

            <input
              type="number"
              id="innings"
              name="innings"
              min="1"
              max="20"
              required
              value="<?= (int)($editingGame['innings'] ?? 7) ?>"
            >
          </div>

          <div class="form-field innings-played-field">
            <label for="actual_innings_played">Played</label>

            <input
              type="number"
              id="actual_innings_played"
              name="actual_innings_played"
              min="0"
              max="20"
              step="0.5"
              value="<?= h((string)($editingGame['actual_innings_played'] ?? '')) ?>"
              placeholder="0"
            >
          </div>

        </div>
      <div class="card" style="margin-bottom:16px;">
        <label class="toggle-row" for="counts_for_pitching_toggle">
          <span class="toggle-label-text">Counts toward pitch counts and rest rules</span>
          <span class="toggle-switch">
            <input
              type="checkbox"
              id="counts_for_pitching_toggle"
              name="counts_for_pitching"
              value="1"
              <?= ((int)($editingGame['counts_for_pitching'] ?? 1) === 1) ? 'checked' : '' ?>
            >
            <span class="toggle-slider"></span>
          </span>
        </label>

        <div class="muted" style="margin-top:6px;">
          When enabled, this game affects pitcher pitch counts and rest availability. Turn it off for scrimmages or practice games.
        </div>

        <label class="toggle-row">
          <span class="toggle-label-text">Count Toward Player Stats</span>

          <span class="toggle-switch">
            <input
              type="checkbox"
              name="counts_toward_stats"
              value="1"
              <?= (int)($editingGame['counts_toward_stats'] ?? 1) === 1 ? 'checked' : '' ?>
            >
            <span class="toggle-slider"></span>
          </span>
        </label>

        <div class="toggle-help">
          Turn this off for scrimmages or practice games that should not affect season stats.
        </div>
      </div>

      <label>Batting Order / Roster</label>
      <p class="muted">
        Check the players who are attending, then drag them into batting order. Select 8 to 14 players.
      </p>

      <?php if (empty($activePlayers)): ?>
        <p class="muted">No active players found. Add players first.</p>
      <?php else: ?>
        <ul id="batting-order-list" class="batting-order-list">
        <?php $battingOrderNumber = 0; ?>
          <?php foreach ($dragRoster as $player): ?>
            <?php
              $playerId = (int)$player['id'];
              $checked = in_array($playerId, $selectedRosterIds, true);
              $orderNumber = $checked ? ++$battingOrderNumber : 0;
            ?>
            <li class="batting-order-item <?= $checked ? '' : 'not-selected' ?>" draggable="true">
                <span class="batting-order-number">

                  <?= $orderNumber > 0 ? (int)$orderNumber : '—' ?>

                </span>

                <span class="drag-handle">☰</span>

              <div class="batting-order-player">
                <span class="batting-order-name">
                    <?= h(format_player_label($player, $labelMode)) ?>
                </span>

                <label class="ios-toggle">
                  <input
                    type="checkbox"
                    class="roster-player-check"
                    value="<?= $playerId ?>"
                    <?= $checked ? 'checked' : '' ?>
                  >
                  <span class="ios-toggle-slider"></span>
                </label>
              </div>

            </li>
          <?php endforeach; ?>
        </ul>

        <div id="selected-player-inputs"></div>
      <?php endif; ?>

      <div class="actions-row">
        <button type="submit"><?= $editingGame ? 'Update Game' : 'Create Game' ?></button>

        <?php if ($editingGame): ?>
          <a class="btn btn-secondary" href="games.php">Cancel Edit</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="actions-row" style="justify-content:space-between;">
      <h2 style="margin:0;">All Games</h2>
      <a class="btn btn-secondary" href="cancelled_games.php">View Cancelled Games</a>
    </div>


    <?php if (empty($games)): ?>
      <p class="muted">No games found yet.</p>
    <?php else: ?>
      <div class="games-card-list">
        <?php foreach ($games as $game): ?>
          <?php $displayStatus = game_display_status($game); ?>

          <article class="game-list-card">
            <div class="game-list-main">
              <div class="game-list-header">
                <div>
                  <h3><?= h((string)$game['game_id']) ?></h3>
                  <?php
                  $homeAway = (string)($game['home_away'] ?? '');
                  $homeAwayClass = match ($homeAway) {
                      'home' => 'pill-home',
                      'away' => 'pill-away',
                      default => 'pill-unset',
                  };

                  $homeAwayLabel = match ($homeAway) {
                      'home' => 'Home',
                      'away' => 'Away',
                      default => 'Home/Away not set',
                  };
                  ?>

                  <span class="pill <?= h($homeAwayClass) ?>">
                    <?= h($homeAwayLabel) ?>
                  </span>

                  <div class="game-list-date">
                    <?= !empty($game['game_date']) ? h((string)$game['game_date']) : 'Date not set' ?>
                  </div>
                </div>

                <span class="pill <?= h(status_class($displayStatus)) ?>">
                  <?= h(game_status_label($game)) ?>
                </span>
              </div>

              <div class="game-list-stats">
                <div>
                  <span>Pitch Count</span>
                  <strong><?= ((int)($game['counts_for_pitching'] ?? 1) === 1) ? 'Yes' : 'No' ?></strong>
                </div>
                <div>
                  <span>Record Stats</span>
                  <strong><?= ((int)($game['counts_toward_stats'] ?? 1) === 1) ? 'Yes' : 'No' ?></strong>
                </div>
                <div>
                  <span>Innings</span>
                  <strong><?= (int)$game['innings'] ?></strong>
                </div>
                <div>
                  <span>Innings Played</span>
                  <strong>
                    <?= $game['actual_innings_played'] !== null && $game['actual_innings_played'] !== ''
                        ? h(number_format((float)$game['actual_innings_played'], 1))
                        : '—'
                    ?>
                  </strong>
                </div>
                <div>
                  <span>Roster</span>
                  <strong><?= (int)$game['roster_size'] ?></strong>
                </div>

                <div>
                  <span>Bench</span>
                  <strong><?= (int)$game['bench_count'] ?></strong>
                </div>
              </div>

              <div class="game-list-created">
                Created <?= h((string)$game['created_at']) ?>
              </div>
            </div>

            <div class="game-list-actions">
              <?php if (($game['status'] ?? '') !== 'locked'): ?>
                <a class="btn-sm" href="games.php?edit=<?= (int)$game['id'] ?>">Edit</a>
              <?php endif; ?>

              <?php if ((string)($game['status'] ?? '') === 'locked'): ?>
                <a class="btn-sm" href="print_lineup.php?game_id=<?= (int)$game['id'] ?>" target="_blank">Print</a>
              <?php endif; ?>

              <a class="btn-sm" href="generate.php?game_id=<?= (int)$game['id'] ?>">Build</a>
              <a class="btn-sm" href="lock.php?game_id=<?= (int)$game['id'] ?>">Finalize</a>
              <a class="btn-sm" href="history.php?game_id=<?= (int)$game['id'] ?>&status=all">History</a>
              <?php if (team_stats_enabled($teamId)): ?>
              <a class="btn-sm" href="game_stats.php?game_id=<?= (int)$game['id'] ?>">Stats</a>
              <?php endif; ?>
              <button
                type="button"
                class="btn-sm btn-secondary"
                onclick='openGameActionModal("cancel", <?= (int)$game["id"] ?>, <?= json_encode((string)$game["game_id"]) ?>)'
              >
                Cancel
              </button>

              <button
                type="button"
                class="btn-sm btn-danger"
                onclick='openGameActionModal("delete", <?= (int)$game["id"] ?>, <?= json_encode((string)$game["game_id"]) ?>)'
              >
                Delete
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>


  </div>
</div>

<div id="gameActionModal" class="help-modal-backdrop hide">
  <div class="help-modal">
    <div class="help-modal-header">
      <h2 id="game_action_title">Game Action</h2>
      <button type="button" class="help-modal-close" onclick="closeGameActionModal()">×</button>
    </div>

    <form method="post" action="games.php">
        <?= csrf_field() ?>
      <input type="hidden" name="action" id="game_action_type">
      <input type="hidden" name="game_db_id" id="game_action_game_id">

      <div class="help-modal-body">
        <div class="help-step">
          <strong id="game_action_game_label"></strong>
          <p id="game_action_description"></p>
        </div>

        <div id="cancel_reason_block">
          <label for="cancel_reason_modal">Reason</label>
          <input
            type="text"
            id="cancel_reason_modal"
            name="cancel_reason"
            placeholder="Rainout, field issue, forfeit, etc"
            maxlength="100"
          >
        </div>
      </div>

      <div class="help-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeGameActionModal()">Cancel</button>
        <button type="submit" id="game_action_submit">Confirm</button>
      </div>
    </form>
  </div>
</div>

<style>
.batting-order-number {
  width: 34px;
  height: 34px;
  border-radius: 999px;
  background: var(--primary);
  color: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
  flex: 0 0 auto;
}

.batting-order-item.not-selected .batting-order-number {
  background: #e5e7eb;
  color: #6b7280;
}
.batting-order-list {
  list-style: none;
  padding: 0;
  margin: 12px 0 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.batting-order-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #ffffff;
  cursor: grab;
}

.batting-order-item.dragging {
  opacity: 0.5;
}

.batting-order-item.not-selected {
  opacity: 0.55;
  background: #f9fafb;
}

.drag-handle {
  font-weight: 800;
  cursor: grab;
}

.batting-order-check {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  width: 100%;
}
</style>

<script>
window.openGameActionModal = function (type, gameId, gameLabel) {
  const modal = document.getElementById('gameActionModal');
  const title = document.getElementById('game_action_title');
  const desc = document.getElementById('game_action_description');
  const actionField = document.getElementById('game_action_type');
  const gameIdField = document.getElementById('game_action_game_id');
  const gameLabelField = document.getElementById('game_action_game_label');
  const reasonBlock = document.getElementById('cancel_reason_block');
  const submitBtn = document.getElementById('game_action_submit');
  const reasonInput = document.getElementById('cancel_reason_modal');

  if (!modal) return;

  gameIdField.value = gameId;
  gameLabelField.textContent = gameLabel;

  if (type === 'cancel') {
    title.textContent = 'Cancel Game';
    desc.textContent = 'Enter a reason for cancelling this game.';
    actionField.value = 'cancel_game';
    reasonBlock.style.display = 'block';
    submitBtn.textContent = 'Confirm Cancel';
    submitBtn.classList.remove('btn-danger');

    if (reasonInput) {
      reasonInput.value = '';
    }
  } else {
    title.textContent = 'Delete Game';
    desc.textContent = 'This will permanently delete the game and all related data.';
    actionField.value = 'permanently_delete_game';
    reasonBlock.style.display = 'none';
    submitBtn.textContent = 'Delete Forever';
    submitBtn.classList.add('btn-danger');
  }

  modal.classList.remove('hide');
  modal.style.display = 'flex';
  document.body.classList.add('modal-open');
};

window.closeGameActionModal = function () {
  const modal = document.getElementById('gameActionModal');
  if (!modal) return;

  modal.classList.add('hide');
  modal.style.display = 'none';
  document.body.classList.remove('modal-open');
};

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('game-form');
  const battingList = document.getElementById('batting-order-list');
  const selectedInputs = document.getElementById('selected-player-inputs');

  function syncSelectedPlayerInputs() {
    if (!battingList || !selectedInputs) return;

    selectedInputs.innerHTML = '';

    battingList.querySelectorAll('.batting-order-item').forEach(function (item) {
      const checkbox = item.querySelector('.roster-player-check');

      if (!checkbox) return;

      item.classList.toggle('not-selected', !checkbox.checked);

      if (checkbox.checked) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'player_ids[]';
        input.value = checkbox.value;
        selectedInputs.appendChild(input);
      }
    });
    let orderNumber = 1;

    battingList.querySelectorAll('.batting-order-item').forEach(function (item) {
      const checkbox = item.querySelector('.roster-player-check');
      const numberEl = item.querySelector('.batting-order-number');

      if (!checkbox || !numberEl) return;

      if (checkbox.checked) {
        numberEl.textContent = orderNumber;
        orderNumber++;
      } else {
        numberEl.textContent = '—';
      }
    });
  }

  if (battingList) {
    let draggedItem = null;

    battingList.querySelectorAll('.batting-order-item').forEach(function (item) {
      item.addEventListener('dragstart', function () {
        draggedItem = item;
        item.classList.add('dragging');
      });

      item.addEventListener('dragend', function () {
        item.classList.remove('dragging');
        draggedItem = null;
        syncSelectedPlayerInputs();
      });
    });

    battingList.addEventListener('dragover', function (event) {
      event.preventDefault();

      const afterElement = getDragAfterElement(battingList, event.clientY);

      if (!draggedItem) return;

      if (afterElement === null) {
        battingList.appendChild(draggedItem);
      } else {
        battingList.insertBefore(draggedItem, afterElement);
      }

      syncSelectedPlayerInputs();
    });

    battingList.querySelectorAll('.roster-player-check').forEach(function (checkbox) {
      checkbox.addEventListener('change', syncSelectedPlayerInputs);
    });

    function getDragAfterElement(container, y) {
      const draggableElements = [...container.querySelectorAll('.batting-order-item:not(.dragging)')];

      return draggableElements.reduce(function (closest, child) {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
          return {
            offset: offset,
            element: child
          };
        }

        return closest;
      }, {
        offset: Number.NEGATIVE_INFINITY,
        element: null
      }).element;
    }

    syncSelectedPlayerInputs();
  }

  if (form) {
    form.addEventListener('submit', function () {
      syncSelectedPlayerInputs();
    });
  }

  const modal = document.getElementById('gameActionModal');
  if (modal) {
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeGameActionModal();
      }
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeGameActionModal();
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('batting-order-list');
  if (!el) return;
  new Sortable(el, {
    animation: 150,
    handle: '.drag-handle',
    ghostClass: 'dragging'
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
