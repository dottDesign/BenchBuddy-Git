<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$LINEUP_ENGINE_CONFIG = require __DIR__ . '/lineup_config.php';
function required_defensive_innings_for_game(int $innings): int
{
    if ($innings <= 5) {
        return 2;
    }

    return 3;
}

function max_bench_innings_for_game(int $innings): int
{
    return max(0, $innings - required_defensive_innings_for_game($innings));
}

function normalize_lineup_player_positions(array $player): array
{
    $allPositions = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];

    $canPlay = $player['can_play'] ?? [];
    $cannotPlay = $player['cannot_play'] ?? [];

    if (!is_array($canPlay)) {
        $canPlay = [];
    }

    if (!is_array($cannotPlay)) {
        $cannotPlay = [];
    }

    $canPlay = array_values(array_unique(array_map('strtoupper', $canPlay)));
    $cannotPlay = array_values(array_unique(array_map('strtoupper', $cannotPlay)));

    if (empty($canPlay)) {
        $canPlay = array_values(array_diff($allPositions, $cannotPlay));
    }

    $player['can_play'] = $canPlay;
    $player['cannot_play'] = $cannotPlay;

    return $player;
}


function lineup_engine_config(array $overrides = []): array
{
    global $LINEUP_ENGINE_CONFIG;
    return array_merge($LINEUP_ENGINE_CONFIG, $overrides);
}

function get_pitching_role(array $player): string
{
    $role = strtolower(trim((string)($player['pitching_role'] ?? 'none')));
    return in_array($role, ['primary', 'emergency', 'none'], true) ? $role : 'none';
}

function get_catching_role(array $player): string
{
    $role = strtolower(trim((string)($player['catching_role'] ?? 'none')));
    return in_array($role, ['primary', 'emergency', 'none'], true) ? $role : 'none';
}


