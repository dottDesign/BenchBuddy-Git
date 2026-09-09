<?php
declare(strict_types=1);



function get_dashboard_stats(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM players
        WHERE team_id = :team_id
        ");
    $stmt->execute(["team_id" => $teamId]);
    $playersTotal = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM players
        WHERE team_id = :team_id
        AND active = 1
        ");
    $stmt->execute(["team_id" => $teamId]);
    $playersActive = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
        AND deleted_at IS NULL
        ");
    $stmt->execute(["team_id" => $teamId]);
    $gamesTotal = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
        AND status = 'draft'
        AND deleted_at IS NULL
        ");
    $stmt->execute(["team_id" => $teamId]);
    $gamesDraft = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
        AND status = 'generated'
        AND deleted_at IS NULL
        ");
    $stmt->execute(["team_id" => $teamId]);
    $gamesGenerated = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
        AND status = 'locked'
        AND deleted_at IS NULL
        ");
    $stmt->execute(["team_id" => $teamId]);
    $gamesLocked = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
        AND deleted_at IS NOT NULL
        ");
    $stmt->execute(["team_id" => $teamId]);
    $gamesCancelled = (int) $stmt->fetchColumn();

    return [
        "players_total" => $playersTotal,
        "players_active" => $playersActive,
        "games_total" => $gamesTotal,
        "games_draft" => $gamesDraft,
        "games_generated" => $gamesGenerated,
        "games_locked" => $gamesLocked,
        "games_cancelled" => $gamesCancelled,
    ];
}
function get_recent_games_dashboard(int $teamId, int $limit = 10): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND deleted_at IS NULL
        ORDER BY
        CASE WHEN locked_at IS NULL THEN 1 ELSE 0 END,
        locked_at DESC,
        created_at DESC,
        id DESC
        LIMIT :limit_num
        ");
    $stmt->bindValue(":team_id", $teamId, PDO::PARAM_INT);
    $stmt->bindValue(":limit_num", $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
function get_next_game_by_status(int $teamId, string $status): ?array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM games
        WHERE team_id = :team_id
        AND status = :status
        AND deleted_at IS NULL
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "status" => $status,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}
function get_season_pitch_totals_dashboard(int $teamId, int $limit = 10): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,

            COALESCE(SUM(pps.innings_pitched), 0) AS total_innings,
            COALESCE(SUM(pps.pitches_thrown), 0) AS total_pitches,
            COUNT(DISTINCT pps.game_id) AS games_pitched

        FROM players p
        INNER JOIN player_pitching_stats pps
            ON pps.player_id = p.id
           AND pps.team_id = p.team_id
        INNER JOIN games g
            ON g.id = pps.game_id
           AND g.team_id = pps.team_id

        WHERE p.team_id = :team_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1

        GROUP BY
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number

        HAVING total_innings > 0 OR total_pitches > 0

        ORDER BY
            total_innings DESC,
            total_pitches DESC,
            p.first_name ASC,
            p.last_name ASC

        LIMIT :limit_num
    ");

    $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
    $stmt->bindValue(':limit_num', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_first_game_needing_pitch_log(int $teamId): ?int
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT g.id
        FROM games g
        WHERE g.team_id = :team_id
        AND g.deleted_at IS NULL
        AND g.status IN ('generated', 'locked')
        AND EXISTS (
          SELECT 1
          FROM pitch_log pl
          WHERE pl.team_id = g.team_id
          AND pl.game_db_id = g.id
          GROUP BY pl.game_db_id
          HAVING COALESCE(SUM(COALESCE(pl.pitches_thrown, 0)), 0) = 0
          )
        ORDER BY
        CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
        g.game_date ASC,
        g.created_at ASC,
        g.id ASC
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
    ]);

    $row = $stmt->fetch();
    return $row ? (int) $row["id"] : null;
}

