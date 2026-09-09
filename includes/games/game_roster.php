<?php
declare(strict_types=1);


function get_most_recent_roster_player_ids(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT g.id
        FROM games g
        INNER JOIN game_roster gr
        ON gr.game_db_id = g.id
        AND gr.team_id = g.team_id
        WHERE g.team_id = :team_id
        AND g.deleted_at IS NULL
        GROUP BY g.id
        ORDER BY
        CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
        g.game_date DESC,
        g.created_at DESC,
        g.id DESC
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
    ]);

    $gameId = (int) ($stmt->fetchColumn() ?: 0);
    if ($gameId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT gr.player_id
        FROM game_roster gr
        INNER JOIN players p
        ON p.id = gr.player_id
        AND p.team_id = gr.team_id
        WHERE gr.team_id = :team_id
        AND gr.game_db_id = :game_db_id
        AND p.active = 1
        ORDER BY gr.batting_order ASC
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameId,
    ]);

    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function build_default_batting_order_from_previous_game(int $teamId): array
{
    validate_team_id($teamId);

    $activePlayers = get_active_players($teamId);
    if (empty($activePlayers)) {
        return [];
    }

    $activeById = [];
    foreach ($activePlayers as $player) {
        $activeById[(int) $player["id"]] = $player;
    }

    $previousOrderIds = get_most_recent_roster_player_ids($teamId);

    $finalIds = [];
    $used = [];

    foreach ($previousOrderIds as $playerId) {
        $playerId = (int) $playerId;
        if (isset($activeById[$playerId]) && !isset($used[$playerId])) {
            $finalIds[] = $playerId;
            $used[$playerId] = true;
        }
    }

    $remainingPlayers = array_values(
        array_filter($activePlayers, function (array $player) use (
            $used,
        ): bool {
            return !isset($used[(int) $player["id"]]);
        }),
    );

    usort($remainingPlayers, function (array $a, array $b): int {
        return strcmp((string) $a["name"], (string) $b["name"]);
    });

    foreach ($remainingPlayers as $player) {
        $playerId = (int) $player["id"];
        if (!isset($used[$playerId])) {
            $finalIds[] = $playerId;
            $used[$playerId] = true;
        }
    }

    return array_slice($finalIds, 0, 14);
}

function create_game_with_default_roster(
    int $teamId,
    string $gameId,
    int $innings = 7,
    ?string $gameDate = null,
): int {
    validate_team_id($teamId);

    $gameDbId = create_game($teamId, $gameId, $innings, $gameDate);

    $defaultPlayerIds = build_default_batting_order_from_previous_game($teamId);

    if (count($defaultPlayerIds) >= 9) {
        save_game_roster($teamId, $gameDbId, $defaultPlayerIds);
    }

    return $gameDbId;
}


