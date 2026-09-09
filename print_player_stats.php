<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$error = '';
$battingRows = [];
$pitchingRows = [];

if (!team_stats_enabled($teamId)) {
    $error = 'Player stat recording is disabled for this team.';
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

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
        GROUP BY p.id, p.first_name, p.last_name, p.jersey_number
        ORDER BY p.last_name ASC, p.first_name ASC
    ");

    $stmt->execute(['team_id' => $teamId]);
    $battingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($battingRows, function (array $a, array $b): int {
        $avgA = calculate_batting_average((int)$a['hits'], (int)$a['at_bats']);
        $avgB = calculate_batting_average((int)$b['hits'], (int)$b['at_bats']);

        return $avgB <=> $avgA;
    });
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

    $stmt->execute(['team_id' => $teamId]);
    $pitchingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($pitchingRows, function (array $a, array $b): int {
        return (int)$b['pitches_thrown'] <=> (int)$a['pitches_thrown'];
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function print_player_name(array $row): string
{
    $name = player_full_name($row);

    if (!empty($row['jersey_number'])) {
        return '#' . (string)$row['jersey_number'] . ' ' . $name;
    }

    return $name;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Player Stats | Print</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111827;
            background: #fff;
            margin: 24px;
            font-size: 12px;
        }

        .no-print {
            margin-bottom: 18px;
        }

        .btn {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 8px;
            background: #111827;
            color: #fff;
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
            margin-bottom: 22px;
            padding-bottom: 14px;
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
            margin-bottom: 26px;
            page-break-inside: avoid;
        }

        .print-section h2 {
            font-size: 18px;
            margin: 0 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #d1d5db;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 5px;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .error {
            padding: 12px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 8px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 0.35in;
            }

            .print-section {
                break-inside: avoid;
            }

            table {
                font-size: 9px;
            }

            th,
            td {
                padding: 4px;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn" onclick="window.print()">Print Stats</button>
    <a class="btn btn-secondary" href="player_stats.php">Back to Player Stats</a>
</div>

<div class="print-header">
    <h1>Player Stats</h1>
    <p>BenchBuddy Season Stats | Printed <?= h(date('Y-m-d g:i A')) ?></p>
</div>

<?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
<?php else: ?>

    <section class="print-section">
        <h2>Season Batting</h2>

        <table>
            <thead>
                <tr>
                    <th>Player</th>
                    <th>G</th>
                    <th>AB</th>
                    <th>R</th>
                    <th>H</th>
                    <th>2B</th>
                    <th>3B</th>
                    <th>HR</th>
                    <th>RBI</th>
                    <th>BB</th>
                    <th>K</th>
                    <th>SB</th>
                    <th>AVG</th>
                    <th>OBP</th>
                    <th>SLG</th>
                    <th>OPS</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($battingRows as $row): ?>
                    <?php
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
                    ?>
                    <tr>
                        <td><?= h(print_player_name($row)) ?></td>
                        <td><?= (int)$row['games_played'] ?></td>
                        <td><?= $atBats ?></td>
                        <td><?= (int)$row['runs'] ?></td>
                        <td><?= $hits ?></td>
                        <td><?= $doubles ?></td>
                        <td><?= $triples ?></td>
                        <td><?= $homeRuns ?></td>
                        <td><?= (int)$row['rbi'] ?></td>
                        <td><?= $walks ?></td>
                        <td><?= (int)$row['strikeouts'] ?></td>
                        <td><?= (int)$row['stolen_bases'] ?></td>
                        <td><?= h(format_baseball_rate($avg)) ?></td>
                        <td><?= h(format_baseball_rate($obp)) ?></td>
                        <td><?= h(format_baseball_rate($slg)) ?></td>
                        <td><?= h(format_baseball_rate($ops)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="print-section">
        <h2>Season Pitching</h2>

        <?php if (empty($pitchingRows)): ?>
            <p>No pitching stats recorded.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>IP</th>
                        <th>Pitches</th>
                        <th>H</th>
                        <th>R</th>
                        <th>ER</th>
                        <th>BB</th>
                        <th>K</th>
                        <th>W</th>
                        <th>L</th>
                        <th>SV</th>
                        <th>ERA</th>
                        <th>WHIP</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($pitchingRows as $row): ?>
                        <?php
                            $ip = (float)$row['innings_pitched'];
                            $er = (int)$row['earned_runs'];
                            $walks = (int)$row['walks'];
                            $hitsAllowed = (int)$row['hits_allowed'];

                            $era = calculate_era($er, $ip);
                            $whip = calculate_whip($walks, $hitsAllowed, $ip);
                        ?>
                        <tr>
                            <td><?= h(print_player_name($row)) ?></td>
                            <td><?= h(number_format($ip, 1)) ?></td>
                            <td><?= (int)$row['pitches_thrown'] ?></td>
                            <td><?= $hitsAllowed ?></td>
                            <td><?= (int)$row['runs_allowed'] ?></td>
                            <td><?= $er ?></td>
                            <td><?= $walks ?></td>
                            <td><?= (int)$row['strikeouts'] ?></td>
                            <td><?= (int)$row['wins'] ?></td>
                            <td><?= (int)$row['losses'] ?></td>
                            <td><?= (int)$row['saves'] ?></td>
                            <td><?= h(number_format($era, 2)) ?></td>
                            <td><?= h(number_format($whip, 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

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
