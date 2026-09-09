<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;

$error = '';
$player = null;
$seasonSummary = [];
$positionTotals = [];
$battingSummary = null;
$pitchingSummary = null;
$gameByGameRows = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($playerId <= 0) {
        throw new RuntimeException('No player selected.');
    }

    $player = get_player_by_id($teamId, $playerId);

    if (!$player) {
        throw new RuntimeException('Player not found for this team.');
    }

    $seasonSummary = get_player_season_summary($teamId, $playerId);
    $positionTotals = get_player_position_totals($teamId, $playerId);

    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(games_played), 0) AS games_played,
            COALESCE(SUM(at_bats), 0) AS at_bats,
            COALESCE(SUM(runs), 0) AS runs,
            COALESCE(SUM(hits), 0) AS hits,
            COALESCE(SUM(doubles_hit), 0) AS doubles_hit,
            COALESCE(SUM(triples_hit), 0) AS triples_hit,
            COALESCE(SUM(home_runs), 0) AS home_runs,
            COALESCE(SUM(rbi), 0) AS rbi,
            COALESCE(SUM(walks), 0) AS walks,
            COALESCE(SUM(strikeouts), 0) AS strikeouts,
            COALESCE(SUM(hit_by_pitch), 0) AS hit_by_pitch,
            COALESCE(SUM(sacrifice_flies), 0) AS sacrifice_flies,
            COALESCE(SUM(stolen_bases), 0) AS stolen_bases
        FROM player_batting_stats
        WHERE team_id = :team_id
          AND player_id = :player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    $battingSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(ps.innings_pitched), 0) AS innings_pitched,
            COALESCE(SUM(ps.pitches_thrown), 0) AS pitches_thrown,
            COUNT(DISTINCT ps.game_id) AS games_pitched,
            COALESCE(SUM(ps.hits_allowed), 0) AS hits_allowed,
            COALESCE(SUM(ps.runs_allowed), 0) AS runs_allowed,
            COALESCE(SUM(ps.earned_runs), 0) AS earned_runs,
            COALESCE(SUM(ps.walks), 0) AS walks,
            COALESCE(SUM(ps.strikeouts), 0) AS strikeouts,
            COALESCE(SUM(ps.wins), 0) AS wins,
            COALESCE(SUM(ps.losses), 0) AS losses,
            COALESCE(SUM(ps.saves), 0) AS saves
        FROM player_pitching_stats ps
        INNER JOIN games g
            ON g.id = ps.game_id
           AND g.team_id = ps.team_id
        WHERE ps.team_id = :team_id
          AND ps.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    $pitchingSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pitchingSummary) {
        $pitchingSummary = [
            'innings_pitched' => 0,
            'pitches_thrown' => 0,
            'games_pitched' => 0,
            'hits_allowed' => 0,
            'runs_allowed' => 0,
            'earned_runs' => 0,
            'walks' => 0,
            'strikeouts' => 0,
            'wins' => 0,
            'losses' => 0,
            'saves' => 0,
        ];
    }

    $stmt = db()->prepare("
        SELECT
            g.id AS game_db_id,
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
            COALESCE(bs.stolen_bases, 0) AS stolen_bases,

            COALESCE(ps.innings_pitched, 0) AS innings_pitched,
            COALESCE(ps.pitches_thrown, 0) AS pitches_thrown,
            COALESCE(ps.strikeouts, 0) AS pitching_strikeouts,
            COALESCE(ps.walks, 0) AS pitching_walks,
            COALESCE(ps.earned_runs, 0) AS earned_runs
        FROM game_roster gr
        INNER JOIN games g
            ON g.id = gr.game_db_id
           AND g.team_id = gr.team_id
        LEFT JOIN (
            SELECT
                team_id,
                game_db_id,
                player_id,
                SUM(at_bats) AS at_bats,
                SUM(runs) AS runs,
                SUM(hits) AS hits,
                SUM(doubles_hit) AS doubles_hit,
                SUM(triples_hit) AS triples_hit,
                SUM(home_runs) AS home_runs,
                SUM(rbi) AS rbi,
                SUM(walks) AS walks,
                SUM(strikeouts) AS strikeouts,
                SUM(stolen_bases) AS stolen_bases
            FROM player_batting_game_stats
            GROUP BY team_id, game_db_id, player_id
        ) bs
            ON bs.game_db_id = g.id
           AND bs.team_id = g.team_id
           AND bs.player_id = gr.player_id
        LEFT JOIN (
            SELECT
                team_id,
                game_id,
                player_id,
                SUM(innings_pitched) AS innings_pitched,
                SUM(pitches_thrown) AS pitches_thrown,
                SUM(strikeouts) AS strikeouts,
                SUM(walks) AS walks,
                SUM(earned_runs) AS earned_runs
            FROM player_pitching_stats
            GROUP BY team_id, game_id, player_id
        ) ps
            ON ps.game_id = g.id
           AND ps.team_id = g.team_id
           AND ps.player_id = gr.player_id
        WHERE gr.team_id = :team_id
          AND gr.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
        ORDER BY
            CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
            g.game_date DESC,
            g.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    $gameByGameRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$playerName = $player ? player_full_name($player) : 'Player Profile';

$atBats = (int)($battingSummary['at_bats'] ?? 0);
$hits = (int)($battingSummary['hits'] ?? 0);
$doubles = (int)($battingSummary['doubles_hit'] ?? 0);
$triples = (int)($battingSummary['triples_hit'] ?? 0);
$homeRuns = (int)($battingSummary['home_runs'] ?? 0);
$walks = (int)($battingSummary['walks'] ?? 0);
$hbp = (int)($battingSummary['hit_by_pitch'] ?? 0);
$sf = (int)($battingSummary['sacrifice_flies'] ?? 0);

$avg = calculate_batting_average($hits, $atBats);
$obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
$slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
$ops = calculate_ops($obp, $slg);

$ip = (float)($pitchingSummary['innings_pitched'] ?? 0);
$earnedRuns = (int)($pitchingSummary['earned_runs'] ?? 0);
$pitchWalks = (int)($pitchingSummary['walks'] ?? 0);
$hitsAllowed = (int)($pitchingSummary['hits_allowed'] ?? 0);

$era = calculate_era($earnedRuns, $ip);
$whip = calculate_whip($pitchWalks, $hitsAllowed, $ip);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($playerName) ?> - Player Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111827;
            background: #ffffff;
            margin: 24px;
            font-size: 13px;
        }

        .no-print {
            margin-bottom: 18px;
        }

        .btn {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 8px;
            background: #111827;
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
            border: 0;
            cursor: pointer;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #111827;
        }

        .print-header {
            border-bottom: 3px solid #111827;
            padding-bottom: 14px;
            margin-bottom: 22px;
        }

        .print-header h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }

        .print-header p {
            margin: 0;
            color: #4b5563;
        }

        .print-section {
            margin-bottom: 24px;
            page-break-inside: avoid;
        }

        .print-section h2 {
            font-size: 18px;
            margin: 0 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #d1d5db;
        }

        .print-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .print-stat {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px;
            background: #f9fafb;
        }

        .print-stat span {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .print-stat strong {
            display: block;
            font-size: 20px;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 0.4in;
            }

            .print-section {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>

<div class="no-print">
    <button class="btn" onclick="window.print()">Print Profile</button>
    <a class="btn btn-secondary" href="player_profile.php?player_id=<?= (int)$playerId ?>">Back to Profile</a>
</div>

<?php if ($error !== ''): ?>
    <div class="print-section">
        <h2>Error</h2>
        <p><?= h($error) ?></p>
    </div>
<?php elseif ($player): ?>

    <div class="print-header">
        <h1>
            <?php if (!empty($player['jersey_number'])): ?>
                #<?= h((string)$player['jersey_number']) ?>
            <?php endif; ?>
            <?= h($playerName) ?>
        </h1>
        <p>
            Status: <?= !empty($player['active']) ? 'Active' : 'Inactive' ?>
            |
            Pitching Role: <?= h(ucfirst((string)($player['pitching_role'] ?? 'none'))) ?>
            |
            Printed: <?= h(date('Y-m-d')) ?>
        </p>
    </div>

    <div class="print-section">
        <h2>Player Summary</h2>

        <div class="print-grid">
            <div class="print-stat"><span>Games Rostered</span><strong><?= count($gameByGameRows) ?></strong></div>
            <div class="print-stat"><span>Bench Innings</span><strong><?= (int)($seasonSummary['total_bench_times'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>Games Benched</span><strong><?= (int)($seasonSummary['games_benched'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>Games Pitched</span><strong><?= (int)($pitchingSummary['games_pitched'] ?? 0) ?></strong></div>
        </div>
    </div>

    <div class="print-section">
        <h2>Season Batting</h2>

        <div class="print-grid">
            <div class="print-stat"><span>AVG</span><strong><?= h(format_baseball_rate($avg)) ?></strong></div>
            <div class="print-stat"><span>OBP</span><strong><?= h(format_baseball_rate($obp)) ?></strong></div>
            <div class="print-stat"><span>SLG</span><strong><?= h(format_baseball_rate($slg)) ?></strong></div>
            <div class="print-stat"><span>OPS</span><strong><?= h(format_baseball_rate($ops)) ?></strong></div>
            <div class="print-stat"><span>AB</span><strong><?= $atBats ?></strong></div>
            <div class="print-stat"><span>H</span><strong><?= $hits ?></strong></div>
            <div class="print-stat"><span>RBI</span><strong><?= (int)($battingSummary['rbi'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>SB</span><strong><?= (int)($battingSummary['stolen_bases'] ?? 0) ?></strong></div>
        </div>
    </div>

    <div class="print-section">
        <h2>Season Pitching</h2>

        <div class="print-grid">
            <div class="print-stat"><span>IP</span><strong><?= h(number_format($ip, 1)) ?></strong></div>
            <div class="print-stat"><span>ERA</span><strong><?= h(number_format($era, 2)) ?></strong></div>
            <div class="print-stat"><span>WHIP</span><strong><?= h(number_format($whip, 2)) ?></strong></div>
            <div class="print-stat"><span>Pitches</span><strong><?= (int)($pitchingSummary['pitches_thrown'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>K</span><strong><?= (int)($pitchingSummary['strikeouts'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>BB</span><strong><?= (int)($pitchingSummary['walks'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>W</span><strong><?= (int)($pitchingSummary['wins'] ?? 0) ?></strong></div>
            <div class="print-stat"><span>SV</span><strong><?= (int)($pitchingSummary['saves'] ?? 0) ?></strong></div>
        </div>
    </div>

    <div class="print-section">
        <h2>Position Usage</h2>

        <?php if (empty($positionTotals)): ?>
            <p class="muted">No defensive innings recorded.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Position</th>
                        <th>Innings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($positionTotals as $position => $innings): ?>
                        <tr>
                            <td><?= h((string)$position) ?></td>
                            <td><?= h(number_format((float)$innings, 1)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="print-section">
        <h2>Game-by-Game Stats</h2>

        <?php if (empty($gameByGameRows)): ?>
            <p class="muted">No game-by-game stats found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Game</th>
                        <th>Date</th>
                        <th>AB</th>
                        <th>H</th>
                        <th>R</th>
                        <th>RBI</th>
                        <th>BB</th>
                        <th>K</th>
                        <th>SB</th>
                        <th>IP</th>
                        <th>Pitches</th>
                        <th>PK</th>
                        <th>PBB</th>
                        <th>ER</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gameByGameRows as $row): ?>
                        <tr>
                            <td><?= h((string)$row['game_id']) ?></td>
                            <td><?= !empty($row['game_date']) ? h((string)$row['game_date']) : 'Date not set' ?></td>
                            <td><?= (int)$row['at_bats'] ?></td>
                            <td><?= (int)$row['hits'] ?></td>
                            <td><?= (int)$row['runs'] ?></td>
                            <td><?= (int)$row['rbi'] ?></td>
                            <td><?= (int)$row['walks'] ?></td>
                            <td><?= (int)$row['strikeouts'] ?></td>
                            <td><?= (int)$row['stolen_bases'] ?></td>
                            <td><?= h(number_format((float)$row['innings_pitched'], 1)) ?></td>
                            <td><?= (int)$row['pitches_thrown'] ?></td>
                            <td><?= (int)$row['pitching_strikeouts'] ?></td>
                            <td><?= (int)$row['pitching_walks'] ?></td>
                            <td><?= (int)$row['earned_runs'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php endif; ?>

<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});
</script>

</body>
</html>
