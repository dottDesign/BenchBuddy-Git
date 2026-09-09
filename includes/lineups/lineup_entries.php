<?php
declare(strict_types=1);


function get_lineup_entries(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        le.*,
        p.name AS player_name,
        p.jersey_number
        FROM lineup_entries le
        INNER JOIN players p
        ON p.id = le.player_id
        WHERE le.team_id = :team_id
        AND le.game_db_id = :game_db_id
        ORDER BY le.inning_num ASC, le.position_code ASC
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);

    return $stmt->fetchAll();
}

function get_bench_entries(int $teamId, int $gameDbId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        be.*,
        p.name AS player_name,
        p.jersey_number
        FROM bench_entries be
        INNER JOIN players p
        ON p.id = be.player_id
        WHERE be.team_id = :team_id
        AND be.game_db_id = :game_db_id
        ORDER BY be.inning_num ASC, be.bench_slot ASC
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_db_id" => $gameDbId,
    ]);

    return $stmt->fetchAll();
}
