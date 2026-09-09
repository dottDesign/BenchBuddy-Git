<?php
declare(strict_types=1);


function log_admin_action(
    int $adminUserId,
    ?int $targetUserId,
    string $action,
    ?string $details = null,
): void {
    if ($adminUserId <= 0) {
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO admin_audit_log (
            admin_user_id,
            target_user_id,
            action,
            details
            )
        VALUES (
            :admin_user_id,
            :target_user_id,
            :action,
            :details
            )
        ");
    $stmt->execute([
        "admin_user_id" => $adminUserId,
        "target_user_id" => $targetUserId,
        "action" => $action,
        "details" => $details,
    ]);
}
function delete_user_account_and_data(int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStmt = $pdo->prepare("
            SELECT email
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $userStmt->execute(['user_id' => $userId]);
        $userEmail = (string)$userStmt->fetchColumn();

        $teamStmt = $pdo->prepare("
            SELECT DISTINCT team_id
            FROM team_memberships
            WHERE user_id = :user_id
        ");
        $teamStmt->execute(['user_id' => $userId]);
        $teamIds = array_map('intval', $teamStmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($teamIds as $teamId) {
            $memberCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM team_memberships
                WHERE team_id = :team_id
                  AND user_id <> :user_id
            ");
            $memberCountStmt->execute([
                'team_id' => $teamId,
                'user_id' => $userId,
            ]);

            $hasOtherMembers = (int)$memberCountStmt->fetchColumn() > 0;

            if (!$hasOtherMembers) {
                delete_team_and_data($teamId);
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM team_memberships
                    WHERE team_id = :team_id
                      AND user_id = :user_id
                ");
                $stmt->execute([
                    'team_id' => $teamId,
                    'user_id' => $userId,
                ]);
            }
        }

        $userQueries = [
            "DELETE FROM admin_audit_log
             WHERE admin_user_id = :user_id
                OR target_user_id = :user_id",

            "DELETE FROM signup_attempts
             WHERE email = :email",

            "DELETE FROM feature_requests WHERE user_id = :user_id",
            "DELETE FROM password_resets WHERE user_id = :user_id",
            "DELETE FROM user_feature_views WHERE user_id = :user_id",
            "DELETE FROM user_sessions WHERE user_id = :user_id",
        ];

        foreach ($userQueries as $sql) {
            $stmt = $pdo->prepare($sql);

            if (str_contains($sql, ':email')) {
                $stmt->execute([
                    'email' => $userEmail,
                ]);
            } else {
                $stmt->execute([
                    'user_id' => $userId,
                ]);
            }
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_team_and_data(int $teamId): void
{
    $pdo = db();

    $queries = [
        "DELETE FROM pitch_log WHERE team_id = :team_id",
        "DELETE FROM bench_entries WHERE team_id = :team_id",
        "DELETE FROM game_roster WHERE team_id = :team_id",
        "DELETE FROM lineup_entries WHERE team_id = :team_id",
        "DELETE FROM lineup_history WHERE team_id = :team_id",
        "DELETE FROM archived_games WHERE team_id = :team_id",

        "DELETE FROM player_positions
         WHERE player_id IN (
             SELECT id FROM players WHERE team_id = :team_id
         )",

        "DELETE FROM players WHERE team_id = :team_id",
        "DELETE FROM lineup_templates WHERE team_id = :team_id",
        "DELETE FROM team_invitations WHERE team_id = :team_id",
        "DELETE FROM team_memberships WHERE team_id = :team_id",
        "DELETE FROM games WHERE team_id = :team_id",
        "DELETE FROM teams WHERE id = :team_id LIMIT 1",
    ];

    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['team_id' => $teamId]);
    }
}
function team_stats_enabled(int $teamId): bool
{
    if ($teamId <= 0) {
        return false;
    }

    $stmt = db()->prepare("
        SELECT COALESCE(stats_enabled, 1)
        FROM teams
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute(['team_id' => $teamId]);

    return (int)$stmt->fetchColumn() === 1;
}

function update_team_stats_enabled(int $teamId, bool $enabled): void
{
    $stmt = db()->prepare("
        UPDATE teams
        SET stats_enabled = :stats_enabled
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'stats_enabled' => $enabled ? 1 : 0,
        'team_id' => $teamId,
    ]);
}
