<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

header('Content-Type: application/json');

try {
    $userId = current_user_id();
    $teamId = current_team_id();
    $draftKey = trim((string)($_POST['draft_key'] ?? ''));

    if ($draftKey === '') {
        throw new RuntimeException('Missing draft key.');
    }

    $stmt = db()->prepare("
        DELETE FROM lineup_drafts
        WHERE user_id = :user_id
          AND team_id = :team_id
          AND draft_key = :draft_key
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $userId,
        'team_id' => $teamId,
        'draft_key' => $draftKey,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
