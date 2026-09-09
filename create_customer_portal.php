<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/vendor/autoload.php';

require_login();
verify_csrf_token();

if (!billing_enabled()) {
    header('Location: billing.php');
    exit;
}

$teamId = current_team_id();
$team = get_team_by_id($teamId);

if (!$team || empty($team['stripe_customer_id'])) {
    header('Location: billing.php');
    exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$domain = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'benchbuddy.devworks.space');

$portalSession = \Stripe\BillingPortal\Session::create([
    'customer' => (string)$team['stripe_customer_id'],
    'return_url' => $domain . '/billing.php',
]);

header('Location: ' . $portalSession->url, true, 303);
exit;
