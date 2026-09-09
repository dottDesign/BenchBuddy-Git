<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();

if ($teamId <= 0) {
    http_response_code(400);
    exit('No team selected.');
}

if (!team_stats_enabled($teamId)) {
    http_response_code(403);
    exit('Stats are not enabled for this team.');
}

$filename = 'benchbuddy-player-stats-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Type',
    'Player ID',
    'Player',
    'Jersey',
    'Games',
    'AB',
    'R',
    'H',
    '2B',
    '3B',
    'HR',
    'RBI',
    'BB',
    'K',
    'HBP',
    'SF',
    'SB',
    'AVG',
    'OBP',
    'SLG',
    'OPS',
    'IP',
    'Pitches',
    'H Allowed',
    'R Allowed',
    'ER',
    'Pitching BB',
    'Pitching K',
    'W',
    'L',
    'SV',
    'ERA',
    'WHIP',
]);

$stmt = db()->prepare("
    SELECT
        p.id,
        p.first_name,
        p.last_name,
        p.jersey_number,

        COALESCE(SUM(bs.games_played), 0) AS games_played,
        COALESCE(SUM(bs.at_bats), 0) AS at_bats,
        COALESCE(SUM(bs.runs), 0) AS runs,
        COALESCE(SUM(bs.hits), 0) AS hits,
        COALESCE(SUM(bs.doubles_hit), 0) AS doubles_hit,
        COALESCE(SUM(bs.triples_hit), 0) AS triples_hit,
        COALESCE(SUM(bs.home_runs), 0) AS home_runs,
        COALESCE(SUM(bs.rbi), 0) AS rbi,
        COALESCE(SUM(bs.walks), 0) AS walks,
        COALESCE(SUM(bs.strikeouts), 0) AS strikeouts,
        COALESCE(SUM(bs.hit_by_pitch), 0) AS hit_by_pitch,
        COALESCE(SUM(bs.sacrifice_flies), 0) AS sacrifice_flies,
        COALESCE(SUM(bs.stolen_bases), 0) AS stolen_bases
    FROM players p
    LEFT JOIN player_batting_stats bs
        ON bs.player_id = p.id
       AND bs.team_id = p.team_id
    WHERE p.team_id = :team_id
    GROUP BY
        p.id,
        p.first_name,
        p.last_name,
        p.jersey_number
    ORDER BY p.last_name ASC, p.first_name ASC
");

$stmt->execute([
    'team_id' => $teamId,
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $atBats = (int)$row['at_bats'];
    $hits = (int)$row['hits'];
    $doubles = (int)$row['doubles_hit'];
    $triples = (int)$row['triples_hit'];
    $homeRuns = (int)$row['home_runs'];
    $walks = (int)$row['walks'];
    $hbp = (int)$row['hit_by_pitch'];
    $sf = (int)$row['sacrifice_flies'];

    $avg = calculate_batting_average($hits, $atBats);
    $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
    $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
    $ops = calculate_ops($obp, $slg);

    fputcsv($output, [
        'Batting',
        (int)$row['id'],
        player_full_name($row),
        (string)($row['jersey_number'] ?? ''),
        (int)$row['games_played'],
        $atBats,
        (int)$row['runs'],
        $hits,
        $doubles,
        $triples,
        $homeRuns,
        (int)$row['rbi'],
        $walks,
        (int)$row['strikeouts'],
        $hbp,
        $sf,
        (int)$row['stolen_bases'],
        format_baseball_rate($avg),
        format_baseball_rate($obp),
        format_baseball_rate($slg),
        format_baseball_rate($ops),
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
    ]);
}

$stmt = db()->prepare("
    SELECT
        p.id,
        p.first_name,
        p.last_name,
        p.jersey_number,

        COALESCE(ps.innings_pitched, 0) AS innings_pitched,
        COALESCE(ps.pitches_thrown, 0) AS pitches_thrown,
        COALESCE(ps.hits_allowed, 0) AS hits_allowed,
        COALESCE(ps.runs_allowed, 0) AS runs_allowed,
        COALESCE(ps.earned_runs, 0) AS earned_runs,
        COALESCE(ps.walks, 0) AS walks,
        COALESCE(ps.strikeouts, 0) AS strikeouts,
        COALESCE(ps.wins, 0) AS wins,
        COALESCE(ps.losses, 0) AS losses,
        COALESCE(ps.saves, 0) AS saves
    FROM players p
    LEFT JOIN (
        SELECT
            team_id,
            player_id,
            SUM(innings_pitched) AS innings_pitched,
            SUM(pitches_thrown) AS pitches_thrown,
            SUM(hits_allowed) AS hits_allowed,
            SUM(runs_allowed) AS runs_allowed,
            SUM(earned_runs) AS earned_runs,
            SUM(walks) AS walks,
            SUM(strikeouts) AS strikeouts,
            SUM(wins) AS wins,
            SUM(losses) AS losses,
            SUM(saves) AS saves
        FROM player_pitching_stats
        GROUP BY team_id, player_id
    ) ps
        ON ps.player_id = p.id
       AND ps.team_id = p.team_id
    WHERE p.team_id = :team_id
    HAVING innings_pitched > 0
        OR pitches_thrown > 0
        OR strikeouts > 0
        OR walks > 0
        OR earned_runs > 0
    ORDER BY p.last_name ASC, p.first_name ASC
");

$stmt->execute([
    'team_id' => $teamId,
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ip = (float)$row['innings_pitched'];
    $earnedRuns = (int)$row['earned_runs'];
    $walks = (int)$row['walks'];
    $hitsAllowed = (int)$row['hits_allowed'];

    $era = calculate_era($earnedRuns, $ip);
    $whip = calculate_whip($walks, $hitsAllowed, $ip);

    fputcsv($output, [
        'Pitching',
        (int)$row['id'],
        player_full_name($row),
        (string)($row['jersey_number'] ?? ''),
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        number_format($ip, 1),
        (int)$row['pitches_thrown'],
        $hitsAllowed,
        (int)$row['runs_allowed'],
        $earnedRuns,
        $walks,
        (int)$row['strikeouts'],
        (int)$row['wins'],
        (int)$row['losses'],
        (int)$row['saves'],
        number_format($era, 2),
        number_format($whip, 2),
    ]);
}

fclose($output);
exit;
