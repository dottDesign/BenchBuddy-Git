<?php
declare(strict_types=1);



function create_game(
    int $teamId,
    string $gameId,
    int $innings = 7,
    ?string $gameDate = null,
): int {
    validate_team_id($teamId);

    $pdo = db();

    $gameId = trim($gameId);
    $gameDate = normalize_game_date($gameDate);

    if ($gameId === "") {
        throw new InvalidArgumentException("Game ID is required.");
    }

    if ($innings <= 0) {
        throw new InvalidArgumentException("Innings must be greater than 0.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO games (team_id, game_id, game_date, innings, status, roster_size, bench_count)
        VALUES (:team_id, :game_id, :game_date, :innings, 'draft', 0, 0)
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_id" => $gameId,
        "game_date" => $gameDate,
        "innings" => $innings,
    ]);

    return (int) $pdo->lastInsertId();
}

function update_game(
    int $teamId,
    int $gameDbId,
    string $gameId,
    int $innings = 7,
    ?string $gameDate = null,
): void {
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $existing = get_game_by_id($teamId, $gameDbId);
    if (!$existing) {
        throw new RuntimeException("Game not found for this team.");
    }

    $gameId = trim($gameId);
    $gameDate = normalize_game_date($gameDate);

    if ($gameId === "") {
        throw new InvalidArgumentException("Game ID is required.");
    }

    if ($innings <= 0) {
        throw new InvalidArgumentException("Innings must be greater than 0.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE games
            SET game_id = :game_id,
            game_date = :game_date,
            innings = :innings
            WHERE id = :id
            AND team_id = :team_id
            ");
    $stmt->execute([
        "id" => $gameDbId,
        "team_id" => $teamId,
        "game_id" => $gameId,
        "game_date" => $gameDate,
        "innings" => $innings,
    ]);
}

function get_all_games(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        ");
    $stmt->execute(["team_id" => $teamId]);

    return $stmt->fetchAll();
}

function get_games_by_status(int $teamId, string $status): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND status = :status
        AND deleted_at IS NULL
        ORDER BY
        CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
        game_date DESC,
        created_at DESC,
        id DESC
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "status" => $status,
    ]);

    return $stmt->fetchAll();
}

function get_game_by_id(int $teamId, int $gameDbId): ?array
{
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE id = :id
        AND team_id = :team_id
        AND deleted_at IS NULL
        LIMIT 1
        ");
    $stmt->execute([
        "id" => $gameDbId,
        "team_id" => $teamId,
    ]);

    $game = $stmt->fetch();
    return $game ?: null;
}

function get_game_by_game_id(int $teamId, string $gameId): ?array
{
    validate_team_id($teamId);

    $gameId = trim($gameId);
    if ($gameId === "") {
        throw new InvalidArgumentException("Game ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND game_id = :game_id
        AND deleted_at IS NULL
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "game_id" => $gameId,
    ]);

    $game = $stmt->fetch();
    return $game ?: null;
}

function update_game_status(int $teamId, int $gameDbId, string $status): void
{
    validate_team_id($teamId);

    $allowed = ["draft", "generated", "locked"];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException("Invalid game status.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE games
            SET status = :status
            WHERE id = :id
            AND team_id = :team_id
            ");
    $stmt->execute([
        "id" => $gameDbId,
        "team_id" => $teamId,
        "status" => $status,
    ]);
}


function get_history_games(int $teamId, string $statusFilter = "locked"): array
{
    validate_team_id($teamId);

    $pdo = db();

    $validStatuses = ["draft", "generated", "locked", "all"];
    if (!in_array($statusFilter, $validStatuses, true)) {
        $statusFilter = "locked";
    }

    if ($statusFilter === "all") {
        $stmt = $pdo->prepare("
            SELECT *
            FROM games
            WHERE team_id = :team_id
            AND deleted_at IS NULL

            ORDER BY
            CASE WHEN locked_at IS NULL THEN 1 ELSE 0 END,
            locked_at DESC,
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date DESC,
            created_at DESC,
            id DESC
            ");
        $stmt->execute(["team_id" => $teamId]);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND status = :status
        AND deleted_at IS NULL
        ORDER BY
        CASE WHEN locked_at IS NULL THEN 1 ELSE 0 END,
        locked_at DESC,
        CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
        game_date DESC,
        created_at DESC,
        id DESC
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "status" => $statusFilter,
    ]);

    return $stmt->fetchAll();
}


function update_locked_game_date(
    int $teamId,
    int $gameDbId,
    ?string $gameDate,
): void {
    validate_team_id($teamId);

    if ($gameDbId <= 0) {
        throw new InvalidArgumentException("A valid game ID is required.");
    }

    $gameDate = normalize_game_date($gameDate);

    $game = get_game_by_id($teamId, $gameDbId);
    if (!$game) {
        throw new RuntimeException("Game not found for this team.");
    }

    if ((string) ($game["status"] ?? "") !== "locked") {
        throw new RuntimeException(
            "Only locked games can have their date updated here.",
        );
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $updateGame = $pdo->prepare("
            UPDATE games
                SET game_date = :game_date
                WHERE id = :id
                AND team_id = :team_id
                ");
        $updateGame->execute([
            "game_date" => $gameDate,
            "id" => $gameDbId,
            "team_id" => $teamId,
        ]);

        $archivePayload = build_archive_payload($teamId, $gameDbId);
        $archiveJson = json_encode($archivePayload, JSON_UNESCAPED_UNICODE);

        if ($archiveJson === false) {
            throw new RuntimeException("Failed to rebuild archive payload.");
        }

        $archiveStmt = $pdo->prepare("
            INSERT INTO archived_games (team_id, game_db_id, archive_json)
            VALUES (:team_id, :game_db_id, :archive_json)
            ON DUPLICATE KEY UPDATE archive_json = VALUES(archive_json)
            ");
        $archiveStmt->execute([
            "team_id" => $teamId,
            "game_db_id" => $gameDbId,
            "archive_json" => $archiveJson,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function game_effective_defensive_innings(array $game): int
{
    $scheduledInnings = max(0, (int)($game['innings'] ?? 0));
    $actualRaw = $game['actual_innings_played'] ?? null;

    if ($actualRaw === null || $actualRaw === '') {
        return $scheduledInnings;
    }

    $actual = max(0, (float)$actualRaw);
    $fullInnings = (int)floor($actual);
    $hasHalfInning = abs($actual - $fullInnings - 0.5) < 0.01;
    $homeAway = (string)($game['home_away'] ?? '');

    if (!$hasHalfInning) {
        return min($scheduledInnings, $fullInnings);
    }

    if ($homeAway === 'away') {
        return min($scheduledInnings, $fullInnings + 1);
    }

    return min($scheduledInnings, $fullInnings);
}
