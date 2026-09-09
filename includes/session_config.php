<?php
declare(strict_types=1);

date_default_timezone_set('America/Vancouver');
function format_local_datetime(?string $value, string $fallback = 'Never'): string
{
    if (empty($value)) {
        return $fallback;
    }

    $dt = new DateTime((string)$value);
    $dt->setTimezone(new DateTimeZone('America/Vancouver'));

    return $dt->format('M j, Y g:i A');
}

const SESSION_IDLE_LIMIT_SECONDS = 1800; // 30 minutes
const SESSION_WARNING_SECONDS = 60;      // show popup 60 seconds before logout

// FOR TESTING
// const SESSION_IDLE_LIMIT_SECONDS = 90;
// const SESSION_WARNING_SECONDS = 30;

function session_touch(): void
{
    $_SESSION['last_activity'] = time();
}

function session_seconds_remaining(): int
{
    $lastActivity = (int)($_SESSION['last_activity'] ?? time());
    $expiresAt = $lastActivity + SESSION_IDLE_LIMIT_SECONDS;

    return max(0, $expiresAt - time());
}

function session_is_expired(): bool
{
    return session_seconds_remaining() <= 0;
}
