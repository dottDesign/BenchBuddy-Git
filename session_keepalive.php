<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/session_config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

if (session_is_expired()) {
    session_unset();
    session_destroy();

    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

session_touch();

echo json_encode([
    'success' => true,
    'seconds_remaining' => session_seconds_remaining(),
    'warning_seconds' => SESSION_WARNING_SECONDS,
]);
