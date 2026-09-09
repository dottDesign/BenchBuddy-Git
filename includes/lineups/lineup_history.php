<?php
declare(strict_types=1);


function save_lineup_history_snapshot(
    int $teamId,
    int $gameDbId,
    array $lineupResult,
    ?int $userId = null
): void {
    validate_team_id($teamId);

    $fairness = calculate_lineup_fairness($lineupResult);

    $payload = [
        "lineup" => $lineupResult,
        "fairness" => $fairness,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException("Failed to encode lineup history snapshot.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO lineup_history (
            team_id,
            game_db_id,
            user_id,
            lineup_json
        ) VALUES (
            :team_id,
            :game_db_id,
            :user_id,
            :lineup_json
        )
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
        "user_id" => $userId,
        "lineup_json" => $json,
    ]);

    $cleanupStmt = $pdo->prepare("
        DELETE FROM lineup_history
        WHERE team_id = :team_id
          AND game_db_id = :game_db_id
          AND id NOT IN (
              SELECT id FROM (
                  SELECT id
                  FROM lineup_history
                  WHERE team_id = :team_id
                    AND game_db_id = :game_db_id
                  ORDER BY id DESC
                  LIMIT 50
              ) AS keep_rows
          )
    ");

    $cleanupStmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);
}
function get_lineup_history_for_game(int $teamId, int $gameId): array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT
            lh.*,
            u.full_name AS user_full_name,
            u.email AS user_email
        FROM lineup_history lh
        LEFT JOIN users u
            ON u.id = lh.user_id
        WHERE lh.team_id = :team_id
          AND lh.game_db_id = :game_db_id
        ORDER BY lh.created_at DESC, lh.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_lineup_history_by_id(int $teamId, int $historyId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT *
        FROM lineup_history
        WHERE id = :id
          AND team_id = :team_id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $historyId,
        'team_id' => $teamId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function restore_lineup_history_snapshot(int $teamId, int $historyId): int
{
    validate_team_id($teamId);

    $historyRow = get_lineup_history_by_id($teamId, $historyId);
    if (!$historyRow) {
        throw new RuntimeException("Lineup history record not found.");
    }

    $payload = json_decode((string) $historyRow["lineup_json"], true);
    if (!is_array($payload)) {
        throw new RuntimeException("Stored lineup history is invalid.");
    }

    $lineupResult = $payload["lineup"] ?? null;
    if (!is_array($lineupResult)) {
        throw new RuntimeException(
            "Stored lineup snapshot is missing lineup data.",
        );
    }

    $gameDbId = (int) $historyRow["game_db_id"];

    save_generated_lineup($teamId, $gameDbId, $lineupResult);

    return $gameDbId;
}
