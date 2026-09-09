<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$teamId = current_team_id();

$availableGames = [];

$stmt = db()->prepare("
    SELECT
        id,
        game_id,
        game_date
    FROM games
    WHERE team_id = :team_id
      AND deleted_at IS NULL
      AND status <> 'locked'
    ORDER BY
        CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
        game_date DESC,
        id DESC
");

$stmt->execute([
    'team_id' => $teamId,
]);

$availableGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labelMode = function_exists('get_team_player_label_mode')
    ? get_team_player_label_mode($teamId)
    : 'both';

if ($labelMode === '') {
    $labelMode = 'both';
}
$pageTitle = 'Manual Lineup Edit';
$currentPage = 'manual_lineup';
$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : (int)($_POST['game_id'] ?? 0);
if ($gameId <= 0) {
    require_once __DIR__ . '/includes/header.php';
    ?>

    <h1 class="page-title brand-title-font">Manual Lineup</h1>

    <div class="card" style="max-width:500px;">
      <h2>Select Game</h2>

      <?php if (empty($availableGames)): ?>
        <p class="muted">No games available.</p>

        <div class="actions-row">
          <a class="btn" href="games.php">Back to Games</a>
        </div>

      <?php else: ?>

        <form method="get">
            <?= csrf_field() ?>
          <label style="display:block; margin-bottom:16px;">
            <span>Select Game</span>

            <select
              name="game_id"
              required
              style="width:100%; margin-top:8px;"
            >
              <option value="">Choose a game</option>

              <?php foreach ($availableGames as $game): ?>
                <option value="<?= (int)$game['id'] ?>">
                  <?= h((string)$game['game_id']) ?>

                  <?php if (!empty($game['game_date'])): ?>
                    | <?= h((string)$game['game_date']) ?>
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="actions-row">
            <button type="submit">Open Manual Lineup</button>
            <a class="btn btn-secondary" href="games.php">Cancel</a>
          </div>
        </form>

      <?php endif; ?>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$action = (string)($_POST['action'] ?? '');

$game = null;
$roster = [];
$lineupResult = null;
$positions = [];
$displayPositions = [];
$innings = 0;
$benchCount = 0;
$duplicateAssignments = [];
$lineupWarnings = [];
$lineupErrors = [];
$lineupSoftWarnings = [];
$fairness = null;
$historyRows = [];
$coachNotes = [];
$rosterMap = [];
$message = '';
$error = '';
$lineupSuggestions = [];
$lateInningPlan = null;
$isEightPlayerMode = false;
$validationMode = (string)($_POST['validation_mode'] ?? 'coach');
$statusMessage = (string)($_GET['msg'] ?? '');
$redirectUrl = 'manual_lineup.php?game_id=' . (int)$gameId;

if ($statusMessage === 'saved') {
    flash_redirect('ok', 'Lineup updated successfully.', $redirectUrl);
} elseif ($statusMessage === 'saved_ignored_suggestions') {
    flash_redirect('ok', 'Lineup saved. Smart suggestions were ignored.', $redirectUrl);
} elseif ($statusMessage === 'restored') {
    flash_redirect('ok', 'Lineup restored successfully.', $redirectUrl);
} elseif ($statusMessage === 'suggestion_applied') {
    flash_redirect('ok', 'Suggestion applied successfully.', $redirectUrl);
} elseif ($statusMessage === 'template_saved') {
    flash_redirect('ok', 'Template saved successfully.', $redirectUrl);
} elseif ($statusMessage === 'template_applied') {
    flash_redirect('ok', 'Template applied successfully.', $redirectUrl);


} elseif ($statusMessage === 'player_removed') {
    $message = 'Player removed from this game lineup.';
} elseif ($statusMessage === 'player_added') {
    $message = 'Player added back to this game lineup.';
} elseif ($statusMessage === 'coach_note_added') {
    $message = 'Coach note added.';
} elseif ($statusMessage === 'coach_note_deleted') {
    $message = 'Coach note deleted.';
} elseif ($statusMessage === 'late_innings_cleared') {
    $message = 'Innings 6 and 7 were cleared. Use the Late Inning Planner to finish the game while staying within fair-play rules.';
}
function fairness_score_class(int $score): string
{
    if ($score >= 90) {
        return 'fairness-good';
    }
    if ($score >= 75) {
        return 'fairness-medium';
    }
    return 'fairness-low';
}

function fairness_score_label(int $score): string
{
    if ($score >= 90) {
        return 'Excellent';
    }
    if ($score >= 75) {
        return 'Good';
    }
    return 'Needs Review';
}
function manual_lineup_history_author_label(array $history): string
{
    $fullName = trim((string)($history['user_full_name'] ?? ''));

    if ($fullName !== '') {
        return $fullName;
    }

    $email = trim((string)($history['user_email'] ?? ''));

    if ($email !== '') {
        return $email;
    }

    return 'Coach';
}

function manual_lineup_datetime_label(?string $datetime): string
{
    $datetime = trim((string)$datetime);

    if ($datetime === '') {
        return 'Unknown time';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return $datetime;
    }

    return date('M j, Y g:i A', $timestamp);
}
function suggestion_badge_label(array $suggestion): string
{
    $type = (string)($suggestion['type'] ?? '');

    return match ($type) {
        'bench_streak' => 'Bench',
        'heavy_role_usage' => (($suggestion['position'] ?? '') === 'P' ? 'Pitching' : 'Catching'),
        'position_overuse' => 'Rotation',
        default => 'Suggestion',
    };
}

function suggestion_badge_class(array $suggestion): string
{
    $type = (string)($suggestion['type'] ?? '');

    return match ($type) {
        'bench_streak' => 'suggestion-badge-bench',
        'heavy_role_usage' => (($suggestion['position'] ?? '') === 'P' ? 'suggestion-badge-pitching' : 'suggestion-badge-catching'),
        'position_overuse' => 'suggestion-badge-rotation',
        default => 'suggestion-badge-default',
    };
}

function apply_lineup_suggestion_to_result(array $lineupResult, array $suggestion): array
{
    $inning = (int)($suggestion['inning'] ?? 0);
    $incomingPlayerId = (int)($suggestion['player_id'] ?? 0);
    $outgoingPlayerId = (int)($suggestion['swap_with_player_id'] ?? 0);
    $position = (string)($suggestion['position'] ?? '');

    if ($inning <= 0 || $incomingPlayerId <= 0 || $outgoingPlayerId <= 0 || $position === '') {
        throw new RuntimeException('Suggestion data is incomplete.');
    }

    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];
    $positions = $lineupResult['positions'] ?? [];
    $benchCount = (int)($lineupResult['bench_count'] ?? 0);

    $outgoingCell = $lineupGrid[$position][$inning] ?? null;
    if (!is_array($outgoingCell) || (int)($outgoingCell['id'] ?? 0) !== $outgoingPlayerId) {
        throw new RuntimeException('Could not find the expected field player for this suggestion.');
    }

    for ($slot = 0; $slot < $benchCount; $slot++) {
        $benchCell = $benchGrid[$slot][$inning] ?? null;

        if (is_array($benchCell) && (int)($benchCell['id'] ?? 0) === $incomingPlayerId) {
            $lineupGrid[$position][$inning] = $benchCell;
            $benchGrid[$slot][$inning] = $outgoingCell;

            $lineupResult['lineup_grid'] = $lineupGrid;
            $lineupResult['bench_grid'] = $benchGrid;

            return $lineupResult;
        }
    }

    foreach ($positions as $otherPosition) {
        $fieldCell = $lineupGrid[$otherPosition][$inning] ?? null;

        if (!is_array($fieldCell) || (int)($fieldCell['id'] ?? 0) !== $incomingPlayerId) {
            continue;
        }

        $lineupGrid[$position][$inning] = $fieldCell;
        $lineupGrid[$otherPosition][$inning] = $outgoingCell;

        $lineupResult['lineup_grid'] = $lineupGrid;
        $lineupResult['bench_grid'] = $benchGrid;

        return $lineupResult;
    }

    throw new RuntimeException('Could not find the expected replacement player for this suggestion.');
}

function apply_bench_streak_suggestion_to_result(array $lineupResult, array $suggestion): array
{
    $inning = (int)($suggestion['inning'] ?? 0);
    $benchPlayerId = (int)($suggestion['player_id'] ?? 0);
    $fieldPlayerId = (int)($suggestion['swap_with_player_id'] ?? 0);
    $position = (string)($suggestion['position'] ?? '');

    if ($inning <= 0 || $benchPlayerId <= 0 || $fieldPlayerId <= 0 || $position === '') {
        throw new RuntimeException('Suggestion data is incomplete.');
    }

    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];
    $benchCount = (int)($lineupResult['bench_count'] ?? 0);

    $fieldCell = $lineupGrid[$position][$inning] ?? null;
    if (!is_array($fieldCell) || (int)($fieldCell['id'] ?? 0) !== $fieldPlayerId) {
        throw new RuntimeException('Could not find the expected field player for this suggestion.');
    }

    $benchSlot = null;
    $benchCell = null;

    for ($slot = 0; $slot < $benchCount; $slot++) {
        $cell = $benchGrid[$slot][$inning] ?? null;
        if (is_array($cell) && (int)($cell['id'] ?? 0) === $benchPlayerId) {
            $benchSlot = $slot;
            $benchCell = $cell;
            break;
        }
    }

    if ($benchSlot === null || !is_array($benchCell)) {
        throw new RuntimeException('Could not find the expected bench player for this suggestion.');
    }

    $lineupGrid[$position][$inning] = $benchCell;
    $benchGrid[$benchSlot][$inning] = $fieldCell;

    $lineupResult['lineup_grid'] = $lineupGrid;
    $lineupResult['bench_grid'] = $benchGrid;

    return $lineupResult;
}