function save_game_roster(int $teamId, int $gameDbId, array $playerIds): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException("Game not found for this team.");
    }

    $playerIds = array_values(
        array_filter(array_map(fn($id) => (int)$id, $playerIds))
    );

    if (count($playerIds) < 8) {
        throw new InvalidArgumentException("At least 8 players are required.");
    }

    if (count($playerIds) > 14) {
        throw new InvalidArgumentException("Maximum supported roster is 14 players.");
    }

    if (count(array_unique($playerIds)) !== count($playerIds)) {
        throw new InvalidArgumentException("Duplicate players found in batting order.");
    }

    foreach ($playerIds as $playerId) {
        $player = get_player_by_id($teamId, $playerId);

        if (!$player) {
            throw new RuntimeException("One or more roster players do not belong to this team.");
        }
    }

    $pdo = db();
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $del = $pdo->prepare("
            DELETE FROM game_roster
            WHERE game_db_id = :game_db_id
              AND team_id = :team_id
        ");

        $del->execute([
            "game_db_id" => $gameDbId,
            "team_id" => $teamId,
        ]);

        $ins = $pdo->prepare("
            INSERT INTO game_roster (
                team_id,
                game_db_id,
                player_id,
                batting_order
            ) VALUES (
                :team_id,
                :game_db_id,
                :player_id,
                :batting_order
            )
        ");

        foreach ($playerIds as $index => $playerId) {
            $ins->execute([
                "team_id" => $teamId,
                "game_db_id" => $gameDbId,
                "player_id" => $playerId,
                "batting_order" => $index + 1,
            ]);
        }

        $rosterSize = count($playerIds);
        $benchCount = max(0, $rosterSize - 9);

        $upd = $pdo->prepare("
            UPDATE games
            SET roster_size = :roster_size,
                bench_count = :bench_count
            WHERE id = :id
              AND team_id = :team_id
        ");

        $upd->execute([
            "id" => $gameDbId,
            "team_id" => $teamId,
            "roster_size" => $rosterSize,
            "bench_count" => $benchCount,
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

function game_defensive_positions_for_roster_count(int $rosterCount): array
{
    if ($rosterCount <= 8) {
        return ["P", "C", "1B", "2B", "3B", "SS", "LF", "RF"];
    }

    return ["P", "C", "1B", "2B", "3B", "SS", "LF", "CF", "RF"];
}

function game_display_positions_for_roster_count(int $rosterCount): array
{
    return ["P", "C", "1B", "2B", "3B", "SS", "LF", "CF", "RF"];
}

function get_game_roster(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        gr.batting_order,
        p.id,
        p.team_id,
        p.first_name,
        p.last_name,
        p.jersey_number,
        p.active
        FROM game_roster gr
        INNER JOIN players p
        ON p.id = gr.player_id
        WHERE gr.game_db_id = :game_db_id
        AND gr.team_id = :team_id
        AND p.team_id = :team_id
        AND p.active = 1
        ORDER BY gr.batting_order ASC
        ");
    $stmt->execute([
        "game_db_id" => $gameDbId,
        "team_id" => $teamId,
    ]);

    $roster = $stmt->fetchAll();

    foreach ($roster as &$player) {
        $full = get_player_by_id($teamId, (int) $player["id"]);
        $player["can_play"] = $full["can_play"] ?? [];
        $player["cannot_play"] = $full["cannot_play"] ?? [];
        $player["pitching_role"] = $full["pitching_role"] ?? "none";
        $player["catching_role"] = $full["catching_role"] ?? "none";
    }
    unset($player);

    return $roster;
}

function remove_player_from_game_lineup(int $teamId, int $gameDbId, int $playerId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0 || $playerId <= 0) {
        throw new InvalidArgumentException('Invalid game or player.');
    }

    $pdo = db();
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $game = get_game_by_id($teamId, $gameDbId);

        if (!$game) {
            throw new RuntimeException('Game not found.');
        }

        if ((string)($game['status'] ?? '') === 'locked') {
            throw new RuntimeException('Locked games cannot be edited.');
        }

        $currentLineup = get_generated_lineup_result($teamId, $gameDbId);

        if (!is_array($currentLineup)) {
            throw new RuntimeException('No generated lineup found for this game.');
        }

        $positions = $currentLineup['positions'] ?? [];
        $innings = (int)($currentLineup['innings'] ?? ($game['innings'] ?? 0));

        if (empty($positions) || $innings <= 0) {
            throw new RuntimeException('Saved lineup data is invalid.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM game_roster
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
              AND player_id = :player_id
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
            'player_id' => $playerId,
        ]);

        $remainingRoster = get_game_roster($teamId, $gameDbId);

        if (count($remainingRoster) < 8) {
            throw new RuntimeException('A game needs at least 8 players.');
        }

        $newPositions = game_defensive_positions_for_roster_count(count($remainingRoster));
        $displayPositions = game_display_positions_for_roster_count(count($remainingRoster));

        $rosterMap = [];

        foreach ($remainingRoster as $player) {
            $rosterMap[(int)$player['id']] = [
                'id' => (int)$player['id'],
                'name' => player_full_name($player),
                'first_name' => (string)($player['first_name'] ?? ''),
                'last_name' => (string)($player['last_name'] ?? ''),
                'jersey_number' => (string)($player['jersey_number'] ?? ''),
            ];
        }

        $lineupGrid = $currentLineup['lineup_grid'] ?? [];
        $benchGrid = $currentLineup['bench_grid'] ?? [];

        for ($inning = 1; $inning <= $innings; $inning++) {
            $assignedThisInning = [];

            foreach ($newPositions as $position) {
                $cell = $lineupGrid[$position][$inning] ?? null;

                if (is_array($cell) && (int)($cell['id'] ?? 0) === $playerId) {
                    $lineupGrid[$position][$inning] = null;
                    $cell = null;
                }

                if (is_array($cell) && isset($rosterMap[(int)($cell['id'] ?? 0)])) {
                    $assignedThisInning[(int)$cell['id']] = true;
                }
            }

            foreach ($benchGrid as $slot => $inningCells) {
                $cell = $benchGrid[$slot][$inning] ?? null;

                if (is_array($cell) && (int)($cell['id'] ?? 0) === $playerId) {
                    unset($benchGrid[$slot][$inning]);
                    continue;
                }

                if (is_array($cell) && isset($rosterMap[(int)($cell['id'] ?? 0)])) {
                    $assignedThisInning[(int)$cell['id']] = true;
                }
            }

            foreach ($newPositions as $position) {
                $cell = $lineupGrid[$position][$inning] ?? null;

                if (is_array($cell)) {
                    continue;
                }

                foreach ($benchGrid as $slot => $inningCells) {
                    $benchCell = $benchGrid[$slot][$inning] ?? null;

                    if (!is_array($benchCell)) {
                        continue;
                    }

                    $benchPlayerId = (int)($benchCell['id'] ?? 0);

                    if ($benchPlayerId > 0 && isset($rosterMap[$benchPlayerId])) {
                        $lineupGrid[$position][$inning] = $benchCell;
                        unset($benchGrid[$slot][$inning]);
                        $assignedThisInning[$benchPlayerId] = true;
                        break;
                    }
                }
            }
        }

        $newBenchCount = max(0, count($remainingRoster) - count($newPositions));
        $newBenchGrid = [];

        for ($slot = 0; $slot < $newBenchCount; $slot++) {
            $newBenchGrid[$slot] = [];

            for ($inning = 1; $inning <= $innings; $inning++) {
                $assignedThisInning = [];

                foreach ($newPositions as $position) {
                    $cell = $lineupGrid[$position][$inning] ?? null;

                    if (is_array($cell) && !empty($cell['id'])) {
                        $assignedThisInning[(int)$cell['id']] = true;
                    }
                }

                foreach ($newBenchGrid as $existingSlot => $existingInnings) {
                    $cell = $existingInnings[$inning] ?? null;

                    if (is_array($cell) && !empty($cell['id'])) {
                        $assignedThisInning[(int)$cell['id']] = true;
                    }
                }

                $replacement = null;

                foreach ($rosterMap as $candidateId => $candidate) {
                    if (!isset($assignedThisInning[$candidateId])) {
                        $replacement = $candidate;
                        break;
                    }
                }

                $newBenchGrid[$slot][$inning] = $replacement;
            }
        }

        $updatedLineup = $currentLineup;
        $updatedLineup['lineup_grid'] = $lineupGrid;
        $updatedLineup['bench_grid'] = $newBenchGrid;
        $updatedLineup['bench_count'] = $newBenchCount;
        $updatedLineup['positions'] = $newPositions;
        $updatedLineup['display_positions'] = $displayPositions;
        $updatedLineup['innings'] = $innings;

        save_generated_lineup($teamId, $gameDbId, $updatedLineup);

        $stmt = $pdo->prepare("
            UPDATE games
            SET roster_size = :roster_size,
                bench_count = :bench_count
            WHERE id = :game_db_id
              AND team_id = :team_id
        ");

        $stmt->execute([
            'roster_size' => count($remainingRoster),
            'bench_count' => $newBenchCount,
            'game_db_id' => $gameDbId,
            'team_id' => $teamId,
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
function add_player_to_game_lineup(int $teamId, int $gameDbId, int $playerId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0 || $playerId <= 0) {
        throw new InvalidArgumentException('Invalid game or player.');
    }

    $player = get_player_by_id($teamId, $playerId);
    if (!$player) {
        throw new RuntimeException('Player not found for this team.');
    }

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    if ((string)($game['status'] ?? '') === 'locked') {
        throw new RuntimeException('Locked games cannot be edited.');
    }

    $pdo = db();
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $alreadyStmt = $pdo->prepare("
            SELECT id
            FROM game_roster
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
              AND player_id = :player_id
            LIMIT 1
        ");

        $alreadyStmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
            'player_id' => $playerId,
        ]);

        if ($alreadyStmt->fetch()) {
            throw new RuntimeException('Player is already on this game roster.');
        }

        $rosterBefore = get_game_roster($teamId, $gameDbId);

        if (count($rosterBefore) >= 14) {
            throw new RuntimeException('Maximum supported roster is 14 players.');
        }

        $orderStmt = $pdo->prepare("
            SELECT COALESCE(MAX(batting_order), 0) + 1
            FROM game_roster
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $orderStmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $newBattingOrder = (int)$orderStmt->fetchColumn();

        $insertRoster = $pdo->prepare("
            INSERT INTO game_roster (
                team_id,
                game_db_id,
                player_id,
                batting_order
            ) VALUES (
                :team_id,
                :game_db_id,
                :player_id,
                :batting_order
            )
        ");

        $insertRoster->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
            'player_id' => $playerId,
            'batting_order' => $newBattingOrder,
        ]);

        $roster = get_game_roster($teamId, $gameDbId);
        $positions = game_defensive_positions_for_roster_count(count($roster));
        $benchCount = max(0, count($roster) - count($positions));
        $innings = (int)($game['innings'] ?? 7);

        $playerCell = [
            'id' => (int)$player['id'],
            'name' => player_full_name($player),
            'first_name' => (string)($player['first_name'] ?? ''),
            'last_name' => (string)($player['last_name'] ?? ''),
            'jersey_number' => (string)($player['jersey_number'] ?? ''),
        ];

        $lineupResult = get_generated_lineup_result($teamId, $gameDbId);

        if (is_array($lineupResult)) {
            $lineupGrid = $lineupResult['lineup_grid'] ?? [];
            $benchGrid = $lineupResult['bench_grid'] ?? [];

            $newBenchGrid = [];

            for ($slot = 0; $slot < $benchCount; $slot++) {
                $newBenchGrid[$slot] = [];

                for ($inning = 1; $inning <= $innings; $inning++) {
                    $existingCell = $benchGrid[$slot][$inning] ?? null;

                    if (is_array($existingCell)) {
                        $newBenchGrid[$slot][$inning] = $existingCell;
                        continue;
                    }

                    $newBenchGrid[$slot][$inning] = $playerCell;
                }
            }

            $lineupResult['lineup_grid'] = $lineupGrid;
            $lineupResult['bench_grid'] = $newBenchGrid;
            $lineupResult['bench_count'] = $benchCount;
            $lineupResult['positions'] = $positions;
            $lineupResult['innings'] = $innings;

            save_generated_lineup($teamId, $gameDbId, $lineupResult);
        } else {
            $newBenchSlot = $benchCount - 1;

            if ($newBenchSlot >= 0) {
                $insertBench = $pdo->prepare("
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

                for ($inning = 1; $inning <= $innings; $inning++) {
                    $insertBench->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $gameDbId,
                        'inning_num' => $inning,
                        'bench_slot' => $newBenchSlot,
                        'player_id' => $playerId,
                    ]);
                }
            }
        }

        $updateGame = $pdo->prepare("
            UPDATE games
            SET roster_size = :roster_size,
                bench_count = :bench_count
            WHERE id = :game_db_id
              AND team_id = :team_id
        ");

        $updateGame->execute([
            'roster_size' => count($roster),
            'bench_count' => $benchCount,
            'game_db_id' => $gameDbId,
            'team_id' => $teamId,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        throw $e;
    }
}
function update_game_batting_order(int $teamId, int $gameDbId, array $playerIds): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException('A valid game ID is required.');
    }

    $playerIds = array_values(array_filter(array_map('intval', $playerIds)));

    if (empty($playerIds)) {
        throw new RuntimeException('Batting order cannot be empty.');
    }

    if (count(array_unique($playerIds)) !== count($playerIds)) {
        throw new RuntimeException('Batting order has duplicate players.');
    }

    $pdo = db();
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $currentStmt = $pdo->prepare("
            SELECT player_id
            FROM game_roster
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
            ORDER BY batting_order ASC
        ");

        $currentStmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $currentPlayerIds = array_map('intval', $currentStmt->fetchAll(PDO::FETCH_COLUMN));

        sort($currentPlayerIds);

        $submittedPlayerIds = $playerIds;
        sort($submittedPlayerIds);

        if ($currentPlayerIds !== $submittedPlayerIds) {
            throw new RuntimeException('Batting order must include every rostered player exactly once.');
        }

        $offsetStmt = $pdo->prepare("
            UPDATE game_roster
            SET batting_order = batting_order + 1000
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
        ");

        $offsetStmt->execute([
            'team_id' => $teamId,
            'game_db_id' => $gameDbId,
        ]);

        $updateStmt = $pdo->prepare("
            UPDATE game_roster
            SET batting_order = :batting_order
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
              AND player_id = :player_id
        ");

        foreach ($playerIds as $index => $playerId) {
            $updateStmt->execute([
                'batting_order' => $index + 1,
                'team_id' => $teamId,
                'game_db_id' => $gameDbId,
                'player_id' => $playerId,
            ]);
        }

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

function sync_game_batting_stats_to_player_batting_stats(int $teamId, int $gameDbId): void
{
    if ($teamId <= 0 || $gameDbId <= 0) {
        return;
    }

    $pdo = db();

    $deleteStmt = $pdo->prepare("
        DELETE FROM player_batting_stats
        WHERE team_id = :team_id
          AND game_id = :game_id
    ");

    $deleteStmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameDbId,
    ]);

    $insertStmt = $pdo->prepare("
        INSERT INTO player_batting_stats (
            player_id,
            team_id,
            game_id,
            games_played,
            at_bats,
            runs,
            hits,
            doubles_hit,
            triples_hit,
            home_runs,
            rbi,
            walks,
            strikeouts,
            hit_by_pitch,
            sacrifice_flies,
            stolen_bases,
            created_at,
            updated_at
        )
        SELECT
            pbgs.player_id,
            pbgs.team_id,
            pbgs.game_db_id,
            1 AS games_played,
            pbgs.at_bats,
            pbgs.runs,
            pbgs.hits,
            pbgs.doubles_hit,
            pbgs.triples_hit,
            pbgs.home_runs,
            pbgs.rbi,
            pbgs.walks,
            pbgs.strikeouts,
            pbgs.hit_by_pitch,
            pbgs.sacrifice_flies,
            pbgs.stolen_bases,
            NOW(),
            NOW()
        FROM player_batting_game_stats pbgs
        WHERE pbgs.team_id = :team_id
          AND pbgs.game_db_id = :game_id
    ");

    $insertStmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameDbId,
    ]);
}
