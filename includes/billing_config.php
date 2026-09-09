<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$privateConfigPath = dirname(__DIR__, 2) . '/private/benchbuddy-private-config.php';

if (!is_readable($privateConfigPath)) {
    http_response_code(500);
    exit('Billing configuration is unavailable.');
}

$allConfig = require $privateConfigPath;
$billingConfig = $allConfig[APP_ENV] ?? [];

define('BILLING_ENABLED', (bool)($billingConfig['BILLING_ENABLED'] ?? false));
define('BILLING_ENFORCEMENT_ENABLED', (bool)($billingConfig['BILLING_ENFORCEMENT_ENABLED'] ?? false));

define('STRIPE_SECRET_KEY', $billingConfig['STRIPE_SECRET_KEY'] ?? '');
define('STRIPE_WEBHOOK_SECRET', $billingConfig['STRIPE_WEBHOOK_SECRET'] ?? '');

define('STRIPE_PRICE_COACH_MONTHLY', $billingConfig['STRIPE_PRICE_COACH_MONTHLY'] ?? '');
define('STRIPE_PRICE_COACHPLUS_MONTHLY', $billingConfig['STRIPE_PRICE_COACHPLUS_MONTHLY'] ?? '');
define('STRIPE_PRICE_UNLIMITED_MONTHLY', $billingConfig['STRIPE_PRICE_UNLIMITED_MONTHLY'] ?? '');

define('STRIPE_PRICE_COACH_YEARLY', $billingConfig['STRIPE_PRICE_COACH_YEARLY'] ?? '');
define('STRIPE_PRICE_COACHPLUS_YEARLY', $billingConfig['STRIPE_PRICE_COACHPLUS_YEARLY'] ?? '');
define('STRIPE_PRICE_UNLIMITED_YEARLY', $billingConfig['STRIPE_PRICE_UNLIMITED_YEARLY'] ?? '');

if (!function_exists('billing_enabled')) {
    function billing_enabled(): bool
    {
        return defined('BILLING_ENABLED') && BILLING_ENABLED === true;
    }
}

if (!function_exists('billing_enforcement_enabled')) {
    function billing_enforcement_enabled(): bool
    {
        return defined('BILLING_ENFORCEMENT_ENABLED') && BILLING_ENFORCEMENT_ENABLED === true;
    }
}
