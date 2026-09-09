<?php
declare(strict_types=1);

function coach_player_display_name(array $player): string
{
    $firstName = trim((string)($player['first_name'] ?? ''));
    $lastName = trim((string)($player['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);

    if ($fullName !== '') {
        return $fullName;
    }

    $name = trim((string)($player['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return 'Player #' . (int)($player['id'] ?? 0);
}

function get_next_coach_game(int $teamId): ?array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT
            g.*,
            t.name AS tournament_name
        FROM games g
        LEFT JOIN tournaments t
            ON t.id = g.tournament_id
           AND t.team_id = g.team_id
        WHERE g.team_id = :team_id
          AND g.deleted_at IS NULL
          AND (
              g.game_date IS NULL
              OR g.game_date >= CURDATE()
          )
        ORDER BY
            CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
            g.game_date ASC,
            g.id ASC
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function get_recent_coach_games(int $teamId, int $limit = 5): array
{
    validate_team_id($teamId);

    $limit = max(1, min(10, $limit));

    $stmt = db()->prepare("
        SELECT
            g.*,
            t.name AS tournament_name
        FROM games g
        LEFT JOIN tournaments t
            ON t.id = g.tournament_id
           AND t.team_id = g.team_id
        WHERE g.team_id = :team_id
          AND g.deleted_at IS NULL
          AND g.game_date IS NOT NULL
          AND g.game_date <= CURDATE()
        ORDER BY
            g.game_date DESC,
            g.id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_coach_pitching_availability(int $teamId, ?string $forGameDate = null): array
{
    validate_team_id($teamId);

    $players = get_active_players($teamId);
    $rows = [];

    foreach ($players as $player) {
        $playerId = (int)$player['id'];

        $lastOuting = get_pitcher_last_outing($teamId, $playerId, $forGameDate);
        $nextAvailableDate = get_pitcher_next_available_date($teamId, $playerId, $forGameDate);

        $today = date('Y-m-d');
        $comparisonDate = $forGameDate !== null && $forGameDate !== ''
            ? $forGameDate
            : $today;

        $status = 'Available';
        $statusKey = 'available';
        $displayNextAvailableDate = null;

        if ($nextAvailableDate !== null && $nextAvailableDate !== '') {
            if ($nextAvailableDate > $comparisonDate) {
                $status = 'Resting';
                $statusKey = 'resting';
                $displayNextAvailableDate = $nextAvailableDate;
            } else {
                $displayNextAvailableDate = null;
            }
        }

        $pitchingRole = trim((string)($player['pitching_role'] ?? ''));
        $catchingRole = trim((string)($player['catching_role'] ?? ''));

        $rows[] = [
            'player' => $player,
            'player_id' => $playerId,
            'name' => coach_player_display_name($player),
            'pitching_role' => $pitchingRole !== '' ? $pitchingRole : 'none',
            'catching_role' => $catchingRole !== '' ? $catchingRole : 'none',
            'last_game_date' => $lastOuting['game_date'] ?? null,
            'last_game_label' => $lastOuting['game_id'] ?? null,
            'last_pitches' => (int)($lastOuting['pitches_thrown'] ?? 0),
            'last_innings' => (float)($lastOuting['innings_pitched'] ?? 0),
            'next_available_date' => $displayNextAvailableDate,
            'status' => $status,
            'status_key' => $statusKey,
        ];
    }

    usort($rows, function (array $a, array $b): int {
        if ($a['status_key'] !== $b['status_key']) {
            return $a['status_key'] === 'resting' ? -1 : 1;
        }

        $roleOrder = [
            'primary' => 1,
            'secondary' => 2,
            'emergency' => 3,
            'none' => 4,
        ];

        $aRole = $roleOrder[$a['pitching_role']] ?? 9;
        $bRole = $roleOrder[$b['pitching_role']] ?? 9;

        if ($aRole !== $bRole) {
            return $aRole <=> $bRole;
        }

        return strnatcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $rows;
}
function get_coach_recent_batting_snapshot(int $teamId, int $limit = 8): array
{
    validate_team_id($teamId);

    $limit = max(1, min(20, $limit));

    $stmt = db()->prepare("
        SELECT
            p.id AS player_id,
            p.first_name,
            p.last_name,
            p.name,
            p.jersey_number,
            MAX(g.game_date) AS last_game_date,
            COALESCE(SUM(pbgs.at_bats), 0) AS at_bats,
            COALESCE(SUM(pbgs.hits), 0) AS hits,
            COALESCE(SUM(pbgs.runs), 0) AS runs,
            COALESCE(SUM(pbgs.rbi), 0) AS rbi,
            COALESCE(SUM(pbgs.walks), 0) AS walks,
            COALESCE(SUM(pbgs.strikeouts), 0) AS strikeouts,
            COALESCE(SUM(pbgs.stolen_bases), 0) AS stolen_bases,
            COUNT(DISTINCT pbgs.game_db_id) AS games_played
        FROM player_batting_game_stats pbgs
        INNER JOIN games g
            ON g.id = pbgs.game_db_id
           AND g.team_id = pbgs.team_id
        INNER JOIN players p
            ON p.id = pbgs.player_id
           AND p.team_id = pbgs.team_id
        WHERE pbgs.team_id = :team_id
          AND g.deleted_at IS NULL
          AND g.game_date IS NOT NULL
        GROUP BY
            p.id,
            p.first_name,
            p.last_name,
            p.name,
            p.jersey_number
        HAVING at_bats > 0
        ORDER BY
            last_game_date DESC,
            hits DESC,
            at_bats DESC
        LIMIT {$limit}
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $atBats = (int)$row['at_bats'];
        $hits = (int)$row['hits'];

        $row['avg'] = calculate_batting_average($hits, $atBats);
        $row['display_name'] = coach_player_display_name($row);

        $rows[] = $row;
    }

    usort($rows, function (array $a, array $b): int {
        $aAvg = (float)($a['avg'] ?? 0);
        $bAvg = (float)($b['avg'] ?? 0);

        if ($aAvg !== $bAvg) {
            return $bAvg <=> $aAvg;
        }

        return ((int)$b['hits']) <=> ((int)$a['hits']);
    });

    return $rows;
}
function get_coach_attention_items(int $teamId, ?array $nextGame = null): array
{
    validate_team_id($teamId);

    $items = [];

    $gameDate = null;

    if ($nextGame && !empty($nextGame['game_date'])) {
        $gameDate = (string)$nextGame['game_date'];
    }

    $pitchingAvailability = get_coach_pitching_availability($teamId, $gameDate);

    $resting = array_values(array_filter($pitchingAvailability, function (array $row): bool {
        return $row['status_key'] === 'resting';
    }));

    if (!empty($resting)) {
        $names = array_map(fn(array $row): string => (string)$row['name'], array_slice($resting, 0, 3));

        $items[] = [
            'level' => 'warning',
            'title' => count($resting) . ' pitcher(s) may be resting',
            'body' => implode(', ', $names) . (count($resting) > 3 ? ', and more.' : '.'),
        ];
    }

    $availablePrimary = array_values(array_filter($pitchingAvailability, function (array $row): bool {
        return $row['status_key'] === 'available' && $row['pitching_role'] === 'primary';
    }));

    if (count($availablePrimary) <= 1) {
        $items[] = [
            'level' => 'warning',
            'title' => 'Limited primary pitching available',
            'body' => 'Only ' . count($availablePrimary) . ' primary pitcher(s) appear available for the next game.',
        ];
    }

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
          AND deleted_at IS NULL
          AND tournament_id IS NOT NULL
          AND game_date >= CURDATE()
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $upcomingTournamentGames = (int)$stmt->fetchColumn();

    if ($upcomingTournamentGames > 0) {
        $items[] = [
            'level' => 'info',
            'title' => 'Upcoming tournament games',
            'body' => $upcomingTournamentGames . ' tournament game(s) are coming up. Check tournament pitching usage before finalizing lineups.',
        ];
    }

    if (empty($items)) {
        $items[] = [
            'level' => 'ok',
            'title' => 'No major alerts',
            'body' => 'No obvious pitching or tournament warnings were found.',
        ];
    }

    return $items;
}
