<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
verify_csrf_token();

header('Content-Type: application/json');

try {
    verify_csrf_token();

    $userId = current_user_id();
    $teamId = current_team_id();

    if ($teamId <= 0) {
        throw new RuntimeException('No active team.');
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid draft payload.');
    }

    $draftKey = trim((string)($payload['draft_key'] ?? ''));
    $gameId = (int)($payload['game_id'] ?? 0);
    $draftData = $payload['draft_data'] ?? null;

    if ($draftKey === '') {
        throw new RuntimeException('Missing draft key.');
    }

    $json = json_encode($draftData, JSON_THROW_ON_ERROR);

    $stmt = db()->prepare("
        INSERT INTO lineup_drafts (
            user_id,
            team_id,
            game_id,
            draft_key,
            draft_data
        )
        VALUES (
            :user_id,
            :team_id,
            :game_id,
            :draft_key,
            :draft_data
        )
        ON DUPLICATE KEY UPDATE
            game_id = VALUES(game_id),
            draft_data = VALUES(draft_data),
            updated_at = NOW()
    ");

    $stmt->execute([
        'user_id' => $userId,
        'team_id' => $teamId,
        'game_id' => $gameId > 0 ? $gameId : null,
        'draft_key' => $draftKey,
        'draft_data' => $json,
    ]);

    echo json_encode([
        'ok' => true,
        'saved_at' => date('M j, Y g:i A'),
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
