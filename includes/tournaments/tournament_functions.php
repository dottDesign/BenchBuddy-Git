<?php
declare(strict_types=1);

function get_tournaments_for_team(int $teamId): array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT
            t.*,
            prs.label AS rule_set_label
        FROM tournaments t
        LEFT JOIN pitch_rule_sets prs
            ON prs.id = t.rule_set_id
        WHERE t.team_id = :team_id
        ORDER BY
            CASE WHEN t.start_date IS NULL THEN 1 ELSE 0 END,
            t.start_date DESC,
            t.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_tournament_by_id(int $teamId, int $tournamentId): ?array
{
    validate_team_id($teamId);

    if ($tournamentId <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT
            t.*,
            prs.label AS rule_set_label
        FROM tournaments t
        LEFT JOIN pitch_rule_sets prs
            ON prs.id = t.rule_set_id
        WHERE t.team_id = :team_id
          AND t.id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'id' => $tournamentId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function get_games_for_tournament(int $teamId, int $tournamentId): array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
          AND tournament_id = :tournament_id
          AND deleted_at IS NULL
        ORDER BY
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date ASC,
            id ASC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_tournament_game_count(int $teamId, int $tournamentId): int
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
          AND tournament_id = :tournament_id
          AND deleted_at IS NULL
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    return (int)$stmt->fetchColumn();
}

function get_games_available_for_tournament(int $teamId, int $tournamentId): array
{
    validate_team_id($teamId);

    $stmt = db()->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
          AND deleted_at IS NULL
          AND (
              tournament_id IS NULL
              OR tournament_id = 0
              OR tournament_id = :tournament_id
          )
        ORDER BY
            CASE WHEN game_date IS NULL THEN 1 ELSE 0 END,
            game_date ASC,
            id ASC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_tournament_pitching_snapshot(int $teamId, int $tournamentId): array
{
    validate_team_id($teamId);

    $players = get_active_players($teamId);
    $snapshot = [];

    foreach ($players as $player) {
        $playerId = (int)$player['id'];

        $snapshot[$playerId] = [
            'player' => $player,
            'innings_pitched' => 0.0,
            'pitches_thrown' => 0,
            'last_game_date' => null,
            'last_game_label' => null,
            'last_pitches' => 0,
            'next_available_date' => null,
            'status' => 'Available',
        ];
    }

    $stmt = db()->prepare("
        SELECT
            pl.player_id,
            COALESCE(SUM(pl.innings_pitched), 0) AS tournament_innings,
            COALESCE(SUM(pl.pitches_thrown), 0) AS tournament_pitches
        FROM pitch_log pl
        INNER JOIN games g
            ON g.id = pl.game_db_id
           AND g.team_id = pl.team_id
        WHERE pl.team_id = :team_id
          AND g.tournament_id = :tournament_id
          AND g.deleted_at IS NULL
        GROUP BY pl.player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playerId = (int)$row['player_id'];

        if (!isset($snapshot[$playerId])) {
            continue;
        }

        $snapshot[$playerId]['innings_pitched'] = (float)$row['tournament_innings'];
        $snapshot[$playerId]['pitches_thrown'] = (int)$row['tournament_pitches'];
    }

    $stmt = db()->prepare("
        SELECT
            pl.player_id,
            pl.pitches_thrown,
            pl.innings_pitched,
            g.id AS game_db_id,
            g.game_id,
            g.game_date
        FROM pitch_log pl
        INNER JOIN games g
            ON g.id = pl.game_db_id
           AND g.team_id = pl.team_id
        INNER JOIN (
            SELECT
                pl2.player_id,
                MAX(g2.game_date) AS last_game_date
            FROM pitch_log pl2
            INNER JOIN games g2
                ON g2.id = pl2.game_db_id
               AND g2.team_id = pl2.team_id
            WHERE pl2.team_id = :team_id
              AND g2.tournament_id = :tournament_id
              AND g2.deleted_at IS NULL
              AND pl2.pitches_thrown > 0
              AND g2.game_date IS NOT NULL
            GROUP BY pl2.player_id
        ) latest
            ON latest.player_id = pl.player_id
           AND latest.last_game_date = g.game_date
        WHERE pl.team_id = :team_id
          AND g.tournament_id = :tournament_id
          AND g.deleted_at IS NULL
          AND pl.pitches_thrown > 0
        ORDER BY g.game_date DESC, g.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playerId = (int)$row['player_id'];

        if (!isset($snapshot[$playerId])) {
            continue;
        }

        if (!empty($snapshot[$playerId]['last_game_date'])) {
            continue;
        }

        $snapshot[$playerId]['last_game_date'] = $row['game_date'] ?? null;
        $snapshot[$playerId]['last_game_label'] = $row['game_id'] ?? null;
        $snapshot[$playerId]['last_pitches'] = (int)($row['pitches_thrown'] ?? 0);
    }

    $today = new DateTimeImmutable(date('Y-m-d'));

    foreach ($snapshot as $playerId => $row) {
        $nextAvailableDate = get_pitcher_next_available_date($teamId, (int)$playerId);

        $snapshot[$playerId]['next_available_date'] = $nextAvailableDate;

        if ($nextAvailableDate !== null && $nextAvailableDate !== '') {
            $availableDate = new DateTimeImmutable($nextAvailableDate);

            if ($availableDate > $today) {
                $snapshot[$playerId]['status'] = 'Resting';
            }
        }
    }

    uasort($snapshot, function (array $a, array $b): int {
        $aStatus = (string)$a['status'];
        $bStatus = (string)$b['status'];

        if ($aStatus !== $bStatus) {
            return $aStatus === 'Resting' ? -1 : 1;
        }

        return ((int)$b['pitches_thrown']) <=> ((int)$a['pitches_thrown']);
    });

    return array_values($snapshot);
}
function tournament_player_display_name(array $player): string
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

function get_tournament_player_stats_overview(int $teamId, int $tournamentId): array
{
    validate_team_id($teamId);

    $players = get_active_players($teamId);
    $stats = [];

    foreach ($players as $player) {
        $playerId = (int)$player['id'];

        $stats[$playerId] = [
            'player' => $player,

            'games_played' => 0,
            'at_bats' => 0,
            'runs' => 0,
            'hits' => 0,
            'doubles_hit' => 0,
            'triples_hit' => 0,
            'home_runs' => 0,
            'rbi' => 0,
            'walks' => 0,
            'strikeouts' => 0,
            'hit_by_pitch' => 0,
            'sacrifice_flies' => 0,
            'stolen_bases' => 0,

            'innings_pitched' => 0.0,
            'pitches_thrown' => 0,
            'hits_allowed' => 0,
            'runs_allowed' => 0,
            'earned_runs' => 0,
            'pitching_walks' => 0,
            'pitching_strikeouts' => 0,
        ];
    }

    $stmt = db()->prepare("
        SELECT
            pbgs.player_id,
            COUNT(DISTINCT pbgs.game_db_id) AS games_played,
            COALESCE(SUM(pbgs.at_bats), 0) AS at_bats,
            COALESCE(SUM(pbgs.runs), 0) AS runs,
            COALESCE(SUM(pbgs.hits), 0) AS hits,
            COALESCE(SUM(pbgs.doubles_hit), 0) AS doubles_hit,
            COALESCE(SUM(pbgs.triples_hit), 0) AS triples_hit,
            COALESCE(SUM(pbgs.home_runs), 0) AS home_runs,
            COALESCE(SUM(pbgs.rbi), 0) AS rbi,
            COALESCE(SUM(pbgs.walks), 0) AS walks,
            COALESCE(SUM(pbgs.strikeouts), 0) AS strikeouts,
            COALESCE(SUM(pbgs.hit_by_pitch), 0) AS hit_by_pitch,
            COALESCE(SUM(pbgs.sacrifice_flies), 0) AS sacrifice_flies,
            COALESCE(SUM(pbgs.stolen_bases), 0) AS stolen_bases
        FROM player_batting_game_stats pbgs
        INNER JOIN games g
            ON g.id = pbgs.game_db_id
           AND g.team_id = pbgs.team_id
        WHERE pbgs.team_id = :team_id
          AND g.tournament_id = :tournament_id
          AND g.deleted_at IS NULL
        GROUP BY pbgs.player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playerId = (int)$row['player_id'];

        if (!isset($stats[$playerId])) {
            continue;
        }

        $stats[$playerId]['games_played'] = (int)$row['games_played'];
        $stats[$playerId]['at_bats'] = (int)$row['at_bats'];
        $stats[$playerId]['runs'] = (int)$row['runs'];
        $stats[$playerId]['hits'] = (int)$row['hits'];
        $stats[$playerId]['doubles_hit'] = (int)$row['doubles_hit'];
        $stats[$playerId]['triples_hit'] = (int)$row['triples_hit'];
        $stats[$playerId]['home_runs'] = (int)$row['home_runs'];
        $stats[$playerId]['rbi'] = (int)$row['rbi'];
        $stats[$playerId]['walks'] = (int)$row['walks'];
        $stats[$playerId]['strikeouts'] = (int)$row['strikeouts'];
        $stats[$playerId]['hit_by_pitch'] = (int)$row['hit_by_pitch'];
        $stats[$playerId]['sacrifice_flies'] = (int)$row['sacrifice_flies'];
        $stats[$playerId]['stolen_bases'] = (int)$row['stolen_bases'];
    }

    $stmt = db()->prepare("
        SELECT
            pps.player_id,
            COALESCE(SUM(pps.innings_pitched), 0) AS innings_pitched,
            COALESCE(SUM(pps.pitches_thrown), 0) AS pitches_thrown,
            COALESCE(SUM(pps.hits_allowed), 0) AS hits_allowed,
            COALESCE(SUM(pps.runs_allowed), 0) AS runs_allowed,
            COALESCE(SUM(pps.earned_runs), 0) AS earned_runs,
            COALESCE(SUM(pps.walks), 0) AS pitching_walks,
            COALESCE(SUM(pps.strikeouts), 0) AS pitching_strikeouts
        FROM player_pitching_stats pps
        INNER JOIN games g
            ON g.id = pps.game_id
           AND g.team_id = pps.team_id
        WHERE pps.team_id = :team_id
          AND g.tournament_id = :tournament_id
          AND g.deleted_at IS NULL
        GROUP BY pps.player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'tournament_id' => $tournamentId,
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playerId = (int)$row['player_id'];

        if (!isset($stats[$playerId])) {
            continue;
        }

        $stats[$playerId]['innings_pitched'] = (float)$row['innings_pitched'];
        $stats[$playerId]['pitches_thrown'] = (int)$row['pitches_thrown'];
        $stats[$playerId]['hits_allowed'] = (int)$row['hits_allowed'];
        $stats[$playerId]['runs_allowed'] = (int)$row['runs_allowed'];
        $stats[$playerId]['earned_runs'] = (int)$row['earned_runs'];
        $stats[$playerId]['pitching_walks'] = (int)$row['pitching_walks'];
        $stats[$playerId]['pitching_strikeouts'] = (int)$row['pitching_strikeouts'];
    }

    uasort($stats, function (array $a, array $b): int {
        $aActivity = (int)$a['at_bats'] + (int)$a['pitches_thrown'];
        $bActivity = (int)$b['at_bats'] + (int)$b['pitches_thrown'];

        if ($aActivity !== $bActivity) {
            return $bActivity <=> $aActivity;
        }

        return tournament_player_display_name($a['player']) <=> tournament_player_display_name($b['player']);
    });

    return array_values($stats);
}
