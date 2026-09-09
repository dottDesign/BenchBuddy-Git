<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
verify_csrf_token();

$teamId = current_team_id();

if ($teamId > 0) {
    dismiss_onboarding($teamId);
}

header('Location: players.php');
exit;
