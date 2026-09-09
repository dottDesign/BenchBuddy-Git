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

$filename = 'benchbuddy-game-stats-import-template.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'player_id',
    'player_name',
    'jersey_number',
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

$players = get_active_players($teamId);

foreach ($players as $player) {
    fputcsv($output, [
        (int)$player['id'],
        player_full_name($player),
        (string)($player['jersey_number'] ?? ''),
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
        '',
        '',
        '',
        '',
        '',
    ]);
}

fclose($output);
exit;
