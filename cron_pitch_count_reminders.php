<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

$stmt = db()->query("
    SELECT
        g.id,
        g.team_id,
        g.game_id,
        g.locked_at,
        t.name AS team_name,
        u.email,
        u.full_name
    FROM games g
    INNER JOIN teams t
        ON t.id = g.team_id
    INNER JOIN team_memberships tm
        ON tm.team_id = t.id
    INNER JOIN users u
        ON u.id = tm.user_id
    LEFT JOIN pitch_log pl
        ON pl.team_id = g.team_id
       AND pl.game_db_id = g.id
       AND (
            COALESCE(pl.pitches_thrown, 0) > 0
            OR COALESCE(pl.innings_pitched, 0) > 0
       )
    WHERE tm.role = 'head_coach'
      AND g.status = 'locked'
      AND g.locked_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND g.pitch_count_reminder_sent_at IS NULL
      AND COALESCE(g.counts_towards_pitching, 1) = 1
      AND pl.player_id IS NULL
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    try {
        send_pitch_count_reminder_email(
            (string)$row['email'],
            (string)$row['full_name'],
            (string)$row['game_id']
        );

        db()->prepare("
            UPDATE games
            SET pitch_count_reminder_sent_at = NOW()
            WHERE id = ?
              AND team_id = ?
        ")->execute([
            (int)$row['id'],
            (int)$row['team_id'],
        ]);

        error_log(
            'Pitch count reminder sent for game ID ' .
            (int)$row['id'] .
            ' to ' .
            (string)$row['email']
        );
    } catch (Throwable $e) {
        error_log(
            'Pitch count reminder failed for game ID ' .
            (int)$row['id'] .
            ': ' .
            $e->getMessage()
        );
    }
}