function normalize_engine_locked_positions(mixed $submitted, int $innings): array
{
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

function get_locked_position_player_id(
    array $cfg,
    string $position,
    int $inning
): int {
    $position = strtoupper(trim($position));

    return (int)(
        $cfg['locked_positions'][$position][$inning]
        ?? 0
    );
}

function get_locked_player_ids_for_inning(array $cfg, int $inning): array
{
    $lockedIds = [];

    foreach (['P', 'C'] as $position) {
        $playerId = get_locked_position_player_id(
            $cfg,
            $position,
            $inning
        );

        if ($playerId > 0) {
            $lockedIds[$playerId] = true;
        }
    }

    return $lockedIds;
}

function find_lineup_player_by_id(array $players, int $playerId): ?array
{
    foreach ($players as $player) {
        if ((int)($player['id'] ?? 0) === $playerId) {
            return $player;
        }
    }

    return null;
}

function validate_engine_locked_positions(
    array $players,
    array $lockedPositions,
    int $innings
): void {
    $playersById = [];

    foreach ($players as $player) {
        $playerId = (int)($player['id'] ?? 0);

        if ($playerId > 0) {
            $playersById[$playerId] = $player;
        }
    }

    for ($inning = 1; $inning <= $innings; $inning++) {
        $pitcherId = (int)($lockedPositions['P'][$inning] ?? 0);
        $catcherId = (int)($lockedPositions['C'][$inning] ?? 0);

        if (
            $pitcherId > 0 &&
            $catcherId > 0 &&
            $pitcherId === $catcherId
        ) {
            throw new RuntimeException(
                "The same player cannot pitch and catch in inning {$inning}."
            );
        }

        if ($pitcherId > 0) {
            if (!isset($playersById[$pitcherId])) {
                throw new RuntimeException(
                    "The selected pitcher for inning {$inning} is not on the game roster."
                );
            }

            $pitcher = $playersById[$pitcherId];

            if (get_pitching_role($pitcher) === 'none') {
                throw new RuntimeException(
                    "The selected pitcher for inning {$inning} is not eligible to pitch."
                );
            }

            if (!pitcher_position_allowed($pitcher)) {
                throw new RuntimeException(
                    "The selected pitcher for inning {$inning} cannot play pitcher."
                );
            }
        }

        if ($catcherId > 0) {
            if (!isset($playersById[$catcherId])) {
                throw new RuntimeException(
                    "The selected catcher for inning {$inning} is not on the game roster."
                );
            }

            $catcher = $playersById[$catcherId];

            if (get_catching_role($catcher) === 'none') {
                throw new RuntimeException(
                    "The selected catcher for inning {$inning} is not eligible to catch."
                );
            }

            if (!can_play_position_mode($catcher, 'C', [], false)) {
                throw new RuntimeException(
                    "The selected catcher for inning {$inning} cannot play catcher."
                );
            }
        }
    }
}



/*
|--------------------------------------------------------------------------
| Core generator
|--------------------------------------------------------------------------
*/
function generate_lineup(array $players, array $config = []): array
{
    foreach ($players as &$player) {
        $player = normalize_lineup_player_positions($player);
    }
    unset($player);
    $cfg = lineup_engine_config($config);

    $players = array_values($players);
    $rosterSize = count($players);

    if ($rosterSize < 8) {
        throw new RuntimeException("At least 8 players are required. Found {$rosterSize}.");
    }

    if ($rosterSize > (int)$cfg['max_roster_size']) {
        throw new RuntimeException("Max supported roster is {$cfg['max_roster_size']}. Found {$rosterSize}.");
    }

    validate_players_for_engine($players);

    $positions = game_defensive_positions_for_roster_count($rosterSize);
    $cfg['positions'] = $positions;

    $innings = max(1, (int)($cfg['innings'] ?? 7));

    $cfg['locked_positions'] = normalize_engine_locked_positions(
        $cfg['locked_positions'] ?? [],
        $innings
    );

    validate_engine_locked_positions(
        $players,
        $cfg['locked_positions'],
        $innings
    );

    $benchCount = max(0, $rosterSize - count($positions));

    $totalBenchSlots = $benchCount * $innings;

    $cfg['required_defensive_innings'] = required_defensive_innings_for_game($innings);
    $cfg['legal_max_bench_innings_per_player'] = max_bench_innings_for_game($innings);

    $cfg['fair_min_bench_innings_per_player'] = $rosterSize > 0
        ? (int)floor($totalBenchSlots / $rosterSize)
        : 0;

    $cfg['fair_max_bench_innings_per_player'] = $rosterSize > 0
        ? (int)ceil($totalBenchSlots / $rosterSize)
        : 0;

    $currentPitcherId = null;
    $pitchedEver = [];
    $retiredPitchers = [];
    $pitchInningsGame = [];

    $currentCatcherId = null;
    $currentCatcherStreak = 0;

    $benchCounts = [];
    $lastBenchInning = [];
    $positionCounts = [];
    $lastPositionByPlayer = [];
    $catchCounts = [];

    foreach ($players as $player) {
        $playerId = (int)$player['id'];
        $benchCounts[$playerId] = 0;
        $lastBenchInning[$playerId] = 0;
        $positionCounts[$playerId] = [];
        $lastPositionByPlayer[$playerId] = null;
        $catchCounts[$playerId] = 0;
    }

    $lineupGrid = [];
    foreach ($positions as $pos) {
        $lineupGrid[$pos] = [];
    }

    $benchGrid = [];
    for ($b = 0; $b < $benchCount; $b++) {
        $benchGrid[$b] = [];
    }

    for ($inning = 1; $inning <= $innings; $inning++) {
        $assignment = solve_inning(
            $players,
            $currentPitcherId,
            $pitchedEver,
            $retiredPitchers,
            $pitchInningsGame,
            $benchCounts,
            $lastBenchInning,
            $positionCounts,
            $lastPositionByPlayer,
            $catchCounts,
            $currentCatcherId,
            $currentCatcherStreak,
            $inning,
            $cfg
        );

        if ($assignment === null) {
            error_log('LINEUP ENGINE FAILED inning=' . $inning);
            error_log('LINEUP ENGINE roster=' . json_encode(array_map(function ($player) {
                return [
                    'id' => $player['id'] ?? null,
                    'name' => $player['name'] ?? (($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
                    'pitching_role' => $player['pitching_role'] ?? null,
                    'catching_role' => $player['catching_role'] ?? null,
                    'can_play' => $player['can_play'] ?? [],
                    'cannot_play' => $player['cannot_play'] ?? [],
                ];
            }, $players)));

            throw new RuntimeException("Cannot solve inning {$inning}.");
        }

        $assignedIds = [];
        foreach ($assignment as $pos => $player) {
            $playerId = (int)$player['id'];
            $assignedIds[$playerId] = true;
            $lineupGrid[$pos][$inning] = $player;

            $positionCounts[$playerId][$pos] = ($positionCounts[$playerId][$pos] ?? 0) + 1;
            $lastPositionByPlayer[$playerId] = $pos;

            if ($pos === 'C') {
                $catchCounts[$playerId] = ($catchCounts[$playerId] ?? 0) + 1;
            }
        }

        $benchPlayers = [];
        foreach ($players as $player) {
            $playerId = (int)$player['id'];
            if (!isset($assignedIds[$playerId])) {
                $benchPlayers[] = $player;
            }
        }

        if (!empty($cfg['balance_bench_fairness'])) {
            usort($benchPlayers, function (array $a, array $b) use ($benchCounts, $lastBenchInning): int {
                $aId = (int)$a['id'];
                $bId = (int)$b['id'];

                $benchCmp = ($benchCounts[$aId] ?? 0) <=> ($benchCounts[$bId] ?? 0);
                if ($benchCmp !== 0) {
                    return $benchCmp;
                }

                $lastCmp = ($lastBenchInning[$aId] ?? 0) <=> ($lastBenchInning[$bId] ?? 0);
                if ($lastCmp !== 0) {
                    return $lastCmp;
                }

                $nameA = isset($a['name']) ? (string)$a['name'] : '';
$nameB = isset($b['name']) ? (string)$b['name'] : '';

return strcmp($nameA, $nameB);
            });
        }

        if (count($benchPlayers) !== $benchCount) {
            throw new RuntimeException(
                "Inning {$inning}: bench count mismatch. Expected {$benchCount}, got " . count($benchPlayers) . '.'
            );
        }

        for ($b = 0; $b < $benchCount; $b++) {
            $benchPlayer = $benchPlayers[$b] ?? null;
            $benchGrid[$b][$inning] = $benchPlayer;

            if ($benchPlayer !== null) {
                $playerId = (int)$benchPlayer['id'];
                $benchCounts[$playerId] = ($benchCounts[$playerId] ?? 0) + 1;
                $lastBenchInning[$playerId] = $inning;
                $lastPositionByPlayer[$playerId] = 'BENCH';
            }
        }

        $pitcherThisInning = (int)$assignment['P']['id'];

        if ($currentPitcherId !== null && $pitcherThisInning !== $currentPitcherId) {
            $retiredPitchers[$currentPitcherId] = true;
        }

        $pitchedEver[$pitcherThisInning] = true;
        $pitchInningsGame[$pitcherThisInning] = ($pitchInningsGame[$pitcherThisInning] ?? 0) + 1;
        $currentPitcherId = $pitcherThisInning;

        $catcherThisInning = isset($assignment['C']) ? (int)$assignment['C']['id'] : null;
        if ($catcherThisInning !== null) {
            if ($currentCatcherId !== null && $catcherThisInning === $currentCatcherId) {
                $currentCatcherStreak++;
            } else {
                $currentCatcherId = $catcherThisInning;
                $currentCatcherStreak = 1;
            }
        }
    }

    return [
        'lineup_grid' => $lineupGrid,
        'bench_grid' => $benchGrid,
        'pitch_log' => $pitchInningsGame,
        'bench_count' => $benchCount,
        'roster_size' => $rosterSize,
        'innings' => $innings,
        'positions' => $positions,
    ];
}

function validate_players_for_engine(array $players): void
{
    $ids = [];
    $names = [];

    foreach ($players as $player) {
    $fullName = trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? ''));

    if (
        !isset($player['id'], $player['can_play'], $player['cannot_play']) ||
        $fullName === ''
    ) {
        throw new InvalidArgumentException('Each player must include id, first_name/last_name, can_play, and cannot_play.');
    }

    $id = (int)$player['id'];
    $name = $fullName;

    if ($id <= 0) {
        throw new InvalidArgumentException('Each player must have a valid numeric id.');
    }

    if ($name === '') {
        throw new InvalidArgumentException('Each player must have a name.');
    }

    if (isset($ids[$id])) {
        throw new InvalidArgumentException("Duplicate player id detected: {$id}");
    }

    if (isset($names[$name])) {
        throw new InvalidArgumentException("Duplicate player name detected in roster: {$name}");
    }

    $ids[$id] = true;
    $names[$name] = true;
}
}

function score_bench_candidate(
    array $player,
    array $benchCounts,
    array $lastBenchInning,
    array $cfg,
    int $inning
): int {
    $playerId = (int)$player['id'];
    $benchTotal = (int)($benchCounts[$playerId] ?? 0);

    $fairMinBench = (int)($cfg['fair_min_bench_innings_per_player'] ?? 0);
    $fairMaxBench = (int)($cfg['fair_max_bench_innings_per_player'] ?? 0);
    $legalMaxBench = (int)($cfg['legal_max_bench_innings_per_player'] ?? 0);

    $score = 0;

    /*
    |--------------------------------------------------------------------------
    | Main fairness driver
    |--------------------------------------------------------------------------
    | Players with fewer bench innings should be benched first.
    */
    $score += $benchTotal * 1000;

    /*
    |--------------------------------------------------------------------------
    | Strongly prefer benching players who are below the fair minimum.
    |--------------------------------------------------------------------------
    */
    if ($benchTotal < $fairMinBench) {
        $score -= 5000;
    }

    /*
    |--------------------------------------------------------------------------
    | Strongly avoid benching players above the fair target.
    |--------------------------------------------------------------------------
    */
    if ($fairMaxBench > 0 && $benchTotal >= $fairMaxBench) {
        $score += 20000;
    }

    /*
    |--------------------------------------------------------------------------
    | Never willingly exceed the legal Rule 25 max.
    |--------------------------------------------------------------------------
    */
    if ($legalMaxBench > 0 && $benchTotal >= $legalMaxBench) {
        $score += 1000000;
    }

    /*
    |--------------------------------------------------------------------------
    | Avoid consecutive bench if requested.
    |--------------------------------------------------------------------------
    */
    if (!empty($cfg['balance_bench_fairness']) && ($lastBenchInning[$playerId] ?? 0) === $inning - 1) {
        $score += 10000;
    }

    /*
    |--------------------------------------------------------------------------
    | Lightly protect specialist roles, but do not let this override fairness.
    |--------------------------------------------------------------------------
    */
    if (get_catching_role($player) === 'primary') {
        $score += 75;
    } elseif (get_catching_role($player) === 'emergency') {
        $score += 30;
    }

    if (get_pitching_role($player) === 'primary') {
        $score += 75;
    } elseif (get_pitching_role($player) === 'emergency') {
        $score += 30;
    }

    /*
    |--------------------------------------------------------------------------
    | Lightly protect players with very few valid positions.
    |--------------------------------------------------------------------------
    */
    $validPositions = count_valid_positions_for_player($player, $cfg, false);
    $score += max(0, 3 - $validPositions) * 50;

    return $score;
}
function build_bench_combinations(
    array $players,
    int $benchCount,
    array $benchCounts,
    array $lastBenchInning,
    array $cfg,
    int $inning,
    bool $allowConsecutiveBench,
    array $protectedPlayerIds = [],
    int $limit = 300
): array {
    if ($benchCount <= 0) {
        return [[]];
    }

    $fairMaxBench = (int)($cfg['fair_max_bench_innings_per_player'] ?? 0);
    $legalMaxBench = (int)($cfg['legal_max_bench_innings_per_player'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | First pass: legal + fairness target.
    |--------------------------------------------------------------------------
    */
    $eligiblePlayers = array_values(array_filter(
        $players,
        function (array $player) use (
            $lastBenchInning,
            $benchCounts,
            $inning,
            $allowConsecutiveBench,
            $fairMaxBench,
            $legalMaxBench,
            $protectedPlayerIds
        ): bool {
            $playerId = (int)$player['id'];

            if (isset($protectedPlayerIds[$playerId])) {
                return false;
            }
            $benchTotal = (int)($benchCounts[$playerId] ?? 0);

            if (!$allowConsecutiveBench && ($lastBenchInning[$playerId] ?? 0) === $inning - 1) {
                return false;
            }

            if ($legalMaxBench > 0 && $benchTotal >= $legalMaxBench) {
                return false;
            }

            if ($fairMaxBench > 0 && $benchTotal >= $fairMaxBench) {
                return false;
            }

            return true;
        }
    ));

    /*
    |--------------------------------------------------------------------------
    | Second pass: allow fair max to be exceeded, but never legal max.
    |--------------------------------------------------------------------------
    | This helps if position restrictions make the fair target impossible.
    */
    if (count($eligiblePlayers) < $benchCount) {
        $eligiblePlayers = array_values(array_filter(
            $players,
            function (array $player) use (
                $lastBenchInning,
                $benchCounts,
                $inning,
                $allowConsecutiveBench,
                $legalMaxBench,
                $protectedPlayerIds
            ): bool {
                $playerId = (int)$player['id'];

                if (isset($protectedPlayerIds[$playerId])) {
                    return false;
                }
                $benchTotal = (int)($benchCounts[$playerId] ?? 0);

                if (!$allowConsecutiveBench && ($lastBenchInning[$playerId] ?? 0) === $inning - 1) {
                    return false;
                }

                if ($legalMaxBench > 0 && $benchTotal >= $legalMaxBench) {
                    return false;
                }

                return true;
            }
        ));
    }

    if (count($eligiblePlayers) < $benchCount) {
        return [];
    }

    $scoredPlayers = [];

    foreach ($eligiblePlayers as $player) {
        $scoredPlayers[] = [
            'player' => $player,
            'score' => score_bench_candidate($player, $benchCounts, $lastBenchInning, $cfg, $inning),
        ];
    }

    usort($scoredPlayers, function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
            return $a['score'] <=> $b['score'];
        }

        $nameA = trim((string)($a['player']['name'] ?? (($a['player']['first_name'] ?? '') . ' ' . ($a['player']['last_name'] ?? ''))));
        $nameB = trim((string)($b['player']['name'] ?? (($b['player']['first_name'] ?? '') . ' ' . ($b['player']['last_name'] ?? ''))));

        return strcmp($nameA, $nameB);
    });

    $results = [];

    $backtrack = function (
        int $start,
        array $chosen
    ) use (&$backtrack, &$results, $scoredPlayers, $benchCount, $limit): void {
        if (count($results) >= $limit) {
            return;
        }

        if (count($chosen) === $benchCount) {
            $results[] = array_map(
                fn(array $row): array => $row['player'],
                $chosen
            );
            return;
        }

        for ($i = $start; $i < count($scoredPlayers); $i++) {
            $chosen[] = $scoredPlayers[$i];
            $backtrack($i + 1, $chosen);
            array_pop($chosen);

            if (count($results) >= $limit) {
                return;
            }
        }
    };

    $backtrack(0, []);

    usort($results, function (array $a, array $b) use ($benchCounts, $lastBenchInning, $cfg, $inning): int {
        $scoreA = score_bench_group($a, $benchCounts, $lastBenchInning, $cfg, $inning);
        $scoreB = score_bench_group($b, $benchCounts, $lastBenchInning, $cfg, $inning);

        return $scoreA <=> $scoreB;
    });

    return $results;
}
function score_bench_group(
    array $benchGroup,
    array $benchCounts,
    array $lastBenchInning,
    array $cfg,
    int $inning
): int {
    $score = 0;

    $fairMinBench = (int)($cfg['fair_min_bench_innings_per_player'] ?? 0);
    $fairMaxBench = (int)($cfg['fair_max_bench_innings_per_player'] ?? 0);
    $legalMaxBench = (int)($cfg['legal_max_bench_innings_per_player'] ?? 0);

    foreach ($benchGroup as $player) {
        $playerId = (int)$player['id'];
        $currentBenchTotal = (int)($benchCounts[$playerId] ?? 0);
        $projectedBenchTotal = $currentBenchTotal + 1;

        $score += score_bench_candidate($player, $benchCounts, $lastBenchInning, $cfg, $inning);

        if ($projectedBenchTotal < $fairMinBench) {
            $score -= 3000;
        }

        if ($fairMaxBench > 0 && $projectedBenchTotal > $fairMaxBench) {
            $score += 50000;
        }

        if ($legalMaxBench > 0 && $projectedBenchTotal > $legalMaxBench) {
            $score += 1000000;
        }

        if (($lastBenchInning[$playerId] ?? 0) === $inning - 1) {
            $score += 15000;
        }
    }

    return $score;
}

function solve_inning(
    array $available,
    ?int $currentPitcherId,
    array $pitchedEver,
    array $retiredPitchers,
    array $pitchInningsGame,
    array $benchCounts,
    array $lastBenchInning,
    array $positionCounts,
    array $lastPositionByPlayer,
    array $catchCounts,
    ?int $currentCatcherId,
    int $currentCatcherStreak,
    int $inning,
    array $cfg
): ?array {
    $benchCount = max(0, count($available) - count($cfg['positions'] ?? []));
    $protectedPlayerIds = get_locked_player_ids_for_inning(
        $cfg,
        $inning
    );
    $benchPasses = !empty($cfg['hard_avoid_consecutive_bench'])
        ? [false, true]
        : [true];

    foreach ($benchPasses as $allowConsecutiveBench) {
        $benchCombinations = build_bench_combinations(
            $available,
            $benchCount,
            $benchCounts,
            $lastBenchInning,
            $cfg,
            $inning,
            $allowConsecutiveBench,
            $protectedPlayerIds
        );

        foreach ($benchCombinations as $benchGroup) {
            $benchIds = [];
            foreach ($benchGroup as $player) {
                $benchIds[(int)$player['id']] = true;
            }

            $fieldAvailable = array_values(array_filter(
                $available,
                fn(array $player): bool => !isset($benchIds[(int)$player['id']])
            ));

            $strict = solve_inning_mode(
                $fieldAvailable,
                $currentPitcherId,
                $pitchedEver,
                $retiredPitchers,
                $pitchInningsGame,
                $benchCounts,
                $lastBenchInning,
                $positionCounts,
                $lastPositionByPlayer,
                $catchCounts,
                $currentCatcherId,
                $currentCatcherStreak,
                $inning,
                $cfg,
                false
            );

            if ($strict !== null) {
                return $strict;
            }

            if (!empty($cfg['allow_relaxed_mode'])) {
                $relaxed = solve_inning_mode(
                    $fieldAvailable,
                    $currentPitcherId,
                    $pitchedEver,
                    $retiredPitchers,
                    $pitchInningsGame,
                    $benchCounts,
                    $lastBenchInning,
                    $positionCounts,
                    $lastPositionByPlayer,
                    $catchCounts,
                    $currentCatcherId,
                    $currentCatcherStreak,
                    $inning,
                    $cfg,
                    true
                );

                if ($relaxed !== null) {
                    return $relaxed;
                }
            }
        }
    }

    return null;
}

function solve_inning_mode(
    array $available,
    ?int $currentPitcherId,
    array $pitchedEver,
    array $retiredPitchers,
    array $pitchInningsGame,
    array $benchCounts,
    array $lastBenchInning,
    array $positionCounts,
    array $lastPositionByPlayer,
    array $catchCounts,
    ?int $currentCatcherId,
    int $currentCatcherStreak,
    int $inning,
    array $cfg,
    bool $relaxedMode
): ?array {
    $positions = $cfg['positions'];
    $candidates = [];

    foreach ($positions as $pos) {
        $lockedPlayerId = get_locked_position_player_id(
            $cfg,
            $pos,
            $inning
        );

        if ($lockedPlayerId > 0) {
            $lockedPlayer = find_lineup_player_by_id(
                $available,
                $lockedPlayerId
            );

            if ($lockedPlayer === null) {
                return null;
            }

            if ($pos === 'P') {
                if (
                    !is_eligible_pitcher(
                        $lockedPlayer,
                        $currentPitcherId,
                        $pitchedEver,
                        $retiredPitchers,
                        $pitchInningsGame,
                        $cfg
                    )
                ) {
                    return null;
                }
            } elseif ($pos === 'C') {
                if (
                    get_catching_role($lockedPlayer) === 'none' ||
                    !can_play_position_mode(
                        $lockedPlayer,
                        'C',
                        $cfg,
                        $relaxedMode
                    )
                ) {
                    return null;
                }
            } elseif (
                !can_play_position_mode(
                    $lockedPlayer,
                    $pos,
                    $cfg,
                    $relaxedMode
                )
            ) {
                return null;
            }

            $candidates[$pos] = [$lockedPlayer];
            continue;
        }

        if ($pos === 'P') {
            $primaryCandidates = array_values(array_filter(
                $available,
                function (array $p) use ($currentPitcherId, $pitchedEver, $retiredPitchers, $pitchInningsGame, $cfg): bool {
                    return get_pitching_role($p) === 'primary'
                        && is_eligible_pitcher($p, $currentPitcherId, $pitchedEver, $retiredPitchers, $pitchInningsGame, $cfg);
                }
            ));

            $emergencyCandidates = array_values(array_filter(
                $available,
                function (array $p) use ($currentPitcherId, $pitchedEver, $retiredPitchers, $pitchInningsGame, $cfg): bool {
                    return get_pitching_role($p) === 'emergency'
                        && is_eligible_pitcher($p, $currentPitcherId, $pitchedEver, $retiredPitchers, $pitchInningsGame, $cfg);
                }
            ));

            $candidates[$pos] = !empty($primaryCandidates) ? $primaryCandidates : $emergencyCandidates;

            usort($candidates[$pos], function (array $a, array $b) use (
                $currentPitcherId,
                $pitchedEver,
                $pitchInningsGame,
                $cfg
            ): int {
                $scoreA = score_pitcher($a, $currentPitcherId, $pitchedEver, $pitchInningsGame, $cfg);
                $scoreB = score_pitcher($b, $currentPitcherId, $pitchedEver, $pitchInningsGame, $cfg);

                if ($scoreA !== $scoreB) {
                    return $scoreA <=> $scoreB;
                }

                $nameA = isset($a['name']) ? (string)$a['name'] : '';
$nameB = isset($b['name']) ? (string)$b['name'] : '';

return strcmp($nameA, $nameB);
            });
        } elseif ($pos === 'C') {
            $primaryCandidates = array_values(array_filter(
                $available,
                function (array $p) use ($cfg, $relaxedMode): bool {
                    return get_catching_role($p) === 'primary'
                        && can_play_position_mode($p, 'C', $cfg, $relaxedMode);
                }
            ));

            $emergencyCandidates = array_values(array_filter(
                $available,
                function (array $p) use ($cfg, $relaxedMode): bool {
                    return get_catching_role($p) === 'emergency'
                        && can_play_position_mode($p, 'C', $cfg, $relaxedMode);
                }
            ));

            $candidates[$pos] = !empty($primaryCandidates) ? $primaryCandidates : $emergencyCandidates;

            usort($candidates[$pos], function (array $a, array $b) use (
                $currentCatcherId,
                $currentCatcherStreak,
                $catchCounts,
                $benchCounts,
                $lastBenchInning,
                $cfg,
                $relaxedMode,
                $inning
            ): int {
                $aId = (int)$a['id'];
                $bId = (int)$b['id'];

                $scoreA = 0;
                $scoreB = 0;

                $validPositionsA = count_valid_positions_for_player($a, $cfg, $relaxedMode);
                $validPositionsB = count_valid_positions_for_player($b, $cfg, $relaxedMode);

                $scoreA += max(0, 6 - $validPositionsA) * 6;
                $scoreB += max(0, 6 - $validPositionsB) * 6;

                if ($currentCatcherId !== null && $currentCatcherStreak < 2) {
                    if ($aId === $currentCatcherId) {
                        $scoreA -= 100;
                    }
                    if ($bId === $currentCatcherId) {
                        $scoreB -= 100;
                    }
                }

                $scoreA += ($catchCounts[$aId] ?? 0) * 12;
                $scoreB += ($catchCounts[$bId] ?? 0) * 12;

                if (get_catching_role($a) === 'emergency') {
                    $scoreA += 40;
                }
                if (get_catching_role($b) === 'emergency') {
                    $scoreB += 40;
                }

                $scoreA += ($benchCounts[$aId] ?? 0) * -12;
                $scoreB += ($benchCounts[$bId] ?? 0) * -12;

                if (($lastBenchInning[$aId] ?? 0) === $inning - 1) {
                    $scoreA += 20;
                }
                if (($lastBenchInning[$bId] ?? 0) === $inning - 1) {
                    $scoreB += 20;
                }

                if ($scoreA !== $scoreB) {
                    return $scoreA <=> $scoreB;
                }

                $nameA = isset($a['name']) ? (string)$a['name'] : '';
$nameB = isset($b['name']) ? (string)$b['name'] : '';

return strcmp($nameA, $nameB);
            });
        } else {
            $candidates[$pos] = array_values(array_filter(
                $available,
                function (array $p) use ($pos, $cfg, $relaxedMode): bool {
                    return can_play_position_mode($p, $pos, $cfg, $relaxedMode);
                }
            ));

            usort($candidates[$pos], function (array $a, array $b) use (
                $pos,
                $positionCounts,
                $lastPositionByPlayer,
                $catchCounts,
                $benchCounts,
                $lastBenchInning,
                $cfg,
                $relaxedMode,
                $inning
            ): int {
                $aId = (int)$a['id'];
                $bId = (int)$b['id'];

                $scoreA = 0;
                $scoreB = 0;

                $validPositionsA = count_valid_positions_for_player($a, $cfg, $relaxedMode);
                $validPositionsB = count_valid_positions_for_player($b, $cfg, $relaxedMode);

                $scoreA += max(0, 6 - $validPositionsA) * 6;
                $scoreB += max(0, 6 - $validPositionsB) * 6;

                $aCanPlayExact = in_array($pos, array_map('strtoupper', $a['can_play'] ?? []), true);
                $bCanPlayExact = in_array($pos, array_map('strtoupper', $b['can_play'] ?? []), true);

                if ($aCanPlayExact && !$bCanPlayExact) {
                    $scoreA -= 15;
                }
                if ($bCanPlayExact && !$aCanPlayExact) {
                    $scoreB -= 15;
                }

                if (!empty($cfg['balance_position_variety'])) {
                    $scoreA += ($positionCounts[$aId][$pos] ?? 0) * 10;
                    $scoreB += ($positionCounts[$bId][$pos] ?? 0) * 10;

                    if (($lastPositionByPlayer[$aId] ?? null) === $pos) {
                        $scoreA += 8;
                    }
                    if (($lastPositionByPlayer[$bId] ?? null) === $pos) {
                        $scoreB += 8;
                    }
                }

                if (!empty($cfg['protect_catcher_workload']) && $pos === 'C') {
                    $scoreA += ($catchCounts[$aId] ?? 0) * 12;
                    $scoreB += ($catchCounts[$bId] ?? 0) * 12;
                }

                if (!empty($cfg['balance_bench_fairness'])) {
                    $scoreA += ($benchCounts[$aId] ?? 0) * -12;
                    $scoreB += ($benchCounts[$bId] ?? 0) * -12;

                    if (($lastBenchInning[$aId] ?? 0) === $inning - 1) {
                        $scoreA += 20;
                    }
                    if (($lastBenchInning[$bId] ?? 0) === $inning - 1) {
                        $scoreB += 20;
                    }
                }

                if (!empty($cfg['balance_season_bench_fairness'])) {
                    $seasonBenchTotals = $cfg['season_bench_totals'] ?? [];
                    $scoreA += ($seasonBenchTotals[$aId] ?? 0) * -5;
                    $scoreB += ($seasonBenchTotals[$bId] ?? 0) * -5;
                }

                if ($scoreA !== $scoreB) {
                    return $scoreA <=> $scoreB;
                }

                $nameA = isset($a['name']) ? (string)$a['name'] : '';
                    $nameB = isset($b['name']) ? (string)$b['name'] : '';

                    return strcmp($nameA, $nameB);
            });
        }

        if (empty($candidates[$pos])) {
            return null;
        }
    }

    $positionOrder = $positions;
    usort($positionOrder, function (string $a, string $b) use ($candidates): int {
        return count($candidates[$a]) <=> count($candidates[$b]);
    });

    $usedIds = [];
    $assignment = [];

    $backtrack = function (int $index) use (&$backtrack, $positionOrder, $candidates, &$usedIds, &$assignment): bool {
        if ($index >= count($positionOrder)) {
            return true;
        }

        $position = $positionOrder[$index];

        foreach ($candidates[$position] as $player) {
            $playerId = (int)$player['id'];

            if (isset($usedIds[$playerId])) {
                continue;
            }

            $usedIds[$playerId] = true;
            $assignment[$position] = $player;

            if ($backtrack($index + 1)) {
                return true;
            }

            unset($usedIds[$playerId], $assignment[$position]);
        }

        return false;
    };

    if (!$backtrack(0)) {
        return null;
    }

    return $assignment;
}

function can_play_position_mode(array $player, string $position, array $cfg, bool $relaxedMode): bool
{
    $position = strtoupper(trim($position));

    $canPlay = $player['can_play'] ?? [];
    $cannotPlay = $player['cannot_play'] ?? [];

    if (is_string($canPlay)) {
        $canPlay = array_filter(array_map('trim', explode(',', $canPlay)));
    }

    if (is_string($cannotPlay)) {
        $cannotPlay = array_filter(array_map('trim', explode(',', $cannotPlay)));
    }

    $canPlay = array_values(array_unique(array_map(
        fn($pos): string => strtoupper(trim((string)$pos)),
        is_array($canPlay) ? $canPlay : []
    )));

    $cannotPlay = array_values(array_unique(array_map(
        fn($pos): string => strtoupper(trim((string)$pos)),
        is_array($cannotPlay) ? $cannotPlay : []
    )));

    // Cannot Play is always a hard safety block.
    // Relaxed mode may loosen Can Play, but it must never override Cannot Play.
    if (in_array($position, $cannotPlay, true)) {
        return false;
    }

    // Pitcher is handled separately through pitcher role and eligibility.
    // Do not allow relaxed mode to place random players at P.
    if ($position === 'P') {
        return in_array('P', $canPlay, true);
    }

    if (!$relaxedMode && !in_array($position, $canPlay, true)) {
        return false;
    }

    return true;
}

function count_valid_positions_for_player(array $player, array $cfg, bool $relaxedMode = false): int
{
    $count = 0;

    foreach (($cfg['positions'] ?? []) as $position) {
        if ($position === 'P') {
            continue;
        }

        if (can_play_position_mode($player, $position, $cfg, $relaxedMode)) {
            $count++;
        }
    }

    return $count;
}
function pitcher_position_allowed(array $player): bool
{
    $canPlay = $player['can_play'] ?? [];
    $cannotPlay = $player['cannot_play'] ?? [];

    if (is_string($canPlay)) {
        $canPlay = array_filter(array_map('trim', explode(',', $canPlay)));
    }

    if (is_string($cannotPlay)) {
        $cannotPlay = array_filter(array_map('trim', explode(',', $cannotPlay)));
    }

    $canPlay = array_values(array_unique(array_map(
        fn($pos): string => strtoupper(trim((string)$pos)),
        is_array($canPlay) ? $canPlay : []
    )));

    $cannotPlay = array_values(array_unique(array_map(
        fn($pos): string => strtoupper(trim((string)$pos)),
        is_array($cannotPlay) ? $cannotPlay : []
    )));

    if (in_array('P', $cannotPlay, true)) {
        return false;
    }

    return in_array('P', $canPlay, true);
}
function is_eligible_pitcher(
    array $player,
    ?int $currentPitcherId,
    array $pitchedEver,
    array $retiredPitchers,
    array $pitchInningsGame,
    array $cfg
): bool {
    $playerId = (int)$player['id'];
    $role = get_pitching_role($player);

    if ($role === 'none') {
        return false;
    }

    if (!pitcher_position_allowed($player)) {
        return false;
    }

    if (isset($retiredPitchers[$playerId])) {
        return false;
    }

    $innings = (int)($pitchInningsGame[$playerId] ?? 0);
    if ($innings >= (int)$cfg['max_pitch_innings_per_game']) {
        return false;
    }

    if (!empty($cfg['prefer_pitcher_continuity']) && $currentPitcherId !== null && $playerId === $currentPitcherId) {
        return true;
    }

    return !isset($pitchedEver[$playerId]);
}
function score_pitcher(
    array $player,
    ?int $currentPitcherId,
    array $pitchedEver,
    array $pitchInningsGame,
    array $cfg
): int {
    $playerId = (int)$player['id'];
    $innings = (int)($pitchInningsGame[$playerId] ?? 0);
    $maxInnings = (int)($cfg['max_pitch_innings_per_game'] ?? 3);

    $isCurrent = $currentPitcherId !== null && $playerId === $currentPitcherId;
    $isFresh = !isset($pitchedEver[$playerId]);
    $role = get_pitching_role($player);

    $rolePenalty = $role === 'emergency' ? 50 : 0;

    // Keep the current pitcher until they hit the max.
    // This prevents switching too early and running out of pitchers.
    if (!empty($cfg['prefer_pitcher_continuity']) && $isCurrent && $innings < $maxInnings) {
        return 0 + $rolePenalty;
    }

    if ($isFresh) {
        return 20 + $rolePenalty;
    }

    if ($isCurrent) {
        return 40 + $innings + $rolePenalty;
    }

    return 100 + $innings + $rolePenalty;
}

/*
|--------------------------------------------------------------------------
| Persistence helpers
|--------------------------------------------------------------------------
*/

function save_generated_lineup(int $teamId, int $gameDbId, array $result): void
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);

    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    $pdo = db();
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $delLineup = $pdo->prepare("
            DELETE FROM lineup_entries
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $delBench = $pdo->prepare("
            DELETE FROM bench_entries
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $delPitch = $pdo->prepare("
            DELETE FROM pitch_log
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $delLineup->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $delBench->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $delPitch->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $insLineup = $pdo->prepare("
            INSERT INTO lineup_entries (
                team_id,
                game_db_id,
                inning_num,
                position_code,
                player_id
            ) VALUES (
                :team_id,
                :game_db_id,
                :inning_num,
                :position_code,
                :player_id
            )
        ");

        foreach (($result['lineup_grid'] ?? []) as $position => $inningMap) {
            foreach ($inningMap as $inning => $player) {
                if (!is_array($player) || empty($player['id'])) {
                    continue;
                }

                $insLineup->execute([
                    'team_id' => $teamId,
                    'game_db_id' => $gameDbId,
                    'inning_num' => (int)$inning,
                    'position_code' => (string)$position,
                    'player_id' => (int)$player['id'],
                ]);
            }
        }

        if (!empty($result['bench_grid'])) {
            $insBench = $pdo->prepare("
                INSERT INTO bench_entries (
                    team_id,
                    game_db_id,
                    inning_num,
                    bench_slot,
                    player_id
                ) VALUES (
                    :team_id,
                    :game_db_id,
                    :inning_num,
                    :bench_slot,
                    :player_id
                )
            ");

            foreach ($result['bench_grid'] as $benchSlotIndex => $inningMap) {
                foreach ($inningMap as $inning => $player) {
                    if (!is_array($player) || empty($player['id'])) {
                        continue;
                    }

                    $insBench->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $gameDbId,
                        'inning_num' => (int)$inning,
                        'bench_slot' => (int)$benchSlotIndex + 1,
                        'player_id' => (int)$player['id'],
                    ]);
                }
            }
        }

        $pitchLog = $result['pitch_log'] ?? [];

        if (!empty($pitchLog)) {
            $insPitch = $pdo->prepare("
                INSERT INTO pitch_log (
                    team_id,
                    game_db_id,
                    player_id,
                    innings_pitched,
                    pitches_thrown
                ) VALUES (
                    :team_id,
                    :game_db_id,
                    :player_id,
                    :innings_pitched,
                    0
                )
            ");

            foreach ($pitchLog as $playerId => $innings) {
                $insPitch->execute([
                    'team_id' => $teamId,
                    'game_db_id' => $gameDbId,
                    'player_id' => (int)$playerId,
                    'innings_pitched' => (int)$innings,
                ]);
            }
        }

        $updGame = $pdo->prepare("
            UPDATE games
            SET status = 'generated',
                roster_size = :roster_size,
                bench_count = :bench_count
            WHERE id = :id
              AND team_id = :team_id
        ");

        $updGame->execute([
            'id' => $gameDbId,
            'team_id' => $teamId,
            'roster_size' => (int)($result['roster_size'] ?? 0),
            'bench_count' => (int)($result['bench_count'] ?? 0),
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }

    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
function generate_lineup_suggestions(array $lineupResult, array $roster = []): array
{
    $suggestions = [];

    $suggestions = array_merge(
        $suggestions,
        suggest_bench_streak_fixes($lineupResult, $roster),
        suggest_pitcher_catcher_load_fixes($lineupResult, $roster),
        suggest_position_overuse_fixes($lineupResult, $roster)
    );

    usort($suggestions, function (array $a, array $b): int {
        return (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0);
    });

    return array_slice($suggestions, 0, 5);
}

function build_inning_player_maps(array $lineupResult): array
{
    $maps = [];
    $positions = $lineupResult['positions'] ?? [];
    $innings = (int)($lineupResult['innings'] ?? 0);
    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];
    $benchCount = (int)($lineupResult['bench_count'] ?? 0);

    for ($inning = 1; $inning <= $innings; $inning++) {
        $maps[$inning] = [
            'field' => [],
            'bench' => [],
        ];

        foreach ($positions as $position) {
            $cell = $lineupGrid[$position][$inning] ?? null;

            if (is_array($cell) && !empty($cell['id'])) {
                $maps[$inning]['field'][(int)$cell['id']] = [
                    'player' => $cell,
                    'position' => $position,
                ];
            }
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $cell = $benchGrid[$slot][$inning] ?? null;

            if (is_array($cell) && !empty($cell['id'])) {
                $maps[$inning]['bench'][(int)$cell['id']] = [
                    'player' => $cell,
                    'slot' => $slot,
                ];
            }
        }
    }

    return $maps;
}

function suggest_bench_streak_fixes(array $lineupResult, array $roster = []): array
{
    $suggestions = [];
    $maps = build_inning_player_maps($lineupResult);
    $fairness = calculate_lineup_fairness($lineupResult);
    $players = $fairness['players'] ?? [];

    $rosterMap = [];
    foreach ($roster as $player) {
        $rosterMap[(int)($player['id'] ?? 0)] = $player;
    }

    foreach ($players as $summary) {
        $playerId = (int)($summary['player_id'] ?? 0);
        $longestBenchStreak = (int)($summary['longest_bench_streak'] ?? 0);

        if ($playerId <= 0 || $longestBenchStreak < 3) {
            continue;
        }

        $benchRun = 0;

        foreach ($maps as $inning => $map) {
            $isBenched = isset($map['bench'][$playerId]);

            if ($isBenched) {
                $benchRun++;
            } else {
                $benchRun = 0;
            }

            if ($benchRun >= 3) {
                foreach ($map['field'] as $fieldPlayerId => $fieldData) {
                    if ($fieldPlayerId === $playerId) {
                        continue;
                    }

                    $benchPlayer = $rosterMap[$playerId] ?? [
                        'first_name' => '',
                        'last_name' => '',
                        'name' => (string)($summary['name'] ?? 'Player'),
                    ];

                    $fieldPlayer = $rosterMap[$fieldPlayerId] ?? [
                        'first_name' => '',
                        'last_name' => '',
                        'name' => (string)($fieldData['player']['name'] ?? 'Player'),
                    ];

                    $benchName = trim((string)($benchPlayer['name'] ?? ''));
                    if ($benchName === '') {
                        $benchName = player_full_name($benchPlayer);
                    }

                    $fieldName = trim((string)($fieldPlayer['name'] ?? ''));
                    if ($fieldName === '') {
                        $fieldName = player_full_name($fieldPlayer);
                    }

                    $suggestions[] = [
                        'type' => 'bench_streak',
                        'message' => 'Swap ' . $benchName . ' in for ' . $fieldName . ' in inning ' . $inning . ' to reduce a long bench streak.',
                        'inning' => (int)$inning,
                        'player_id' => $playerId,
                        'swap_with_player_id' => (int)$fieldPlayerId,
                        'position' => (string)$fieldData['position'],
                        'priority' => 100 - (int)$inning,
                    ];

                    break 2;
                }
            }
        }
    }

    return $suggestions;
}

function suggest_pitcher_catcher_load_fixes(array $lineupResult, array $roster = []): array
{
    $suggestions = [];
    $positions = $lineupResult['positions'] ?? [];
    $innings = (int)($lineupResult['innings'] ?? 0);
    $lineupGrid = $lineupResult['lineup_grid'] ?? [];

    $roleLimits = [
        'P' => 3,
        'C' => 3,
    ];

    $usage = [];
    $rosterMap = [];

    foreach ($roster as $player) {
        $rosterMap[(int)($player['id'] ?? 0)] = $player;
    }

    foreach ($positions as $position) {
        for ($inning = 1; $inning <= $innings; $inning++) {
            $cell = $lineupGrid[$position][$inning] ?? null;

            if (!is_array($cell) || empty($cell['id'])) {
                continue;
            }

            $playerId = (int)$cell['id'];
            $usage[$playerId][$position] = ($usage[$playerId][$position] ?? 0) + 1;
        }
    }

    foreach ($usage as $playerId => $playerUsage) {
        foreach ($roleLimits as $position => $limit) {
            $count = (int)($playerUsage[$position] ?? 0);

            if ($count <= $limit) {
                continue;
            }

            for ($inning = 1; $inning <= $innings; $inning++) {
                $cell = $lineupGrid[$position][$inning] ?? null;

                if ((int)($cell['id'] ?? 0) !== $playerId) {
                    continue;
                }

                $player = $rosterMap[$playerId] ?? [
                    'first_name' => '',
                    'last_name' => '',
                    'name' => (string)($cell['name'] ?? 'Player'),
                ];

                $playerName = trim((string)($player['name'] ?? ''));
                if ($playerName === '') {
                    $playerName = player_full_name($player);
                }

                $suggestions[] = [
                    'type' => 'heavy_role_usage',
                    'message' => 'Consider changing ' . $playerName . ' out of ' . ($position === 'P' ? 'pitcher' : 'catcher') . ' in inning ' . $inning . ' to reduce heavy ' . ($position === 'P' ? 'pitching' : 'catching') . ' usage.',
                    'inning' => $inning,
                    'player_id' => $playerId,
                    'position' => $position,
                    'priority' => $position === 'P' ? 90 : 85,
                ];

                break;
            }
        }
    }

    return $suggestions;
}

function suggest_position_overuse_fixes(array $lineupResult, array $roster = []): array
{
    $suggestions = [];
    $positions = $lineupResult['positions'] ?? [];
    $innings = (int)($lineupResult['innings'] ?? 0);
    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];
    $benchCount = (int)($lineupResult['bench_count'] ?? 0);

    $positionLimits = [
        'SS' => 3,
        'CF' => 3,
        '1B' => 4,
        '2B' => 4,
        '3B' => 4,
        'LF' => 4,
        'RF' => 4,
    ];

    $rosterMap = [];
    foreach ($roster as $player) {
        $rosterMap[(int)($player['id'] ?? 0)] = $player;
    }

    $usage = [];

    foreach ($positions as $position) {
        if ($position === 'P' || $position === 'C') {
            continue;
        }

        for ($inning = 1; $inning <= $innings; $inning++) {
            $cell = $lineupGrid[$position][$inning] ?? null;

            if (!is_array($cell) || empty($cell['id'])) {
                continue;
            }

            $playerId = (int)$cell['id'];
            $usage[$playerId][$position][] = $inning;
        }
    }

    foreach ($usage as $playerId => $playerPositions) {
        foreach ($playerPositions as $position => $inningList) {
            $limit = (int)($positionLimits[$position] ?? 4);

            if (count($inningList) <= $limit) {
                continue;
            }

            $flagInning = (int)($inningList[$limit] ?? 0);
            if ($flagInning <= 0) {
                continue;
            }

            $overusedPlayer = $rosterMap[$playerId] ?? [
                'first_name' => '',
                'last_name' => '',
                'name' => 'Player',
            ];

            $overusedName = trim((string)($overusedPlayer['name'] ?? ''));
            if ($overusedName === '') {
                $overusedName = player_full_name($overusedPlayer);
            }

            $swapFound = false;

            for ($slot = 0; $slot < $benchCount; $slot++) {
                $benchCell = $benchGrid[$slot][$flagInning] ?? null;

                if (!is_array($benchCell) || empty($benchCell['id'])) {
                    continue;
                }

                $benchPlayerId = (int)$benchCell['id'];
                if ($benchPlayerId === $playerId) {
                    continue;
                }

                $benchPlayer = $rosterMap[$benchPlayerId] ?? [
                    'first_name' => '',
                    'last_name' => '',
                    'name' => (string)($benchCell['name'] ?? 'Player'),
                ];

                $benchName = trim((string)($benchPlayer['name'] ?? ''));
                if ($benchName === '') {
                    $benchName = player_full_name($benchPlayer);
                }

                $suggestions[] = [
                    'type' => 'position_overuse',
                    'message' => 'Swap ' . $overusedName . ' with ' . $benchName . ' in inning ' . $flagInning . ' to improve position rotation at ' . $position . '.',
                    'inning' => $flagInning,
                    'player_id' => $benchPlayerId,
                    'swap_with_player_id' => $playerId,
                    'position' => $position,
                    'priority' => ($position === 'SS' || $position === 'CF') ? 80 : 70,
                ];

                $swapFound = true;
                break;
            }

            if ($swapFound) {
                continue;
            }

            foreach ($positions as $otherPosition) {
                if ($otherPosition === $position || $otherPosition === 'P' || $otherPosition === 'C') {
                    continue;
                }

                $otherCell = $lineupGrid[$otherPosition][$flagInning] ?? null;

                if (!is_array($otherCell) || empty($otherCell['id'])) {
                    continue;
                }

                $otherPlayerId = (int)$otherCell['id'];
                if ($otherPlayerId === $playerId) {
                    continue;
                }

                $otherPlayer = $rosterMap[$otherPlayerId] ?? [
                    'first_name' => '',
                    'last_name' => '',
                    'name' => (string)($otherCell['name'] ?? 'Player'),
                ];

                $otherName = trim((string)($otherPlayer['name'] ?? ''));
                if ($otherName === '') {
                    $otherName = player_full_name($otherPlayer);
                }

                $suggestions[] = [
                    'type' => 'position_overuse',
                    'message' => 'Swap ' . $overusedName . ' with ' . $otherName . ' in inning ' . $flagInning . ' to improve position rotation at ' . $position . '.',
                    'inning' => $flagInning,
                    'player_id' => $otherPlayerId,
                    'swap_with_player_id' => $playerId,
                    'position' => $position,
                    'priority' => ($position === 'SS' || $position === 'CF') ? 80 : 70,
                ];

                break;
            }
        }
    }

    return $suggestions;
}


function load_generated_lineup(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    $pdo = db();

    $lineupStmt = $pdo->prepare("
        SELECT
            le.inning_num,
            le.position_code,
            p.id AS player_id,
            p.name,
            p.jersey_number
        FROM lineup_entries le
        INNER JOIN players p
          ON p.id = le.player_id
         AND p.team_id = le.team_id
        WHERE le.team_id = :team_id
          AND le.game_db_id = :game_db_id
        ORDER BY le.position_code ASC, le.inning_num ASC
    ");
    $lineupStmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
    ]);
    $lineupRows = $lineupStmt->fetchAll();

    $benchStmt = $pdo->prepare("
        SELECT
            be.inning_num,
            be.bench_slot,
            p.id AS player_id,
            p.name,
            p.jersey_number
        FROM bench_entries be
        INNER JOIN players p
          ON p.id = be.player_id
         AND p.team_id = be.team_id
        WHERE be.team_id = :team_id
          AND be.game_db_id = :game_db_id
        ORDER BY be.bench_slot ASC, be.inning_num ASC
    ");
    $benchStmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
    ]);
    $benchRows = $benchStmt->fetchAll();

    $lineupGrid = [];
    foreach ($lineupRows as $row) {
        $pos = (string)$row['position_code'];
        $inning = (int)$row['inning_num'];

        $lineupGrid[$pos][$inning] = [
            'id' => (int)$row['player_id'],
            'name' => (string)$row['name'],
            'jersey_number' => (string)($row['jersey_number'] ?? ''),
        ];
    }

    $benchGrid = [];
    foreach ($benchRows as $row) {
        $slot = (int)$row['bench_slot'] - 1;
        $inning = (int)$row['inning_num'];

        $benchGrid[$slot][$inning] = [
            'id' => (int)$row['player_id'],
            'name' => (string)$row['name'],
            'jersey_number' => (string)($row['jersey_number'] ?? ''),
        ];
    }

    return [
        'lineup_grid' => $lineupGrid,
        'bench_grid' => $benchGrid,
    ];
}

function calculate_pitch_log_from_lineup(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT player_id, COUNT(*) AS innings_pitched
        FROM lineup_entries
        WHERE team_id = :team_id
          AND game_db_id = :game_db_id
          AND position_code = 'P'
        GROUP BY player_id
    ");
    $stmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
    ]);

    $rows = $stmt->fetchAll();
    $pitchLog = [];

    foreach ($rows as $row) {
        $pitchLog[(int)$row['player_id']] = (int)$row['innings_pitched'];
    }

    return $pitchLog;
}

