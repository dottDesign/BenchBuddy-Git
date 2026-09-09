<?php
declare(strict_types=1);

function get_all_players(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $sql = "
    SELECT
    p.id,
    p.team_id,
    p.first_name,
    p.last_name,
    CONCAT(p.first_name, ' ', p.last_name) AS name,
    p.jersey_number,
    p.active,
    p.created_at,
    p.pitching_role,
    p.catching_role,
    pp.position_code,
    pp.can_play,
    pp.cannot_play
    FROM players p
    LEFT JOIN player_positions pp
    ON pp.player_id = p.id
    WHERE p.team_id = :team_id
    ORDER BY p.active DESC, p.first_name ASC, p.last_name ASC, pp.position_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(["team_id" => $teamId]);
    $rows = $stmt->fetchAll();

    $players = [];

    foreach ($rows as $row) {
        $id = (int) $row["id"];

        if (!isset($players[$id])) {
            $players[$id] = [
                "id" => $id,
                "team_id" => (int) $row["team_id"],
                "name" => trim(
                    (string) ($row["first_name"] ?? "") .
                        " " .
                        (string) ($row["last_name"] ?? ""),
                ),
                "jersey_number" => $row["jersey_number"],
                "active" => (int) $row["active"],
                "created_at" => $row["created_at"],
                "pitching_role" => (string) ($row["pitching_role"] ?? "none"),
                "catching_role" => (string) ($row["catching_role"] ?? "none"),
                "can_play" => [],
                "cannot_play" => [],
            ];
        }

        if (!empty($row["position_code"])) {
            $pos = strtoupper(trim((string) $row["position_code"]));

            if ((int) $row["can_play"] === 1) {
                $players[$id]["can_play"][] = $pos;
            }

            if ((int) $row["cannot_play"] === 1) {
                $players[$id]["cannot_play"][] = $pos;
            }
        }
    }

    foreach ($players as &$player) {
        $player["can_play"] = normalize_positions($player["can_play"]);
        $player["cannot_play"] = normalize_positions($player["cannot_play"]);
    }
    unset($player);

    return array_values($players);
}

