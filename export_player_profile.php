<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;

if ($teamId <= 0) {
    http_response_code(400);
    exit('No team selected.');
}

if ($playerId <= 0) {
    http_response_code(400);
    exit('No player selected.');
}

if (!team_stats_enabled($teamId)) {
    http_response_code(403);
    exit('Stats are not enabled for this team.');
}

$player = get_player_by_id($teamId, $playerId);

if (!$player) {
    http_response_code(404);
    exit('Player not found.');
}

$playerName = player_full_name($player);
$safeName = preg_replace('/[^a-z0-9]+/i', '-', strtolower($playerName));
$safeName = trim((string)$safeName, '-');

$filename = 'benchbuddy-' . $safeName . '-stats-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Game',
    'Date',
    'Home/Away',
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
]);

$stmt = db()->prepare("
    SELECT
        g.game_id,
        g.game_date,
        g.home_away,

        COALESCE(bs.at_bats, 0) AS at_bats,
        COALESCE(bs.runs, 0) AS runs,
        COALESCE(bs.hits, 0) AS hits,
        COALESCE(bs.doubles_hit, 0) AS doubles_hit,
        COALESCE(bs.triples_hit, 0) AS triples_hit,
        COALESCE(bs.home_runs, 0) AS home_runs,
        COALESCE(bs.rbi, 0) AS rbi,
        COALESCE(bs.walks, 0) AS walks,
        COALESCE(bs.strikeouts, 0) AS strikeouts,
        COALESCE(bs.hit_by_pitch, 0) AS hit_by_pitch,
        COALESCE(bs.sacrifice_flies, 0) AS sacrifice_flies,
        COALESCE(bs.stolen_bases, 0) AS stolen_bases,

        COALESCE(ps.innings_pitched, 0) AS innings_pitched,
        COALESCE(ps.pitches_thrown, 0) AS pitches_thrown,
        COALESCE(ps.hits_allowed, 0) AS hits_allowed,
        COALESCE(ps.runs_allowed, 0) AS runs_allowed,
        COALESCE(ps.earned_runs, 0) AS earned_runs,
        COALESCE(ps.walks, 0) AS pitching_walks,
        COALESCE(ps.strikeouts, 0) AS pitching_strikeouts,
        COALESCE(ps.wins, 0) AS wins,
        COALESCE(ps.losses, 0) AS losses,
        COALESCE(ps.saves, 0) AS saves
    FROM game_roster gr
    INNER JOIN games g
        ON g.id = gr.game_db_id
       AND g.team_id = gr.team_id
    LEFT JOIN player_batting_game_stats bs
        ON bs.game_db_id = g.id
       AND bs.team_id = g.team_id
       AND bs.player_id = gr.player_id
    LEFT JOIN player_pitching_stats ps
        ON ps.game_id = g.id
       AND ps.team_id = g.team_id
       AND ps.player_id = gr.player_id
    WHERE gr.team_id = :team_id
      AND gr.player_id = :player_id
      AND g.deleted_at IS NULL
      AND COALESCE(g.counts_toward_stats, 1) = 1
    ORDER BY
        CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
        g.game_date ASC,
        g.id ASC
");

$stmt->execute([
    'team_id' => $teamId,
    'player_id' => $playerId,
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
        (string)$row['game_id'],
        (string)($row['game_date'] ?? ''),
        (string)($row['home_away'] ?? ''),
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
        number_format((float)$row['innings_pitched'], 1),
        (int)$row['pitches_thrown'],
        (int)$row['hits_allowed'],
        (int)$row['runs_allowed'],
        (int)$row['earned_runs'],
        (int)$row['pitching_walks'],
        (int)$row['pitching_strikeouts'],
        (int)$row['wins'],
        (int)$row['losses'],
        (int)$row['saves'],
    ]);
}

fclose($output);
exit;
