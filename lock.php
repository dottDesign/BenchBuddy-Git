<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Finalize Game';
$currentPage = 'lock';
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';
$message = '';
$error = '';
$games = [];
$game = null;
$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$pitchLog = [];
$archiveRow = null;
$gameRoster = [];

function merge_extra_pitchers_into_pitch_data(array &$pitchCounts, array &$inningsPitched, array &$didNotPitch, array $extraPitchers): void
{
    foreach ($extraPitchers as $extra) {
        if (!is_array($extra)) {
            continue;
        }

        $playerId = (int)($extra['player_id'] ?? 0);
        $inningsValue = round(max(0, (float)($extra['innings_pitched'] ?? 0)), 1);
        $pitchesValue = max(0, (int)($extra['pitches_thrown'] ?? 0));

        if ($playerId <= 0) {
            continue;
        }

        if ($inningsValue === 0 && $pitchesValue === 0) {
            continue;
        }

        $inningsPitched[$playerId] = $inningsValue;
        $pitchCounts[$playerId] = $pitchesValue;
        unset($didNotPitch[$playerId]);
    }
}

function build_pitch_log_fallback_from_lineup(int $teamId, int $gameDbId): array
{
    $inningsMap = calculate_pitch_log_from_lineup($teamId, $gameDbId);
    $roster = get_game_roster($teamId, $gameDbId);
    $rosterMap = [];
    $pitchLog = [];

    foreach ($roster as $player) {
        $rosterMap[(int)$player['id']] = $player;
    }

    foreach ($inningsMap as $playerId => $inningsPitched) {
        if (!isset($rosterMap[$playerId])) {
            continue;
        }

        $pitchLog[] = [
            'player_id' => (int)$playerId,
            'innings_pitched' => round((float)$inningsPitched, 1),
            'pitches_thrown' => 0,
            'name' => player_full_name($rosterMap[$playerId]),
            'first_name' => (string)($rosterMap[$playerId]['first_name'] ?? ''),
            'last_name' => (string)($rosterMap[$playerId]['last_name'] ?? ''),
            'jersey_number' => (string)($rosterMap[$playerId]['jersey_number'] ?? ''),
        ];
    }

    return $pitchLog;
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    $games = get_all_games($teamId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');
        $selectedGameId = (int)($_POST['game_db_id'] ?? 0);
        if ($action === '') {
            throw new RuntimeException('No lock action was submitted.');
        }
        if ($selectedGameId <= 0) {
            throw new RuntimeException('Please select a game.');
        }
        if ($action === 'update_final_game_details') {
            $gameId = $selectedGameId;

            $game = get_game_by_id($teamId, $gameId);

            if (!$game) {
                throw new RuntimeException('Game not found.');
            }

            if ((string)($game['status'] ?? '') !== 'locked') {
                throw new RuntimeException('Only finalized games can be updated here.');
            }

            $actualInningsPlayedRaw = trim((string)($_POST['actual_innings_played'] ?? ''));
            $actualInningsPlayed = $actualInningsPlayedRaw !== ''
                ? (float)$actualInningsPlayedRaw
                : null;

            if ($actualInningsPlayed !== null && ($actualInningsPlayed < 0 || $actualInningsPlayed > 20)) {
                throw new RuntimeException('Actual innings played must be between 0 and 20.');
            }

            $homeAway = (string)($_POST['home_away'] ?? '');

            if (!in_array($homeAway, ['', 'home', 'away'], true)) {
                throw new RuntimeException('Invalid home/away value.');
            }

            $stmt = db()->prepare("
                UPDATE games
                SET
                    actual_innings_played = :actual_innings_played,
                    home_away = :home_away
                WHERE id = :game_id
                  AND team_id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'actual_innings_played' => $actualInningsPlayed,
                'home_away' => $homeAway !== '' ? $homeAway : null,
                'game_id' => $gameId,
                'team_id' => $teamId,
            ]);

            flash_redirect('ok', 'Final game details updated.', 'lock.php?game_id=' . $gameId);
        }
        $game = get_game_by_id($teamId, $selectedGameId);
        if (!$game) {
            throw new RuntimeException('Game not found for this team.');
        }

        if ($action === 'lock_game') {
            if (($game['status'] ?? '') === 'locked') {
                throw new RuntimeException('This game is already locked.');
            }

            if (($game['status'] ?? '') !== 'generated') {
                throw new RuntimeException('Only built lineups can be locked.');
            }

            lock_generated_lineup($teamId, $selectedGameId);
            $_SESSION['ga_events'][] = 'game_locked';
            $completedGames = get_completed_game_count($teamId);

            $milestoneMap = [
                10 => 'milestone_10_sent_at',
                25 => 'milestone_25_sent_at',
                50 => 'milestone_50_sent_at',
            ];

            if (isset($milestoneMap[$completedGames])) {
                $sentColumn = $milestoneMap[$completedGames];

                $teamStmt = db()->prepare("
                    SELECT
                        t.name,
                        t.{$sentColumn},
                        u.full_name,
                        u.email
                    FROM teams t
                    INNER JOIN team_memberships tm
                        ON tm.team_id = t.id
                    INNER JOIN users u
                        ON u.id = tm.user_id
                    WHERE t.id = :team_id
                      AND tm.role = 'head_coach'
                    LIMIT 1
                ");

                $teamStmt->execute([
                    'team_id' => $teamId,
                ]);

                $coach = $teamStmt->fetch(PDO::FETCH_ASSOC);

                if ($coach && empty($coach[$sentColumn])) {
                    try {
                        send_games_tracked_milestone_email(
                            (string)$coach['email'],
                            (string)$coach['full_name'],
                            (string)$coach['name'],
                            $completedGames
                        );

                        db()->prepare("
                            UPDATE teams
                            SET {$sentColumn} = NOW()
                            WHERE id = ?
                        ")->execute([$teamId]);

                    } catch (Throwable $e) {
                        error_log('Game milestone email failed: ' . $e->getMessage());
                    }
                }
            }
            $completedGames = get_completed_game_count($teamId);

            if ($completedGames === 1) {

                $teamStmt = db()->prepare("
                    SELECT
                        t.name,
                        t.milestone_1_sent_at,
                        u.full_name,
                        u.email
                    FROM teams t
                    INNER JOIN team_memberships tm
                        ON tm.team_id = t.id
                    INNER JOIN users u
                        ON u.id = tm.user_id
                    WHERE t.id = :team_id
                      AND tm.role = 'head_coach'
                    LIMIT 1
                ");

                $teamStmt->execute([
                    'team_id' => $teamId,
                ]);

                $coach = $teamStmt->fetch(PDO::FETCH_ASSOC);

                if (
                    $coach &&
                    empty($coach['milestone_1_sent_at'])
                ) {
                    try {
                        send_first_game_ready_email(
                            (string)$coach['email'],
                            (string)$coach['full_name'],
                            (string)$coach['name']
                        );

                        db()->prepare("
                            UPDATE teams
                            SET milestone_1_sent_at = NOW()
                            WHERE id = ?
                        ")->execute([$teamId]);

                    } catch (Throwable $e) {
                        error_log(
                            'First game milestone email failed: ' .
                            $e->getMessage()
                        );
                    }
                }
            }
            flash_redirect(
                'ok',
                'Lineup locked successfully. You can now print it.',
                'lock.php?game_id=' . $selectedGameId
            );
        }

        if ($action === 'save_pitch_counts') {
            $pitchCounts = isset($_POST['pitch_counts']) && is_array($_POST['pitch_counts'])
                ? $_POST['pitch_counts']
                : [];

            $inningsPitched = isset($_POST['innings_pitched']) && is_array($_POST['innings_pitched'])
                ? $_POST['innings_pitched']
                : [];

            $didNotPitch = isset($_POST['did_not_pitch']) && is_array($_POST['did_not_pitch'])
                ? $_POST['did_not_pitch']
                : [];

            $extraPitchers = isset($_POST['extra_pitchers']) && is_array($_POST['extra_pitchers'])
                ? $_POST['extra_pitchers']
                : [];

            merge_extra_pitchers_into_pitch_data($pitchCounts, $inningsPitched, $didNotPitch, $extraPitchers);

            save_pitch_counts_for_game(
                $teamId,
                $selectedGameId,
                $pitchCounts,
                $inningsPitched,
                $didNotPitch
            );

            flash_redirect(
                'ok',
                'Pitch counts updated successfully.',
                'lock.php?game_id=' . $selectedGameId
            );

        } elseif ($action === 'reopen_game') {
            if (($game['status'] ?? '') !== 'locked') {
                throw new RuntimeException('Only finalized games can be reopened.');
            }

            $pdo = db();
            $stmt = $pdo->prepare("
                UPDATE games
                SET status = 'generated',
                    locked_at = NULL
                WHERE id = :id
                  AND team_id = :team_id
            ");
            $stmt->execute([
                'id' => $selectedGameId,
                'team_id' => $teamId,
            ]);

            $message = 'Game reopened successfully. You can now update the lineup and lock it again.';
        }
    }

    if ($selectedGameId > 0) {
        $game = get_game_by_id($teamId, $selectedGameId);

        if ($game) {
            $pitchLog = get_pitch_log_for_game($teamId, $selectedGameId);

            if (empty($pitchLog) && ($game['status'] ?? '') === 'generated') {
                $pitchLog = build_pitch_log_fallback_from_lineup($teamId, $selectedGameId);
            }

            $archiveRow = get_archive_record_for_game($teamId, $selectedGameId);
            $gameRoster = get_game_roster($teamId, $selectedGameId);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine();

    $games = get_all_games($teamId);

    if ($selectedGameId > 0) {
        $game = get_game_by_id($teamId, $selectedGameId);

        if ($game) {
            $pitchLog = get_pitch_log_for_game($teamId, $selectedGameId);

            if (empty($pitchLog) && ($game['status'] ?? '') === 'generated') {
                $pitchLog = build_pitch_log_fallback_from_lineup($teamId, $selectedGameId);
            }

            $archiveRow = get_archive_record_for_game($teamId, $selectedGameId);
            $gameRoster = get_game_roster($teamId, $selectedGameId);
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Lock Game</h1>

<div class="workflow">
  <div class="workflow-step">1 Set Roster</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step">2 Build Lineup</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step active">3 Lock Lineup</div>
</div>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="lock-grid">
  <div class="card">
    <h2>Select Game</h2>

    <form method="get" action="lock.php">
        <?= csrf_field() ?>
      <label for="game_id">Game</label>
      <select name="game_id" id="game_id" required>
        <option value="">-- Select a Game --</option>
        <?php foreach ($games as $g): ?>
          <option
            value="<?= (int)$g['id'] ?>"
            <?= $selectedGameId === (int)$g['id'] ? 'selected' : '' ?>
          >
            <?= h((string)$g['game_id']) ?> | Status: <?= h((string)$g['status']) ?> | Roster: <?= (int)$g['roster_size'] ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="actions-row">
        <button type="submit">Load Game</button>
      </div>
    </form>
  </div>

  <?php if ($game): ?>
    <div class="card">
      <h2>Game Details</h2>

      <div class="meta">
        <div class="meta-box">
          <div class="meta-label">Game ID</div>
          <div class="meta-value"><?= h((string)$game['game_id']) ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Status</div>
          <div class="meta-value"><?= h((string)$game['status']) ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Innings</div>
          <div class="meta-value"><?= (int)$game['innings'] ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Roster Size</div>
          <div class="meta-value"><?= (int)$game['roster_size'] ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Bench Count</div>
          <div class="meta-value"><?= (int)$game['bench_count'] ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Locked At</div>
          <div class="meta-value">
            <?= ($game['locked_at'] ?? '') !== '' ? h((string)$game['locked_at']) : 'Not Locked' ?>
          </div>
        </div>
      </div>



      <div class="actions-row" style="margin-top:18px; flex-wrap:wrap;">

          <?php if (($game['status'] ?? '') != 'locked'): ?>

        <a class="btn btn-secondary" href="generate.php?game_id=<?= (int)$game['id'] ?>">
          Build Lineup
        </a>
        <?php endif; ?>

        <?php if (($game['status'] ?? '') === 'generated'): ?>
        <button
          type="submit"
          form="pitch-log-form"
          class="btn"
          onclick="document.getElementById('pitch-log-action').value='lock_game'; return confirm('Lock this lineup for printing?');"
        >
          Lock Lineup for Print
        </button>

        <button
          type="submit"
          form="pitch-log-form"
          class="btn btn-secondary"
          onclick="document.getElementById('pitch-log-action').value='save_pitch_counts';"
        >
          Save Pitch Counts
        </button>
        <?php elseif (($game['status'] ?? '') === 'locked'): ?>
          <a class="btn btn-secondary" href="print_lineup.php?game_id=<?= (int)$game['id'] ?>" target="_blank">
            Print Lineup
          </a>

          <button
            type="submit"
            form="pitch-log-form"
            class="btn btn-secondary"
            onclick="document.getElementById('pitch-log-action').value='save_pitch_counts';"
          >
            Save Pitch Counts
          </button>

          <form method="post" action="lock.php" style="display:inline;">
              <?= csrf_field() ?>
            <input type="hidden" name="action" value="reopen_game">
            <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">
            <button
              type="submit"
              class="btn"
              onclick="return confirm('Reopen this game for editing?');"
            >
              Reopen Game
            </button>
          </form>
        <?php endif; ?>


      </div>
      <?php if ($game && (string)($game['status'] ?? '') === 'locked'): ?>
        <div class="card" style="margin-bottom:16px;">
          <h2>Final Game Details</h2>

          <p class="muted">
            Update the actual innings played and home/away status for accurate position usage and bench tracking.
          </p>

          <form method="post" action="lock.php?game_id=<?= (int)$game['id'] ?>">
              <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_final_game_details">
            <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">

            <div class="game-meta-grid">
              <div class="form-field">
                <label for="actual_innings_played">Actual Innings Played</label>
                <input
                  type="number"
                  id="actual_innings_played"
                  name="actual_innings_played"
                  min="0"
                  max="20"
                  step="0.5"
                  value="<?= h((string)($game['actual_innings_played'] ?? '')) ?>"
                  placeholder="<?= h((string)($game['innings'] ?? 7)) ?>"
                >
              </div>

              <div class="form-field">
                <label for="home_away">Home / Away</label>
                <select id="home_away" name="home_away">
                  <option value="">-- Select --</option>
                  <option value="home" <?= (string)($game['home_away'] ?? '') === 'home' ? 'selected' : '' ?>>Home</option>
                  <option value="away" <?= (string)($game['home_away'] ?? '') === 'away' ? 'selected' : '' ?>>Away</option>
                </select>
              </div>
            </div>

            <div class="actions-row" style="margin-top:14px;">
              <button type="submit">Update Game Details</button>
            </div>
          </form>
        </div>
      <?php endif; ?>
      <?php if (($game['status'] ?? '') === 'locked'): ?>
        <p style="margin-top:18px;"><strong>This game is already locked.</strong></p>
      <?php elseif (($game['status'] ?? '') !== 'generated'): ?>
        <p style="margin-top:18px;">
          This game must be built before it can be locked.
          <a href="generate.php?game_id=<?= (int)$game['id'] ?>">Build it first</a>.
        </p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Pitch Log</h2>

      <form method="post" action="lock.php" id="pitch-log-form">
          <?= csrf_field() ?>
        <input type="hidden" name="game_db_id" value="<?= (int)$game['id'] ?>">
        <input type="hidden" name="action" id="pitch-log-action" value="">

        <div class="table-wrap desktop-table">
          <table>
            <thead>
              <tr>
                <th>Player</th>
                <th>Actual Innings</th>
                <th>Pitches Thrown</th>
                <th>Did Not Pitch</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pitchLog)): ?>
                <tr>
                  <td colspan="4">No pitch log recorded yet. Add a pitcher below.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($pitchLog as $row): ?>
                  <?php $playerId = (int)$row['player_id']; ?>
                  <tr>
                    <td>
                        <?= h(format_player_label($row, $labelMode)) ?>
                    </td>
                    <td style="width:180px;">
                        <input
                          type="number"
                          name="innings_pitched[<?= $playerId ?>]"
                          min="0"
                          max="<?= h(number_format((float)$game['innings'], 1, '.', '')) ?>"
                          step="0.1"
                          inputmode="decimal"
                          value="<?= h(number_format((float)$row['innings_pitched'], 1, '.', '')) ?>"
                          style="max-width:140px;"
                          class="actual-innings-input"
                          data-player-id="<?= $playerId ?>"
                        >
                    </td>
                    <td style="width:180px;">
                      <input
                        type="number"
                        name="pitch_counts[<?= $playerId ?>]"
                        min="0"
                        value="<?= (int)($row['pitches_thrown'] ?? 0) ?>"
                        style="max-width:140px;"
                        class="pitch-count-input"
                        data-player-id="<?= $playerId ?>"
                      >
                    </td>
                    <td style="width:160px;">
                      <label class="toggle-row">
                        <span class="toggle-switch">
                          <input
                            type="checkbox"
                            name="did_not_pitch[<?= $playerId ?>]"
                            value="1"
                            class="did-not-pitch-toggle"
                            data-player-id="<?= $playerId ?>"
                          >
                          <span class="toggle-slider"></span>
                        </span>
                      </label>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="mobile-cards">
          <?php if (empty($pitchLog)): ?>
            <p class="muted">No pitch log recorded yet. Add a pitcher below.</p>
          <?php else: ?>
            <?php foreach ($pitchLog as $row): ?>
              <?php $playerId = (int)$row['player_id']; ?>
              <div class="mobile-card">
                <div class="mobile-card-title">
                    <?= h(format_player_label($row, $labelMode)) ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Actual Innings</span>
                  <input
                      type="number"
                      name="innings_pitched[<?= $playerId ?>]"
                      min="0"
                      max="<?= h(number_format((float)$game['innings'], 1, '.', '')) ?>"
                      step="0.1"
                      inputmode="decimal"
                      value="<?= h(number_format((float)$row['innings_pitched'], 1, '.', '')) ?>"
                      style="max-width:140px;"
                      class="actual-innings-input"
                      data-player-id="<?= $playerId ?>"
                  >
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Pitches</span>
                  <input
                    type="number"
                    name="pitch_counts[<?= $playerId ?>]"
                    min="0"
                    value="<?= (int)($row['pitches_thrown'] ?? 0) ?>"
                    style="max-width:140px;"
                    class="pitch-count-input"
                    data-player-id="<?= $playerId ?>"
                  >
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Did not pitch</span>
                  <label class="toggle-row">
                    <span class="toggle-switch">
                      <input
                        type="checkbox"
                        name="did_not_pitch[<?= $playerId ?>]"
                        value="1"
                        class="did-not-pitch-toggle"
                        data-player-id="<?= $playerId ?>"
                      >
                      <span class="toggle-slider"></span>
                    </span>
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="card" style="margin-top:20px;">
          <h3>Add Extra Pitcher</h3>
          <p class="muted">Use this if a different player pitched during the game than the one originally scheduled.</p>

          <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="game-meta-grid" style="margin-bottom:12px;">
              <div class="form-field">
                <label for="save_extra_pitcher_<?= $i ?>">Player</label>
                <select name="extra_pitchers[<?= $i ?>][player_id]" id="save_extra_pitcher_<?= $i ?>">
                  <option value="">-- Select Player --</option>
                  <?php foreach ($gameRoster as $player): ?>
                    <option value="<?= (int)$player['id'] ?>">
                        <?= h(format_player_label($player, $labelMode)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-field">
                <label for="save_extra_innings_<?= $i ?>">Actual Innings</label>
                <input
                  type="number"
                  id="save_extra_innings_<?= $i ?>"
                  name="extra_pitchers[<?= $i ?>][innings_pitched]"
                  min="0"
                  max="<?= h(number_format((float)$game['innings'], 1, '.', '')) ?>"
                  step="0.1"
                  inputmode="decimal"
                  value="0.0"
                >
              </div>

              <div class="form-field">
                <label for="save_extra_pitches_<?= $i ?>">Pitches Thrown</label>
                <input
                  type="number"
                  id="save_extra_pitches_<?= $i ?>"
                  name="extra_pitchers[<?= $i ?>][pitches_thrown]"
                  min="0"
                  value="0"
                >
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Archive Status</h2>

      <?php if ($archiveRow): ?>
        <div class="meta">
          <div class="meta-box">
            <div class="meta-label">Archive Record ID</div>
            <div class="meta-value"><?= (int)$archiveRow['id'] ?></div>
          </div>
          <div class="meta-box">
            <div class="meta-label">Game DB ID</div>
            <div class="meta-value"><?= (int)$archiveRow['game_db_id'] ?></div>
          </div>
          <div class="meta-box">
            <div class="meta-label">Archived At</div>
            <div class="meta-value"><?= h((string)$archiveRow['created_at']) ?></div>
          </div>
        </div>

        <h3 style="margin-top:20px;display:none;">Archive JSON Preview</h3>
        <pre style="display:none;"><?= h(substr((string)$archiveRow['archive_json'], 0, 4000)) ?></pre>
      <?php else: ?>
        <p>No archive record exists yet for this game.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const toggles = document.querySelectorAll('.did-not-pitch-toggle');

  toggles.forEach(function (toggle) {
    function syncPitchRowState() {
      const playerId = toggle.dataset.playerId;
      const inningsInputs = document.querySelectorAll('.actual-innings-input[data-player-id="' + playerId + '"]');
      const pitchInputs = document.querySelectorAll('.pitch-count-input[data-player-id="' + playerId + '"]');

      if (toggle.checked) {
        inningsInputs.forEach(function (input) {
          input.value = '0';
          input.setAttribute('readonly', 'readonly');
        });

        pitchInputs.forEach(function (input) {
          input.value = '0';
          input.setAttribute('readonly', 'readonly');
        });
      } else {
        inningsInputs.forEach(function (input) {
          input.removeAttribute('readonly');
        });

        pitchInputs.forEach(function (input) {
          input.removeAttribute('readonly');
        });
      }
    }

    toggle.addEventListener('change', syncPitchRowState);
    syncPitchRowState();
  });

  const pitchForm = document.getElementById('pitch-log-form');

  if (pitchForm) {
    pitchForm.addEventListener('submit', function () {
      const isMobile = window.matchMedia('(max-width: 640px)').matches;

      const desktopInputs = pitchForm.querySelectorAll('.desktop-table input, .desktop-table select');
      const mobileInputs = pitchForm.querySelectorAll('.mobile-cards input, .mobile-cards select');

      if (isMobile) {
        desktopInputs.forEach(function (input) {
          input.disabled = true;
        });
      } else {
        mobileInputs.forEach(function (input) {
          input.disabled = true;
        });
      }
    });
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
