<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/billing_config.php';
require_once __DIR__ . '/db.php';

function team_has_active_subscription(int $teamId): bool
{
    if ($teamId <= 0) {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT billing_override, subscription_status
        FROM teams
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        return false;
    }

    if ((int)($team['billing_override'] ?? 0) === 1) {
        return true;
    }

    return in_array((string)($team['subscription_status'] ?? ''), ['active', 'trialing'], true);
}

function require_paid_team(int $teamId): void
{
    if (!billing_enforcement_enabled()) {
        return;
    }

    if (!team_has_active_subscription($teamId)) {
        header('Location: billing.php');
        exit;
    }
}


function update_team_subscription_from_stripe(
    int $teamId,
    string $planKey,
    string $billingInterval,
    string $stripeCustomerId,
    string $stripeSubscriptionId,
    string $subscriptionStatus,
    ?int $currentPeriodEnd
): void {
    $periodEnd = $currentPeriodEnd !== null
        ? date('Y-m-d H:i:s', $currentPeriodEnd)
        : null;

    $stmt = db()->prepare("
        UPDATE teams
        SET
            plan_key = :p_plan_key,
            billing_interval = :p_billing_interval,
            stripe_customer_id = :p_stripe_customer_id,
            stripe_subscription_id = :p_stripe_subscription_id,
            subscription_status = :p_subscription_status,
            subscription_current_period_end = :p_subscription_current_period_end,
            billing_override = 0,
            is_trial = 0,
            trial_ends_at = NULL
        WHERE id = :p_team_id
        LIMIT 1
    ");

    $stmt->bindValue(':p_plan_key', $planKey, PDO::PARAM_STR);
    $stmt->bindValue(':p_billing_interval', $billingInterval, PDO::PARAM_STR);
    $stmt->bindValue(':p_stripe_customer_id', $stripeCustomerId, PDO::PARAM_STR);
    $stmt->bindValue(':p_stripe_subscription_id', $stripeSubscriptionId, PDO::PARAM_STR);
    $stmt->bindValue(':p_subscription_status', $subscriptionStatus, PDO::PARAM_STR);

    if ($periodEnd === null) {
        $stmt->bindValue(':p_subscription_current_period_end', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':p_subscription_current_period_end', $periodEnd, PDO::PARAM_STR);
    }

    $stmt->bindValue(':p_team_id', $teamId, PDO::PARAM_INT);
    $stmt->execute();
}


function set_team_billing_override(int $teamId, bool $enabled): void
{
    if ($teamId <= 0) {
        throw new RuntimeException('Invalid team.');
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE teams
        SET billing_override = :billing_override
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'billing_override' => $enabled ? 1 : 0,
        'team_id' => $teamId,
    ]);
}
function app_setting_bool(string $key, bool $default = false): bool
{
    try {
        $stmt = db()->prepare("
            SELECT setting_value
            FROM app_settings
            WHERE setting_key = :setting_key
            LIMIT 1
        ");
        $stmt->execute(['setting_key' => $key]);

        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    } catch (Throwable $e) {
        return $default;
    }
}

function update_app_setting(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $stmt->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function billing_enabled(): bool
{
    return app_setting_bool('billing_enabled', false);
}

function billing_enforcement_enabled(): bool
{
    return app_setting_bool('billing_enforcement_enabled', false);
}



function get_head_coach_for_team(int $teamId): ?array
{
    $stmt = db()->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            t.name AS team_name
        FROM team_memberships tm
        INNER JOIN users u
            ON u.id = tm.user_id
        INNER JOIN teams t
            ON t.id = tm.team_id
        WHERE tm.team_id = :team_id
          AND tm.role = 'head_coach'
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $coach = $stmt->fetch(PDO::FETCH_ASSOC);

    return $coach ?: null;
}
