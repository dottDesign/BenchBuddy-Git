<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Build Lineup';
$currentPage = 'generate';

$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';

$message = '';
$error = '';

$games = [];
$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$selectedGame = null;

$roster = [];
$generatedResult = null;
$lineupWarnings = [];
$pitcherAvailability = [];
$catcherAvailability = [];

$lockedPositions = [
    'P' => [],
    'C' => [],
];


function display_player_name(array $player): string
{
    $name = trim((string)($player['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $first = trim((string)($player['first_name'] ?? ''));
    $last = trim((string)($player['last_name'] ?? ''));

    $fullName = trim($first . ' ' . $last);

    if ($fullName !== '') {
        return $fullName;
    }

    return 'Unknown Player';
}
function build_player_lookup(array $roster): array
{
    $lookup = [];

    foreach ($roster as $player) {
        $playerId = (int)($player['id'] ?? 0);

        if ($playerId > 0) {
            $lookup[$playerId] = $player;
        }
    }

    return $lookup;
}
function normalize_generator_locked_positions(
    mixed $submitted,
    int $innings
): array {
    $normalized = [
        'P' => [],
        'C' => [],
    ];

    if (!is_array($submitted)) {
        return $normalized;
    }

    foreach (['P', 'C'] as $position) {
        $assignments = $submitted[$position] ?? [];

        if (!is_array($assignments)) {
            continue;
        }

        for ($inning = 1; $inning <= $innings; $inning++) {
            $playerId = (int)($assignments[$inning] ?? 0);

            if ($playerId > 0) {
                $normalized[$position][$inning] = $playerId;
            }
        }
    }

    return $normalized;
}

function validate_generator_locked_positions(
    array $lockedPositions,
    array $roster,
    int $innings
): array {
    $errors = [];
    $rosterById = [];

    foreach ($roster as $player) {
        $playerId = (int)($player['id'] ?? 0);

        if ($playerId > 0) {
            $rosterById[$playerId] = $player;
        }
    }

    for ($inning = 1; $inning <= $innings; $inning++) {
        $pitcherId = (int)($lockedPositions['P'][$inning] ?? 0);
        $catcherId = (int)($lockedPositions['C'][$inning] ?? 0);

        if ($pitcherId > 0 && !isset($rosterById[$pitcherId])) {
            $errors[] = "The pitcher selected for inning {$inning} is not on this game's roster.";
        }

        if ($catcherId > 0 && !isset($rosterById[$catcherId])) {
            $errors[] = "The catcher selected for inning {$inning} is not on this game's roster.";
        }

        if (
            $pitcherId > 0 &&
            $catcherId > 0 &&
            $pitcherId === $catcherId
        ) {
            $errors[] = "The same player cannot pitch and catch in inning {$inning}.";
        }

        if ($pitcherId > 0 && isset($rosterById[$pitcherId])) {
            $pitcher = $rosterById[$pitcherId];

            $pitchingRole = strtolower(
                trim((string)($pitcher['pitching_role'] ?? 'none'))
            );

            if ($pitchingRole === 'none') {
                $errors[] =
                    display_player_name($pitcher) .
                    " cannot be assigned to pitcher in inning {$inning} because their pitching role is set to None.";
            }

            $cannotPlay = $pitcher['cannot_play'] ?? [];

            if (is_array($cannotPlay) && in_array('P', $cannotPlay, true)) {
                $errors[] =
                    display_player_name($pitcher) .
                    " cannot be assigned to pitcher in inning {$inning}.";
            }
        }

        if ($catcherId > 0 && isset($rosterById[$catcherId])) {
            $catcher = $rosterById[$catcherId];

            $catchingRole = strtolower(
                trim((string)($catcher['catching_role'] ?? 'none'))
            );

            if ($catchingRole === 'none') {
                $errors[] =
                    display_player_name($catcher) .
                    " cannot be assigned to catcher in inning {$inning} because their catching role is set to None.";
            }

            $cannotPlay = $catcher['cannot_play'] ?? [];

            if (is_array($cannotPlay) && in_array('C', $cannotPlay, true)) {
                $errors[] =
                    display_player_name($catcher) .
                    " cannot be assigned to catcher in inning {$inning}.";
            }
        }
    }

    return array_values(array_unique($errors));
}
function display_generated_player_name(array $cell, array $playerLookup): string
{
    $playerId = (int)($cell['id'] ?? $cell['player_id'] ?? 0);

    if ($playerId > 0 && isset($playerLookup[$playerId])) {
        return display_player_name($playerLookup[$playerId]);
    }

    return display_player_name($cell);
}
function display_generated_player_label(
    array $cell,
    array $playerLookup,
    string $labelMode
): string {
    $playerId = (int)($cell['id'] ?? $cell['player_id'] ?? 0);

    if ($playerId > 0 && isset($playerLookup[$playerId])) {
        return format_player_label($playerLookup[$playerId], $labelMode);
    }

    return format_player_label($cell, $labelMode);
}

function generate_required_defensive_innings(int $innings): int
{
    if ($innings <= 5) {
        return 2;
    }

    return 3;
}

function generate_fair_play_status_label(int $defensiveInnings, int $requiredDefensiveInnings): string
{
    if ($defensiveInnings >= $requiredDefensiveInnings) {
        return 'OK';
    }

    return 'Violation';
}

function generate_fair_play_status_class(int $defensiveInnings, int $requiredDefensiveInnings): string
{
    if ($defensiveInnings >= $requiredDefensiveInnings) {
        return 'fair-play-ok';
    }

    return 'fair-play-violation';
}
function build_pitcher_availability_status(
    int $teamId,
    int $playerId,
    string $gameDate,
    int $excludeGameId,
    ?string $division
): array {
    if ($gameDate === '') {
        return [
            'available' => true,
            'reason' => 'Available. No game date set, so rest rules were not applied.',
            'next_available_date' => null,
        ];
    }

    $lastOuting = get_pitcher_last_outing(
        $teamId,
        $playerId,
        $gameDate !== '' ? $gameDate : null,
        $excludeGameId
    );
    $restEligible = is_pitcher_rest_eligible(
        $teamId,
        $playerId,
        $gameDate !== '' ? $gameDate : null,
        $excludeGameId
    );

    $consecutiveEligible = true;
    $consecutiveReason = '';

    if (
        function_exists('get_pitching_previous_consecutive_days') &&
        function_exists('get_no_rest_pitch_limit_for_division')
    ) {
        $previousDays = get_pitching_previous_consecutive_days(
            $teamId,
            $playerId,
            $gameDate,
            $excludeGameId
        );

        $previousConsecutiveDays = count($previousDays);
        $noRestLimit = get_no_rest_pitch_limit_for_division($division);

        if ($previousConsecutiveDays >= 3) {
            $consecutiveEligible = false;
            $consecutiveReason = 'Not available. Pitchers are not permitted to pitch on four consecutive days.';
        } elseif ($previousConsecutiveDays >= 2) {
            $previousTwoDayTotal = 0;

            foreach (array_slice($previousDays, 0, 2) as $day) {
                $previousTwoDayTotal += (int)($day['pitches_thrown'] ?? 0);
            }

            if ($previousTwoDayTotal > $noRestLimit) {
                $consecutiveEligible = false;
                $consecutiveReason =
                    'Not available. This would be a third consecutive pitching day, but the previous two days total ' .
                    $previousTwoDayTotal .
                    ' pitches. The ' .
                    ($division ?: 'division') .
                    ' no-rest limit is ' .
                    $noRestLimit .
                    '.';
            }
        }
    }

    $nextAvailableDate = get_pitcher_next_available_date(
        $teamId,
        $playerId,
        $gameDate !== '' ? $gameDate : null,
        $excludeGameId
    );

    if (!$consecutiveEligible) {
        return [
            'available' => false,
            'reason' => $consecutiveReason,
            'next_available_date' => $nextAvailableDate,
        ];
    }

    if (!$restEligible) {
        if ($lastOuting) {
            $lastPitches = (int)($lastOuting['pitches_thrown'] ?? 0);
            $lastDate = (string)($lastOuting['game_date'] ?? '');
            $maxPitches = get_max_pitch_count(
                $division,
                $teamId
            );

            if ($lastPitches > $maxPitches) {
                return [
                    'available' => false,
                    'reason' => 'Not available. Last outing: ' . $lastDate . ', ' . $lastPitches . ' pitches, which exceeds the ' . ($division ?: 'division') . ' max of ' . $maxPitches . '.',
                    'next_available_date' => $nextAvailableDate,
                ];
            }

            $requiredRest = get_required_rest_days_from_pitch_count($lastPitches, $division, $teamId);

            return [
                'available' => false,
                'reason' => 'Resting. Last outing: ' . $lastDate . ', ' . $lastPitches . ' pitches, requires ' . $requiredRest . ' day(s) rest.',
                'next_available_date' => $nextAvailableDate,
            ];
        }

        return [
            'available' => false,
            'reason' => 'Resting due to pitch count rules.',
            'next_available_date' => $nextAvailableDate,
        ];
    }

    return [
        'available' => true,
        'reason' => 'Available',
        'next_available_date' => $nextAvailableDate,
    ];
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

        if ($selectedGameId <= 0) {
            throw new RuntimeException('Please select a game.');
        }

        $selectedGame = get_game_by_id($teamId, $selectedGameId);
        if (!$selectedGame) {
            throw new RuntimeException('Game not found for this team.');
        }

        if ($action === 'generate_lineup') {
            if (($selectedGame['status'] ?? '') === 'locked') {
                throw new RuntimeException('Locked games cannot be regenerated.');
            }

            $roster = get_game_roster($teamId, $selectedGameId);

            if (count($roster) < 8) {
                throw new RuntimeException('This game needs at least 8 rostered players before building a lineup.');
            }

            $innings = max(1, (int)($selectedGame['innings'] ?? 7));

            $lockedPositions = normalize_generator_locked_positions(
                $_POST['locked_positions'] ?? [],
                $innings
            );

            $gameDate = trim((string)($selectedGame['game_date'] ?? ''));
            $division = get_team_division($teamId);

            $pitcherAvailability = [];
            $catcherAvailability = [];


            $eligiblePrimaryPitchers = 0;
            $eligibleEmergencyPitchers = 0;
            $eligiblePrimaryCatchers = 0;
            $eligibleEmergencyCatchers = 0;

            foreach ($roster as &$player) {
                $allPositions = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];

                $player['can_play'] = $player['can_play'] ?? [];
                $player['cannot_play'] = $player['cannot_play'] ?? [];

                if (empty($player['can_play'])) {
                    $player['can_play'] = array_values(array_diff($allPositions, $player['cannot_play']));
                }

                $playerId = (int)$player['id'];
                $pitchingRole = strtolower(trim((string)($player['pitching_role'] ?? 'none')));

                if ($pitchingRole !== 'none') {
                    $pitchStatus = build_pitcher_availability_status(
                        $teamId,
                        $playerId,
                        $gameDate,
                        (int)$selectedGame['id'],
                        $division
                    );

                    if (!$pitchStatus['available']) {
                        $player['pitching_role'] = 'none';
                    }

                    $finalPitchingRole = strtolower(trim((string)($player['pitching_role'] ?? 'none')));

                    if ($finalPitchingRole === 'primary') {
                        $eligiblePrimaryPitchers++;
                    }

                    if ($finalPitchingRole === 'emergency') {
                        $eligibleEmergencyPitchers++;
                    }

                    $pitcherAvailability[] = [
                        'player_id' => $playerId,
                        'name' => player_full_name($player),
                        'jersey_number' => (string)($player['jersey_number'] ?? ''),
                        'available' => $finalPitchingRole !== 'none',
                        'pitching_role' => $finalPitchingRole,
                        'next_available_date' => $pitchStatus['next_available_date'],
                        'reason' => $pitchStatus['reason'],
                    ];
                }

                $catchingRole = strtolower(trim((string)($player['catching_role'] ?? 'none')));
                $canPlay = $player['can_play'] ?? [];
                $cannotPlay = $player['cannot_play'] ?? [];

                $canCatch = empty($canPlay) || in_array('C', $canPlay, true);
                $cannotCatch = in_array('C', $cannotPlay, true);

                if ($catchingRole !== 'none') {
                    $catcherAvailable = true;
                    $catcherReason = 'Available';

                    if (!$canCatch) {
                        $catcherAvailable = false;
                        $catcherReason = 'Not available. This player is marked as a catcher but C is not included in Can Play.';
                    } elseif ($cannotCatch) {
                        $catcherAvailable = false;
                        $catcherReason = 'Not available. This player is marked as a catcher but C is also listed in Cannot Play.';
                    }

                    $finalCatchingRole = $catcherAvailable ? $catchingRole : 'none';

                    if ($finalCatchingRole === 'primary') {
                        $eligiblePrimaryCatchers++;
                    }

                    if ($finalCatchingRole === 'emergency') {
                        $eligibleEmergencyCatchers++;
                    }

                    $catcherAvailability[] = [
                        'player_id' => $playerId,
                        'name' => player_full_name($player),
                        'jersey_number' => (string)($player['jersey_number'] ?? ''),
                        'available' => $catcherAvailable,
                        'catching_role' => $finalCatchingRole !== 'none' ? $finalCatchingRole : $catchingRole,
                        'reason' => $catcherReason,
                    ];

                    if (!$catcherAvailable) {
                        $player['catching_role'] = 'none';
                    }
                }
            }
            unset($player);
            $lockedPositionErrors = validate_generator_locked_positions(
                $lockedPositions,
                $roster,
                $innings
            );

            if (!empty($lockedPositionErrors)) {
                throw new RuntimeException(
                    implode(' ', $lockedPositionErrors)
                );
            }
            if ($eligiblePrimaryPitchers === 0 && $eligibleEmergencyPitchers === 0) {
                throw new RuntimeException('No pitchers are eligible for this game based on pitch count rest rules.');
            }

            if ($eligiblePrimaryCatchers === 0 && $eligibleEmergencyCatchers === 0) {
                throw new RuntimeException('No catchers are eligible for this game based on catcher roles and position settings.');
            }

            $generatorOverrides = [
                'allow_relaxed_mode' => !empty($_POST['allow_relaxed_mode']),
                'enforce_cannot_play' => true,
                'hard_avoid_consecutive_bench' => empty($_POST['allow_consecutive_bench']),
                'balance_bench_fairness' => true,

                // Coach-selected pitcher and catcher assignments.
                'locked_positions' => $lockedPositions,
                'innings' => $innings,
            ];

            $generatedResult = generate_lineup($roster, $generatorOverrides);
            save_generated_lineup($teamId, $selectedGameId, $generatedResult);

            $_SESSION['ga_events'][] = 'lineup_created';

            $lineupWarnings = analyze_lineup_warnings($generatedResult);

            if ($eligiblePrimaryPitchers === 0 && $eligibleEmergencyPitchers > 0) {
                flash_redirect('ok', 'Lineup generated successfully using emergency pitchers because no primary pitchers were eligible.', 'generate.php?game_id=' . $selectedGameId);
            } elseif ($eligiblePrimaryCatchers === 0 && $eligibleEmergencyCatchers > 0) {
                flash_redirect('ok', 'Lineup generated successfully using emergency catchers because no primary catchers were eligible.', 'generate.php?game_id=' . $selectedGameId);
            } else {
                flash_redirect('ok', 'Lineup generated successfully.', 'generate.php?game_id=' . $selectedGameId);

            }
        }
    }

    if ($selectedGameId > 0) {
        $selectedGame = get_game_by_id($teamId, $selectedGameId);

        if ($selectedGame) {
            $roster = get_game_roster($teamId, $selectedGameId);

            if (
                ($selectedGame['status'] ?? '') === 'generated' ||
                ($selectedGame['status'] ?? '') === 'locked'
            ) {
                $generatedResult = get_generated_lineup_result(
                    $teamId,
                    $selectedGameId
                );

                if ($generatedResult) {
                    $lineupWarnings = analyze_lineup_warnings($generatedResult);

                    /*
                    |--------------------------------------------------------------------------
                    | Restore Pitcher and Catcher Planner selections
                    |--------------------------------------------------------------------------
                    | After generation, flash_redirect() reloads this page. Rebuild the
                    | planner selections from the saved lineup so they remain selected.
                    */
                    $generatedInnings = (int)($generatedResult['innings'] ?? 0);

                    for ($inning = 1; $inning <= $generatedInnings; $inning++) {
                        $pitcherCell = $generatedResult['lineup_grid']['P'][$inning] ?? null;
                        $catcherCell = $generatedResult['lineup_grid']['C'][$inning] ?? null;

                        if (is_array($pitcherCell)) {
                            $pitcherId = (int)(
                                $pitcherCell['id']
                                ?? $pitcherCell['player_id']
                                ?? 0
                            );

                            if ($pitcherId > 0) {
                                $lockedPositions['P'][$inning] = $pitcherId;
                            }
                        }

                        if (is_array($catcherCell)) {
                            $catcherId = (int)(
                                $catcherCell['id']
                                ?? $catcherCell['player_id']
                                ?? 0
                            );

                            if ($catcherId > 0) {
                                $lockedPositions['C'][$inning] = $catcherId;
                            }
                        }
                    }
                }
            }

            $gameDate = trim((string)($selectedGame['game_date'] ?? ''));
            $division = get_team_division($teamId);
            $pitcherAvailability = [];
            $catcherAvailability = [];
            foreach ($roster as $player) {
                $playerId = (int)$player['id'];

                $pitchingRole = strtolower(trim((string)($player['pitching_role'] ?? 'none')));

                if ($pitchingRole !== 'none') {
                    $pitchStatus = build_pitcher_availability_status(
                        $teamId,
                        $playerId,
                        $gameDate,
                        (int)$selectedGame['id'],
                        $division
                    );

                    $pitcherAvailability[] = [
                        'player_id' => $playerId,
                        'name' => player_full_name($player),
                        'jersey_number' => (string)($player['jersey_number'] ?? ''),
                        'available' => (bool)$pitchStatus['available'],
                        'pitching_role' => $pitchingRole,
                        'next_available_date' => $pitchStatus['next_available_date'],
                        'reason' => $pitchStatus['reason'],
                    ];
                }

                $catchingRole = strtolower(trim((string)($player['catching_role'] ?? 'none')));
                $canCatch = in_array('C', $player['can_play'] ?? [], true);
                $cannotCatch = in_array('C', $player['cannot_play'] ?? [], true);

                if ($catchingRole !== 'none') {
                    $catcherAvailable = true;
                    $catcherReason = 'Available';

                    if (!$canCatch) {
                        $catcherAvailable = false;
                        $catcherReason = 'Not available. This player is marked as a catcher but C is not included in Can Play.';
                    } elseif ($cannotCatch) {
                        $catcherAvailable = false;
                        $catcherReason = 'Not available. This player is marked as a catcher but C is also listed in Cannot Play.';
                    }

                    $catcherAvailability[] = [
                        'player_id' => $playerId,
                        'name' => player_full_name($player),
                        'jersey_number' => (string)($player['jersey_number'] ?? ''),
                        'available' => $catcherAvailable,
                        'catching_role' => $catchingRole,
                        'reason' => $catcherReason,
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log($e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

    $rawError = $e->getMessage();

    if (
        stripos($rawError, 'same player cannot pitch and catch') !== false
    ) {
        $error = $rawError;

    } elseif (
        stripos($rawError, 'selected pitcher') !== false ||
        stripos($rawError, 'selected catcher') !== false ||
        stripos($rawError, 'cannot be assigned to pitcher') !== false ||
        stripos($rawError, 'cannot be assigned to catcher') !== false
    ) {
        $error = $rawError;

    } elseif (stripos($rawError, 'Cannot solve inning') !== false) {
        $error =
            'BenchBuddy could not build this lineup with the selected pitcher and catcher plan. ' .
            'Check that the assigned players are eligible, that the pitcher plan does not return ' .
            'a removed pitcher later in the game, and that the remaining roster can fill every position.';

    } elseif (
        stripos($rawError, 'At least 9 players are required') !== false ||
        stripos($rawError, 'At least 8 players are required') !== false
    ) {
        $error = 'You need at least 8 players on the roster before building a lineup.';
    } elseif (stripos($rawError, 'No pitchers are eligible') !== false) {
        $error = 'No pitchers are currently eligible for this game based on pitch count and consecutive-day rules.';
    } elseif (stripos($rawError, 'No catchers are eligible') !== false) {
        $error = 'No catchers are currently eligible for this game based on catcher roles and position settings.';
    } elseif (stripos($rawError, 'Locked games cannot be regenerated') !== false) {
        $error = 'This game has already been finalized and cannot be rebuilt.';
    } else {
        $error = $rawError;
    }

    if ($teamId > 0) {
        $games = get_all_games($teamId);
    }

    if ($selectedGameId > 0) {
        $selectedGame = get_game_by_id($teamId, $selectedGameId);

        if ($selectedGame) {
            $roster = get_game_roster($teamId, $selectedGameId);

            if (($selectedGame['status'] ?? '') === 'generated' || ($selectedGame['status'] ?? '') === 'locked') {
                $generatedResult = get_generated_lineup_result($teamId, $selectedGameId);

                if ($generatedResult) {
                    $lineupWarnings = analyze_lineup_warnings($generatedResult);
                }
            }
        }
    }
}
$playerLookup = build_player_lookup($roster);
require_once __DIR__ . '/includes/header.php';

?>

<h1 class="page-title brand-title-font">Build Lineup</h1>

<div class="workflow">
  <div class="workflow-step">1 Set Roster</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step active">2 Build Lineup</div>
  <span class="workflow-arrow">→</span>
  <div class="workflow-step">3 Finalize Game</div>
</div>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="layout-grid">
  <div class="card">
    <h2>Select Game</h2>

    <form method="get" action="generate.php">
        <?= csrf_field() ?>
      <label for="game_id">Game</label>
      <select name="game_id" id="game_id" required>
        <option value="">-- Select a Game --</option>
        <?php foreach ($games as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $selectedGameId === (int)$g['id'] ? 'selected' : '' ?>>
            <?= h((string)$g['game_id']) ?>
            | <?= h((string)$g['status']) ?>
            | <?= !empty($g['game_date']) ? h((string)$g['game_date']) : 'No date' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="actions-row">
        <button type="submit">Load Game</button>
      </div>
    </form>
  </div>

  <?php if ($selectedGame): ?>
    <div class="card">
      <h2>Game Details</h2>

      <div class="meta">
        <div class="meta-box">
          <div class="meta-label">Game ID</div>
          <div class="meta-value"><?= h((string)$selectedGame['game_id']) ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Status</div>
          <div class="meta-value">
            <span class="pill <?= h(status_class((string)$selectedGame['status'])) ?>">
              <?= h((string)$selectedGame['status']) ?>
            </span>
          </div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Game Date</div>
          <div class="meta-value">
            <?= !empty($selectedGame['game_date']) ? h((string)$selectedGame['game_date']) : 'Not set' ?>
          </div>
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
      </div>

      <?php if (($selectedGame['status'] ?? '') !== 'locked'): ?>
        <form method="post" action="generate.php" style="margin-top:18px;">
            <?= csrf_field() ?>
          <input type="hidden" name="action" value="generate_lineup">
          <input type="hidden" name="game_db_id" value="<?= (int)$selectedGame['id'] ?>">

              <?php
                $gameInnings = max(1, (int)($selectedGame['innings'] ?? 7));

                $availablePitcherIds = [];
                foreach ($pitcherAvailability as $pitcherStatus) {
                    if (!empty($pitcherStatus['available'])) {
                        $availablePitcherIds[(int)$pitcherStatus['player_id']] = true;
                    }
                }

                $availableCatcherIds = [];
                foreach ($catcherAvailability as $catcherStatus) {
                    if (!empty($catcherStatus['available'])) {
                        $availableCatcherIds[(int)$catcherStatus['player_id']] = true;
                    }
                }
              ?>

              <div class="battery-planner" style="margin-top:18px;">
                <h3>Pitcher and Catcher Planner</h3>

                <p class="muted">
                  Assign pitchers and catchers before generating. BenchBuddy will keep these
                  selections in place and build the remaining fielding rotation around them.
                  Leave an inning blank to let BenchBuddy choose.
                </p>

                <div class="table-wrap desktop-table">
                  <table>
                    <thead>
                      <tr>
                        <th>Inning</th>
                        <th>Pitcher</th>
                        <th>Catcher</th>
                      </tr>
                    </thead>

                    <tbody>
                      <?php for ($inning = 1; $inning <= $gameInnings; $inning++): ?>
                        <?php
                          $selectedPitcherId = (int)($lockedPositions['P'][$inning] ?? 0);
                          $selectedCatcherId = (int)($lockedPositions['C'][$inning] ?? 0);
                        ?>

                        <tr>
                          <th>Inning <?= (int)$inning ?></th>

                          <td>
                            <select
                              name="locked_positions[P][<?= (int)$inning ?>]"
                              class="battery-position-select"
                              data-position="P"
                              data-inning="<?= (int)$inning ?>"
                            >
                              <option value="">BenchBuddy chooses</option>

                              <?php foreach ($roster as $player): ?>
                                <?php
                                  $playerId = (int)($player['id'] ?? 0);
                                  $pitchingRole = strtolower(
                                      trim((string)($player['pitching_role'] ?? 'none'))
                                  );

                                  $isAvailablePitcher =
                                      $pitchingRole !== 'none' &&
                                      isset($availablePitcherIds[$playerId]);
                                ?>

                                <?php if ($isAvailablePitcher): ?>
                                  <option
                                    value="<?= $playerId ?>"
                                    <?= $selectedPitcherId === $playerId ? 'selected' : '' ?>
                                  >
                                    <?= h(format_player_label($player, $labelMode)) ?>
                                    — <?= h(ucfirst($pitchingRole)) ?>
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </select>
                          </td>

                          <td>
                            <select
                              name="locked_positions[C][<?= (int)$inning ?>]"
                              class="battery-position-select"
                              data-position="C"
                              data-inning="<?= (int)$inning ?>"
                            >
                              <option value="">BenchBuddy chooses</option>

                              <?php foreach ($roster as $player): ?>
                                <?php
                                  $playerId = (int)($player['id'] ?? 0);
                                  $catchingRole = strtolower(
                                      trim((string)($player['catching_role'] ?? 'none'))
                                  );

                                  $isAvailableCatcher =
                                      $catchingRole !== 'none' &&
                                      isset($availableCatcherIds[$playerId]);
                                ?>

                                <?php if ($isAvailableCatcher): ?>
                                  <option
                                    value="<?= $playerId ?>"
                                    <?= $selectedCatcherId === $playerId ? 'selected' : '' ?>
                                  >
                                    <?= h(format_player_label($player, $labelMode)) ?>
                                    — <?= h(ucfirst($catchingRole)) ?>
                                  </option>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </select>
                          </td>
                        </tr>
                      <?php endfor; ?>
                    </tbody>
                  </table>
                </div>

                <div class="mobile-cards">
                  <?php for ($inning = 1; $inning <= $gameInnings; $inning++): ?>
                    <?php
                      $selectedPitcherId = (int)($lockedPositions['P'][$inning] ?? 0);
                      $selectedCatcherId = (int)($lockedPositions['C'][$inning] ?? 0);
                    ?>

                    <div class="mobile-card">
                      <div class="mobile-card-title">
                        Inning <?= (int)$inning ?>
                      </div>

                      <label>
                        Pitcher

                        <select
                          name="locked_positions[P][<?= (int)$inning ?>]"
                          class="battery-position-select"
                          data-position="P"
                          data-inning="<?= (int)$inning ?>"
                        >
                          <option value="">BenchBuddy chooses</option>

                          <?php foreach ($roster as $player): ?>
                            <?php
                              $playerId = (int)($player['id'] ?? 0);
                              $pitchingRole = strtolower(
                                  trim((string)($player['pitching_role'] ?? 'none'))
                              );

                              $isAvailablePitcher =
                                  $pitchingRole !== 'none' &&
                                  isset($availablePitcherIds[$playerId]);
                            ?>

                            <?php if ($isAvailablePitcher): ?>
                              <option
                                value="<?= $playerId ?>"
                                <?= $selectedPitcherId === $playerId ? 'selected' : '' ?>
                              >
                                <?= h(format_player_label($player, $labelMode)) ?>
                                — <?= h(ucfirst($pitchingRole)) ?>
                              </option>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </select>
                      </label>

                      <label style="margin-top:12px;">
                        Catcher

                        <select
                          name="locked_positions[C][<?= (int)$inning ?>]"
                          class="battery-position-select"
                          data-position="C"
                          data-inning="<?= (int)$inning ?>"
                        >
                          <option value="">BenchBuddy chooses</option>

                          <?php foreach ($roster as $player): ?>
                            <?php
                              $playerId = (int)($player['id'] ?? 0);
                              $catchingRole = strtolower(
                                  trim((string)($player['catching_role'] ?? 'none'))
                              );

                              $isAvailableCatcher =
                                  $catchingRole !== 'none' &&
                                  isset($availableCatcherIds[$playerId]);
                            ?>

                            <?php if ($isAvailableCatcher): ?>
                              <option
                                value="<?= $playerId ?>"
                                <?= $selectedCatcherId === $playerId ? 'selected' : '' ?>
                              >
                                <?= h(format_player_label($player, $labelMode)) ?>
                                — <?= h(ucfirst($catchingRole)) ?>
                              </option>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>



          <details class="advanced-settings" style="margin-top:16px;" <?= $error !== '' ? 'open' : '' ?>>
            <summary>Advanced Settings</summary>

            <div class="generator-settings-grid" style="margin-top:14px;">
              <div class="toggle-group">
                <label class="toggle-row">
                  <span class="toggle-label-text">Allow relaxed mode</span>
                  <span class="toggle-switch">
                    <input type="checkbox" name="allow_relaxed_mode" value="1">
                    <span class="toggle-slider"></span>
                  </span>
                </label>
                <div class="toggle-help">
                  If a lineup cannot be built, this allows players to fill positions outside their preferred spots while still following core safety limits.
                </div>
              </div>


              <div class="toggle-group">
                <label class="toggle-row">
                  <span class="toggle-label-text">Allow consecutive bench</span>
                  <span class="toggle-switch">
                    <input type="checkbox" name="allow_consecutive_bench" value="1">
                    <span class="toggle-slider"></span>
                  </span>
                </label>
                <div class="toggle-help">
                  Normally players will not sit two innings in a row. Turn this on only if roster limits make that necessary.
                </div>
              </div>


            </div>
          </details>

          <?php if (($selectedGame['status'] ?? '') === 'generated'): ?>
                <div class="actions-row">
                    <button type="submit">Re-generate Lineup</button>
                </div>
          <?php else: ?>
                <div class="actions-row">
                    <button type="submit">Generate Lineup</button>
                </div>
          <?php endif; ?>




        </form>
      <?php else: ?>
        <div class="actions-row" style="margin-top:18px; flex-wrap:wrap;">
          <span class="muted" style="align-self:center; font-weight:700;">This game is already finalized.</span>

          <a class="btn btn-secondary" href="print_lineup.php?game_id=<?= (int)$selectedGame['id'] ?>" target="_blank">
            Print Lineup
          </a>

          <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$selectedGame['id'] ?>#pitch-log">
            Save Pitch Counts
          </a>

          <form method="post" action="lock.php" style="display:inline;" onsubmit="return confirm('Reopen this finalized game for editing?');">
              <?= csrf_field() ?>
            <input type="hidden" name="action" value="reopen_game">
            <input type="hidden" name="game_db_id" value="<?= (int)$selectedGame['id'] ?>">
            <button type="submit" class="btn">Reopen Game</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (($selectedGame['status'] ?? '') === 'generated'): ?>
        <div class="actions-row" style="margin-top:18px; flex-wrap:wrap;">
          <a class="btn btn-secondary" href="manual_lineup.php?game_id=<?= (int)$selectedGame['id'] ?>">
            Manual Edit
          </a>

          <form method="post" action="lock.php" style="display:inline;">
              <?= csrf_field() ?>
            <input type="hidden" name="action" value="lock_game">
            <input type="hidden" name="game_db_id" value="<?= (int)$selectedGame['id'] ?>">

            <button
              type="submit"
              class="btn"
              onclick="return confirm('Lock this lineup for printing?');"
            >
              Lock Lineup for Print
            </button>
          </form>

          <a class="btn btn-secondary" href="lock.php?game_id=<?= (int)$selectedGame['id'] ?>#pitch-log">
            Save Pitch Counts
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Roster</h2>

      <?php if (empty($roster)): ?>
        <p class="muted">No roster saved for this game yet.</p>
      <?php else: ?>
        <div class="table-wrap desktop-table">
          <table class="roster">
            <thead>
              <tr>
                <th>Batting Order</th>
                <th>Player</th>
                <th>Pitching Role</th>
                <th>Catching Role</th>
                <th>Can Play</th>
                <th>Cannot Play</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($roster as $player): ?>
                <tr>
                  <td><?= (int)($player['batting_order'] ?? 0) ?></td>
                  <td>
                      <?= h(format_player_label($player, $labelMode)) ?>
                  </td>
                  <td class="<?= h(ucfirst((string)($player['pitching_role'] ?? 'none'))) ?>">
                    <?= h(ucfirst((string)($player['pitching_role'] ?? 'none'))) ?>
                  </td>
                  <td class="<?= h(ucfirst((string)($player['catching_role'] ?? 'none'))) ?>">
                    <?= h(ucfirst((string)($player['catching_role'] ?? 'none'))) ?>
                  </td>
                  <td><?= h(implode(', ', $player['can_play'] ?? [])) ?></td>
                  <td><?= h(implode(', ', $player['cannot_play'] ?? [])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mobile-cards">
          <?php foreach ($roster as $player): ?>
            <div class="mobile-card">
                <div class="mobile-card-title">
                  <?= (int)($player['batting_order'] ?? 0) ?>. <?= h(format_player_label($player, $labelMode)) ?>
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
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Pitcher Availability</h2>

      <?php if (empty($pitcherAvailability)): ?>
        <p class="muted">No pitchers found on this roster.</p>
      <?php else: ?>
        <div class="table-wrap desktop-table">
          <table>
            <thead>
              <tr>
                <th>Pitcher</th>
                <th>Role</th>
                <th>Status</th>
                <th>Next Available</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pitcherAvailability as $pitcher): ?>
                <tr>
                  <td>
                      <?= h(format_player_label($pitcher, $labelMode)) ?>
                  </td>
                  <td><?= h(ucfirst((string)($pitcher['pitching_role'] ?? 'none'))) ?></td>
                  <td>
                    <span class="pill <?= $pitcher['available'] ? 'locked' : 'draft' ?>">
                      <?= $pitcher['available'] ? 'Available' : 'Resting' ?>
                    </span>
                  </td>
                  <td>
                      <?php if ($pitcher['available']): ?>
                        Now
                      <?php elseif (!empty($pitcher['next_available_date'])): ?>
                        <?= h((string)$pitcher['next_available_date']) ?>
                      <?php else: ?>
                        TBD
                      <?php endif; ?>
                  </td>
                  <td><?= h($pitcher['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mobile-cards">
          <?php foreach ($pitcherAvailability as $pitcher): ?>
            <div class="mobile-card">
              <div class="mobile-card-title">
                  <?= h(format_player_label($pitcher, $labelMode)) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Role</span>
                <?= h(ucfirst((string)($pitcher['pitching_role'] ?? 'none'))) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Status</span>
                <span class="pill <?= $pitcher['available'] ? 'locked' : 'draft' ?>">
                  <?= $pitcher['available'] ? 'Available' : 'Resting' ?>
                </span>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Next</span>
                <?php if (!empty($pitcher['next_available_date'])): ?>
                  <?= h((string)$pitcher['next_available_date']) ?>
                <?php elseif ($pitcher['available']): ?>
                  Now
                <?php else: ?>
                  TBD
                <?php endif; ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Reason</span>
                <?= h($pitcher['reason']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Catcher Availability</h2>

      <?php if (empty($catcherAvailability)): ?>
        <p class="muted">No catchers found on this roster.</p>
      <?php else: ?>
        <div class="table-wrap desktop-table">
          <table>
            <thead>
              <tr>
                <th>Catcher</th>
                <th>Role</th>
                <th>Status</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($catcherAvailability as $catcher): ?>
                <tr>
                  <td>
                      <?= h(format_player_label($catcher, $labelMode)) ?>
                  </td>
                  <td><?= h(ucfirst((string)($catcher['catching_role'] ?? 'none'))) ?></td>
                  <td>
                    <span class="pill <?= $catcher['available'] ? 'locked' : 'draft' ?>">
                      <?= $catcher['available'] ? 'Available' : 'Unavailable' ?>
                    </span>
                  </td>
                  <td><?= h($catcher['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mobile-cards">
          <?php foreach ($catcherAvailability as $catcher): ?>
            <div class="mobile-card">
              <div class="mobile-card-title">
                  <?= h(format_player_label($catcher, $labelMode)) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Role</span>
                <?= h(ucfirst((string)($catcher['catching_role'] ?? 'none'))) ?>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Status</span>
                <span class="pill <?= $catcher['available'] ? 'locked' : 'draft' ?>">
                  <?= $catcher['available'] ? 'Available' : 'Unavailable' ?>
                </span>
              </div>

              <div class="mobile-card-row">
                <span class="mobile-card-label">Reason</span>
                <?= h($catcher['reason']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($lineupWarnings)): ?>
      <div class="card" style="margin-top:18px;">
        <h2>Lineup Warnings</h2>

        <?php foreach ($lineupWarnings as $warning): ?>
          <div class="msg <?= $warning['type'] === 'error' ? 'err' : 'warn' ?>">
            <?= h($warning['message']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($generatedResult && !empty($generatedResult['lineup_grid'])): ?>
      <div class="card">
        <h2>Generated Lineup</h2>

        <div class="table-wrap desktop-table">
            <table class="grid-table">
            <thead>
              <tr>
                <th>Position</th>
                <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
                  <th>Inning <?= $inning ?></th>
                <?php endfor; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($generatedResult['positions'] ?? []) as $position): ?>
                <tr>
                  <th><?= h((string)$position) ?></th>
                  <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
                    <?php $cell = $generatedResult['lineup_grid'][$position][$inning] ?? null; ?>
                    <td>
                      <?php if (is_array($cell)): ?>
                      <?= h(display_generated_player_label($cell, $playerLookup, $labelMode)) ?>
                      <?php endif; ?>
                    </td>
                  <?php endfor; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mobile-cards generated-lineup-mobile">
          <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
            <div class="mobile-card">
              <div class="mobile-card-title">Inning <?= $inning ?></div>

              <?php foreach (($generatedResult['positions'] ?? []) as $position): ?>
                <?php $cell = $generatedResult['lineup_grid'][$position][$inning] ?? null; ?>

                <div class="mobile-card-row">
                  <span class="mobile-card-label"><?= h((string)$position) ?></span>

                  <span>
                      <?php if (is_array($cell)): ?>
                        <?= h(display_generated_player_label($cell, $playerLookup, $labelMode)) ?>
                      <?php else: ?>
                        <span class="muted">Open</span>
                      <?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>



      <?php if (!empty($generatedResult['bench_grid'])): ?>
        <div class="card">
          <h2>Bench Assignments</h2>

          <div class="table-wrap desktop-table">
              <table class="grid-table">
              <thead>
                <tr>
                  <th>Bench Slot</th>
                  <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
                    <th>Inning <?= $inning ?></th>
                  <?php endfor; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($generatedResult['bench_grid'] as $slot => $rows): ?>
                  <tr>
                    <th>Bench <?= (int)$slot + 1 ?></th>
                    <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
                      <?php $cell = $rows[$inning] ?? null; ?>
                      <td>
                          <?php if (is_array($cell)): ?>
                            <?= h(display_generated_player_label($cell, $playerLookup, $labelMode)) ?>
                          <?php endif; ?>
                      </td>
                    <?php endfor; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="mobile-cards bench-assignments-mobile">
            <?php for ($inning = 1; $inning <= (int)$generatedResult['innings']; $inning++): ?>
              <div class="mobile-card">
                <div class="mobile-card-title">Inning <?= $inning ?> Bench</div>

                <?php foreach ($generatedResult['bench_grid'] as $slot => $rows): ?>
                  <?php $cell = $rows[$inning] ?? null; ?>

                  <div class="mobile-card-row">
                    <span class="mobile-card-label">Bench <?= (int)$slot + 1 ?></span>

                    <span>
                        <?php if (is_array($cell)): ?>
                          <?= h(display_generated_player_label($cell, $playerLookup, $labelMode)) ?>
                        <?php else: ?>
                          <span class="muted">Open</span>
                        <?php endif; ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>



      <?php
      $infieldPositions = ['P', 'C', '1B', '2B', '3B', 'SS'];
      $outfieldPositions = ['LF', 'CF', 'RF'];
      $playSummary = [];

      foreach (($generatedResult['positions'] ?? []) as $position) {
          foreach (($generatedResult['lineup_grid'][$position] ?? []) as $inning => $cell) {
              if (!is_array($cell) || empty($cell['id'])) {
                  continue;
              }

              $playerId = (int)$cell['id'];

              if (!isset($playSummary[$playerId])) {
                  $playSummary[$playerId] = [
                  'player_id' => $playerId,
                  'name' => display_generated_player_name($cell, $playerLookup),
                  'first_name' => (string)($playerLookup[$playerId]['first_name'] ?? ''),
                  'last_name' => (string)($playerLookup[$playerId]['last_name'] ?? ''),
                  'jersey_number' => (string)($playerLookup[$playerId]['jersey_number'] ?? $cell['jersey_number'] ?? ''),
                      'infield' => 0,
                      'outfield' => 0,
                      'pitcher' => 0,
                      'bench' => 0,
                      'catcher' => 0,
                      'total_defense' => 0,
                  ];
              }

              if (in_array($position, $infieldPositions, true)) {
                  $playSummary[$playerId]['infield']++;
              }

              if (in_array($position, $outfieldPositions, true)) {
                  $playSummary[$playerId]['outfield']++;
              }

              if ($position === 'P') {
                  $playSummary[$playerId]['pitcher']++;
              }

              if ($position === 'C') {
                  $playSummary[$playerId]['catcher']++;
              }

              $playSummary[$playerId]['total_defense']++;
          }
      }
      foreach (($generatedResult['bench_grid'] ?? []) as $slot => $inningsGrid) {
          foreach ($inningsGrid as $inning => $cell) {
              if (!is_array($cell) || empty($cell['id'])) {
                  continue;
              }

              $playerId = (int)$cell['id'];

              if (!isset($playSummary[$playerId])) {
                  $playSummary[$playerId] = [
                      'player_id' => $playerId,
                      'name' => display_generated_player_name($cell, $playerLookup),
                      'first_name' => (string)($playerLookup[$playerId]['first_name'] ?? ''),
                      'last_name' => (string)($playerLookup[$playerId]['last_name'] ?? ''),
                      'jersey_number' => (string)($playerLookup[$playerId]['jersey_number'] ?? $cell['jersey_number'] ?? ''),
                      'infield' => 0,
                      'outfield' => 0,
                      'pitcher' => 0,
                      'catcher' => 0,
                      'bench' => 0,
                      'total_defense' => 0,
                  ];
              }

              $playSummary[$playerId]['bench']++;
          }
      }
      uasort($playSummary, function (array $a, array $b): int {
          return strcmp($a['name'], $b['name']);
      });
      ?>

      <?php if (!empty($playSummary)): ?>
        <?php $requiredDefensiveInnings = generate_required_defensive_innings((int)($generatedResult['innings'] ?? 7)); ?>

        <div class="card">
          <h2>Position Summary</h2>

          <div class="msg info" style="margin-bottom:12px;">
            Fair Play Rule: each player must play at least
            <?= (int)$requiredDefensiveInnings ?>
            complete defensive inning<?= (int)$requiredDefensiveInnings === 1 ? '' : 's' ?>
            in this <?= (int)($generatedResult['innings'] ?? 7) ?>-inning game.
          </div>

          <div class="table-wrap desktop-table">
            <table>
              <thead>
                <tr>
                  <th>Player</th>
                  <th>Infield Innings</th>
                  <th>Outfield Innings</th>
                  <th>Pitcher</th>
                  <th>Catcher</th>
                  <th>Bench</th>
                  <th>Total Defensive Innings</th>
                  <th>Fair Play</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($playSummary as $row): ?>
                  <tr>
                    <td>
                        <?= h(format_player_label($row, $labelMode)) ?>
                    </td>
                    <td><?= (int)$row['infield'] ?></td>
                    <td><?= (int)$row['outfield'] ?></td>
                    <td><?= (int)$row['pitcher'] ?></td>
                    <td><?= (int)$row['catcher'] ?></td>
                    <td><?= (int)($row['bench'] ?? 0) ?></td>
                    <td><?= (int)$row['total_defense'] ?></td>
                    <td>
                      <?php
                        $defensiveInnings = (int)$row['total_defense'];
                        $fairPlayClass = generate_fair_play_status_class($defensiveInnings, $requiredDefensiveInnings);
                        $fairPlayLabel = generate_fair_play_status_label($defensiveInnings, $requiredDefensiveInnings);
                      ?>
                      <span class="pill <?= h($fairPlayClass) ?>">
                        <?= h($fairPlayLabel) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="mobile-cards">
            <?php foreach ($playSummary as $row): ?>
              <div class="mobile-card">
                <div class="mobile-card-title">
                    <?= h(display_player_name($row)) ?>
                  <?php if ($row['jersey_number'] !== ''): ?>
                    (#<?= h($row['jersey_number']) ?>)
                  <?php endif; ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Infield</span>
                  <?= (int)$row['infield'] ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Outfield</span>
                  <?= (int)$row['outfield'] ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Pitcher</span>
                  <?= (int)$row['pitcher'] ?>
                </div>

                <div class="mobile-card-row">
                  <span class="mobile-card-label">Catcher</span>
                  <?= (int)$row['catcher'] ?>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Bench</span>
                  <?= (int)($row['bench'] ?? 0) ?>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Total</span>
                  <?= (int)$row['total_defense'] ?>
                </div>
                <div class="mobile-card-row">
                  <span class="mobile-card-label">Fair Play</span>
                  <?php
                    $defensiveInnings = (int)$row['total_defense'];
                    $fairPlayClass = generate_fair_play_status_class($defensiveInnings, $requiredDefensiveInnings);
                    $fairPlayLabel = generate_fair_play_status_label($defensiveInnings, $requiredDefensiveInnings);
                  ?>
                  <span class="pill <?= h($fairPlayClass) ?>">
                    <?= h($fairPlayLabel) ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>



      <?php if (($selectedGame['status'] ?? '') === 'generated'): ?>
        <div class="actions-row" style="margin-top:16px;">
          <a class="btn btn-secondary" href="manual_lineup.php?game_id=<?= (int)$selectedGame['id'] ?>">
            Edit Lineup Manually
          </a>
          <a class="btn" href="lock.php?game_id=<?= (int)$selectedGame['id'] ?>">Finalize Game</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
  const generatorForm = document.querySelector(
    'form[action="generate.php"] input[name="action"][value="generate_lineup"]'
  )?.closest('form');

  if (!generatorForm) {
    return;
  }

  generatorForm.addEventListener('submit', function () {
    const isMobile = window.matchMedia('(max-width: 760px)').matches;

    const desktopFields = generatorForm.querySelectorAll(
      '.battery-planner .desktop-table select'
    );

    const mobileFields = generatorForm.querySelectorAll(
      '.battery-planner .mobile-cards select'
    );

    desktopFields.forEach(function (field) {
      field.disabled = isMobile;
    });

    mobileFields.forEach(function (field) {
      field.disabled = !isMobile;
    });
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function validateBatteryInning(inning) {
    const visiblePitchers = Array.from(
      document.querySelectorAll(
        '.battery-position-select[data-position="P"][data-inning="' + inning + '"]'
      )
    ).filter(function (field) {
      return field.offsetParent !== null;
    });

    const visibleCatchers = Array.from(
      document.querySelectorAll(
        '.battery-position-select[data-position="C"][data-inning="' + inning + '"]'
      )
    ).filter(function (field) {
      return field.offsetParent !== null;
    });

    const pitcher = visiblePitchers[0] || null;
    const catcher = visibleCatchers[0] || null;

    if (!pitcher || !catcher) {
      return;
    }

    pitcher.setCustomValidity('');
    catcher.setCustomValidity('');

    if (
      pitcher.value !== '' &&
      catcher.value !== '' &&
      pitcher.value === catcher.value
    ) {
      const message =
        'The same player cannot pitch and catch in inning ' + inning + '.';

      pitcher.setCustomValidity(message);
      catcher.setCustomValidity(message);
    }
  }

  document.querySelectorAll('.battery-position-select').forEach(function (field) {
    field.addEventListener('change', function () {
      validateBatteryInning(this.dataset.inning);
    });
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
