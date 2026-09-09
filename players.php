<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Players';
$currentPage = 'players';
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';

$message = '';
$error = '';
$players = [];
$editingPlayer = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$allPositions = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];

$form = [
    'first_name' => '',
    'last_name' => '',
    'jersey_number' => '',
    'active' => 1,
    'pitching_role' => 'none',
    'catching_role' => 'none',
    'can_play' => [],
    'cannot_play' => [],
];
$playerStatCards = [];

function player_stat_card_label(array $player, array $statCards): string
{
    $playerId = (int)($player['id'] ?? 0);
    $stats = $statCards[$playerId] ?? null;

    if (!$stats) {
        return 'No stats yet';
    }

    return 'AVG ' . $stats['avg'] .
        ' | OBP ' . $stats['obp'] .
        ' | OPS ' . $stats['ops'];
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_player') {
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $jerseyNumber = trim((string)($_POST['jersey_number'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;
            $pitchingRole = strtolower(trim((string)($_POST['pitching_role'] ?? 'none')));
            $catchingRole = strtolower(trim((string)($_POST['catching_role'] ?? 'none')));

            $canPlay = array_values(array_unique(array_map('strtoupper', $_POST['can_play'] ?? [])));
            $cannotPlay = array_values(array_unique(array_map('strtoupper', $_POST['cannot_play'] ?? [])));
            $cannotPlay = array_values(array_diff($cannotPlay, $canPlay));

            if ($firstName === '') {
                throw new RuntimeException('Player first name is required.');
            }

            assert_team_limit_available(
                $teamId,
                'players_per_team',
                count_team_players_for_limit($teamId),
                'Your current plan has reached its player limit. Upgrade to add more players.'
            );

            create_player(
                $teamId,
                $firstName,
                $lastName,
                $jerseyNumber !== '' ? $jerseyNumber : null,
                $canPlay,
                $cannotPlay,
                $active,
                $pitchingRole,
                $catchingRole
            );
            $_SESSION['ga_events'][] = 'player_added';

            $stmt = db()->prepare("
                SELECT onboarding_player_modal_shown
                FROM teams
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'team_id' => $teamId,
            ]);

            $modalShown = (int)$stmt->fetchColumn();
            $playerCount = count_team_players_for_limit($teamId);

            if (!onboarding_dismissed($teamId)) {

                // First player added
                if ($playerCount === 1 && $modalShown === 0) {

                    set_next_step_modal(
                        'Player added',
                        'Add 8 more players before creating your first game.',
                        'Add Another Player',
                        'players.php'
                    );

                    db()->prepare("
                        UPDATE teams
                        SET onboarding_player_modal_shown = 1
                        WHERE id = :team_id
                        LIMIT 1
                    ")->execute([
                        'team_id' => $teamId,
                    ]);
                }

                // Ninth player added
                if ($playerCount === 9) {

                    set_next_step_modal(
                        'Ready for your first game',
                        'You now have enough players to create a game and build your first lineup.',
                        'Create Game',
                        'games.php'
                    );
                }
            }

            flash_redirect('ok', 'Player added successfully.', 'players.php');
            $form = [
                'first_name' => '',
                'last_name' => '',
                'jersey_number' => '',
                'active' => 1,
                'pitching_role' => 'none',
                'catching_role' => 'none',
                'can_play' => [],
                'cannot_play' => [],
            ];
        }

        if ($action === 'update_player') {
            $playerId = (int)($_POST['player_id'] ?? 0);
            if ($playerId <= 0) {
                throw new RuntimeException('Invalid player selected.');
            }

            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $jerseyNumber = trim((string)($_POST['jersey_number'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;
            $pitchingRole = strtolower(trim((string)($_POST['pitching_role'] ?? 'none')));
            $catchingRole = strtolower(trim((string)($_POST['catching_role'] ?? 'none')));

            $canPlay = array_values(array_unique(array_map('strtoupper', $_POST['can_play'] ?? [])));
            $cannotPlay = array_values(array_unique(array_map('strtoupper', $_POST['cannot_play'] ?? [])));
            $cannotPlay = array_values(array_diff($cannotPlay, $canPlay));

            if ($firstName === '') {
                throw new RuntimeException('Player first name is required.');
            }

            update_player(
                $teamId,
                $playerId,
                $firstName,
                $lastName,
                $jerseyNumber !== '' ? $jerseyNumber : null,
                $canPlay,
                $cannotPlay,
                $active,
                $pitchingRole,
                $catchingRole
            );
            flash_redirect('ok', 'Player updated successfully.', 'players.php');
            $editId = 0;
            $editingPlayer = null;
        }

        if ($action === 'delete_player') {
            $playerId = (int)($_POST['player_id'] ?? 0);
            if ($playerId <= 0) {
                throw new RuntimeException('Invalid player selected.');
            }

            delete_player($teamId, $playerId);
            flash_redirect('ok', 'Player deleted successfully.', 'players.php');

            if ($editId === $playerId) {
                $editId = 0;
                $editingPlayer = null;
            }
        }
    }

    if ($editId > 0) {
        $editingPlayer = get_player_by_id($teamId, $editId);

        if (!$editingPlayer) {
            throw new RuntimeException('Player not found for this team.');
        }

        $form = [
            'first_name' => (string)($editingPlayer['first_name'] ?? ''),
            'last_name' => (string)($editingPlayer['last_name'] ?? ''),
            'jersey_number' => (string)($editingPlayer['jersey_number'] ?? ''),
            'active' => (int)($editingPlayer['active'] ?? 1),
            'pitching_role' => (string)($editingPlayer['pitching_role'] ?? 'none'),
            'catching_role' => (string)($editingPlayer['catching_role'] ?? 'none'),
            'can_play' => $editingPlayer['can_play'] ?? [],
            'cannot_play' => $editingPlayer['cannot_play'] ?? [],
        ];
    }

    $players = get_all_players($teamId);
    $stmt = db()->prepare("
        SELECT
            player_id,
            COALESCE(SUM(at_bats), 0) AS at_bats,
            COALESCE(SUM(hits), 0) AS hits,
            COALESCE(SUM(doubles_hit), 0) AS doubles_hit,
            COALESCE(SUM(triples_hit), 0) AS triples_hit,
            COALESCE(SUM(home_runs), 0) AS home_runs,
            COALESCE(SUM(walks), 0) AS walks,
            COALESCE(SUM(hit_by_pitch), 0) AS hit_by_pitch,
            COALESCE(SUM(sacrifice_flies), 0) AS sacrifice_flies
        FROM player_batting_stats
        WHERE team_id = :team_id
        GROUP BY player_id
    ");

    $stmt->execute(['team_id' => $teamId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playerId = (int)$row['player_id'];

        $atBats = (int)$row['at_bats'];
        $hits = (int)$row['hits'];
        $doubles = (int)$row['doubles_hit'];
        $triples = (int)$row['triples_hit'];
        $homeRuns = (int)$row['home_runs'];
        $walks = (int)$row['walks'];
        $hbp = (int)$row['hit_by_pitch'];
        $sf = (int)$row['sacrifice_flies'];

        $avg = calculate_batting_average($hits, $atBats);
        $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
        $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
        $ops = calculate_ops($obp, $slg);

        $playerStatCards[$playerId] = [
            'avg' => format_baseball_rate($avg),
            'obp' => format_baseball_rate($obp),
            'slg' => format_baseball_rate($slg),
            'ops' => format_baseball_rate($ops),
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();

    if ($editId > 0) {
        $editingPlayer = get_player_by_id($teamId, $editId);
        if ($editingPlayer) {
            $form = [
                'first_name' => (string)($editingPlayer['first_name'] ?? ''),
                'last_name' => (string)($editingPlayer['last_name'] ?? ''),
                'jersey_number' => (string)($editingPlayer['jersey_number'] ?? ''),
                'active' => (int)($editingPlayer['active'] ?? 1),
                'pitching_role' => (string)($editingPlayer['pitching_role'] ?? 'none'),
                'catching_role' => (string)($editingPlayer['catching_role'] ?? 'none'),
                'can_play' => $editingPlayer['can_play'] ?? [],
                'cannot_play' => $editingPlayer['cannot_play'] ?? [],
            ];
        }
    } else {
        $form = [
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
            'jersey_number' => trim((string)($_POST['jersey_number'] ?? '')),
            'active' => isset($_POST['active']) ? 1 : 0,
            'pitching_role' => strtolower(trim((string)($_POST['pitching_role'] ?? 'none'))),
            'catching_role' => strtolower(trim((string)($_POST['catching_role'] ?? 'none'))),
            'can_play' => array_values(array_unique(array_map('strtoupper', $_POST['can_play'] ?? []))),
            'cannot_play' => array_values(array_unique(array_map('strtoupper', $_POST['cannot_play'] ?? []))),
        ];
        $form['cannot_play'] = array_values(array_diff($form['cannot_play'], $form['can_play']));
    }

    if ($teamId > 0) {
        $players = get_all_players($teamId);
        $stmt = db()->prepare("
            SELECT
                player_id,
                COALESCE(SUM(at_bats), 0) AS at_bats,
                COALESCE(SUM(hits), 0) AS hits,
                COALESCE(SUM(doubles_hit), 0) AS doubles_hit,
                COALESCE(SUM(triples_hit), 0) AS triples_hit,
                COALESCE(SUM(home_runs), 0) AS home_runs,
                COALESCE(SUM(walks), 0) AS walks,
                COALESCE(SUM(hit_by_pitch), 0) AS hit_by_pitch,
                COALESCE(SUM(sacrifice_flies), 0) AS sacrifice_flies,
                COALESCE(SUM(rbi), 0) AS rbi,
                COALESCE(SUM(strikeouts), 0) AS strikeouts
            FROM player_batting_stats
            WHERE team_id = :team_id
            GROUP BY player_id
        ");

        $stmt->execute([
            'team_id' => $teamId,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $playerId = (int)$row['player_id'];

            $atBats = (int)$row['at_bats'];
            $hits = (int)$row['hits'];
            $doubles = (int)$row['doubles_hit'];
            $triples = (int)$row['triples_hit'];
            $homeRuns = (int)$row['home_runs'];
            $walks = (int)$row['walks'];
            $hbp = (int)$row['hit_by_pitch'];
            $sf = (int)$row['sacrifice_flies'];

            $avg = calculate_batting_average($hits, $atBats);
            $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
            $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
            $ops = calculate_ops($obp, $slg);

            $playerStatCards[$playerId] = [
                'avg' => format_baseball_rate($avg),
                'obp' => format_baseball_rate($obp),
                'slg' => format_baseball_rate($slg),
                'ops' => format_baseball_rate($ops),
                'rbi' => (int)$row['rbi'],
                'strikeouts' => (int)$row['strikeouts'],
            ];
        }

    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Players</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="players-layout">
  <div class="card">
    <h2><?= $editingPlayer ? 'Edit Player' : 'Add Player' ?></h2>

    <form method="post" action="players.php<?= $editingPlayer ? '?edit=' . (int)$editingPlayer['id'] : '' ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editingPlayer ? 'update_player' : 'create_player' ?>">
      <?php if ($editingPlayer): ?>
        <input type="hidden" name="player_id" value="<?= (int)$editingPlayer['id'] ?>">
      <?php endif; ?>

      <div class="player-form-grid">
        <div class="form-field">
          <label for="first_name">First Name</label>
          <input
            type="text"
            id="first_name"
            name="first_name"
            value="<?= h((string)$form['first_name']) ?>"
            required
          >
        </div>

        <div class="form-field">
          <label for="last_name">Last Name</label>
          <input
            type="text"
            id="last_name"
            name="last_name"
            value="<?= h((string)$form['last_name']) ?>"
          >
        </div>

        <div class="form-field">
          <label for="jersey_number">Jersey Number</label>
          <input
            type="text"
            id="jersey_number"
            name="jersey_number"
            value="<?= h((string)$form['jersey_number']) ?>"
          >
        </div>

        <div class="form-field toggle-field">
          <label class="toggle-row" for="active_toggle">
            <span class="toggle-label-text">Active Player</span>
            <span class="toggle-switch">
              <input
                type="checkbox"
                id="active_toggle"
                name="active"
                value="1"
                <?= !empty($form['active']) ? 'checked' : '' ?>
              >
              <span class="toggle-slider"></span>
            </span>
          </label>
        </div>

        <div class="form-field">
          <label for="pitching_role">Pitching Role</label>
          <select id="pitching_role" name="pitching_role">
            <option value="none" <?= $form['pitching_role'] === 'none' ? 'selected' : '' ?>>Does Not Pitch</option>
            <option value="emergency" <?= $form['pitching_role'] === 'emergency' ? 'selected' : '' ?>>Emergency Pitcher</option>
            <option value="primary" <?= $form['pitching_role'] === 'primary' ? 'selected' : '' ?>>Primary Pitcher</option>
          </select>
        </div>

        <div class="form-field">
          <label for="catching_role">Catching Role</label>
          <select id="catching_role" name="catching_role">
            <option value="none" <?= $form['catching_role'] === 'none' ? 'selected' : '' ?>>Does Not Catch</option>
            <option value="emergency" <?= $form['catching_role'] === 'emergency' ? 'selected' : '' ?>>Emergency Catcher</option>
            <option value="primary" <?= $form['catching_role'] === 'primary' ? 'selected' : '' ?>>Primary Catcher</option>
          </select>
        </div>

        <div class="form-field span-2">
          <p class="muted compact-help">
            Primary pitchers/catchers are used first. Emergency pitchers/catchers are only used if no eligible primary players are available.
          </p>
        </div>
      </div>

      <div class="position-pill-group" style="margin-top:18px;">
        <div class="position-pill-section">
          <div class="position-pill-label">Can Play</div>
          <div class="position-pill-row">
            <?php foreach ($allPositions as $pos): ?>
              <label class="position-pill can-play-pill">
                <input
                  type="checkbox"
                  name="can_play[]"
                  value="<?= h($pos) ?>"
                  <?= in_array($pos, $form['can_play'], true) ? 'checked' : '' ?>
                >
                <span><?= h($pos) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="position-pill-section">
          <div class="position-pill-label">Cannot Play</div>
          <div class="position-pill-row">
            <?php foreach ($allPositions as $pos): ?>
              <label class="position-pill cannot-play-pill">
                <input
                  type="checkbox"
                  name="cannot_play[]"
                  value="<?= h($pos) ?>"
                  <?= in_array($pos, $form['cannot_play'], true) ? 'checked' : '' ?>
                >
                <span><?= h($pos) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <p class="muted" style="margin-top:12px;">
        <em>**If a position is selected in both, Can Play will win automatically.</em>
      </p>

      <div class="stack-actions" style="margin-top:18px;">
        <button type="submit"><?= $editingPlayer ? 'Save Player' : 'Add Player' ?></button>

        <?php if ($editingPlayer): ?>
          <a class="btn btn-secondary" href="players.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>All Players</h2>
    <div class="players-list">

    <div class="mobile-cards">
      <?php if (empty($players)): ?>
        <p class="muted">No players added yet.</p>
      <?php else: ?>
        <?php foreach ($players as $player): ?>
          <div class="mobile-card">
              <div class="mobile-card-title">
                <?= h(format_player_label($player, $labelMode)) ?>
              </div>

              <?php if (team_stats_enabled($teamId)): ?>

              <div class="player-stat-card">
                <?= h(player_stat_card_label($player, $playerStatCards)) ?>
              </div>
              <?php endif; ?>


              <div class="mobile-card-row">
                    <span class="mobile-card-label">Jersey</span>
                    #<?= h((string)($player['jersey_number'] ?? '')) ?>
                  </div>

                  <div class="mobile-card-row">
                    <span class="mobile-card-label">Status</span>
                    <span class="pill <?= !empty($player['active']) ? 'generated' : 'default' ?>">
                      <?= !empty($player['active']) ? 'Active' : 'Inactive' ?>
                    </span>
                  </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Pitching</span>
              <?= h(ucfirst((string)($player['pitching_role'] ?? 'none'))) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Catching</span>
              <?= h(ucfirst((string)($player['catching_role'] ?? 'none'))) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Can Play</span>
              <?= h(implode(', ', $player['can_play'] ?? [])) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Cannot Play</span>
              <?= h(implode(', ', $player['cannot_play'] ?? [])) ?>
            </div>

            <div class="stack-actions" style="margin-top:12px;">
              <a class="btn btn-secondary" href="players.php?edit=<?= (int)$player['id'] ?>">Edit</a>
              <a class="btn btn-secondary" href="player_profile.php?player_id=<?= (int)$player['id'] ?>">Profile</a>

              <form method="post" action="players.php" onsubmit="return confirm('Delete this player?');">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_player">
                <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                <button type="submit" class="btn btn-secondary">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    </div>
  </div>
</div>

<style>
.players-layout {
  display: grid;
  gap: 20px;
}

.player-form-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  align-items: start;
}

.player-form-grid .form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.player-form-grid .span-2 {
  grid-column: span 2;
}

.player-form-grid .span-4 {
  grid-column: span 4;
}

.toggle-field {
  justify-content: end;
}

.compact-help {
  margin: 0;
}

.position-pill-group {
  display: grid;
  gap: 16px;
}

.position-pill-section {
  display: grid;
  gap: 8px;
}

.position-pill-label {
  font-size: 14px;
  font-weight: 700;
  color: #374151;
}

.position-pill-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.position-pill {
  position: relative;
  display: inline-block;
  cursor: pointer;
}

.position-pill input[type="checkbox"] {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.position-pill span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 56px;
  padding: 10px 14px;
  border-radius: 999px;
  border: 1px solid #d1d5db;
  background: #ffffff;
  color: #374151;
  font-size: 14px;
  font-weight: 600;
  line-height: 1;
  transition: all 0.2s ease;
  user-select: none;
}

.position-pill:hover span {
  border-color: #9ca3af;
}

.can-play-pill input[type="checkbox"]:checked + span {
  background: #ecfdf5;
  border-color: #10b981;
  color: #065f46;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.12);
}

.cannot-play-pill input[type="checkbox"]:checked + span {
  background: #fef2f2;
  border-color: #ef4444;
  color: #991b1b;
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.12);
}

@media (max-width: 900px) {
  .player-form-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .player-form-grid .span-2,
  .player-form-grid .span-4 {
    grid-column: span 2;
  }

  .toggle-field {
    justify-content: start;
  }
}

@media (max-width: 560px) {
  .player-form-grid {
    grid-template-columns: 1fr;
  }

  .player-form-grid .span-2,
  .player-form-grid .span-4 {
    grid-column: span 1;
  }
}
@media (min-width: 769px) {
  .players-list .mobile-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
