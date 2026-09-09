<?php
declare(strict_types=1);


function get_pitcher_last_outing(
    int $teamId,
    int $playerId,
    ?string $beforeGameDate = null,
    ?int $excludeGameId = null
): ?array {
    validate_team_id($teamId);

    $pdo = db();

    $sql = "
        SELECT
            g.id AS game_db_id,
            g.game_id,
            g.game_date,
            pl.pitches_thrown,
            pl.innings_pitched
        FROM pitch_log pl
        INNER JOIN games g
            ON g.id = pl.game_db_id
           AND g.team_id = pl.team_id
        WHERE pl.team_id = :team_id
          AND pl.player_id = :player_id
          AND g.game_date IS NOT NULL
          AND g.deleted_at IS NULL
          AND COALESCE(pl.pitches_thrown, 0) > 0
    ";

    $params = [
        "team_id" => $teamId,
        "player_id" => $playerId,
    ];

    if ($excludeGameId !== null && $excludeGameId > 0) {
        $sql .= "
            AND g.id <> :exclude_game_id
        ";

        $params["exclude_game_id"] = $excludeGameId;
    }

    if ($beforeGameDate !== null && trim($beforeGameDate) !== '') {
        $sql .= "
            AND g.game_date < :before_game_date
        ";

        $params["before_game_date"] = $beforeGameDate;
    }

    $sql .= "
        ORDER BY g.game_date DESC, g.id DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function is_pitcher_rest_eligible(
    int $teamId,
    int $playerId,
    ?string $gameDate = null,
    ?int $excludeGameId = null
): bool {
    validate_team_id($teamId);

    if ($gameDate === null || trim($gameDate) === '') {
        return true;
    }

    $lastOuting = get_pitcher_last_outing(
        $teamId,
        $playerId,
        $gameDate,
        $excludeGameId
    );

    if (!$lastOuting) {
        return true;
    }

    $lastGameDate = trim((string)($lastOuting['game_date'] ?? ''));
    $pitchCount = (int)($lastOuting['pitches_thrown'] ?? 0);

    if ($lastGameDate === '' || $pitchCount <= 0) {
        return true;
    }

    $division = get_team_division($teamId);
    $requiredRestDays = get_required_rest_days_from_pitch_count($pitchCount, $division, $teamId);

    if ($requiredRestDays <= 0) {
        return true;
    }

    $lastDate = new DateTimeImmutable($lastGameDate);
    $eligibleDate = $lastDate
        ->modify('+' . $requiredRestDays . ' day')
        ->format('Y-m-d');

    return $gameDate >= $eligibleDate;
}


function get_pitcher_availability_summary(
    int $teamId,
    int $playerId,
    ?int $excludeGameId = null,
    ?string $gameDate = null,
): array {
    validate_team_id($teamId);

    $gameDate = trim((string)$gameDate);

    if ($gameDate !== '' && !is_pitcher_consecutive_days_eligible($teamId, $playerId, $gameDate, $excludeGameId)) {
        $division = get_team_division($teamId);
        $noRestLimit = get_no_rest_pitch_limit_for_division($division);

        $previousDays = get_pitching_previous_consecutive_days(
            $teamId,
            $playerId,
            $gameDate,
            $excludeGameId
        );

        $previousTwoDayTotal = 0;
        foreach (array_slice($previousDays, 0, 2) as $day) {
            $previousTwoDayTotal += (int)($day['pitches_thrown'] ?? 0);
        }

        return [
            'has_history' => true,
            'available_now' => false,
            'next_available_date' => null,
            'reason' => 'Not eligible for a 3rd consecutive pitching day. Previous 2-day total is ' . $previousTwoDayTotal . ' pitches, which exceeds the ' . $division . ' no-rest limit of ' . $noRestLimit . '.',
        ];
    }

    $lastOuting = get_pitcher_last_outing(
        $teamId,
        $playerId,
        $gameDate !== '' ? $gameDate : null,
        $excludeGameId
    );
    if (!$lastOuting) {
        return [
            'has_history' => false,
            'available_now' => true,
            'next_available_date' => null,
            'reason' => 'No previous pitching outing found.',
        ];
    }

    $lastGameDate = trim((string)($lastOuting['game_date'] ?? ''));
    $pitchCount = (int)($lastOuting['pitches_thrown'] ?? 0);
    $division = get_team_division($teamId);
    $maxPitches = get_max_pitch_count_for_division($division);

    if ($lastGameDate === '' || $pitchCount <= 0) {
        return [
            'has_history' => false,
            'available_now' => true,
            'next_available_date' => null,
            'reason' => 'No previous pitching outing found.',
        ];
    }

    if ($pitchCount > $maxPitches) {
        return [
            'has_history' => true,
            'available_now' => false,
            'next_available_date' => null,
            'reason' => 'Last outing exceeded the ' . $division . ' max pitch count of ' . $maxPitches . '.',
        ];
    }

    $requiredRestDays = get_required_rest_days_from_pitch_count($pitchCount, $division, $teamId);
    $lastDate = new DateTimeImmutable($lastGameDate);
    $nextAvailable = $requiredRestDays > 0
        ? $lastDate->modify('+' . $requiredRestDays . ' day')->format('Y-m-d')
        : null;

    return [
        'has_history' => true,
        'available_now' => true,
        'next_available_date' => $nextAvailable,
        'reason' => $requiredRestDays > 0
            ? 'Last outing: ' . $lastGameDate . ', ' . $pitchCount . ' pitches, requires ' . $requiredRestDays . ' day(s) rest.'
            : 'Eligible. Last outing: ' . $lastGameDate . ', ' . $pitchCount . ' pitches, requires 0 day(s) rest.',
    ];
}



function get_pitching_previous_consecutive_days(
    int $teamId,
    int $playerId,
    string $gameDate,
    ?int $excludeGameId = null
): array {
    validate_team_id($teamId);

    $pdo = db();

    $sql = "
        SELECT
            g.game_date,
            SUM(COALESCE(pl.pitches_thrown, 0)) AS pitches_thrown
        FROM pitch_log pl
        INNER JOIN games g
            ON g.id = pl.game_db_id
            AND g.team_id = pl.team_id
        WHERE pl.team_id = :team_id
          AND pl.player_id = :player_id
          AND g.game_date IS NOT NULL
          AND g.game_date < :game_date
          AND g.deleted_at IS NULL
          AND COALESCE(pl.pitches_thrown, 0) > 0
    ";

    $params = [
        "team_id" => $teamId,
        "player_id" => $playerId,
        "game_date" => $gameDate,
    ];

    if ($excludeGameId !== null && $excludeGameId > 0) {
        $sql .= " AND g.id <> :exclude_game_id ";
        $params["exclude_game_id"] = $excludeGameId;
    }

    $sql .= "
        GROUP BY g.game_date
        ORDER BY g.game_date DESC
        LIMIT 4
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $consecutive = [];
    $expectedDate = (new DateTimeImmutable($gameDate))->modify("-1 day");

    foreach ($rows as $row) {
        $rowDate = (string)($row["game_date"] ?? "");

        if ($rowDate !== $expectedDate->format("Y-m-d")) {
            break;
        }

        $consecutive[] = [
            "game_date" => $rowDate,
            "pitches_thrown" => (int)($row["pitches_thrown"] ?? 0),
        ];

        $expectedDate = $expectedDate->modify("-1 day");
    }

    return $consecutive;
}

function is_pitcher_consecutive_days_eligible(
    int $teamId,
    int $playerId,
    string $gameDate,
    ?int $excludeGameId = null
): bool {
    $gameDate = trim($gameDate);

    if ($gameDate === "") {
        return true;
    }

    $division = get_team_division($teamId);
    $noRestLimit = get_no_rest_pitch_limit_for_division($division);

    $previousDays = get_pitching_previous_consecutive_days(
        $teamId,
        $playerId,
        $gameDate,
        $excludeGameId
    );

    $previousConsecutiveDays = count($previousDays);

    if ($previousConsecutiveDays >= 3) {
        return false;
    }

    if ($previousConsecutiveDays < 2) {
        return true;
    }

    $previousTwoDayTotal = 0;

    foreach (array_slice($previousDays, 0, 2) as $day) {
        $previousTwoDayTotal += (int)($day["pitches_thrown"] ?? 0);
    }

    return $previousTwoDayTotal <= $noRestLimit;
}

function get_pitcher_next_available_date(
    int $teamId,
    int $playerId,
    ?string $beforeGameDate = null,
    ?int $excludeGameId = null
): ?string {
    validate_team_id($teamId);

    $lastOuting = get_pitcher_last_outing(
        $teamId,
        $playerId,
        $beforeGameDate,
        $excludeGameId
    );

    if (!$lastOuting) {
        return null;
    }

    $lastGameDate = trim((string)($lastOuting['game_date'] ?? ''));

    if ($lastGameDate === '') {
        return null;
    }

    $division = get_team_division($teamId);
    $pitchCount = (int)($lastOuting['pitches_thrown'] ?? 0);

    if ($pitchCount <= 0) {
        return null;
    }

    $requiredRestDays = get_required_rest_days_from_pitch_count(
        $pitchCount,
        $division,
        $teamId
    );

    if ($requiredRestDays <= 0) {
        return null;
    }

    $lastDate = new DateTimeImmutable($lastGameDate);

    return $lastDate
        ->modify('+' . $requiredRestDays . ' day')
        ->format('Y-m-d');
}
