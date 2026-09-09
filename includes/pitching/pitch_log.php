<?php
declare(strict_types=1);


function get_pitch_log_for_game(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException("Game not found for this team.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            ps.player_id,
            ps.innings_pitched,
            ps.pitches_thrown,
            p.first_name,
            p.last_name,
            CONCAT(p.first_name, ' ', p.last_name) AS name,
            p.jersey_number
        FROM player_pitching_stats ps
        INNER JOIN players p
            ON p.id = ps.player_id
           AND p.team_id = ps.team_id
        WHERE ps.team_id = :team_id
          AND ps.game_id = :game_db_id
        ORDER BY ps.innings_pitched DESC, p.first_name ASC, p.last_name ASC
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function get_pitch_innings_map_for_game(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException("Game not found for this team.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        le.player_id,
        COUNT(*) AS innings_pitched
        FROM lineup_entries le
        WHERE le.team_id = :team_id
        AND le.game_db_id = :game_db_id
        AND le.position_code = 'P'
        GROUP BY le.player_id
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row["player_id"]] = (int) $row["innings_pitched"];
    }

    return $map;
}
function save_pitch_counts_for_game(
    int $teamId,
    int $gameDbId,
    array $pitchCounts,
    array $inningsPitched = [],
    array $didNotPitch = [],
): void {
    validate_team_id($teamId);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException("Game not found for this team.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $deletePitchLogStmt = $pdo->prepare("
            DELETE FROM pitch_log
            WHERE team_id = :team_id
              AND game_db_id = :game_db_id
              AND player_id = :player_id
        ");

        $deletePitchingStatsStmt = $pdo->prepare("
            DELETE FROM player_pitching_stats
            WHERE team_id = :team_id
              AND game_id = :game_db_id
              AND player_id = :player_id
        ");

        $upsertPitchLogStmt = $pdo->prepare("
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

        $updatePitchingStatsStmt = $pdo->prepare("
            UPDATE player_pitching_stats
            SET
                innings_pitched = :innings_pitched,
                pitches_thrown = :pitches_thrown,
                updated_at = NOW()
            WHERE team_id = :team_id
              AND game_id = :game_db_id
              AND player_id = :player_id
            LIMIT 1
        ");

        $insertPitchingStatsStmt = $pdo->prepare("
            INSERT INTO player_pitching_stats (
                team_id,
                game_id,
                player_id,
                innings_pitched,
                pitches_thrown,
                hits_allowed,
                runs_allowed,
                earned_runs,
                walks,
                strikeouts,
                wins,
                losses,
                saves,
                created_at
            ) VALUES (
                :team_id,
                :game_db_id,
                :player_id,
                :innings_pitched,
                :pitches_thrown,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                NOW()
            )
        ");

        $allPlayerIds = array_unique(
            array_merge(
                array_map("intval", array_keys($pitchCounts)),
                array_map("intval", array_keys($inningsPitched)),
                array_map("intval", array_keys($didNotPitch))
            )
        );

        foreach ($allPlayerIds as $playerId) {
            if ($playerId <= 0) {
                continue;
            }

            $markedDidNotPitch = !empty($didNotPitch[$playerId]);

            if ($markedDidNotPitch) {
                $deletePitchLogStmt->execute([
                    "team_id" => $teamId,
                    "game_db_id" => $gameDbId,
                    "player_id" => $playerId,
                ]);

                $deletePitchingStatsStmt->execute([
                    "team_id" => $teamId,
                    "game_db_id" => $gameDbId,
                    "player_id" => $playerId,
                ]);

                continue;
            }

            $actualInnings = round(max(0, (float)($inningsPitched[$playerId] ?? 0)), 1);
            $actualPitches = max(0, (int)($pitchCounts[$playerId] ?? 0));

            if ($actualInnings <= 0 && $actualPitches <= 0) {
                $deletePitchLogStmt->execute([
                    "team_id" => $teamId,
                    "game_db_id" => $gameDbId,
                    "player_id" => $playerId,
                ]);

                $deletePitchingStatsStmt->execute([
                    "team_id" => $teamId,
                    "game_db_id" => $gameDbId,
                    "player_id" => $playerId,
                ]);

                continue;
            }

            $upsertPitchLogStmt->execute([
                "team_id" => $teamId,
                "game_db_id" => $gameDbId,
                "player_id" => $playerId,
                "innings_pitched" => $actualInnings,
                "pitches_thrown" => $actualPitches,
            ]);

            $updatePitchingStatsStmt->execute([
                "team_id" => $teamId,
                "game_db_id" => $gameDbId,
                "player_id" => $playerId,
                "innings_pitched" => $actualInnings,
                "pitches_thrown" => $actualPitches,
            ]);

            if ($updatePitchingStatsStmt->rowCount() === 0) {
                $insertPitchingStatsStmt->execute([
                    "team_id" => $teamId,
                    "game_db_id" => $gameDbId,
                    "player_id" => $playerId,
                    "innings_pitched" => $actualInnings,
                    "pitches_thrown" => $actualPitches,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
