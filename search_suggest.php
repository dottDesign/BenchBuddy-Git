<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

header('Content-Type: application/json');

$teamId = current_team_id();
$query = trim((string)($_GET['q'] ?? ''));

if ($teamId <= 0 || strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

$normalizedQuery = strtolower($query);
$results = [];

$staticSuggestions = [
    ['type' => 'Smart Search', 'title' => 'Top Hitters', 'subtitle' => 'Rank players by OPS', 'url' => 'search.php?q=top+hitters', 'keywords' => 'top hitters batting leaders best ops'],
    ['type' => 'Smart Search', 'title' => 'Top Pitchers', 'subtitle' => 'Rank pitchers by ERA and strikeouts', 'url' => 'search.php?q=top+pitchers', 'keywords' => 'top pitchers pitching leaders best era'],
    ['type' => 'Smart Search', 'title' => 'Recent Games', 'subtitle' => 'Show latest games', 'url' => 'search.php?q=recent+games', 'keywords' => 'recent games latest games last games'],
    ['type' => 'Smart Search', 'title' => 'SB Leaders', 'subtitle' => 'Most stolen bases', 'url' => 'search.php?q=most+stolen+bases', 'keywords' => 'stolen bases steals sb leaders'],
    ['type' => 'Page', 'title' => 'Manual Lineup', 'subtitle' => 'Edit positions and bench assignments', 'url' => 'manual_lineup.php', 'keywords' => 'manual lineup edit lineup positions bench'],
    ['type' => 'Page', 'title' => 'Pitch Rules', 'subtitle' => 'Pitch count limits and rest rules', 'url' => 'pitch_rules.php', 'keywords' => 'pitch rules pitch count rest days'],
    ['type' => 'Page', 'title' => 'Player Stats', 'subtitle' => 'Batting and pitching leaderboards', 'url' => 'player_stats.php', 'keywords' => 'player stats batting pitching era whip ops'],
    ['type' => 'Page', 'title' => 'Games', 'subtitle' => 'Create and manage games', 'url' => 'games.php', 'keywords' => 'games create game schedule roster'],
    ['type' => 'Page', 'title' => 'History', 'subtitle' => 'Review archived games and lineups', 'url' => 'history.php', 'keywords' => 'history archived games lineups'],
];

foreach ($staticSuggestions as $item) {
    $haystack = strtolower($item['title'] . ' ' . $item['subtitle'] . ' ' . $item['keywords']);

    if (str_contains($haystack, $normalizedQuery)) {
        unset($item['keywords']);
        $results[] = $item;
    }
}

$like = '%' . $query . '%';

$stmt = db()->prepare("
    SELECT
        'Player' AS type,
        CONCAT(first_name, ' ', last_name) AS title,
        CONCAT('Jersey #', COALESCE(jersey_number, '')) AS subtitle,
        CONCAT('player_profiles.php?player_id=', id) AS url
    FROM players
    WHERE team_id = :team_id
      AND active = 1
      AND (
        first_name LIKE :q
        OR last_name LIKE :q
        OR jersey_number LIKE :q
      )

    UNION ALL

    SELECT
        'Game' AS type,
        game_id AS title,
        CONCAT('Status: ', status, ' | Date: ', COALESCE(game_date, 'Not set')) AS subtitle,
        CONCAT('history.php?status=all&game_id=', id) AS url
    FROM games
    WHERE team_id = :team_id
      AND deleted_at IS NULL
      AND (
        game_id LIKE :q
        OR status LIKE :q
        OR game_date LIKE :q
      )

    LIMIT 8
");

$stmt->execute([
    'team_id' => $teamId,
    'q' => $like,
]);

$results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));

$unique = [];
$deduped = [];

foreach ($results as $result) {
    $key = strtolower((string)$result['type'] . '|' . (string)$result['title'] . '|' . (string)$result['url']);

    if (!isset($unique[$key])) {
        $unique[$key] = true;
        $deduped[] = $result;
    }
}

echo json_encode(array_slice($deduped, 0, 10));
exit;