function get_first_generated_game_needing_pitch_log(int $teamId): ?int
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT g.id
        FROM games g
        WHERE g.team_id = :team_id
        AND g.deleted_at IS NULL
        AND g.status = 'generated'
        AND EXISTS (
          SELECT 1
          FROM pitch_log pl
          WHERE pl.team_id = g.team_id
          AND pl.game_db_id = g.id
          GROUP BY pl.game_db_id
          HAVING COALESCE(SUM(COALESCE(pl.pitches_thrown, 0)), 0) = 0
          )
        ORDER BY
        CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
        g.game_date ASC,
        g.created_at ASC,
        g.id ASC
        LIMIT 1
        ");

    $stmt->execute([
        "team_id" => $teamId,
    ]);

    $row = $stmt->fetch();
    return $row ? (int) $row["id"] : null;
}

function get_first_locked_game_needing_pitch_log(int $teamId): ?int
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT g.id
        FROM games g
        WHERE g.team_id = :team_id
        AND g.deleted_at IS NULL
        AND g.status = 'locked'
        AND EXISTS (
          SELECT 1
          FROM pitch_log pl
          WHERE pl.team_id = g.team_id
          AND pl.game_db_id = g.id
          GROUP BY pl.game_db_id
          HAVING COALESCE(SUM(COALESCE(pl.pitches_thrown, 0)), 0) = 0
          )
        ORDER BY
        CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
        g.game_date ASC,
        g.created_at ASC,
        g.id ASC
        LIMIT 1
        ");

    $stmt->execute([
        "team_id" => $teamId,
    ]);

    $row = $stmt->fetch();
    return $row ? (int) $row["id"] : null;
}

function get_season_alerts_dashboard(int $teamId): array
{
    validate_team_id($teamId);

    $alerts = [];

    $firstGameNeedingPitchLogId = get_first_game_needing_pitch_log($teamId);
    if ($firstGameNeedingPitchLogId) {
        $alerts[] = [
            "level" => "warn",
            "title" => "Missing pitch counts",
            "text" => "Pitch counts still need to be recorded.",
            "action_url" => "lock.php?game_id=" . $firstGameNeedingPitchLogId,
            "action_label" => "Add Pitch Log",
        ];
    }

    $benchFairness = get_bench_fairness_summary_dashboard($teamId);

    $lowestBenchRate =
        $benchFairness['lowest_bench_percentage'] ?? null;

    $highestBenchRate =
        $benchFairness['highest_bench_percentage'] ?? null;

    if (
        is_array($lowestBenchRate) &&
        is_array($highestBenchRate)
    ) {
        $lowestPercentage =
            (float)($lowestBenchRate['bench_percentage'] ?? 0);

        $highestPercentage =
            (float)($highestBenchRate['bench_percentage'] ?? 0);

        $percentageGap = round(
            $highestPercentage - $lowestPercentage,
            1
        );

        if ($percentageGap >= 10.0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Bench usage is getting uneven',
                'text' =>
                    'There is currently a ' .
                    number_format($percentageGap, 1) .
                    '-percentage-point gap between the lowest and highest bench rates.',
                'action_url' => 'generate.php',
                'action_label' => 'Review Lineups',
            ];
        }
    }

    $nextDraftGame = get_next_game_by_status($teamId, "draft");
    if ($nextDraftGame) {
        $alerts[] = [
            "level" => "info",
            "title" => "Draft game needs a lineup",
            "text" =>
                "Game " .
                (string) $nextDraftGame["game_id"] .
                " is still in draft status.",
            "action_url" => "games.php?edit=" . (int) $nextDraftGame["id"],
            "action_label" => "Open Game",
        ];
    }

    return $alerts;
}


