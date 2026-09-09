<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$privateConfigPath = dirname(__DIR__, 2) . '/private/benchbuddy-private-config.php';

if (!is_readable($privateConfigPath)) {
    http_response_code(500);
    exit('Mail configuration is unavailable.');
}

$allConfig = require $privateConfigPath;
$appConfig = $allConfig[APP_ENV] ?? [];

return $appConfig['MAIL'] ?? [
    'host' => '',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'tls',
    'from_email' => 'benchbuddy.devworks@gmail.com',
    'from_name' => APP_ENV === 'staging' ? 'BenchBuddy Staging' : 'BenchBuddy',
];