function get_generated_lineup_result(int $teamId, int $gameDbId): array
{
    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    $roster = get_game_roster($teamId, $gameDbId);
    $cfg = lineup_engine_config();

    $rosterCount = count($roster);
    $positions = game_defensive_positions_for_roster_count($rosterCount);

    $innings = (int)($game['innings'] ?? ($cfg['innings'] ?? 7));
    $benchCount = (int)($game['bench_count'] ?? max(0, count($roster) - count($positions)));

    $saved = load_generated_lineup($teamId, $gameDbId);

    return [
        'lineup_grid' => $saved['lineup_grid'] ?? [],
        'bench_grid' => $saved['bench_grid'] ?? [],
        'pitch_log' => calculate_pitch_log_from_lineup($teamId, $gameDbId),
        'bench_count' => $benchCount,
        'roster_size' => (int)($game['roster_size'] ?? count($roster)),
        'innings' => $innings,
        'positions' => $positions,
    ];
}

/*
|--------------------------------------------------------------------------
| Manual edit helpers
|--------------------------------------------------------------------------
*/

function validate_manual_lineup_payload(
    int $teamId,
    int $gameDbId,
    array $lineupGrid,
    array $benchGrid
): array {
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    $roster = get_game_roster($teamId, $gameDbId);
    if (count($roster) < 8) {
        throw new RuntimeException('This game needs at least 8 rostered players.');
    }

    $cfg = lineup_engine_config();
    $positions = game_defensive_positions_for_roster_count(count($roster));
    $innings = (int)($game['innings'] ?? 7);
    $rosterIds = array_map(fn(array $p): int => (int)$p['id'], $roster);
    $rosterIdSet = array_fill_keys($rosterIds, true);
    $benchCount = max(0, count($rosterIds) - count($positions));

    $normalizedLineup = [];
    $normalizedBench = [];

    for ($inning = 1; $inning <= $innings; $inning++) {
        $usedThisInning = [];

        foreach ($positions as $position) {
            $playerId = (int)($lineupGrid[$position][$inning] ?? 0);

            if ($playerId <= 0) {
                throw new RuntimeException("Missing player for {$position} in inning {$inning}.");
            }

            if (!isset($rosterIdSet[$playerId])) {
                throw new RuntimeException("Invalid player assigned to {$position} in inning {$inning}.");
            }

            if (isset($usedThisInning[$playerId])) {
                throw new RuntimeException("Duplicate player assignment in inning {$inning}.");
            }

            $usedThisInning[$playerId] = true;
            $normalizedLineup[$position][$inning] = $playerId;
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $playerId = (int)($benchGrid[$slot][$inning] ?? 0);

            if ($playerId <= 0) {
                throw new RuntimeException('Every bench slot must have a player assigned.');
            }

            if (!isset($rosterIdSet[$playerId])) {
                throw new RuntimeException("Invalid bench player in inning {$inning}.");
            }

            if (isset($usedThisInning[$playerId])) {
                throw new RuntimeException("Duplicate player assignment in inning {$inning}.");
            }

            $usedThisInning[$playerId] = true;
            $normalizedBench[$slot][$inning] = $playerId;
        }

        if (count($usedThisInning) !== count($rosterIds)) {
            throw new RuntimeException("Each inning must use every rostered player exactly once. Problem found in inning {$inning}.");
        }
    }

    return [
        'game' => $game,
        'roster' => $roster,
        'positions' => $positions,
        'innings' => $innings,
        'bench_count' => $benchCount,
        'lineup_grid' => $normalizedLineup,
        'bench_grid' => $normalizedBench,
    ];
}