function get_season_bench_totals_dashboard(int $teamId, int $limit = 10): array
{
    validate_team_id($teamId);

    $pdo = db();

    $sql = "
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,
            COUNT(be.id) AS total_bench_times,
            COUNT(DISTINCT be.game_db_id) AS games_benched,
            COUNT(DISTINCT gr.game_db_id) AS games_rostered
        FROM players p
        INNER JOIN game_roster gr
            ON gr.player_id = p.id
            AND gr.team_id = p.team_id
        INNER JOIN games g
            ON g.id = gr.game_db_id
            AND g.team_id = gr.team_id
        LEFT JOIN bench_entries be
            ON be.player_id = p.id
            AND be.team_id = p.team_id
            AND be.game_db_id = gr.game_db_id
        WHERE p.team_id = :team_id
          AND p.active = 1
          AND g.deleted_at IS NULL
          AND g.status = 'locked'
        GROUP BY p.id, p.first_name, p.last_name, p.jersey_number
        ORDER BY total_bench_times DESC, games_benched DESC, p.first_name ASC, p.last_name ASC
        LIMIT :limit_num
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(":team_id", $teamId, PDO::PARAM_INT);
    $stmt->bindValue(":limit_num", $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
function get_bench_fairness_summary_dashboard(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number,

            COALESCE(
                SUM(COALESCE(bench_totals.bench_innings, 0)),
                0
            ) AS total_bench_innings,

            COALESCE(
                SUM(COALESCE(g.innings, 0)),
                0
            ) AS total_eligible_innings,

            ROUND(
                (
                    COALESCE(
                        SUM(COALESCE(bench_totals.bench_innings, 0)),
                        0
                    )
                    /
                    NULLIF(
                        COALESCE(SUM(COALESCE(g.innings, 0)), 0),
                        0
                    )
                ) * 100,
                1
            ) AS bench_percentage

        FROM players p

        INNER JOIN game_roster gr
            ON gr.player_id = p.id
           AND gr.team_id = p.team_id

        INNER JOIN games g
            ON g.id = gr.game_db_id
           AND g.team_id = gr.team_id

        LEFT JOIN (
            SELECT
                be.team_id,
                be.player_id,
                be.game_db_id,
                COUNT(be.id) AS bench_innings
            FROM bench_entries be
            GROUP BY
                be.team_id,
                be.player_id,
                be.game_db_id
        ) bench_totals
            ON bench_totals.team_id = p.team_id
           AND bench_totals.player_id = p.id
           AND bench_totals.game_db_id = gr.game_db_id

        WHERE p.team_id = :team_id
          AND g.status = 'locked'
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1

        GROUP BY
            p.id,
            p.first_name,
            p.last_name,
            p.jersey_number

        HAVING total_eligible_innings > 0

        ORDER BY
            bench_percentage ASC,
            total_bench_innings ASC,
            p.first_name ASC,
            p.last_name ASC
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($players)) {
        return [
            'lowest_bench_percentage' => null,
            'highest_bench_percentage' => null,
            'average_bench_percentage' => 0.0,
        ];
    }

    $lowest = $players[0];
    $highest = $players[count($players) - 1];

    /*
     * Calculate the team's overall bench percentage.
     *
     * This uses total bench innings divided by total eligible innings,
     * rather than averaging individual percentages. That prevents a
     * player with only one rostered game from having disproportionate
     * influence on the team average.
     */
    $teamBenchInnings = 0;
    $teamEligibleInnings = 0;

    foreach ($players as $player) {
        $teamBenchInnings +=
            (int)($player['total_bench_innings'] ?? 0);

        $teamEligibleInnings +=
            (int)($player['total_eligible_innings'] ?? 0);
    }

    $averageBenchPercentage = $teamEligibleInnings > 0
        ? round(($teamBenchInnings / $teamEligibleInnings) * 100, 1)
        : 0.0;

    return [
        'lowest_bench_percentage' => $lowest,
        'highest_bench_percentage' => $highest,
        'average_bench_percentage' => $averageBenchPercentage,
    ];
}

function get_player_season_bench_totals(int $teamId, array $playerIds): array
{
    validate_team_id($teamId);

    $playerIds = array_values(array_filter(array_map("intval", $playerIds)));
    if (empty($playerIds)) {
        return [];
    }

    $pdo = db();

    $placeholders = implode(",", array_fill(0, count($playerIds), "?"));

    $sql = "
    SELECT
    be.player_id,
    COUNT(be.id) AS total_bench_times
    FROM bench_entries be
    INNER JOIN games g
    ON g.id = be.game_db_id
    AND g.team_id = be.team_id
    WHERE be.team_id = ?
    AND g.status = 'locked'
    AND g.deleted_at IS NULL
    AND be.player_id IN ($placeholders)
    GROUP BY be.player_id
    ";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$teamId], $playerIds);
    $stmt->execute($params);

    $totals = [];
    foreach ($playerIds as $playerId) {
        $totals[(int) $playerId] = 0;
    }

    foreach ($stmt->fetchAll() as $row) {
        $totals[(int) $row["player_id"]] = (int) $row["total_bench_times"];
    }

    return $totals;
}

function get_player_season_summary(int $teamId, int $playerId): array
{
    validate_team_id($teamId);

    if ($playerId <= 0) {
        throw new InvalidArgumentException("A valid player ID is required.");
    }

    $player = get_player_by_id($teamId, $playerId);
    if (!$player) {
        throw new RuntimeException("Player not found for this team.");
    }

    $pdo = db();

    $gamesRosteredStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT gr.game_db_id)
        FROM game_roster gr
        INNER JOIN games g
        ON g.id = gr.game_db_id
        AND g.team_id = gr.team_id
        WHERE gr.team_id = :team_id
        AND gr.player_id = :player_id
        AND g.deleted_at IS NULL
        ");
    $gamesRosteredStmt->execute([
        "team_id" => $teamId,
        "player_id" => $playerId,
    ]);
    $gamesRostered = (int) $gamesRosteredStmt->fetchColumn();

    $benchStmt = $pdo->prepare("
        SELECT
        COUNT(be.id) AS total_bench_times,
        COUNT(DISTINCT be.game_db_id) AS games_benched
        FROM bench_entries be
        INNER JOIN games g
        ON g.id = be.game_db_id
        AND g.team_id = be.team_id
        WHERE be.team_id = :team_id
        AND be.player_id = :player_id
        AND g.deleted_at IS NULL
        AND g.status = 'locked'
        ");
    $benchStmt->execute([
        "team_id" => $teamId,
        "player_id" => $playerId,
    ]);
    $benchRow = $benchStmt->fetch() ?: [];

    $pitchStmt = $pdo->prepare("
        SELECT
        COALESCE(SUM(pl.innings_pitched), 0) AS total_innings_pitched,
        COALESCE(SUM(pl.pitches_thrown), 0) AS total_pitches_thrown,
        COUNT(DISTINCT pl.game_db_id) AS games_pitched
        FROM pitch_log pl
        INNER JOIN games g
        ON g.id = pl.game_db_id
        AND g.team_id = pl.team_id
        WHERE pl.team_id = :team_id
        AND pl.player_id = :player_id
        AND g.deleted_at IS NULL
        AND g.status = 'locked'
        ");
    $pitchStmt->execute([
        "team_id" => $teamId,
        "player_id" => $playerId,
    ]);
    $pitchRow = $pitchStmt->fetch() ?: [];

    return [
        "games_rostered" => $gamesRostered,
        "total_bench_times" => (int) ($benchRow["total_bench_times"] ?? 0),
        "games_benched" => (int) ($benchRow["games_benched"] ?? 0),
        "total_innings_pitched" =>
            (int) ($pitchRow["total_innings_pitched"] ?? 0),
        "total_pitches_thrown" =>
            (int) ($pitchRow["total_pitches_thrown"] ?? 0),
        "games_pitched" => (int) ($pitchRow["games_pitched"] ?? 0),
    ];
}
