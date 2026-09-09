<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/vendor/autoload.php';
require_once __DIR__ . '/includes/billing_config.php';

require_login();

if (!billing_enabled()) {
    header('Location: billing.php');
    exit;
}

$teamId = current_team_id();
$user = get_current_user_record();

if ($teamId <= 0 || !$user) {
    throw new RuntimeException('Missing team or user.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: billing.php');
    exit;
}
verify_csrf_token();

$plan = (string)($_POST['billing_plan'] ?? '');
$priceMap = [
    'coach_monthly' => [
        'price_id' => STRIPE_PRICE_COACH_MONTHLY,
        'plan_key' => 'coach',
        'billing_interval' => 'monthly',
    ],
    'coach_yearly' => [
        'price_id' => STRIPE_PRICE_COACH_YEARLY,
        'plan_key' => 'coach',
        'billing_interval' => 'yearly',
    ],
    'coachplus_monthly' => [
        'price_id' => STRIPE_PRICE_COACHPLUS_MONTHLY,
        'plan_key' => 'coachplus',
        'billing_interval' => 'monthly',
    ],
    'coachplus_yearly' => [
        'price_id' => STRIPE_PRICE_COACHPLUS_YEARLY,
        'plan_key' => 'coachplus',
        'billing_interval' => 'yearly',
    ],
    'unlimited_monthly' => [
        'price_id' => STRIPE_PRICE_UNLIMITED_MONTHLY,
        'plan_key' => 'unlimited',
        'billing_interval' => 'monthly',
    ],
    'unlimited_yearly' => [
        'price_id' => STRIPE_PRICE_UNLIMITED_YEARLY,
        'plan_key' => 'unlimited',
        'billing_interval' => 'yearly',
    ],
];

if (!isset($priceMap[$plan])) {
    throw new RuntimeException('Invalid plan selected.');
}

$selectedPlan = $priceMap[$plan];

$trialDays = 30;
$promoCode = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
$promo = null;

if ($promoCode !== '' && function_exists('get_valid_promo_code')) {
    $promo = get_valid_promo_code($promoCode);

    if ($promo) {
        $trialDays = max(1, (int)$promo['trial_days']);
    }
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$domain = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'benchbuddy.devworks.space');

$checkoutSession = \Stripe\Checkout\Session::create([
    'mode' => 'subscription',
    'customer_email' => (string)$user['email'],
    'line_items' => [[
    'price' => $selectedPlan['price_id'],
    'quantity' => 1,
    ]],
    'success_url' => $domain . '/billing_success.php',
    'cancel_url' => $domain . '/billing.php',
    'metadata' => [
        'team_id' => (string)$teamId,
        'plan_key' => $selectedPlan['plan_key'],
        'billing_interval' => $selectedPlan['billing_interval'],
    ],
    'subscription_data' => [
        'trial_period_days' => $trialDays,
        'metadata' => [
            'team_id' => (string)$teamId,
            'plan_key' => $selectedPlan['plan_key'],
            'billing_interval' => $selectedPlan['billing_interval'],
            'promo_code' => $promo ? $promoCode : '',
            'trial_days' => (string)$trialDays,
        ],
    ],
    ]);

    if ($promo) {
        db()->prepare("
            UPDATE promo_codes
            SET
                redemption_count = redemption_count + 1,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ")->execute([
            'id' => (int)$promo['id'],
        ]);
    }

    header('Location: ' . $checkoutSession->url, true, 303);
    exit;

header('Location: ' . $checkoutSession->url, true, 303);
exit;
