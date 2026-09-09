<?php
declare(strict_types=1);
function lineup_cell_player_name(array $cell): string
{
    $name = trim((string)($cell['name'] ?? ''));

    if ($name !== '') {
        $jerseyNumber = trim((string)($cell['jersey_number'] ?? ''));

        if ($jerseyNumber !== '') {
            $name = preg_replace(
                '/\s*\(#' . preg_quote($jerseyNumber, '/') . '\)\s*$/',
                '',
                $name
            );
        }

        return trim($name) !== '' ? trim($name) : 'Unknown player';
    }

    $first = trim((string)($cell['first_name'] ?? ''));
    $last = trim((string)($cell['last_name'] ?? ''));

    $fullName = trim($first . ' ' . $last);

    if ($fullName !== '') {
        return $fullName;
    }

    return 'Unknown player';
}

function lineup_player_label_from_id(int $playerId, array $playerNames): string
{
    $label = trim((string)($playerNames[$playerId] ?? ''));

    if ($label !== '' && $label !== 'Unknown player') {
        return $label;
    }

    return 'Unknown player';
}

function lineup_hydrate_player_names_from_db(array $playerNames, array $playerIds): array
{
    $missingIds = [];

    foreach ($playerIds as $playerId => $_value) {
        $playerId = (int)$playerId;

        if ($playerId <= 0) {
            continue;
        }

        $currentLabel = trim((string)($playerNames[$playerId] ?? ''));

        if ($currentLabel === '' || $currentLabel === 'Unknown player') {
            $missingIds[] = $playerId;
        }
    }

    $missingIds = array_values(array_unique($missingIds));

    if (empty($missingIds) || !function_exists('db')) {
        return $playerNames;
    }

    $placeholders = implode(',', array_fill(0, count($missingIds), '?'));

    $stmt = db()->prepare("
        SELECT
            id,
            first_name,
            last_name,
            jersey_number
        FROM players
        WHERE id IN ($placeholders)
    ");

    $stmt->execute($missingIds);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $playerId = (int)($row['id'] ?? 0);

        if ($playerId <= 0) {
            continue;
        }

        $firstName = trim((string)($row['first_name'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);

        if ($fullName !== '') {
            $playerNames[$playerId] = $fullName;
        }
    }

    return $playerNames;
}
function analyze_lineup_warnings(array $lineupResult): array
{
    $warnings = [];

    $positions = $lineupResult["positions"] ?? [];
    $innings = (int) ($lineupResult["innings"] ?? 0);
    $benchCount = (int) ($lineupResult["bench_count"] ?? 0);
    $lineupGrid = $lineupResult["lineup_grid"] ?? [];
    $benchGrid = $lineupResult["bench_grid"] ?? [];

    $playerNames = [];
    $playerPositionCounts = [];
    $playerPitcherInnings = [];
    $playerCatcherInnings = [];
    $allPlayerIds = [];


    /*
    |--------------------------------------------------------------------------
    | First pass: collect all player ids and names from field + bench
    |--------------------------------------------------------------------------
    */
    foreach ($positions as $position) {
        foreach ($lineupGrid[$position] ?? [] as $cell) {
            $playerId = (int) ($cell["id"] ?? 0);
            if ($playerId > 0) {
                $allPlayerIds[$playerId] = true;
                if (!isset($playerNames[$playerId])) {
                    $playerNames[$playerId] = lineup_cell_player_name($cell);
                }
            }
        }
    }

    for ($slot = 0; $slot < $benchCount; $slot++) {
        foreach ($benchGrid[$slot] ?? [] as $cell) {
            $playerId = (int) ($cell["id"] ?? 0);

            if ($playerId > 0) {
                $allPlayerIds[$playerId] = true;

                if (!isset($playerNames[$playerId])) {
                    $playerNames[$playerId] = is_array($cell)
                        ? lineup_cell_player_name($cell)
                        : 'Unknown player';
                }
            }
        }
    }

    $playerNames = lineup_hydrate_player_names_from_db($playerNames, $allPlayerIds);

    /*
    |--------------------------------------------------------------------------
    | Structural warnings inning by inning
    |--------------------------------------------------------------------------
    */
    for ($inning = 1; $inning <= $innings; $inning++) {
        $seenPlayers = [];
        $hasPitcher = false;
        $hasCatcher = false;

        foreach ($positions as $position) {
            $cell = $lineupGrid[$position][$inning] ?? null;
            $playerId = (int) ($cell["id"] ?? 0);
            $playerName = is_array($cell)
                ? lineup_cell_player_name($cell)
                : 'Unknown player';

            if ($playerId <= 0) {
                $warnings[] = [
                    "type" => "error",
                    "message" =>
                        "Inning " .
                        $inning .
                        ": no player assigned to " .
                        $position .
                        ".",
                ];
                continue;
            }

            $allPlayerIds[$playerId] = true;
            $playerNames[$playerId] = $playerNames[$playerId] ?? $playerName;

            if ($position === "P") {
                $hasPitcher = true;
                $playerPitcherInnings[$playerId] =
                    ($playerPitcherInnings[$playerId] ?? 0) + 1;
            }

            if ($position === "C") {
                $hasCatcher = true;
                $playerCatcherInnings[$playerId] =
                    ($playerCatcherInnings[$playerId] ?? 0) + 1;
            }

            $playerPositionCounts[$playerId][$position] =
                ($playerPositionCounts[$playerId][$position] ?? 0) + 1;

            if (isset($seenPlayers[$playerId])) {
                $warnings[] = [
                    "type" => "error",
                    "message" =>
                        "Inning " .
                        $inning .
                        ": " .
                        $playerNames[$playerId] .
                        " is assigned more than once.",
                ];
            }

            $seenPlayers[$playerId] = true;
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $cell = $benchGrid[$slot][$inning] ?? null;
            $playerId = (int) ($cell["id"] ?? 0);

            if ($playerId <= 0) {
                continue;
            }

            $allPlayerIds[$playerId] = true;
            if (!isset($playerNames[$playerId])) {
                $playerNames[$playerId] = is_array($cell)
                    ? lineup_cell_player_name($cell)
                    : 'Unknown player';
            }

            if (isset($seenPlayers[$playerId])) {
                $warnings[] = [
                    "type" => "error",
                    "message" =>
                        "Inning " .
                        $inning .
                        ": " .
                        $playerNames[$playerId] .
                        " appears on both the field and bench.",
                ];
            }

            $seenPlayers[$playerId] = true;
        }

        if (!$hasPitcher) {
            $warnings[] = [
                "type" => "error",
                "message" => "Inning " . $inning . ": no pitcher assigned.",
            ];
        }

        if (!$hasCatcher) {
            $warnings[] = [
                "type" => "error",
                "message" => "Inning " . $inning . ": no catcher assigned.",
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Consecutive bench streak warnings
    |--------------------------------------------------------------------------
    |
    | This version measures the full streak and warns when the streak ends,
    | or at end of game if it never ends.
    |--------------------------------------------------------------------------
    */
    foreach (array_keys($allPlayerIds) as $playerId) {
        $currentRun = 0;
        $runStart = 0;

        for ($inning = 1; $inning <= $innings; $inning++) {
            $isBenched = false;

            for ($slot = 0; $slot < $benchCount; $slot++) {
                $cell = $benchGrid[$slot][$inning] ?? null;
                $benchPlayerId = (int) ($cell["id"] ?? 0);

                if ($benchPlayerId === $playerId) {
                    $isBenched = true;
                    break;
                }
            }

            if ($isBenched) {
                if ($currentRun === 0) {
                    $runStart = $inning;
                }
                $currentRun++;
            } else {
                if ($currentRun >= 2) {
                    $warnings[] = [
                        "type" => "warning",
                        "player_id" => (int)$playerId,
                        "message" =>
                            lineup_player_label_from_id((int)$playerId, $playerNames) .
                            " is benched for " .
                            $currentRun .
                            " consecutive inning(s) (innings " .
                            $runStart .
                            "-" .
                            ($inning - 1) .
                            ").",
                    ];
                }

                $currentRun = 0;
                $runStart = 0;
            }
        }

        if ($currentRun >= 2) {
            $warnings[] = [
                "type" => "warning",
                "player_id" => (int)$playerId,
                "message" =>
                    lineup_player_label_from_id((int)$playerId, $playerNames) .
                    " is benched for " .
                    $currentRun .
                    " consecutive inning(s) (innings " .
                    $runStart .
                    "-" .
                    $innings .
                    ").",
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Too many innings at one position
    |--------------------------------------------------------------------------
    */
    foreach ($playerPositionCounts as $playerId => $positionCounts) {
        foreach ($positionCounts as $position => $count) {
            if ($position === "P" || $position === "C") {
                continue;
            }

            if ($count >= 4) {
                $warnings[] = [
                    "type" => "warning",
                    "player_id" => (int)$playerId,
                    "message" =>
                        lineup_player_label_from_id((int)$playerId, $playerNames) .
                        " is assigned to " .
                        $position .
                        " for " .
                        $count .
                        " inning(s).",
                ];
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Heavy pitcher usage
    |--------------------------------------------------------------------------
    */
    foreach ($playerPitcherInnings as $playerId => $count) {
        if ($count >= 3) {
            $playerLabel = lineup_player_label_from_id((int)$playerId, $playerNames);

            $warnings[] = [
                "type" => "warning",
                "player_id" => (int)$playerId,
                "message" =>
                    $playerLabel .
                    " is scheduled to pitch for " .
                    $count .
                    " inning(s).",
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Heavy catcher usage
    |--------------------------------------------------------------------------
    */
    foreach ($playerCatcherInnings as $playerId => $count) {
        if ($count >= 4) {
            $playerLabel = lineup_player_label_from_id((int)$playerId, $playerNames);

            $warnings[] = [
                "type" => "warning",
                "player_id" => (int)$playerId,
                "message" =>
                    $playerLabel .
                    " is scheduled to catch for " .
                    $count .
                    " inning(s).",
            ];
        }
    }

    return $warnings;
}



function build_lineup_fairness_stats(array $lineupResult): array
{
    $positions = $lineupResult["positions"] ?? [];
    $innings = (int) ($lineupResult["innings"] ?? 0);
    $lineupGrid = $lineupResult["lineup_grid"] ?? [];
    $benchGrid = $lineupResult["bench_grid"] ?? [];

    $players = [];

    // Collect all players from field
    foreach ($positions as $position) {
        foreach ($lineupGrid[$position] ?? [] as $inning => $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $playerId = (int) ($cell["id"] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            if (!isset($players[$playerId])) {
                $players[$playerId] = [
                    "player_id" => $playerId,
                    "name" => lineup_cell_player_name($cell),
                    "jersey_number" => (string) ($cell["jersey_number"] ?? ""),
                    "defensive_innings" => 0,
                    "bench_innings" => 0,
                    "longest_bench_streak" => 0,
                    "current_bench_streak" => 0,
                    "pitcher_innings" => 0,
                    "catcher_innings" => 0,
                    "position_counts" => [],
                    "unique_positions_played" => 0,
                    "max_single_position_innings" => 0,
                ];
            }
        }
    }

    // Collect all players from bench
    foreach ($benchGrid as $slot => $inningsGrid) {
        foreach ($inningsGrid as $inning => $cell) {
            if (!is_array($cell)) {
                continue;
            }

            $playerId = (int) ($cell["id"] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            if (!isset($players[$playerId])) {
                $players[$playerId] = [
                    "player_id" => $playerId,
                    "name" => lineup_cell_player_name($cell),
                    "jersey_number" => (string) ($cell["jersey_number"] ?? ""),
                    "defensive_innings" => 0,
                    "bench_innings" => 0,
                    "longest_bench_streak" => 0,
                    "current_bench_streak" => 0,
                    "pitcher_innings" => 0,
                    "catcher_innings" => 0,
                    "position_counts" => [],
                    "unique_positions_played" => 0,
                    "max_single_position_innings" => 0,
                ];
            }
        }
    }

    // Inning-by-inning analysis
    for ($inning = 1; $inning <= $innings; $inning++) {
        $benchedThisInning = [];

        foreach ($positions as $position) {
            $cell = $lineupGrid[$position][$inning] ?? null;
            if (!is_array($cell)) {
                continue;
            }

            $playerId = (int) ($cell["id"] ?? 0);
            if ($playerId <= 0 || !isset($players[$playerId])) {
                continue;
            }

            $players[$playerId]["defensive_innings"]++;
            $players[$playerId]["position_counts"][$position] =
                ($players[$playerId]["position_counts"][$position] ?? 0) + 1;

            if ($position === "P") {
                $players[$playerId]["pitcher_innings"]++;
            }

            if ($position === "C") {
                $players[$playerId]["catcher_innings"]++;
            }
        }

        foreach ($benchGrid as $slot => $inningsGrid) {
            $cell = $inningsGrid[$inning] ?? null;
            if (!is_array($cell)) {
                continue;
            }

            $playerId = (int) ($cell["id"] ?? 0);
            if ($playerId <= 0 || !isset($players[$playerId])) {
                continue;
            }

            $players[$playerId]["bench_innings"]++;
            $benchedThisInning[$playerId] = true;
        }

        foreach ($players as $playerId => &$player) {
            if (isset($benchedThisInning[$playerId])) {
                $player["current_bench_streak"]++;
                if (
                    $player["current_bench_streak"] >
                    $player["longest_bench_streak"]
                ) {
                    $player["longest_bench_streak"] =
                        $player["current_bench_streak"];
                }
            } else {
                $player["current_bench_streak"] = 0;
            }
        }
        unset($player);
    }

    // Final derived stats
    foreach ($players as &$player) {
        $positionCounts = $player["position_counts"] ?? [];
        $player["unique_positions_played"] = count($positionCounts);
        $player["max_single_position_innings"] = !empty($positionCounts)
            ? max($positionCounts)
            : 0;

        unset($player["current_bench_streak"], $player["position_counts"]);
    }
    unset($player);

    return array_values($players);
}
function calculate_lineup_fairness(array $lineupResult): array
{
    $playerStats = build_lineup_fairness_stats($lineupResult);

    if (empty($playerStats)) {
        return [
            "team_score" => 100,
            "average_bench_innings" => 0,
            "average_defensive_innings" => 0,
            "players" => [],
        ];
    }

    $totalBench = 0;
    $totalDefense = 0;

    foreach ($playerStats as $player) {
        $totalBench += (int)($player["bench_innings"] ?? 0);
        $totalDefense += (int)($player["defensive_innings"] ?? 0);
    }

    $playerCount = count($playerStats);
    $avgBench = $totalBench / $playerCount;
    $avgDefense = $totalDefense / $playerCount;

    $teamPenalty = 0;

    foreach ($playerStats as &$player) {
        $score = 100;
        $reasons = [];

        $benchInnings = (int)($player["bench_innings"] ?? 0);
        $defensiveInnings = (int)($player["defensive_innings"] ?? 0);
        $longestBenchStreak = (int)($player["longest_bench_streak"] ?? 0);
        $pitcherInnings = (int)($player["pitcher_innings"] ?? 0);
        $catcherInnings = (int)($player["catcher_innings"] ?? 0);
        $uniquePositionsPlayed = (int)($player["unique_positions_played"] ?? 0);
        $maxSinglePositionInnings = (int)($player["max_single_position_innings"] ?? 0);

        $benchDiff = abs($benchInnings - $avgBench);
        $defenseDiff = abs($defensiveInnings - $avgDefense);

        $benchPenalty = (int)round($benchDiff * 12);
        if ($benchPenalty > 0) {
            $score -= $benchPenalty;
            $reasons[] = "Bench usage is uneven compared with the team average.";
        }

        $defensePenalty = (int)round($defenseDiff * 8);
        if ($defensePenalty > 0) {
            $score -= $defensePenalty;
            $reasons[] = "Defensive innings are uneven compared with the team average.";
        }

        if ($longestBenchStreak >= 2) {
            $penalty = ($longestBenchStreak - 1) * 8;
            $score -= $penalty;
            $reasons[] = "Benched for {$longestBenchStreak} consecutive innings.";
        }

        if ($pitcherInnings > 3) {
            $score -= ($pitcherInnings - 3) * 8;
            $reasons[] = "Scheduled to pitch for {$pitcherInnings} innings.";
        }

        if ($catcherInnings > 4) {
            $score -= ($catcherInnings - 4) * 5;
            $reasons[] = "Scheduled to catch for {$catcherInnings} innings.";
        }

        if ($maxSinglePositionInnings > 4) {
            $score -= ($maxSinglePositionInnings - 4) * 4;
            $reasons[] = "Same position for {$maxSinglePositionInnings} innings.";
        }

        if ($defensiveInnings > 0) {
            if ($uniquePositionsPlayed <= 1) {
                $score -= 10;
                $reasons[] = "Limited position variety.";
            } elseif ($uniquePositionsPlayed === 2) {
                $score -= 4;
                $reasons[] = "Only played 2 different positions.";
            }
        }

        $score = max(0, min(100, $score));

        if (empty($reasons)) {
            $reasons[] = "Balanced defensive and bench usage.";
        }

        $player["fairness_score"] = $score;
        $player["fairness_reasons"] = $reasons;

        $teamPenalty += 100 - $score;
    }
    unset($player);

    $teamScore = 100 - (int)round($teamPenalty / $playerCount);
    $teamScore = max(0, min(100, $teamScore));

    usort($playerStats, function (array $a, array $b): int {
        return strcmp((string)($a["name"] ?? ""), (string)($b["name"] ?? ""));
    });

    return [
        "team_score" => $teamScore,
        "average_bench_innings" => round($avgBench, 2),
        "average_defensive_innings" => round($avgDefense, 2),
        "players" => $playerStats,
    ];
}

function fairness_reason_summary(array $warnings): string
{
    if (empty($warnings)) {
        return "Balanced lineup with no major issues.";
    }

    $counts = [
        "bench" => 0,
        "position" => 0,
        "pitcher" => 0,
        "catcher" => 0,
        "other" => 0,
    ];

    foreach ($warnings as $w) {
        $msg = strtolower((string) ($w["message"] ?? ""));

        if (str_contains($msg, "bench")) {
            $counts["bench"]++;
        } elseif (str_contains($msg, "position")) {
            $counts["position"]++;
        } elseif (str_contains($msg, "pitch")) {
            $counts["pitcher"]++;
        } elseif (str_contains($msg, "catch")) {
            $counts["catcher"]++;
        } else {
            $counts["other"]++;
        }
    }

    $parts = [];

    if ($counts["bench"] > 0) {
        $parts[] =
            $counts["bench"] .
            " bench issue" .
            ($counts["bench"] > 1 ? "s" : "");
    }

    if ($counts["position"] > 0) {
        $parts[] = $counts["position"] . " position imbalance";
    }

    if ($counts["pitcher"] > 0) {
        $parts[] = $counts["pitcher"] . " pitching load issue";
    }

    if ($counts["catcher"] > 0) {
        $parts[] = $counts["catcher"] . " catching load issue";
    }

    if ($counts["other"] > 0) {
        $parts[] =
            $counts["other"] .
            " general issue" .
            ($counts["other"] > 1 ? "s" : "");
    }

    return implode(" • ", $parts);
}

function get_player_fairness_reasons(array $player): array
{
    $reasons = [];

    $benchInnings = (int)($player['bench_innings'] ?? 0);
    $longestBenchStreak = (int)($player['longest_bench_streak'] ?? 0);
    $pitcherInnings = (int)($player['pitcher_innings'] ?? 0);
    $catcherInnings = (int)($player['catcher_innings'] ?? 0);
    $uniquePositionsPlayed = (int)($player['unique_positions_played'] ?? 0);
    $maxSinglePositionInnings = (int)($player['max_single_position_innings'] ?? 0);
    $score = (int)($player['fairness_score'] ?? 100);

    if ($maxSinglePositionInnings > 4) {
        $reasons[] = 'Same position for too many innings.';
    }

    if ($uniquePositionsPlayed <= 1 && ($player['defensive_innings'] ?? 0) > 0) {
        $reasons[] = 'Limited position variety.';
    }

    if ($longestBenchStreak >= 2) {
        $reasons[] = 'Benched for ' . $longestBenchStreak . ' consecutive innings.';
    }

    if ($pitcherInnings > 3) {
        $reasons[] = 'Heavy pitching load.';
    }

    if ($catcherInnings > 4) {
        $reasons[] = 'Heavy catching load.';
    }

    if ($benchInnings >= 3) {
        $reasons[] = 'High bench usage.';
    }

    if (empty($reasons)) {
        if ($score >= 90) {
            $reasons[] = 'Balanced defensive and bench usage.';
        } else {
            $reasons[] = 'Score reduced by overall lineup balance.';
        }
    }

    return $reasons;
}
