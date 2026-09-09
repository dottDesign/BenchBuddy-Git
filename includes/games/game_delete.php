<?php
declare(strict_types=1);

function delete_game(int $teamId, int $gameDbId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        DELETE FROM games
            WHERE id = :id
            AND team_id = :team_id
            ");
    $stmt->execute([
        "id" => $gameDbId,
        "team_id" => $teamId,
    ]);
}


function delete_game_completely(int $teamId, int $gameDbId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $tables = [
            "archived_games",
            "pitch_log",
            "bench_entries",
            "lineup_entries",
            "game_roster",
        ];

        foreach ($tables as $table) {
            $stmt = $pdo->prepare("
                DELETE FROM {$table}
                    WHERE team_id = :team_id

                    AND game_db_id = :game_db_id
                    ");
            $stmt->execute([
                "team_id" => $teamId,
                "game_db_id" => $gameDbId,
            ]);
        }

        $stmt = $pdo->prepare("
            DELETE FROM games
                WHERE id = :id
                AND team_id = :team_id
                ");
        $stmt->execute([
            "id" => $gameDbId,
            "team_id" => $teamId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


function cancel_game(
    int $teamId,
    int $gameDbId,
    ?string $reason = "cancelled",
): void {
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE games
            SET deleted_at = NOW(),
            delete_reason = :reason
            WHERE id = :id
            AND team_id = :team_id
            AND deleted_at IS NULL
            ");

    $stmt->execute([
        "id" => $gameDbId,
        "team_id" => $teamId,
        "reason" =>
            $reason !== null && trim($reason) !== ""
                ? trim($reason)
                : "cancelled",
    ]);
}
function restore_cancelled_game(int $teamId, int $gameDbId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("Invalid game.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE games
            SET deleted_at = NULL,
            delete_reason = NULL
            WHERE id = :game_id
            AND team_id = :team_id
            AND deleted_at IS NOT NULL
            ");

    $stmt->execute([
        "game_id" => $gameDbId,
        "team_id" => $teamId,
    ]);
}


function permanently_delete_game(int $teamId, int $gameDbId): void
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $deleteArchive = $pdo->prepare("
            DELETE FROM archived_games
                WHERE team_id = :team_id
                AND game_db_id = :game_db_id
                ");

        $deletePitchLog = $pdo->prepare("
            DELETE FROM pitch_log
                WHERE team_id = :team_id
                AND game_db_id = :game_db_id
                ");

        $deleteBench = $pdo->prepare("
            DELETE FROM bench_entries
                WHERE team_id = :team_id
                AND game_db_id = :game_db_id
                ");

        $deleteLineup = $pdo->prepare("
            DELETE FROM lineup_entries
                WHERE team_id = :team_id
                AND game_db_id = :game_db_id
                ");

        $deleteRoster = $pdo->prepare("
            DELETE FROM game_roster
                WHERE team_id = :team_id
                AND game_db_id = :game_db_id
                ");

        $deleteGame = $pdo->prepare("
            DELETE FROM games
                WHERE id = :id
                AND team_id = :team_id
                ");

        $params = [
            "team_id" => $teamId,
            "game_db_id" => $gameDbId,
        ];

        $deleteArchive->execute($params);
        $deletePitchLog->execute($params);
        $deleteBench->execute($params);
        $deleteLineup->execute($params);
        $deleteRoster->execute($params);

        $deleteGame->execute([
            "id" => $gameDbId,
            "team_id" => $teamId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_archive_record_for_game(int $teamId, int $gameDbId): ?array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM archived_games
        WHERE team_id = :team_id
        AND game_db_id = :game_db_id
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}
