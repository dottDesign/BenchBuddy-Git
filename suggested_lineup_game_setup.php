<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Create Game From Suggested Lineup';
$currentPage = 'games';

$teamId = current_team_id();
$error = '';
$playerIds = $_POST['player_ids'] ?? $_GET['player_ids'] ?? [];

if (!is_array($playerIds)) {
    $playerIds = [];
}

$playerIds = array_values(array_unique(array_filter(array_map('intval', $playerIds))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_game') {
    verify_csrf_token();
    try {
        if ($teamId <= 0) {
            throw new RuntimeException('No team selected.');
        }

        $gameName = trim((string)($_POST['game_id'] ?? ''));
        $gameDate = trim((string)($_POST['game_date'] ?? ''));
        $homeAway = trim((string)($_POST['home_away'] ?? ''));
        $innings = (int)($_POST['innings'] ?? 7);

        $playerIds = $_POST['player_ids'] ?? [];

        if (!is_array($playerIds)) {
            throw new RuntimeException('Invalid suggested lineup.');
        }

        $playerIds = array_values(array_unique(array_filter(array_map('intval', $playerIds))));

        if ($gameName === '') {
            throw new RuntimeException('Game name is required.');
        }

        if ($innings < 1 || $innings > 9) {
            throw new RuntimeException('Innings must be between 1 and 9.');
        }

        if (!in_array($homeAway, ['home', 'away'], true)) {
            throw new RuntimeException('Please choose home or away.');
        }

        if (count($playerIds) < 8) {
            throw new RuntimeException('A game needs at least 8 players.');
        }

        if (count($playerIds) > 14) {
            $playerIds = array_slice($playerIds, 0, 14);
        }

        foreach ($playerIds as $playerId) {
            $player = get_player_by_id($teamId, $playerId);

            if (!$player || (int)($player['active'] ?? 0) !== 1) {
                throw new RuntimeException('One or more players are no longer active.');
            }
        }

        $gameDbId = create_game(
            $teamId,
            $gameName,
            $innings,
            $gameDate !== '' ? $gameDate : null
        );

        if (function_exists('update_game_home_away')) {
            update_game_home_away($teamId, $gameDbId, $homeAway);
        } else {
            $stmt = db()->prepare("
                UPDATE games
                SET home_away = :home_away
                WHERE id = :game_id
                  AND team_id = :team_id
            ");

            $stmt->execute([
                'home_away' => $homeAway,
                'game_id' => $gameDbId,
                'team_id' => $teamId,
            ]);
        }

        save_game_roster($teamId, $gameDbId, $playerIds);

        header('Location: generate.php?game_id=' . (int)$gameDbId);
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$players = [];

if ($teamId > 0 && !empty($playerIds)) {
    foreach ($playerIds as $playerId) {
        $player = get_player_by_id($teamId, $playerId);

        if ($player && (int)($player['active'] ?? 0) === 1) {
            $players[] = $player;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Create Game From Suggested Lineup</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:18px;">
  <h2>Game Setup</h2>
  <p class="muted">
    Review the suggested batting order, then set the game details before creating the game.
  </p>

  <?php if (count($players) < 8): ?>
    <div class="msg err">
      This suggested lineup has <?= count($players) ?> active player<?= count($players) === 1 ? '' : 's' ?>. A game needs at least 8.
    </div>

    <div class="actions-row">
      <a class="btn btn-secondary" href="player_stats.php">Back to Player Stats</a>
    </div>
  <?php else: ?>

    <form method="post" action="suggested_lineup_game_setup.php">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_game">

      <?php foreach ($players as $player): ?>
        <input
          type="hidden"
          name="player_ids[]"
          value="<?= (int)$player['id'] ?>"
        >
      <?php endforeach; ?>

      <label for="game_id">Game Name</label>
      <input
        type="text"
        id="game_id"
        name="game_id"
        value="Suggested Lineup - <?= h(date('M j, Y')) ?>"
        required
      >

      <label for="game_date">Game Date</label>
      <input
        type="date"
        id="game_date"
        name="game_date"
        value="<?= h(date('Y-m-d')) ?>"
      >

      <label for="home_away">Home/Away</label>
      <select id="home_away" name="home_away" required>
        <option value="">-- Select --</option>
        <option value="home">Home</option>
        <option value="away">Away</option>
      </select>

      <label for="innings">Innings</label>
      <input
        type="number"
        id="innings"
        name="innings"
        min="1"
        max="9"
        value="7"
        required
      >

      <div class="card" style="margin-top:18px;">
        <h2>Suggested Batting Order</h2>

        <ul id="suggested-batting-order" class="batting-order-list">
          <?php foreach ($players as $player): ?>
            <li class="batting-order-item" draggable="true">
              <span class="drag-handle">☰</span>

              <span>
                <?= h(format_player_label($player, function_exists('player_label_mode') ? player_label_mode() : 'both')) ?>
              </span>

              <input
                type="hidden"
                name="player_ids[]"
                value="<?= (int)$player['id'] ?>"
              >
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit" class="btn">
          Create Game & Build Lineup
        </button>

        <a class="btn btn-secondary" href="player_stats.php">
          Cancel
        </a>
      </div>
    </form>

  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const list = document.getElementById('suggested-batting-order');

  if (!list) {
    return;
  }

  let draggedItem = null;

  list.querySelectorAll('.batting-order-item').forEach(function (item) {
    item.addEventListener('dragstart', function () {
      draggedItem = item;
      item.classList.add('dragging');
    });

    item.addEventListener('dragend', function () {
      item.classList.remove('dragging');
      draggedItem = null;
    });
  });

  list.addEventListener('dragover', function (event) {
    event.preventDefault();

    const afterElement = getDragAfterElement(list, event.clientY);

    if (!draggedItem) {
      return;
    }

    if (afterElement === null) {
      list.appendChild(draggedItem);
    } else {
      list.insertBefore(draggedItem, afterElement);
    }
  });

  function getDragAfterElement(container, y) {
    const draggableElements = [
      ...container.querySelectorAll('.batting-order-item:not(.dragging)')
    ];

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
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