function get_active_players(int $teamId): array
{
    return array_values(
        array_filter(
            get_all_players($teamId),
            fn(array $p): bool => (int) $p["active"] === 1,
        ),
    );
}
function get_player_by_id(int $teamId, int $playerId): ?array
{
    validate_team_id($teamId);

    if ($playerId <= 0) {
        throw new InvalidArgumentException("A valid player ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        p.id,
        p.team_id,
        p.first_name,
        p.last_name,
        CONCAT(p.first_name, ' ', p.last_name) AS name,
        p.jersey_number,
        p.active,
        p.created_at,
        p.pitching_role,
        p.catching_role,
        pp.position_code,
        pp.can_play,
        pp.cannot_play
        FROM players p
        LEFT JOIN player_positions pp
        ON pp.player_id = p.id
        WHERE p.id = :id
        AND p.team_id = :team_id
        ORDER BY pp.position_code ASC
        ");
    $stmt->execute([
        "id" => $playerId,
        "team_id" => $teamId,
    ]);

    $rows = $stmt->fetchAll();
    if (!$rows) {
        return null;
    }

    $player = [
        "id" => (int) $rows[0]["id"],
        "team_id" => (int) $rows[0]["team_id"],
        "first_name" => (string) ($rows[0]["first_name"] ?? ""),
        "last_name" => (string) ($rows[0]["last_name"] ?? ""),
        "name" => trim(
            (string) ($rows[0]["first_name"] ?? "") .
                " " .
                (string) ($rows[0]["last_name"] ?? ""),
        ),
        "jersey_number" => (string) ($rows[0]["jersey_number"] ?? ""),
        "active" => (int) ($rows[0]["active"] ?? 1),
        "created_at" => (string) ($rows[0]["created_at"] ?? ""),
        "pitching_role" => (string) ($rows[0]["pitching_role"] ?? "none"),
        "catching_role" => (string) ($rows[0]["catching_role"] ?? "none"),
        "can_play" => [],
        "cannot_play" => [],
    ];

    foreach ($rows as $row) {
        if (!empty($row["position_code"])) {
            $pos = strtoupper(trim((string) $row["position_code"]));

            if ((int) $row["can_play"] === 1) {
                $player["can_play"][] = $pos;
            }

            if ((int) $row["cannot_play"] === 1) {
                $player["cannot_play"][] = $pos;
            }
        }
    }

    $player["can_play"] = normalize_positions($player["can_play"]);
    $player["cannot_play"] = normalize_positions($player["cannot_play"]);

    return $player;
}

function get_player_by_name(int $teamId, string $name): ?array
{
    validate_team_id($teamId);

    $name = normalize_name($name);
    if ($name === "") {
        throw new InvalidArgumentException("Player name is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM players
        WHERE team_id = :team_id
        AND name = :name
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "name" => $name,
    ]);

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return get_player_by_id($teamId, (int) $row["id"]);
}
function create_player(
    int $teamId,
    string $firstName,
    string $lastName,
    ?string $jerseyNumber,
    array $canPlay,
    array $cannotPlay,
    int $active,
    string $pitchingRole,
    string $catchingRole,
): int {
    validate_team_id($teamId);

    $firstName = trim($firstName);
    $lastName = trim($lastName);

    if ($firstName === "") {
        throw new RuntimeException("Player first name is required.");
    }

    $pitchingRole = in_array(
        $pitchingRole,
        ["none", "emergency", "primary"],
        true,
    )
        ? $pitchingRole
        : "none";
    $catchingRole = in_array(
        $catchingRole,
        ["none", "emergency", "primary"],
        true,
    )
        ? $catchingRole
        : "none";

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO players (
                team_id,
                first_name,
                last_name,
                jersey_number,
                active,
                pitching_role,
                catching_role
                )
            VALUES (
                :team_id,
                :first_name,
                :last_name,
                :jersey_number,
                :active,
                :pitching_role,
                :catching_role
                )
            ");
        $stmt->execute([
            "team_id" => $teamId,
            "first_name" => $firstName,
            "last_name" => $lastName !== "" ? $lastName : null,
            "jersey_number" => $jerseyNumber,
            "active" => $active,
            "pitching_role" => $pitchingRole,
            "catching_role" => $catchingRole,
        ]);

        $playerId = (int) $pdo->lastInsertId();

        $positionStmt = $pdo->prepare("
            INSERT INTO player_positions (player_id, position_code, can_play, cannot_play)
            VALUES (:player_id, :position_code, :can_play, :cannot_play)
            ");

        $allPositions = ["P", "C", "1B", "2B", "3B", "SS", "LF", "CF", "RF"];

        foreach ($allPositions as $position) {
            $positionStmt->execute([
                "player_id" => $playerId,
                "position_code" => $position,
                "can_play" => in_array($position, $canPlay, true) ? 1 : 0,
                "cannot_play" => in_array($position, $cannotPlay, true) ? 1 : 0,
            ]);
        }

        $pdo->commit();
        return $playerId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function update_player(
    int $teamId,
    int $playerId,
    string $firstName,
    string $lastName,
    ?string $jerseyNumber,
    array $canPlay,
    array $cannotPlay,
    int $active,
    string $pitchingRole,
    string $catchingRole,
): void {
    validate_team_id($teamId);

    $firstName = trim($firstName);
    $lastName = trim($lastName);

    if ($firstName === "") {
        throw new RuntimeException("Player first name is required.");
    }

    $pitchingRole = in_array(
        $pitchingRole,
        ["none", "emergency", "primary"],
        true,
    )
        ? $pitchingRole
        : "none";
    $catchingRole = in_array(
        $catchingRole,
        ["none", "emergency", "primary"],
        true,
    )
        ? $catchingRole
        : "none";

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            UPDATE players
                SET
                first_name = :first_name,
                last_name = :last_name,
                jersey_number = :jersey_number,
                active = :active,
                pitching_role = :pitching_role,
                catching_role = :catching_role
                WHERE id = :player_id
                AND team_id = :team_id
                ");
        $stmt->execute([
            "first_name" => $firstName,
            "last_name" => $lastName !== "" ? $lastName : null,
            "jersey_number" => $jerseyNumber,
            "active" => $active,
            "pitching_role" => $pitchingRole,
            "catching_role" => $catchingRole,
            "player_id" => $playerId,
            "team_id" => $teamId,
        ]);

        $deleteStmt = $pdo->prepare("
            DELETE FROM player_positions
                WHERE player_id = :player_id
                ");
        $deleteStmt->execute([
            "player_id" => $playerId,
        ]);

        $insertStmt = $pdo->prepare("
            INSERT INTO player_positions (player_id, position_code, can_play, cannot_play)
            VALUES (:player_id, :position_code, :can_play, :cannot_play)
            ");

        $allPositions = ["P", "C", "1B", "2B", "3B", "SS", "LF", "CF", "RF"];

        foreach ($allPositions as $position) {
            $insertStmt->execute([
                "player_id" => $playerId,
                "position_code" => $position,
                "can_play" => in_array($position, $canPlay, true) ? 1 : 0,
                "cannot_play" => in_array($position, $cannotPlay, true) ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


function delete_player(int $teamId, int $playerId): void
{
    validate_team_id($teamId);

    if ($playerId <= 0) {
        throw new InvalidArgumentException("A valid player ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        DELETE FROM players
            WHERE id = :id
            AND team_id = :team_id
            ");
    $stmt->execute([
        "id" => $playerId,
        "team_id" => $teamId,
    ]);
}

function save_player_positions(
    int $teamId,
    int $playerId,
    array $canPlay,
    array $cannotPlay,
    ?PDO $pdo = null,
): void {
    validate_team_id($teamId);

    if ($playerId <= 0) {
        throw new InvalidArgumentException("A valid player ID is required.");
    }

    $pdo = $pdo ?? db();

    $player = get_player_by_id($teamId, $playerId);
    if (!$player) {
        throw new RuntimeException("Player not found for this team.");
    }

    $allPositions = array_values(
        array_unique(array_merge($canPlay, $cannotPlay)),
    );
    sort($allPositions);

    if (!$allPositions) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO player_positions (player_id, position_code, can_play, cannot_play)
        VALUES (:player_id, :position_code, :can_play, :cannot_play)
        ");

    foreach ($allPositions as $pos) {
        $stmt->execute([
            "player_id" => $playerId,
            "position_code" => $pos,
            "can_play" => in_array($pos, $canPlay, true) ? 1 : 0,
            "cannot_play" => in_array($pos, $cannotPlay, true) ? 1 : 0,
        ]);
    }
}

function player_full_name(array $player): string
{
    $first = trim((string) ($player["first_name"] ?? ""));
    $last = trim((string) ($player["last_name"] ?? ""));

    return trim($first . " " . $last);
}

function get_player_position_totals(int $teamId, int $playerId): array
{
    validate_team_id($teamId);

    if ($playerId <= 0) {
        throw new InvalidArgumentException("A valid player ID is required.");
    }

    $player = get_player_by_id($teamId, $playerId);
    if (!$player) {
        throw new RuntimeException("Player not found for this team.");
    }

    $pdo = db();

    $positions = ["P", "C", "1B", "2B", "3B", "SS", "LF", "CF", "RF"];
    $totals = array_fill_keys($positions, 0);

    $stmt = $pdo->prepare("
        SELECT
            le.position_code,
            COUNT(*) AS innings_played
        FROM lineup_entries le
        INNER JOIN games g
            ON g.id = le.game_db_id
           AND g.team_id = le.team_id
        WHERE le.team_id = :team_id
          AND le.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
          AND le.inning_num <= COALESCE(g.actual_innings_played, g.innings)
        GROUP BY le.position_code
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "player_id" => $playerId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $position = strtoupper(trim((string)($row["position_code"] ?? "")));

        if ($position !== "" && array_key_exists($position, $totals)) {
            $totals[$position] = (float)($row["innings_played"] ?? 0);
        }
    }

    $pitchStmt = $pdo->prepare("
        SELECT COALESCE(SUM(ps.innings_pitched), 0)
        FROM player_pitching_stats ps
        INNER JOIN games g
            ON g.id = ps.game_id
           AND g.team_id = ps.team_id
        WHERE ps.team_id = :team_id
          AND ps.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
    ");

    $pitchStmt->execute([
        "team_id" => $teamId,
        "player_id" => $playerId,
    ]);

    $totals["P"] = (float)$pitchStmt->fetchColumn();

    return $totals;
}

function normalize_pitching_role(?string $pitchingRole): string
{
    $pitchingRole = strtolower(trim((string) $pitchingRole));

    $allowed = ["none", "emergency", "primary"];

    if ($pitchingRole === "") {
        return "none";
    }

    if (!in_array($pitchingRole, $allowed, true)) {
        throw new InvalidArgumentException("Invalid pitching role.");
    }

    return $pitchingRole;
}
function normalize_catching_role(?string $role): string
{
    $role = strtolower(trim((string) $role));

    if ($role === "") {
        return "none";
    }

    $allowed = ["primary", "emergency", "none"];

    if (!in_array($role, $allowed, true)) {
        throw new InvalidArgumentException("Invalid catching role.");
    }

    return $role;
}

function format_baseball_rate(float $value): string
{
    if ($value <= 0) {
        return '.000';
    }

    return ltrim(number_format($value, 3), '0');
}

function calculate_batting_average(int $hits, int $atBats): float
{
    return $atBats > 0
        ? round($hits / $atBats, 3)
        : 0.0;
}

function calculate_obp(
    int $hits,
    int $walks,
    int $hitByPitch,
    int $atBats,
    int $sacrificeFlies
): float {
    $denominator =
        $atBats +
        $walks +
        $hitByPitch +
        $sacrificeFlies;

    if ($denominator <= 0) {
        return 0.0;
    }

    return round(
        ($hits + $walks + $hitByPitch) / $denominator,
        3
    );
}

function calculate_slugging(
    int $hits,
    int $doubles,
    int $triples,
    int $homeRuns,
    int $atBats
): float {
    if ($atBats <= 0) {
        return 0.0;
    }

    $singles =
        $hits -
        $doubles -
        $triples -
        $homeRuns;

    $totalBases =
        $singles +
        ($doubles * 2) +
        ($triples * 3) +
        ($homeRuns * 4);

    return round($totalBases / $atBats, 3);
}

function calculate_ops(float $obp, float $slugging): float
{
    return round($obp + $slugging, 3);
}

function calculate_era(
    int $earnedRuns,
    float $inningsPitched
): float {
    if ($inningsPitched <= 0) {
        return 0.0;
    }

    return round(
        ($earnedRuns * 7) / $inningsPitched,
        2
    );
}

function calculate_whip(
    int $walks,
    int $hitsAllowed,
    float $inningsPitched
): float {
    if ($inningsPitched <= 0) {
        return 0.0;
    }

    return round(
        ($walks + $hitsAllowed) / $inningsPitched,
        2
    );
}
