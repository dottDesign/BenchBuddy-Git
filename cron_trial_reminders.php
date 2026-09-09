<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

$stmt = db()->query("
    SELECT
        t.id,
        t.name,
        t.trial_ends_at,
        t.trial_7_day_email_sent_at,
        t.trial_3_day_email_sent_at,
        t.trial_1_day_email_sent_at,
        u.email,
        u.full_name
    FROM teams t
    INNER JOIN team_memberships tm
        ON tm.team_id = t.id
    INNER JOIN users u
        ON u.id = tm.user_id
    WHERE tm.role = 'head_coach'
      AND t.is_trial = 1
      AND t.subscription_status = 'trialing'
      AND t.trial_ends_at IS NOT NULL
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $daysRemaining = (int)ceil(
        (strtotime((string)$row['trial_ends_at']) - time()) / 86400
    );

    $sentColumn = null;

    if ($daysRemaining === 7 && empty($row['trial_7_day_email_sent_at'])) {
        $sentColumn = 'trial_7_day_email_sent_at';
    } elseif ($daysRemaining === 3 && empty($row['trial_3_day_email_sent_at'])) {
        $sentColumn = 'trial_3_day_email_sent_at';
    } elseif ($daysRemaining === 1 && empty($row['trial_1_day_email_sent_at'])) {
        $sentColumn = 'trial_1_day_email_sent_at';
    }

    if ($sentColumn === null) {
        continue;
    }

    try {
        send_trial_expiring_email(
            (string)$row['email'],
            (string)$row['full_name'],
            (string)$row['name'],
            $daysRemaining,
            date('F j, Y', strtotime((string)$row['trial_ends_at']))
        );

        db()->prepare("
            UPDATE teams
            SET {$sentColumn} = NOW()
            WHERE id = ?
        ")->execute([(int)$row['id']]);

        error_log('Trial reminder sent for team ID ' . (int)$row['id'] . ' to ' . (string)$row['email']);
    } catch (Throwable $e) {
        error_log('Trial reminder failed for team ID ' . (int)$row['id'] . ': ' . $e->getMessage());
    }
}
