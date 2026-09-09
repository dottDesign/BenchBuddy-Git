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

$filename = 'benchbuddy-' . $safeName . '-game-log-template.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'game_id',
    'game_date',
    'home_away',
    'ab',
    'r',
    'h',
    '2b',
    '3b',
    'hr',
    'rbi',
    'bb',
    'k',
    'hbp',
    'sf',
    'sb',
    'ip',
    'pitches',
    'hits_allowed',
    'runs_allowed',
    'er',
    'pitching_bb',
    'pitching_k',
    'w',
    'l',
    'sv',
]);

fputcsv($output, [
    'Game 1',
    date('Y-m-d'),
    'home',
    3,
    1,
    2,
    1,
    0,
    0,
    2,
    1,
    0,
    0,
    0,
    1,
    0,
    0,
    0,
    0,
    0,
    0,
    0,
    0,
    0,
    0,
]);

fputcsv($output, [
    'Game 2',
    date('Y-m-d', strtotime('+2 days')),
    'away',
    2,
    0,
    1,
    0,
    0,
    0,
    1,
    0,
    1,
    0,
    0,
    0,
    1.0,
    24,
    2,
    1,
    1,
    1,
    2,
    1,
    0,
    0,
]);

fclose($output);
exit;