function find_duplicate_assignments(array $postedLineup, array $postedBench, array $positions, int $innings, int $benchCount): array
{
    $duplicates = [];

    for ($inning = 1; $inning <= $innings; $inning++) {
        $counts = [];

        foreach ($positions as $position) {
            $playerId = (int)($postedLineup[$position][$inning] ?? 0);
            if ($playerId > 0) {
                $counts[$playerId] = ($counts[$playerId] ?? 0) + 1;
            }
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $playerId = (int)($postedBench[$slot][$inning] ?? 0);
            if ($playerId > 0) {
                $counts[$playerId] = ($counts[$playerId] ?? 0) + 1;
            }
        }

        foreach ($counts as $playerId => $count) {
            if ($count > 1) {
                $duplicates[$inning][$playerId] = true;
            }
        }
    }

    return $duplicates;
}

function build_lineup_result_from_post(
    array $postedLineup,
    array $postedBench,
    array $roster,
    array $positions,
    int $innings,
    int $benchCount
): array {
    $rosterMap = [];

    foreach ($roster as $player) {
        $rosterMap[(int)$player['id']] = [
            'id' => (int)$player['id'],
            'name' => player_full_name($player),
            'first_name' => (string)($player['first_name'] ?? ''),
            'last_name' => (string)($player['last_name'] ?? ''),
            'jersey_number' => (string)($player['jersey_number'] ?? ''),
        ];
    }

    $lineupGrid = [];
    foreach ($positions as $position) {
        for ($inning = 1; $inning <= $innings; $inning++) {
            $playerId = (int)($postedLineup[$position][$inning] ?? 0);
            $lineupGrid[$position][$inning] = $rosterMap[$playerId] ?? null;
        }
    }

    $benchGrid = [];
    for ($slot = 0; $slot < $benchCount; $slot++) {
        for ($inning = 1; $inning <= $innings; $inning++) {
            $playerId = (int)($postedBench[$slot][$inning] ?? 0);
            $benchGrid[$slot][$inning] = $rosterMap[$playerId] ?? null;
        }
    }

    return [
        'lineup_grid' => $lineupGrid,
        'bench_grid' => $benchGrid,
        'positions' => $positions,
        'innings' => $innings,
        'bench_count' => $benchCount,
    ];
}
function analyze_late_inning_fair_play(
    array $lineupResult,
    array $roster,
    array $positions,
    int $innings,
    int $benchCount,
    int $lateStartInning = 6
): array {
    $playerMap = [];

    foreach ($roster as $player) {
        $playerId = (int)($player['id'] ?? 0);

        if ($playerId <= 0) {
            continue;
        }

        $playerMap[$playerId] = [
            'player' => $player,
            'played_innings' => 0,
            'bench_innings' => 0,
        ];
    }

    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];

    $blankLateInnings = [];

    for ($inning = 1; $inning <= $innings; $inning++) {
        $hasLateBlank = false;

        foreach ($positions as $position) {
            $cell = $lineupGrid[$position][$inning] ?? null;

            if (!is_array($cell) || (int)($cell['id'] ?? 0) <= 0) {
                if ($inning >= $lateStartInning) {
                    $hasLateBlank = true;
                }
                continue;
            }

            $playerId = (int)$cell['id'];

            if (isset($playerMap[$playerId])) {
                $playerMap[$playerId]['played_innings']++;
            }
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $cell = $benchGrid[$slot][$inning] ?? null;

            if (!is_array($cell) || (int)($cell['id'] ?? 0) <= 0) {
                if ($inning >= $lateStartInning) {
                    $hasLateBlank = true;
                }
                continue;
            }

            $playerId = (int)$cell['id'];

            if (isset($playerMap[$playerId])) {
                $playerMap[$playerId]['bench_innings']++;
            }
        }

        if ($inning >= $lateStartInning && $hasLateBlank) {
            $blankLateInnings[] = $inning;
        }
    }

    $blankLateInnings = array_values(array_unique($blankLateInnings));
    $remainingInnings = count($blankLateInnings);

    if ($remainingInnings <= 0) {
        return [
            'has_blank_late_innings' => false,
            'blank_innings' => [],
            'must_play' => [],
            'cannot_bench' => [],
            'can_bench' => [],
        ];
    }

    $activeRosterCount = count($roster);
    $fieldersPerInning = count($positions);

    $totalBenchSlots = max(0, ($activeRosterCount - $fieldersPerInning) * $innings);

    $maxBenchInnings = $activeRosterCount > 0
        ? (int)ceil($totalBenchSlots / $activeRosterCount)
        : 0;

    $minimumDefensiveInnings = max(0, $innings - $maxBenchInnings);

    $mustPlay = [];
    $cannotBench = [];
    $canBench = [];

    foreach ($playerMap as $playerId => $row) {
        $played = (int)$row['played_innings'];
        $benched = (int)$row['bench_innings'];

        $neededDefensiveInnings = max(0, $minimumDefensiveInnings - $played);
        $benchRoom = max(0, $maxBenchInnings - $benched);

        $item = [
            'player' => $row['player'],
            'played_innings' => $played,
            'bench_innings' => $benched,
            'needed_defensive_innings' => $neededDefensiveInnings,
            'bench_room' => $benchRoom,
            'max_bench_innings' => $maxBenchInnings,
            'minimum_defensive_innings' => $minimumDefensiveInnings,
        ];

        if ($neededDefensiveInnings >= $remainingInnings) {
            $mustPlay[] = $item;
            continue;
        }

        if ($benchRoom <= 0) {
            $cannotBench[] = $item;
            continue;
        }

        $canBench[] = $item;
    }

    usort($mustPlay, function (array $a, array $b): int {
        return $b['needed_defensive_innings'] <=> $a['needed_defensive_innings'];
    });

    usort($cannotBench, function (array $a, array $b): int {
        return $b['bench_innings'] <=> $a['bench_innings'];
    });

    usort($canBench, function (array $a, array $b): int {
        return $a['bench_room'] <=> $b['bench_room'];
    });

    return [
        'has_blank_late_innings' => true,
        'blank_innings' => $blankLateInnings,
        'remaining_innings' => $remainingInnings,
        'max_bench_innings' => $maxBenchInnings,
        'minimum_defensive_innings' => $minimumDefensiveInnings,
        'must_play' => $mustPlay,
        'cannot_bench' => $cannotBench,
        'can_bench' => $canBench,
    ];
}


function split_lineup_warnings(array $lineupWarnings, string $validationMode = 'coach'): array
{
    if ($validationMode === 'coach') {
        return [[], array_values($lineupWarnings)];
    }
    $lineupErrors = array_values(array_filter(
        $lineupWarnings,
        fn($warning) => ($warning['type'] ?? '') === 'error'
    ));
    $lineupSoftWarnings = array_values(array_filter(
        $lineupWarnings,
        fn($warning) => ($warning['type'] ?? '') !== 'error'
    ));
    return [$lineupErrors, $lineupSoftWarnings];
}

function build_posted_assignments_from_result(array $lineupResult): array
{
    $postedLineup = [];
    $postedBench = [];

    foreach (($lineupResult['lineup_grid'] ?? []) as $position => $inningsGrid) {
        foreach ($inningsGrid as $inningNumber => $cell) {
            $postedLineup[$position][(int)$inningNumber] = (int)($cell['id'] ?? 0);
        }
    }

    foreach (($lineupResult['bench_grid'] ?? []) as $slot => $inningsGrid) {
        foreach ($inningsGrid as $inningNumber => $cell) {
            $postedBench[(int)$slot][(int)$inningNumber] = (int)($cell['id'] ?? 0);
        }
    }

    return [$postedLineup, $postedBench];
}

