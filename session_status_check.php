<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/session_config.php';

header('Content-Type: application/json');

$loggedIn = function_exists('is_logged_in')
    ? is_logged_in()
    : !empty($_SESSION['user_id']);

echo json_encode([
    'logged_in' => $loggedIn,
    'seconds_remaining' => $loggedIn ? session_seconds_remaining() : 0,
    'warning_seconds' => SESSION_WARNING_SECONDS,
]);
