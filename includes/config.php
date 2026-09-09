<?php
declare(strict_types=1);

function app_host(): string
{
    return strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
}

function is_staging_environment(): bool
{
    return in_array(app_host(), [
        'benchbuddy-staging.devworks.space',
    ], true);
}

$env = is_staging_environment() ? 'staging' : 'production';

$privateConfigPath = dirname(__DIR__, 2) . '/private/benchbuddy-private-config.php';

if (!is_readable($privateConfigPath)) {
    http_response_code(500);
    exit('Application configuration is unavailable.');
}

$allConfig = require $privateConfigPath;

if (!isset($allConfig[$env]) || !is_array($allConfig[$env])) {
    http_response_code(500);
    exit('Application environment configuration is unavailable.');
}

$appConfig = $allConfig[$env];

define('APP_ENV', $env);

define('APP_NAME', $appConfig['APP_NAME']);
define('APP_URL', $appConfig['APP_URL']);
define('SESSION_NAME', $appConfig['SESSION_NAME']);

define('DB_HOST', $appConfig['DB_HOST']);
define('DB_NAME', $appConfig['DB_NAME']);
define('DB_USER', $appConfig['DB_USER']);
define('DB_PASS', $appConfig['DB_PASS']);
define('DB_CHARSET', $appConfig['DB_CHARSET'] ?? 'utf8mb4');