function apply_template_to_current_game(int $teamId, int $gameId, array $template, array $roster): void
{
    $payload = json_decode((string)($template['lineup_json'] ?? ''), true);

    if (!is_array($payload)) {
        throw new RuntimeException('Template data is invalid.');
    }

    $lineupResult = $payload['lineup'] ?? $payload;

    if (!is_array($lineupResult)) {
        throw new RuntimeException('Template lineup data is invalid.');
    }

    [$postedLineup, $postedBench] = build_posted_assignments_from_result($lineupResult);

    save_manual_lineup($teamId, $gameId, $postedLineup, $postedBench);
}
function rebuild_bench_from_field_assignments(
    array $postedLineup,
    array $roster,
    array $positions,
    int $innings,
    int $benchCount
): array {
    $rosterIds = array_map(
        fn(array $player): int => (int)$player['id'],
        $roster
    );

    $postedBench = [];

    for ($inning = 1; $inning <= $innings; $inning++) {
        $fieldIds = [];

        foreach ($positions as $position) {
            $playerId = (int)($postedLineup[$position][$inning] ?? 0);

            if ($playerId > 0) {
                $fieldIds[$playerId] = true;
            }
        }

        $benchIds = [];

        foreach ($rosterIds as $playerId) {
            if (!isset($fieldIds[$playerId])) {
                $benchIds[] = $playerId;
            }
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $postedBench[$slot][$inning] = $benchIds[$slot] ?? 0;
        }
    }

    return $postedBench;
}
try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($gameId <= 0) {
        throw new RuntimeException('No game selected.');
    }

    $game = get_game_by_id($teamId, $gameId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    if (($game['status'] ?? '') === 'locked') {
        throw new RuntimeException('Locked games cannot be edited.');
    }

    $roster = get_game_roster($teamId, $gameId);
    $rosterMap = manual_roster_player_map($roster);
    if (count($roster) < 8) {
        throw new RuntimeException('This game needs at least 8 rostered players.');
    }

    $lineupResult = get_generated_lineup_result($teamId, $gameId);
    if (!is_array($lineupResult)) {
        throw new RuntimeException('No saved lineup found for this game.');
    }

    $positions = $lineupResult['positions'] ?? game_defensive_positions_for_roster_count(count($roster));
    $displayPositions = game_display_positions_for_roster_count(count($roster));
    $isEightPlayerMode = count($positions) === 8;
    $innings = (int)($lineupResult['innings'] ?? ($game['innings'] ?? 7));
    $activePositionCount = count($positions);
    $activeRosterCount = count($roster);
    $benchCount = max(0, $activeRosterCount - $activePositionCount);
    $lineupResult['bench_count'] = $benchCount;
    if (isset($lineupResult['bench_grid']) && is_array($lineupResult['bench_grid'])) {
        $lineupResult['bench_grid'] = array_slice(
            $lineupResult['bench_grid'],
            0,
            $benchCount,
            true
        );
    }
    $lineupResult['bench_count'] = $benchCount;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    if ($action === 'clear_late_innings') {
        $currentSavedLineup = get_generated_lineup_result(
            $teamId,
            $gameId
        );

        if (!is_array($currentSavedLineup)) {
            throw new RuntimeException(
                'No saved lineup found to clear.'
            );
        }

        save_lineup_history_snapshot(
            $teamId,
            $gameId,
            $currentSavedLineup,
            current_user_id()
        );

        /*
        |--------------------------------------------------------------------------
        | Read the currently selected manual lineup
        |--------------------------------------------------------------------------
        */
        $postedLineup = $_POST['lineup'] ?? [];
        $postedBench = $_POST['bench'] ?? [];

        if (!is_array($postedLineup)) {
            $postedLineup = [];
        }

        if (!is_array($postedBench)) {
            $postedBench = [];
        }

        /*
        |--------------------------------------------------------------------------
        | Fall back to the saved lineup if the form fields were not submitted
        |--------------------------------------------------------------------------
        */
        if (empty($postedLineup)) {
            [$postedLineup, $postedBench] =
                build_posted_assignments_from_result(
                    $currentSavedLineup
                );
        }

        if (empty($postedBench)) {
            $postedBench = rebuild_bench_from_field_assignments(
                $postedLineup,
                $roster,
                $positions,
                $innings,
                $benchCount
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Build a result using the current selections
        |--------------------------------------------------------------------------
        */
        $updatedLineupResult = build_lineup_result_from_post(
            $postedLineup,
            $postedBench,
            $roster,
            $positions,
            $innings,
            $benchCount
        );

        /*
        |--------------------------------------------------------------------------
        | Clear innings 6 and later
        |--------------------------------------------------------------------------
        */
        for ($inning = 6; $inning <= $innings; $inning++) {
            foreach ($positions as $position) {
                $updatedLineupResult['lineup_grid'][$position][$inning] = null;
            }

            for ($slot = 0; $slot < $benchCount; $slot++) {
                $updatedLineupResult['bench_grid'][$slot][$inning] = null;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save directly because save_manual_lineup() rejects blank innings
        |--------------------------------------------------------------------------
        */
        save_generated_lineup(
            $teamId,
            $gameId,
            $updatedLineupResult
        );

        $stmt = db()->prepare("
            DELETE FROM lineup_drafts
            WHERE user_id = :user_id
              AND team_id = :team_id
              AND draft_key = :draft_key
        ");

        $stmt->execute([
            'user_id' => current_user_id(),
            'team_id' => $teamId,
            'draft_key' => 'manual_lineup_' . $gameId,
        ]);

        header(
            'Location: manual_lineup.php?game_id=' .
            $gameId .
            '&msg=late_innings_cleared' .
            '&skip_draft=1'
        );
        exit;
    }
    if ($action === 'add_coach_note') {
        $noteText = (string)($_POST['coach_note'] ?? '');
        $userId = function_exists('current_user_id') ? current_user_id() : null;

        add_lineup_coach_note(
            $teamId,
            $gameId,
            $userId ? (int)$userId : null,
            $noteText
        );

        header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=coach_note_added');
        exit;
    }

    if ($action === 'delete_coach_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);

        delete_lineup_coach_note($teamId, $noteId);

        header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=coach_note_deleted');
        exit;
    }
    if ($action === 'remove_player_from_game') {
        $removePlayerId = (int)($_POST['remove_player_id'] ?? 0);

        if ($removePlayerId <= 0) {
            throw new RuntimeException('Invalid player selected.');
        }

        remove_player_from_game_lineup($teamId, $gameId, $removePlayerId);

        db()->prepare("
            DELETE FROM lineup_drafts
            WHERE user_id = :user_id
              AND team_id = :team_id
              AND draft_key = :draft_key
        ")->execute([
            'user_id' => current_user_id(),
            'team_id' => $teamId,
            'draft_key' => 'manual_lineup_' . $gameId,
        ]);

        header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=player_removed');
        exit;
    }

        if ($action === 'add_player_to_game') {
            $addPlayerId = (int)($_POST['add_player_id'] ?? 0);

            if ($addPlayerId <= 0) {
                throw new RuntimeException('Invalid player selected.');
            }

            add_player_to_game_lineup($teamId, $gameId, $addPlayerId);

            db()->prepare("
                DELETE FROM lineup_drafts
                WHERE user_id = :user_id
                  AND team_id = :team_id
                  AND draft_key = :draft_key
            ")->execute([
                'user_id' => current_user_id(),
                'team_id' => $teamId,
                'draft_key' => 'manual_lineup_' . $gameId,
            ]);

            header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=player_added&skip_draft=1');
            exit;
        }
        if ($action === 'apply_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $template = get_lineup_template_by_id($teamId, $templateId);

            if (!$template) {
                throw new RuntimeException('Template not found.');
            }

            $currentSavedLineup = get_generated_lineup_result($teamId, $gameId);
            if (is_array($currentSavedLineup)) {
                save_lineup_history_snapshot($teamId, $gameId, $currentSavedLineup, current_user_id());
            }

            apply_template_to_current_game($teamId, $gameId, $template, $roster);

            header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=template_applied');
            exit;
        }
        if ($action === 'save_template' && isset($_POST['template_name'])) {

                    $templateName = trim((string)($_POST['template_name'] ?? ''));
                    if ($templateName === '') {
                        throw new RuntimeException('Template name is required.');
                    }
                    assert_team_limit_available(
                        $teamId,
                        'lineup_templates_per_team',
                        count_team_lineup_templates_for_limit($teamId),
                        'Your current plan has reached its lineup template limit. Upgrade to save more templates.'
                    );
                    save_lineup_template(
                        $teamId,
                        $templateName,
                        $lineupResult,
                        count($roster)
                    );
                    header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=template_saved');
                    exit;
                }
        if ($action === 'restore_history') {
            $historyId = (int)($_POST['history_id'] ?? 0);

            if ($historyId <= 0) {
                throw new RuntimeException('Invalid history selection.');
            }

            restore_lineup_history_snapshot($teamId, $historyId);

            header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=restored');
            exit;
        }

        if ($action === 'apply_suggestion') {
            $suggestionType = (string)($_POST['suggestion_type'] ?? '');
            $inning = (int)($_POST['suggestion_inning'] ?? 0);
            $playerId = (int)($_POST['suggestion_player_id'] ?? 0);
            $swapWithPlayerId = (int)($_POST['suggestion_swap_with_player_id'] ?? 0);
            $position = (string)($_POST['suggestion_position'] ?? '');

            if (!in_array($suggestionType, ['bench_streak', 'position_overuse'], true)) {
                throw new RuntimeException('This suggestion type cannot be applied right now.');
            }

            $suggestion = [
                'type' => $suggestionType,
                'inning' => $inning,
                'player_id' => $playerId,
                'swap_with_player_id' => $swapWithPlayerId,
                'position' => $position,
            ];

            if ($suggestionType === 'bench_streak') {
                $updatedLineupResult = apply_bench_streak_suggestion_to_result($lineupResult, $suggestion);
            } else {
                $updatedLineupResult = apply_lineup_suggestion_to_result($lineupResult, $suggestion);
            }

            [$postedLineup, $postedBench] = build_posted_assignments_from_result($updatedLineupResult);
            $previewWarnings = analyze_lineup_warnings($updatedLineupResult);
            $previewWarnings = manual_fix_lineup_warning_player_labels($previewWarnings, $rosterMap, $labelMode);

            [$previewErrors] = split_lineup_warnings($previewWarnings);

            if (!empty($previewErrors)) {
                $lineupResult = $updatedLineupResult;
                $lineupWarnings = $previewWarnings;
                $fairness = calculate_lineup_fairness($lineupResult);
                $lineupSuggestions = generate_lineup_suggestions($lineupResult, $roster);
                $duplicateAssignments = find_duplicate_assignments($postedLineup, $postedBench, $positions, $innings, $benchCount);

                throw new RuntimeException('This suggestion could not be applied because it creates lineup errors.');
            }

            $currentSavedLineup = get_generated_lineup_result($teamId, $gameId);

            if (is_array($currentSavedLineup)) {
                save_lineup_history_snapshot($teamId, $gameId, $currentSavedLineup, current_user_id());
            }

            save_manual_lineup($teamId, $gameId, $postedLineup, $postedBench);

            header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=suggestion_applied');
            exit;
        }

        if ($action === 'save_manual_lineup' || $action === 'save_manual_lineup_ignore_suggestions') {
            $postedLineup = $_POST['lineup'] ?? [];
            if (!is_array($postedLineup)) {
                $postedLineup = [];
            }

            $postedBench = $_POST['bench'] ?? [];
            if (!is_array($postedBench)) {
                $postedBench = [];
            }

            if (empty($postedBench)) {
                $postedBench = rebuild_bench_from_field_assignments(
                    $postedLineup,
                    $roster,
                    $positions,
                    $innings,
                    $benchCount
                );
            }

            $previewLineupResult = build_lineup_result_from_post(
                $postedLineup,
                $postedBench,
                $roster,
                $positions,
                $innings,
                $benchCount
            );

            $lineupWarnings = analyze_lineup_warnings($previewLineupResult);
            $lineupWarnings = manual_fix_lineup_warning_player_labels($lineupWarnings, $rosterMap, $labelMode);

            $fairness = calculate_lineup_fairness($previewLineupResult);
            [$lineupErrors, $lineupSoftWarnings] = split_lineup_warnings($lineupWarnings);


            $duplicateAssignments = find_duplicate_assignments(
                $postedLineup,
                $postedBench,
                $positions,
                $innings,
                $benchCount
            );
            $lineupResult = $previewLineupResult;
            $lineupSuggestions = generate_lineup_suggestions($lineupResult, $roster);
            $currentSavedLineup = get_generated_lineup_result($teamId, $gameId);

            if (is_array($currentSavedLineup)) {
                save_lineup_history_snapshot($teamId, $gameId, $currentSavedLineup, current_user_id());
            }
            $battingOrder = $_POST['batting_order'] ?? [];
            if (is_array($battingOrder)) {
                $battingOrder = array_values(array_filter(array_map('intval', $battingOrder)));
                if (count($battingOrder) !== count($roster)) {
                    throw new RuntimeException('Batting order must include every rostered player exactly once.');
                }
                if (count(array_unique($battingOrder)) !== count($battingOrder)) {
                    throw new RuntimeException('Batting order has duplicate players.');
                }
                update_game_batting_order($teamId, $gameId, $battingOrder);
            }
            save_manual_lineup($teamId, $gameId, $postedLineup, $postedBench);

            $stmt = db()->prepare("
                DELETE FROM lineup_drafts
                WHERE user_id = :user_id
                  AND team_id = :team_id
                  AND draft_key = :draft_key
            ");

            $stmt->execute([
                'user_id' => current_user_id(),
                'team_id' => $teamId,
                'draft_key' => 'manual_lineup_' . $gameId,
            ]);
            header('Location: manual_lineup.php?game_id=' . $gameId . '&msg=saved');
            exit;
        }
    }

    [$initialPostedLineup, $initialPostedBench] = build_posted_assignments_from_result($lineupResult);

    $duplicateAssignments = find_duplicate_assignments(
        $initialPostedLineup,
        $initialPostedBench,
        $positions,
        $innings,
        $benchCount
    );

    $lineupWarnings = analyze_lineup_warnings($lineupResult);
    $lineupWarnings = manual_fix_lineup_warning_player_labels($lineupWarnings, $rosterMap, $labelMode);

    $fairness = calculate_lineup_fairness($lineupResult);
    [$lineupErrors, $lineupSoftWarnings] = split_lineup_warnings($lineupWarnings, $validationMode);
    $lineupSuggestions = generate_lineup_suggestions($lineupResult, $roster);

    $lateInningPlan = analyze_late_inning_fair_play(
        $lineupResult,
        $roster,
        $positions,
        $innings,
        $benchCount
    );

    $coachNotes = get_lineup_coach_notes($teamId, $gameId);
    $historyRows = get_lineup_history_for_game($teamId, $gameId);

    $manualHistoryLimit = null;

    if (
        function_exists('billing_enforcement_enabled') &&
        billing_enforcement_enabled()
    ) {
        $manualHistoryLimit = team_manual_lineup_history_limit($teamId);

        if ($manualHistoryLimit !== null) {
            $historyRows = array_slice($historyRows, 0, $manualHistoryLimit);
        }
    }
    foreach ($historyRows as &$row) {
        $decoded = json_decode((string)($row['lineup_json'] ?? ''), true);
        $row['fairness'] = is_array($decoded) ? ($decoded['fairness'] ?? null) : null;
    }
    unset($row);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
function manual_roster_player_map(array $roster): array
{
    $map = [
        'by_id' => [],
        'by_name' => [],
    ];

    foreach ($roster as $player) {
        $id = (int)($player['id'] ?? 0);
        $name = strtolower(trim(player_full_name($player)));

        if ($id > 0) {
            $map['by_id'][$id] = $player;
        }

        if ($name !== '') {
            $map['by_name'][$name] = $player;
        }
    }

    return $map;
}

function manual_fairness_player_label(array $fairnessRow, array $rosterMap, string $labelMode): string
{
    $playerId = (int)(
        $fairnessRow['id']
        ?? $fairnessRow['player_id']
        ?? $fairnessRow['playerId']
        ?? $fairnessRow['player_db_id']
        ?? 0
    );

    if ($playerId > 0 && isset($rosterMap['by_id'][$playerId])) {
        return format_player_label($rosterMap['by_id'][$playerId], $labelMode);
    }

    $name = strtolower(trim((string)($fairnessRow['name'] ?? '')));

    if ($name !== '' && isset($rosterMap['by_name'][$name])) {
        return format_player_label($rosterMap['by_name'][$name], $labelMode);
    }

    return format_player_label($fairnessRow, $labelMode);
}
function manual_warning_player_label(int $playerId, array $rosterMap, string $labelMode): string
{
    if ($playerId > 0 && isset($rosterMap['by_id'][$playerId])) {
        return format_player_label($rosterMap['by_id'][$playerId], $labelMode);
    }

    return 'Unknown player';
}

function manual_fix_lineup_warning_player_labels(array $warnings, array $rosterMap, string $labelMode): array
{
    foreach ($warnings as &$warning) {
        $message = (string)($warning['message'] ?? '');

        if ($message === '') {
            continue;
        }

        $playerId = (int)(
            $warning['player_id']
            ?? $warning['player_db_id']
            ?? $warning['id']
            ?? 0
        );

        if ($playerId > 0 && isset($rosterMap['by_id'][$playerId])) {
            $playerLabel = manual_warning_player_label($playerId, $rosterMap, $labelMode);

            $message = preg_replace('/Player #\d+/i', $playerLabel, $message);
            $message = preg_replace('/(?<![A-Za-z0-9])#' . preg_quote((string)$playerId, '/') . '\b/', $playerLabel, $message);

            $warning['message'] = $message;
            continue;
        }

        $message = preg_replace_callback(
            '/Player #(\d+)/i',
            function (array $matches) use ($rosterMap, $labelMode): string {
                $matchedId = (int)$matches[1];

                if ($matchedId > 0 && isset($rosterMap['by_id'][$matchedId])) {
                    return manual_warning_player_label($matchedId, $rosterMap, $labelMode);
                }

                return 'Unknown player';
            },
            $message
        );

        $message = preg_replace_callback(
            '/(?<![A-Za-z0-9])#(\d+)\b/',
            function (array $matches) use ($rosterMap, $labelMode): string {
                $matchedId = (int)$matches[1];

                if ($matchedId > 0 && isset($rosterMap['by_id'][$matchedId])) {
                    return manual_warning_player_label($matchedId, $rosterMap, $labelMode);
                }

                return $matches[0];
            },
            $message
        );

        $warning['message'] = $message;
    }

    unset($warning);

    return $warnings;
}

function manual_position_data_value(array $player, string $key): string
{
    $positions = $player[$key] ?? [];

    if (is_string($positions)) {
        $positions = array_filter(array_map('trim', explode(',', $positions)));
    }

    if (!is_array($positions)) {
        return '';
    }

    $positions = array_values(array_unique(array_filter(array_map(
        fn($position): string => strtoupper(trim((string)$position)),
        $positions
    ))));

    return implode(',', $positions);
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Manual Lineup Edit</h1>

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

<?php if (!empty($lineupErrors) || !empty($lineupSoftWarnings)): ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Lineup Feedback</h2>

    <?php foreach ($lineupErrors as $warning): ?>
      <div class="msg err"><?= h((string)$warning['message']) ?></div>
    <?php endforeach; ?>

    <?php foreach ($lineupSoftWarnings as $warning): ?>
      <div class="msg warn"><?= h((string)$warning['message']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($game && $lineupResult !== null): ?>
<div class="account-grid">

    <div class="card lineup-notes-card">
      <h2>Coach Notes</h2>

      <p class="muted">
        Leave notes, reminders, or lineup tips for other coaches working on this game.
      </p>

      <form method="post" action="manual_lineup.php?game_id=<?= (int)$gameId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_coach_note">
        <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

        <label for="coach_note">Add Note</label>
        <textarea
          id="coach_note"
          name="coach_note"
          rows="3"
          maxlength="2000"
          placeholder="Example: Save Alex for Sunday. Give Hunter early innings. Bella should catch max 3 innings."
          required
        ></textarea>

        <div class="actions-row" style="margin-top:12px;">
          <button type="submit">Add Coach Note</button>
        </div>
      </form>

      <?php if (empty($coachNotes)): ?>
        <p class="muted" style="margin-top:16px;">No coach notes yet.</p>
      <?php else: ?>
        <div class="lineup-notes-list">
          <?php foreach ($coachNotes as $note): ?>
            <article class="lineup-note">
              <div class="lineup-note-meta">
                <strong><?= h(lineup_note_author_label($note)) ?></strong>
                <span><?= h(manual_lineup_datetime_label((string)$note['created_at'])) ?></span>
              </div>

              <p><?= nl2br(h((string)$note['note'])) ?></p>

              <form
                method="post"
                action="manual_lineup.php?game_id=<?= (int)$gameId ?>"
                style="margin-top:8px;"
              >
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_coach_note">
                <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">
                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">

                <button
                  type="submit"
                  class="btn btn-secondary"
                  onclick="return confirm('Delete this coach note?');"
                >
                  Delete
                </button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
<div class="card" style="margin-bottom:16px;">
    <h2>Load Template</h2>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="apply_template">
      <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

      <select name="template_id" required>
        <option value="">-- Select Template --</option>
        <?php foreach (get_lineup_templates($teamId) as $tpl): ?>
          <option value="<?= (int)$tpl['id'] ?>">
            <?= h((string)$tpl['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn">Apply Template</button>
    </form>
  </div>


  <div class="card" style="margin-bottom:16px;">
    <h2>Add Player To This Game</h2>
    <p class="muted">
      This adds an active player back to this game roster. You can then assign them manually.
    </p>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_player_to_game">
      <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

      <select name="add_player_id" required>
        <option value="">-- Select Player --</option>

        <?php
          $rosterIds = array_map(fn($p) => (int)$p['id'], $roster);
          $availablePlayers = array_filter(
              get_active_players($teamId),
              fn($p) => !in_array((int)$p['id'], $rosterIds, true)
          );
        ?>

        <?php foreach ($availablePlayers as $player): ?>
          <option value="<?= (int)$player['id'] ?>">
              <?= h(format_player_label($player, $labelMode)) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-secondary">Add To This Game</button>
    </form>
  </div>




    <div class="card" style="margin-bottom:16px;">
      <h2>Remove Player From This Game</h2>
      <p class="muted">
        This only removes the player from this game roster and generated lineup. It does not deactivate them.
      </p>

      <form method="post" action="manual_lineup.php" onsubmit="return confirm('Remove this player from this game lineup?');">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_player_from_game">
        <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

        <select name="remove_player_id" required>
          <option value="">-- Select Player --</option>
          <?php foreach ($roster as $player): ?>
            <option value="<?= (int)$player['id'] ?>">
                <?= h(format_player_label($player, $labelMode)) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-secondary">
          Remove From This Game
        </button>
      </form>
    </div>


</div>
<div class="card" style="margin-bottom:16px;">
  <h2>Save Current Lineup as Template</h2>

  <form method="post" action="manual_lineup.php">
      <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

    <div class="actions-row">
      <input
        type="text"
        name="template_name"
        placeholder="Template name"
        required
      >

      <button type="submit" class="btn btn-secondary">
        Save as Template
      </button>
    </div>
  </form>
</div>
<div class="card late-inning-planner-card" style="margin-bottom:16px;">
  <h2>Late Inning Planner</h2>

  <p class="muted">
    Leave innings 6 and 7 blank, then use this guide during the game to stay within fair-play rules.
  </p>

  <div style="margin-bottom:16px;">
      <button
          type="submit"
          class="btn btn-secondary"
          form="manual-lineup-form"
          name="action"
          value="clear_late_innings"
          onclick="return confirm(
              'Clear all assignments for innings 6 and 7? Your current selections for innings 1 through 5 will be preserved.'
          );"
      >
          Clear Innings 6–7
      </button>
  </div>

  <?php if (is_array($lateInningPlan) && !empty($lateInningPlan['has_blank_late_innings'])): ?>
    <div class="meta" style="margin-bottom:16px;">
      <div class="meta-box">
        <div class="meta-label">Blank Innings</div>
        <div class="meta-value">
          <?= h(implode(', ', array_map('strval', $lateInningPlan['blank_innings'] ?? []))) ?>
        </div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Minimum Defensive Innings</div>
        <div class="meta-value"><?= (int)($lateInningPlan['minimum_defensive_innings'] ?? 0) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Max Bench Innings</div>
        <div class="meta-value"><?= (int)($lateInningPlan['max_bench_innings'] ?? 0) ?></div>
      </div>
    </div>

    <?php if (!empty($lateInningPlan['must_play'])): ?>
      <div class="msg err">
        These players must play the remaining blank inning(s) to stay within fair-play rules.
      </div>

      <div class="late-planner-list">
        <?php foreach ($lateInningPlan['must_play'] as $item): ?>
          <div class="late-planner-item must-play">
            <strong><?= h(format_player_label($item['player'], $labelMode)) ?></strong>
            <span>
              Needs <?= (int)$item['needed_defensive_innings'] ?> more defensive inning<?= (int)$item['needed_defensive_innings'] === 1 ? '' : 's' ?>.
              Already benched <?= (int)$item['bench_innings'] ?>.
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($lateInningPlan['cannot_bench'])): ?>
      <div class="msg warn" style="margin-top:12px;">
        These players should not be benched again.
      </div>

      <div class="late-planner-list">
        <?php foreach ($lateInningPlan['cannot_bench'] as $item): ?>
          <div class="late-planner-item cannot-bench">
            <strong><?= h(format_player_label($item['player'], $labelMode)) ?></strong>
            <span>
              Already benched <?= (int)$item['bench_innings'] ?> of <?= (int)$item['max_bench_innings'] ?> allowed inning<?= (int)$item['max_bench_innings'] === 1 ? '' : 's' ?>.
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($lateInningPlan['can_bench'])): ?>
      <details style="margin-top:12px;">
        <summary>Players who can still be benched</summary>

        <div class="late-planner-list" style="margin-top:12px;">
          <?php foreach ($lateInningPlan['can_bench'] as $item): ?>
            <div class="late-planner-item can-bench">
              <strong><?= h(format_player_label($item['player'], $labelMode)) ?></strong>
              <span>
                Can sit <?= (int)$item['bench_room'] ?> more inning<?= (int)$item['bench_room'] === 1 ? '' : 's' ?>.
                Played <?= (int)$item['played_innings'] ?>.
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

  <?php else: ?>
    <p class="muted">
      Innings 6 and 7 are currently filled. Clear them if you want to plan those innings later.
    </p>
  <?php endif; ?>
</div>
<form method="post" action="manual_lineup.php" id="manual-lineup-form">
<?= csrf_field() ?>
  <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">

  <div class="card" style="margin-bottom:16px;">
    <h2>Edit Batting Order</h2>
    <p class="muted">Drag players to reorder the batting lineup.</p>

    <ul id="batting-order-list" class="batting-order-list">
      <?php foreach ($roster as $player): ?>
        <li class="batting-order-item" draggable="true">
          <span class="drag-handle">☰</span>
          <span>
              <?= h(format_player_label($player, $labelMode)) ?>

          </span>

          <input
            type="hidden"
            name="batting_order[]"
            value="<?= (int)$player['id'] ?>"
          >
        </li>
      <?php endforeach; ?>
    </ul>
  </div>


  <div class="card">


      <?php if (!empty($lineupSuggestions)): ?>
          <h2>Smart Suggestions</h2>

          <?php foreach ($lineupSuggestions as $suggestion): ?>
            <?php
              $type = (string)($suggestion['type'] ?? '');
              $canApply =
                in_array($type, ['bench_streak', 'position_overuse'], true) &&
                !empty($suggestion['swap_with_player_id']) &&
                !empty($suggestion['player_id']) &&
                !empty($suggestion['inning']) &&
                !empty($suggestion['position']);
            ?>

            <div class="smart-suggestion-item">
              <span class="suggestion-badge <?= h(suggestion_badge_class($suggestion)) ?>">
                <?= h(suggestion_badge_label($suggestion)) ?>
              </span>

              <div class="smart-suggestion-text">
                <?= h((string)$suggestion['message']) ?>
              </div>

              <?php if ($canApply): ?>
                <button
                  type="submit"
                  class="btn btn-secondary"
                  name="action"
                  value="apply_suggestion"
                  formaction="manual_lineup.php"
                  formmethod="post"
                  onclick="
                    this.form.suggestion_type.value='<?= h($type) ?>';
                    this.form.suggestion_inning.value='<?= (int)$suggestion['inning'] ?>';
                    this.form.suggestion_player_id.value='<?= (int)$suggestion['player_id'] ?>';
                    this.form.suggestion_swap_with_player_id.value='<?= (int)$suggestion['swap_with_player_id'] ?>';
                    this.form.suggestion_position.value='<?= h((string)$suggestion['position']) ?>';
                  "
                >
                  Apply
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

      <?php endif; ?>


  </div>



  <div class="card">
    <h2>
      <?= h((string)$game['game_id']) ?>
      <?php if (!empty($game['game_date'])): ?>
        | <?= h((string)$game['game_date']) ?>
      <?php endif; ?>
    </h2>



      <div style="margin-bottom:16px;">
        <label class="ios-toggle-wrap" for="swap_mode_toggle">
          <span class="ios-toggle-label">Swap mode</span>
          <span class="ios-toggle">
            <input type="checkbox" id="swap_mode_toggle" checked>
            <span class="ios-toggle-slider"></span>
          </span>
        </label>

        <p class="muted" style="margin-top:6px;">
          When enabled, choosing a player already used in the same inning will automatically swap the two players.
        </p>
      </div>

      <input type="hidden" name="suggestion_type" value="">
      <input type="hidden" name="suggestion_inning" value="">
      <input type="hidden" name="suggestion_player_id" value="">
      <input type="hidden" name="suggestion_swap_with_player_id" value="">
      <input type="hidden" name="suggestion_position" value="">

      <?php if ($isEightPlayerMode): ?>
        <div class="msg warn" style="margin-bottom:16px;">
          8-player mode is active. Center field is shown as open.
        </div>
      <?php endif; ?>
<div class="desktop-lineup-editor">
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
            <?php foreach ($displayPositions as $position): ?>
              <?php $isOpenCenterField = $isEightPlayerMode && $position === 'CF'; ?>
              <tr>
                <th><?= h((string)$position) ?></th>

                <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
                  <?php if ($isOpenCenterField): ?>
                    <td class="open-position-cell">
                      <div class="open-position-label">Open</div>
                    </td>
                  <?php else: ?>
                    <?php $selectedPlayerId = (int)($lineupResult['lineup_grid'][$position][$inning]['id'] ?? 0); ?>
                    <?php $isDuplicate = isset($duplicateAssignments[$inning][$selectedPlayerId]) && $selectedPlayerId > 0; ?>
                    <td>
                        <select
                          name="lineup[<?= h((string)$position) ?>][<?= $inning ?>]"
                          class="<?= $isDuplicate ? 'duplicate-select' : '' ?> lineup-select"
                          data-type="lineup"
                          data-position="<?= h((string)$position) ?>"
                          data-inning="<?= $inning ?>"
                        >
                        <option value="">-- Select Player --</option>
                        <?php foreach ($roster as $player): ?>
                          <?php
                            $playerId = (int)$player['id'];
                            $canPlayData = manual_position_data_value($player, 'can_play');
                            $cannotPlayData = manual_position_data_value($player, 'cannot_play');
                          ?>
                          <option
                            value="<?= $playerId ?>"
                            data-name="<?= h(format_player_label($player, $labelMode)) ?>"
                            data-first-name="<?= h((string)($player['first_name'] ?? '')) ?>"
                            data-last-name="<?= h((string)($player['last_name'] ?? '')) ?>"
                            data-number="<?= h((string)($player['jersey_number'] ?? '')) ?>"
                            data-can-play="<?= h($canPlayData) ?>"
                            data-cannot-play="<?= h($cannotPlayData) ?>"
                            <?= $selectedPlayerId === $playerId ? 'selected' : '' ?>
                          >
                              <?= h(format_player_label($player, $labelMode)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                      <?php if ($isDuplicate): ?>
                        <div class="duplicate-note">Duplicate</div>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                <?php endfor; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>





      <?php if ($benchCount > 0): ?>
        <div class="table-wrap" style="margin-top:20px;">
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
                    <?php $selectedBenchId = (int)($lineupResult['bench_grid'][$slot][$inning]['id'] ?? 0); ?>
                    <?php $isDuplicateBench = isset($duplicateAssignments[$inning][$selectedBenchId]) && $selectedBenchId > 0; ?>
                    <td>
                        <select
                          name="bench[<?= $slot ?>][<?= $inning ?>]"
                          class="<?= $isDuplicateBench ? 'duplicate-select' : '' ?> lineup-select"
                          data-type="bench"
                          data-slot="<?= $slot ?>"
                          data-inning="<?= $inning ?>"
                        >
                        <option value="">-- Select Player --</option>
                        <?php foreach ($roster as $player): ?>
                          <?php $playerId = (int)$player['id']; ?>
                          <option
                            value="<?= $playerId ?>"
                            data-name="<?= h(format_player_label($player, $labelMode)) ?>"
                            data-first-name="<?= h((string)($player['first_name'] ?? '')) ?>"
                            data-last-name="<?= h((string)($player['last_name'] ?? '')) ?>"
                            data-number="<?= h((string)($player['jersey_number'] ?? '')) ?>"
                            <?= $selectedBenchId === $playerId ? 'selected' : '' ?>
                          >
                              <?= h(format_player_label($player, $labelMode)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ($isDuplicateBench): ?>
                        <div class="duplicate-note">Duplicate</div>
                      <?php endif; ?>
                    </td>
                  <?php endfor; ?>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
</div>

        <div class="mobile-lineup-editor">
          <?php
            $mobileLineupGrid = $lineupResult['lineup_grid'] ?? [];
            $mobileBenchGrid = $lineupResult['bench_grid'] ?? [];
          ?>

          <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
            <section class="mobile-inning-card" data-inning="<?= (int)$inning ?>">
              <h3><?= h(ordinal((int)$inning)) ?> Inning</h3>

              <?php foreach ($positions as $position): ?>
                <?php
                  $cell = $mobileLineupGrid[$position][$inning] ?? null;

                  if (is_object($cell)) {
                      $cell = (array)$cell;
                  }

                  $currentPlayerId = is_array($cell)
                      ? (int)($cell['id'] ?? $cell['player_id'] ?? 0)
                      : 0;
                ?>

                <label class="mobile-position-row">
                  <span><?= h((string)$position) ?></span>

                  <select
                    name="lineup[<?= h((string)$position) ?>][<?= (int)$inning ?>]"
                    class="mobile-lineup-select lineup-select"
                    data-type="lineup"
                    data-position="<?= h((string)$position) ?>"
                    data-inning="<?= (int)$inning ?>"
                  >
                    <option value="">-- Select Player --</option>

                    <?php foreach ($roster as $player): ?>
                      <?php
                        $optionPlayerId = (int)($player['id'] ?? 0);
                        $canPlayData = manual_position_data_value($player, 'can_play');
                        $cannotPlayData = manual_position_data_value($player, 'cannot_play');
                      ?>
                      <option
                        value="<?= $optionPlayerId ?>"
                        data-name="<?= h(format_player_label($player, $labelMode)) ?>"
                        data-first-name="<?= h((string)($player['first_name'] ?? '')) ?>"
                        data-last-name="<?= h((string)($player['last_name'] ?? '')) ?>"
                        data-number="<?= h((string)($player['jersey_number'] ?? '')) ?>"
                        data-can-play="<?= h($canPlayData) ?>"
                        data-cannot-play="<?= h($cannotPlayData) ?>"
                        <?= $currentPlayerId === $optionPlayerId ? 'selected' : '' ?>
                      >
                          <?= h(format_player_label($player, $labelMode)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              <?php endforeach; ?>

              <?php if ($benchCount > 0): ?>
                <h4>Bench</h4>

                <?php for ($slot = 0; $slot < $benchCount; $slot++): ?>
                  <?php
                    $cell = $mobileBenchGrid[$slot][$inning] ?? null;

                    if (is_object($cell)) {
                        $cell = (array)$cell;
                    }

                    $currentPlayerId = is_array($cell)
                        ? (int)($cell['id'] ?? $cell['player_id'] ?? 0)
                        : 0;
                  ?>

                  <label class="mobile-position-row">
                    <span>B<?= (int)($slot + 1) ?></span>

                    <select
                      name="bench[<?= (int)$slot ?>][<?= (int)$inning ?>]"
                      class="mobile-lineup-select lineup-select"
                      data-type="bench"
                      data-slot="<?= (int)$slot ?>"
                      data-inning="<?= (int)$inning ?>"
                    >
                      <option value="">-- Select Player --</option>

                      <?php foreach ($roster as $player): ?>
                        <?php $optionPlayerId = (int)($player['id'] ?? 0); ?>
                        <option
                          value="<?= $optionPlayerId ?>"
                          data-name="<?= h(format_player_label($player, $labelMode)) ?>"
                          data-first-name="<?= h((string)($player['first_name'] ?? '')) ?>"
                          data-last-name="<?= h((string)($player['last_name'] ?? '')) ?>"
                          data-number="<?= h((string)($player['jersey_number'] ?? '')) ?>"
                          <?= $currentPlayerId === $optionPlayerId ? 'selected' : '' ?>
                        >
                            <?= h(format_player_label($player, $labelMode)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                <?php endfor; ?>
              <?php endif; ?>
            </section>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

      <div id="lineupDraftStatus" class="muted" style="margin-top:10px; font-size:13px;">
        Autosave ready
      </div>

      <?php if (!empty($lineupSuggestions) || !empty($lineupSoftWarnings)): ?>
      <div class="msg warn" style="margin-top:16px;">
        BenchBuddy has feedback for this lineup. You can still save manually.
      </div>
      <?php endif; ?>

      <div class="card" style="margin-top:16px;">
        <label for="validation_mode"><strong>Save mode</strong></label>
        <select name="validation_mode" id="validation_mode">
          <option value="coach" selected>Coach Mode - save with warnings</option>
          <option value="strict">Strict Mode - block lineup errors</option>
        </select>
        <div class="muted" style="margin-top:6px;">
          Coach Mode lets you save manually even when BenchBuddy has warnings or suggestions.
        </div>
      </div>

      <div class="actions-row" style="margin-top:20px;">
        <button type="submit" name="action" value="save_manual_lineup">
          Save Manual Lineup
        </button>

        <a class="btn btn-secondary" href="generate.php?game_id=<?= (int)$gameId ?>">Back to Build Lineup</a>
        <a class="btn" href="lock.php?game_id=<?= (int)$gameId ?>">Finalize Game</a>
      </div>
    </form>

  </div>

  <?php if (is_array($fairness)): ?>
    <div class="card" style="margin-bottom:16px;">
      <h2>Fairness Score</h2>

      <div class="meta" style="margin-bottom:16px;">
        <div class="meta-box">
          <div class="meta-label">Team Fairness</div>
          <div class="meta-value fairness-score <?= h(fairness_score_class((int)$fairness['team_score'])) ?>">
            <?= (int)$fairness['team_score'] ?>/100
          </div>
        </div>

        <div class="meta-box fairness-label <?= h(fairness_score_class((int)$fairness['team_score'])) ?>">
          <?= h(fairness_score_label((int)$fairness['team_score'])) ?>
          <div class="fairness-summary">
            <?= h(fairness_reason_summary($lineupWarnings)) ?>
          </div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Avg Bench Innings</div>
          <div class="meta-value"><?= h((string)$fairness['average_bench_innings']) ?></div>
        </div>

        <div class="meta-box">
          <div class="meta-label">Avg Defensive Innings</div>
          <div class="meta-value"><?= h((string)$fairness['average_defensive_innings']) ?></div>
        </div>
      </div>

      <div class="table-wrap desktop-only ">
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>Score</th>
              <th>Bench Innings</th>
              <th>Defensive Innings</th>
              <th>Longest Bench Streak</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($fairness['players'] ?? []) as $row): ?>
              <tr>
                <td>
                    <?= h(manual_fairness_player_label($row, $rosterMap, $labelMode)) ?>
                </td>
                <td>
                  <?php
                    $fairnessScore = (int)($row['fairness_score'] ?? 100);
                    $fairnessReasons = $row['fairness_reasons'] ?? ['Balanced defensive and bench usage.'];
                    $tooltipText = implode("\n", $fairnessReasons);
                  ?>

                  <span class="fairness-score <?= h(fairness_score_class($fairnessScore)) ?>">
                      <span class="fairness-tooltip-wrap" tabindex="0">
                      <span class="fairness-pill" title="<?= h($tooltipText) ?>">
                        <?= $fairnessScore ?>/100
                      </span>

                      <span class="fairness-tooltip">
                        <strong>Why this score</strong>
                        <?php foreach ($fairnessReasons as $i => $reason): ?>
                          <span>
                            <?= ($i + 1) ?>. <?= h((string)$reason) ?>
                          </span>
                        <?php endforeach; ?>
                      </span>
                    </span>
                  </span>
                </td>
                <td><?= (int)$row['bench_innings'] ?></td>
                <td><?= (int)$row['defensive_innings'] ?></td>
                <td><?= (int)$row['longest_bench_streak'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-only mobile-card-list">
        <?php foreach ($fairness['players'] ?? [] as $row): ?>
          <?php
            $fairnessScore = (int)($row['fairness_score'] ?? 100);
            $fairnessReasons = $row['fairness_reasons'] ?? ['Balanced defensive and bench usage.'];
          ?>

          <article class="mobile-card">
            <div class="mobile-card-header">
              <div>
                  <h3><?= h(format_player_label($row, $labelMode)) ?></h3>
                <?php if (!empty($row['jersey_number'])): ?>
                  <p>#<?= h((string)$row['jersey_number']) ?></p>
                <?php endif; ?>
              </div>

              <span class="fairness-score <?= h(fairness_score_class($fairnessScore)) ?>">
                <?= $fairnessScore ?>/100
              </span>
            </div>

            <div class="mobile-stat-grid">
              <div><strong>Defense</strong><span><?= (int)($row['defensive_innings'] ?? 0) ?></span></div>
              <div><strong>Bench</strong><span><?= (int)($row['bench_innings'] ?? 0) ?></span></div>
              <div><strong>Pitcher</strong><span><?= (int)($row['pitcher_innings'] ?? 0) ?></span></div>
              <div><strong>Catcher</strong><span><?= (int)($row['catcher_innings'] ?? 0) ?></span></div>
            </div>

            <div class="mobile-reasons">
              <strong>Why this score</strong>
              <?php foreach ($fairnessReasons as $i => $reason): ?>
                <p><?= ($i + 1) ?>. <?= h((string)$reason) ?></p>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($historyRows)): ?>
    <div class="card" style="margin-bottom:16px;">
      <h2>Lineup History</h2>
      <?php if (isset($manualHistoryLimit) && $manualHistoryLimit !== null): ?>
        <div class="msg info" style="margin-bottom:12px;">
          Your current plan shows your latest <?= (int)$manualHistoryLimit ?> saved lineup version<?= (int)$manualHistoryLimit === 1 ? '' : 's' ?>.
          <a href="billing.php?upgrade_reason=manual_lineup_history_items">Upgrade</a>
          for more manual lineup history.
        </div>
      <?php endif; ?>
      <?php $latest = $historyRows[0]; ?>

      <form method="post" style="margin-bottom:12px;">
          <?= csrf_field() ?>
        <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">
        <input type="hidden" name="action" value="restore_history">
        <input type="hidden" name="history_id" value="<?= (int)$latest['id'] ?>">
        <button type="submit" class="btn">Undo Last Save</button>
      </form>

      <div class="table-wrap desktop-only">
        <table>
          <thead>
              <tr>
                <th>Saved At</th>
                <th>Saved By</th>
                <th>Fairness</th>
                <th>Restore</th>
              </tr>
          </thead>
          <tbody>
            <?php foreach ($historyRows as $row): ?>
              <tr>
                  <td><?= h(manual_lineup_datetime_label((string)$row['created_at'])) ?></td>
                  <td><?= h(manual_lineup_history_author_label($row)) ?></td>
                  <td>
                  <?php if (!empty($row['fairness'])): ?>
                    <span class="fairness-score <?= h(fairness_score_class((int)$row['fairness']['team_score'])) ?>">
                      <?= (int)$row['fairness']['team_score'] ?>/100
                    </span>
                  <?php else: ?>
                    <span class="muted">–</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Restore this version?');">
                      <?= csrf_field() ?>
                    <input type="hidden" name="game_id" value="<?= (int)$gameId ?>">
                    <input type="hidden" name="action" value="restore_history">
                    <input type="hidden" name="history_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-secondary">Restore</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-only mobile-card-list">
        <?php foreach ($historyRows ?? [] as $history): ?>
          <article class="mobile-card">
            <div class="mobile-card-header">
              <div>
                <h3>Saved Lineup</h3>
                <p><?= h(manual_lineup_datetime_label((string)$history['created_at'])) ?></p>
                <p>Saved by <?= h(manual_lineup_history_author_label($history)) ?></p>

                <?php if (!empty($history['fairness'])): ?>
                  <span class="fairness-score <?= h(fairness_score_class((int)$history['fairness']['team_score'])) ?>">
                    <?= (int)$history['fairness']['team_score'] ?>/100
                  </span>
                <?php else: ?>
                  <span class="muted">–</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="mobile-card-actions">
              <form method="post">
                  <?= csrf_field() ?>
                <input type="hidden" name="history_id" value="<?= (int)$history['id'] ?>">
                <input type="hidden" name="action" value="restore_history">
                <button type="submit" class="btn-sm">Restore</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modeSelect = document.getElementById('player_label_mode');
  const swapToggle = document.getElementById('swap_mode_toggle');
  const selects = document.querySelectorAll('.lineup-select');
  const battingList = document.getElementById('batting-order-list');

  function optionListIncludes(value, listString) {
    if (!value || !listString) {
      return false;
    }

    return listString
      .split(',')
      .map(function (item) {
        return item.trim().toUpperCase();
      })
      .includes(value.trim().toUpperCase());
  }

  function selectedPlayerCannotPlay(select) {
    const position = (select.dataset.position || '').trim().toUpperCase();

    if (!position || select.dataset.type !== 'lineup') {
      return false;
    }

    const option = select.options[select.selectedIndex];

    if (!option || option.value === '') {
      return false;
    }

    const canPlay = option.dataset.canPlay || '';
    const cannotPlay = option.dataset.cannotPlay || '';

    if (optionListIncludes(position, cannotPlay)) {
      return true;
    }

    if (canPlay !== '' && !optionListIncludes(position, canPlay)) {
      return true;
    }

    return false;
  }

  function updatePositionWarnings() {
    document.querySelectorAll('.lineup-select[data-type="lineup"]').forEach(function (select) {
      const wrap = select.closest('td') || select.closest('.mobile-position-row');

      if (wrap) {
        const oldNote = wrap.querySelector('.position-warning-note');

        if (oldNote) {
          oldNote.remove();
        }
      }

      if (selectedPlayerCannotPlay(select)) {
        select.classList.add('position-warning');

        if (wrap) {
          const note = document.createElement('div');
          note.className = 'position-warning-note';
          note.textContent = 'Cannot play this position';
          wrap.appendChild(note);
        }
      } else {
        select.classList.remove('position-warning');
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
      });
    });

    battingList.addEventListener('dragover', function (event) {
      event.preventDefault();

      const afterElement = getDragAfterElement(battingList, event.clientY);

      if (!draggedItem) {
        return;
      }

      if (afterElement === null) {
        battingList.appendChild(draggedItem);
      } else {
        battingList.insertBefore(draggedItem, afterElement);
      }
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
  }
  if (!selects.length) {
    return;
  }

  function buildLabel(option, mode) {
      const fullName = option.dataset.name || option.textContent.trim();
      const firstName = option.dataset.firstName || '';
      const lastName = option.dataset.lastName || '';
      const number = option.dataset.number || '';

      if (option.value === '') {
        return '-- Select Player --';
      }

      if (mode === 'full_name') {
        return fullName;
      }

      if (mode === 'first_name') {
        return firstName !== '' ? firstName : fullName;
      }

      if (mode === 'last_name') {
        return lastName !== '' ? lastName : fullName;
      }

      if (mode === 'first_name_number') {
        if (firstName !== '' && number !== '') {
          return firstName + ' (#' + number + ')';
        }

        if (firstName !== '') {
          return firstName;
        }

        return fullName;
      }

      if (mode === 'number') {
        return number !== '' ? '#' + number : fullName;
      }

      if (mode === 'both') {
        return fullName;
      }

      return fullName;
  }

  function updateLabels() {
    const mode = modeSelect ? modeSelect.value : <?= json_encode($labelMode) ?>;

    selects.forEach(function (select) {
      Array.from(select.options).forEach(function (option) {
        option.textContent = buildLabel(option, mode);
      });
    });

  }

  if (modeSelect) {
    modeSelect.addEventListener('change', updateLabels);
  }

  function getInningSelects(inning, changedSelect) {
    return Array.from(selects).filter(function (select) {
      return select.dataset.inning === String(inning) && select !== changedSelect;
    });
  }

  selects.forEach(function (select) {
    select.addEventListener('focus', function () {
      this.dataset.previousValue = this.value;
    });

    if (!select.dataset.previousValue) {
      select.dataset.previousValue = select.value;
    }

    select.addEventListener('change', function () {
      const newValue = this.value;
      const oldValue = this.dataset.previousValue || '';
      const inning = this.dataset.inning;

      if (!swapToggle || !swapToggle.checked) {
        this.dataset.previousValue = newValue;
        return;
      }

      if (!newValue || !inning) {
        this.dataset.previousValue = newValue;
        return;
      }

      const inningSelects = getInningSelects(inning, this);

      let duplicateSelect = null;
      for (const other of inningSelects) {
        if (other.value === newValue) {
          duplicateSelect = other;
          break;
        }
      }

      if (duplicateSelect) {
        duplicateSelect.value = oldValue;
        duplicateSelect.dataset.previousValue = oldValue;
      }

      this.dataset.previousValue = this.value;
      updatePositionWarnings();
    });
  });

  updateLabels();
  updatePositionWarnings();
});

const battingList = document.getElementById('batting-order-list');

if (battingList && typeof Sortable !== 'undefined') {

  new Sortable(battingList, {
    animation: 150,
    handle: '.drag-handle',
    ghostClass: 'dragging',
    delay: 120,
    delayOnTouchOnly: true,
    touchStartThreshold: 5
  });
}

document.addEventListener('click', function (event) {
  const clickedTooltip = event.target.closest('.fairness-tooltip-wrap');

  document.querySelectorAll('.fairness-tooltip-wrap.is-pinned').forEach(function (wrap) {
    if (wrap !== clickedTooltip) {
      wrap.classList.remove('is-pinned');
    }
  });

  if (!clickedTooltip) {
    return;
  }

  clickedTooltip.classList.toggle('is-pinned');

  const tooltip = clickedTooltip.querySelector('.fairness-tooltip');

  if (tooltip) {
    tooltip.classList.remove('bottom');

    const rect = tooltip.getBoundingClientRect();

    if (rect.top < 10) {
      tooltip.classList.add('bottom');
    }
  }
});



document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('manual-lineup-form');

  if (!form) {
    return;
  }
  form.addEventListener('submit', function () {
    const isMobile = window.matchMedia('(max-width: 760px)').matches;

    const desktopInputs = form.querySelectorAll(
      '.desktop-lineup-editor select, .desktop-lineup-editor input'
    );

    const mobileInputs = form.querySelectorAll(
      '.mobile-lineup-editor select, .mobile-lineup-editor input'
    );

    if (isMobile) {
      desktopInputs.forEach(function (input) {
        input.disabled = true;
      });

      mobileInputs.forEach(function (input) {
        input.disabled = false;
      });
    } else {
      desktopInputs.forEach(function (input) {
        input.disabled = false;
      });

      mobileInputs.forEach(function (input) {
        input.disabled = true;
      });
    }
  });
});
</script>
<script>
  window.GAME_ID = <?= (int)$gameId ?>;
  window.CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
(function () {
  if (!window.CSRF_TOKEN) {
    console.error('CSRF token is missing.');
    return;
  }
  if (!window.GAME_ID || Number(window.GAME_ID) <= 0) {
    return;
  }
  const DRAFT_KEY = 'manual_lineup_' + window.GAME_ID;
  const statusEl = document.getElementById('lineupDraftStatus');
  let saveTimer = null;
  let isApplyingDraft = false;

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function collectDraftData() {
    const data = {};
    const form = document.getElementById('manual-lineup-form');

    if (!form) {
      return {};
    }

    const excludedFields = [
      'csrf_token',
      '_csrf',
      'csrf',
      'action',
      'game_id',
      'suggestion_type',
      'suggestion_inning',
      'suggestion_player_id',
      'suggestion_swap_with_player_id',
      'suggestion_position'
    ];

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      if (!field.name || field.disabled) {
        return;
      }

      if (
        excludedFields.includes(field.name) ||
        field.name.toLowerCase().includes('csrf')
      ) {
        return;
      }

      if (field.type === 'checkbox') {
        data[field.name] = field.checked ? '1' : '0';
        return;
      }

      if (field.type === 'radio') {
        if (field.checked) {
          data[field.name] = field.value;
        }

        return;
      }

      data[field.name] = field.value;
    });

    return data;
  }

  function applyDraftData(data) {
    isApplyingDraft = true;

    const excludedFields = [
      'csrf_token',
      '_csrf',
      'csrf',
      'action',
      'game_id',
      'suggestion_type',
      'suggestion_inning',
      'suggestion_player_id',
      'suggestion_swap_with_player_id',
      'suggestion_position'
    ];

    Object.keys(data).forEach(function (name) {
      if (
        excludedFields.includes(name) ||
        name.toLowerCase().includes('csrf')
      ) {
        return;
      }

      const fields = document.querySelectorAll(
        '[name="' + CSS.escape(name) + '"]'
      );

      fields.forEach(function (field) {
        if (field.type === 'checkbox') {
          field.checked = data[name] === '1';
        } else if (field.type === 'radio') {
          field.checked = field.value === data[name];
        } else {
          field.value = data[name];
        }

        field.dispatchEvent(
          new Event('change', {
            bubbles: true
          })
        );
      });
    });

    isApplyingDraft = false;
  }

  async function saveDraft() {
    if (isApplyingDraft) {
      return;
    }

    setStatus('Saving draft...');

    const draftData = collectDraftData();

    const response = await fetch('save_lineup_draft.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.CSRF_TOKEN
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        draft_key: DRAFT_KEY,
        game_id: window.GAME_ID,
        draft_data: draftData
      })
    });

    const text = await response.text();

    let data;

    try {
      data = JSON.parse(text);
    } catch (e) {
      throw new Error('Invalid response: ' + text.substring(0, 200));
    }

    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Save endpoint failed.');
    }

    setStatus('Draft saved ' + data.saved_at);
  }

  function scheduleSave() {
    if (isApplyingDraft) {
      return;
    }

    clearTimeout(saveTimer);

    saveTimer = setTimeout(function () {
      saveDraft().catch(function (error) {
        console.error(error);
        setStatus('Draft could not be saved: ' + error.message);
      });
    }, 800);
  }

  async function loadDraft() {
    const response = await fetch(
      'load_lineup_draft.php?draft_key=' + encodeURIComponent(DRAFT_KEY),
      { credentials: 'same-origin' }
    );

    const data = await response.json();

    if (!data.ok || !data.has_draft) {
      setStatus('Autosave ready');
      return;
    }

    const urlParams = new URLSearchParams(window.location.search);

    const skipDraftMessages = [
      'saved',
      'saved_ignored_suggestions',
      'player_added',
      'player_removed',
      'template_applied',
      'template_saved',
      'suggestion_applied',
      'restored',
      'late_innings_cleared'
    ];

    const msg = urlParams.get('msg');

    if (skipDraftMessages.includes(msg)) {

      await fetch('clear_lineup_draft.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': window.CSRF_TOKEN
        },
        body: 'draft_key=' + encodeURIComponent(DRAFT_KEY)
      });

      setStatus('Autosave reset');
      return;
    }

    if (confirm('Resume your saved lineup draft from ' + data.updated_at + '?')) {
      applyDraftData(data.draft_data || {});
      setStatus('Draft restored from ' + data.updated_at);
    } else {
      setStatus('Autosave ready');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    const msg = urlParams.get('msg');
    const skipDraft = urlParams.get('skip_draft') === '1';

    const skipDraftMessages = [
      'saved',
      'saved_ignored_suggestions',
      'player_added',
      'player_removed',
      'template_applied',
      'template_saved',
      'suggestion_applied',
      'restored',
      'late_innings_cleared'
    ];

    if (skipDraft || skipDraftMessages.includes(msg)) {
      clearManualLineupDraft().finally(function () {
        setStatus('Autosave reset');
      });
    } else {
      loadDraft().catch(function () {
        setStatus('Autosave ready');
      });
    }

    const form = document.getElementById('manual-lineup-form');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      field.addEventListener('change', scheduleSave);
      field.addEventListener('input', scheduleSave);
    });
  });

  window.clearManualLineupDraft = function () {
    return fetch('clear_lineup_draft.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': window.CSRF_TOKEN
      },
      body: 'draft_key=' + encodeURIComponent(DRAFT_KEY)
    });
  };
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