function save_manual_lineup(
    int $teamId,
    int $gameDbId,
    array $lineupGrid,
    array $benchGrid
): void {
    $validated = validate_manual_lineup_payload($teamId, $gameDbId, $lineupGrid, $benchGrid);
    $positions = $validated['positions'];
    $innings = $validated['innings'];
    $benchCount = $validated['bench_count'];
    $normalizedLineup = $validated['lineup_grid'];
    $normalizedBench = $validated['bench_grid'];

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $delLineup = $pdo->prepare("
            DELETE FROM lineup_entries
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");
        $delBench = $pdo->prepare("
            DELETE FROM bench_entries
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");
        $delPitch = $pdo->prepare("
            DELETE FROM pitch_log
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $delLineup->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);
        $delBench->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);
        $delPitch->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $insertLineup = $pdo->prepare("
            INSERT INTO lineup_entries (team_id, game_db_id, inning_num, position_code, player_id)
            VALUES (:team_id, :game_db_id, :inning_num, :position_code, :player_id)
        ");

        for ($inning = 1; $inning <= $innings; $inning++) {
            foreach ($positions as $position) {
                $insertLineup->execute([
                    'team_id' => $teamId,
                    'game_db_id' => $gameDbId,
                    'inning_num' => $inning,
                    'position_code' => $position,
                    'player_id' => (int)$normalizedLineup[$position][$inning],
                ]);
            }
        }

        if ($benchCount > 0) {
            $insertBench = $pdo->prepare("
                INSERT INTO bench_entries (team_id, game_db_id, inning_num, bench_slot, player_id)
                VALUES (:team_id, :game_db_id, :inning_num, :bench_slot, :player_id)
            ");

            for ($inning = 1; $inning <= $innings; $inning++) {
                for ($slot = 0; $slot < $benchCount; $slot++) {
                    $insertBench->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $gameDbId,
                        'inning_num' => $inning,
                        'bench_slot' => $slot + 1,
                        'player_id' => (int)$normalizedBench[$slot][$inning],
                    ]);
                }
            }
        }

        $pitchLog = [];
        for ($inning = 1; $inning <= $innings; $inning++) {
            $pitcherId = (int)$normalizedLineup['P'][$inning];
            $pitchLog[$pitcherId] = ($pitchLog[$pitcherId] ?? 0) + 1;
        }

        if (!empty($pitchLog)) {
            $insertPitch = $pdo->prepare("
                INSERT INTO pitch_log (team_id, game_db_id, player_id, innings_pitched, pitches_thrown)
                VALUES (:team_id, :game_db_id, :player_id, :innings_pitched, 0)
            ");

            foreach ($pitchLog as $playerId => $inningsPitched) {
                $insertPitch->execute([
                    'team_id' => $teamId,
                    'game_db_id' => $gameDbId,
                    'player_id' => (int)$playerId,
                    'innings_pitched' => (int)$inningsPitched,
                ]);
            }
        }

        $updateGame = $pdo->prepare("
            UPDATE games
            SET status = 'generated'
            WHERE id = :id
              AND team_id = :team_id
        ");
        $updateGame->execute([
            'id' => $gameDbId,
            'team_id' => $teamId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| Archive / finalize helpers
|--------------------------------------------------------------------------
*/

function build_archive_payload(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for archive.');
    }

    $pdo = db();
    $cfg = lineup_engine_config();

    $rosterStmt = $pdo->prepare("
        SELECT
            gr.batting_order,
            p.id,
            p.name,
            p.jersey_number
        FROM game_roster gr
        INNER JOIN players p
          ON p.id = gr.player_id
         AND p.team_id = gr.team_id
        WHERE gr.team_id = :team_id
          AND gr.game_db_id = :game_db_id
        ORDER BY gr.batting_order ASC
    ");
    $rosterStmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
    ]);
    $roster = $rosterStmt->fetchAll();

    $lineup = load_generated_lineup($teamId, $gameDbId);
    $pitchLog = get_pitch_log_for_game($teamId, $gameDbId);

    $rosterCount = count($roster);
    $game['positions'] = game_defensive_positions_for_roster_count($rosterCount);

    return [
        'game' => $game,
        'roster' => $roster,
        'lineup' => $lineup,
        'pitch_log' => $pitchLog,
        'archived_at' => date('Y-m-d H:i:s'),
    ];
}

function lock_generated_lineup(int $teamId, int $gameDbId): void
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found for this team.');
    }

    if (($game['status'] ?? '') !== 'generated') {
        throw new RuntimeException('Only generated games can be locked.');
    }

    $pitchLog = calculate_pitch_log_from_lineup($teamId, $gameDbId);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $selectExistingPitch = $pdo->prepare("
            SELECT pitches_thrown
            FROM pitch_log
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
              AND player_id = :player_id
            LIMIT 1
        ");

        $upsertPitch = $pdo->prepare("
            INSERT INTO pitch_log (
                team_id,
                game_db_id,
                player_id,
                innings_pitched,
                pitches_thrown
            )
            VALUES (
                :team_id,
                :game_db_id,
                :player_id,
                :innings_pitched,
                :pitches_thrown
            )
            ON DUPLICATE KEY UPDATE
                innings_pitched = VALUES(innings_pitched),
                pitches_thrown = VALUES(pitches_thrown)
        ");

        foreach ($pitchLog as $playerId => $innings) {
            $selectExistingPitch->execute([
                'team_id' => $teamId,
                'game_db_id' => $gameDbId,
                'player_id' => (int)$playerId,
            ]);

            $existingPitchesThrown = $selectExistingPitch->fetchColumn();
            $pitchesThrown = $existingPitchesThrown !== false ? (int)$existingPitchesThrown : 0;

            $upsertPitch->execute([
                'team_id' => $teamId,
                'game_db_id' => $gameDbId,
                'player_id' => (int)$playerId,
                'innings_pitched' => (int)$innings,
                'pitches_thrown' => $pitchesThrown,
            ]);
        }

        $archivePayload = build_archive_payload($teamId, $gameDbId);

        $archiveJson = json_encode($archivePayload, JSON_UNESCAPED_UNICODE);
        if ($archiveJson === false) {
            throw new RuntimeException('Failed to encode archive payload.');
        }

        $archiveStmt = $pdo->prepare("
            INSERT INTO archived_games (team_id, game_db_id, archive_json)
            VALUES (:team_id, :game_db_id, :archive_json)
            ON DUPLICATE KEY UPDATE archive_json = VALUES(archive_json)
        ");
        $archiveStmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
            'archive_json' => $archiveJson,
        ]);

        $updGame = $pdo->prepare("
            UPDATE games
            SET status = 'locked',
                locked_at = NOW()
            WHERE id = :id
              AND team_id = :team_id
        ");
        $updGame->execute([
            'id' => $gameDbId,
            'team_id' => $teamId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
