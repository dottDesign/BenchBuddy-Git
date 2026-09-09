<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

header('Content-Type: application/json');

try {
    $userId = current_user_id();
    $teamId = current_team_id();
    $draftKey = trim((string)($_GET['draft_key'] ?? ''));

    if ($draftKey === '') {
        throw new RuntimeException('Missing draft key.');
    }

    $stmt = db()->prepare("
        SELECT draft_data, updated_at
        FROM lineup_drafts
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

    $draft = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$draft) {
        echo json_encode([
            'ok' => true,
            'has_draft' => false,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'has_draft' => true,
        'draft_data' => json_decode((string)$draft['draft_data'], true),
        'updated_at' => $draft['updated_at'],
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
