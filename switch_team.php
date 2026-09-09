<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
verify_csrf_token();

$userId = current_user_id();
$redirect = trim((string)($_POST['redirect'] ?? 'index.php'));
$teamId = (int)($_POST['team_id'] ?? 0);

if ($redirect === '') {
    $redirect = 'index.php';
}

$redirect = basename($redirect) === $redirect ? $redirect : 'index.php';

try {
    if ($teamId <= 0) {
        throw new RuntimeException('Invalid team selected.');
    }

    if (!user_belongs_to_team($userId, $teamId)) {
        throw new RuntimeException('You do not have access to that team.');
    }

    set_current_team($teamId);
    $_SESSION['current_team_id'] = $teamId;

    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    header('Location: index.php?msg=' . urlencode($e->getMessage()));
    exit;
}
