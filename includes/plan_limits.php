<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function normalize_plan_key(?string $planKey): string
{
    $planKey = strtolower(trim((string)$planKey));

    return match ($planKey) {
        'coach' => 'coach',

        'coachplus',
        'coach_plus',
        'coach-plus',
        'coachpro',
        'coach_pro',
        'coach-pro' => 'coachplus',
        'unlimited',
        'club',
        'clubpro',
        'club_pro',
        'club-pro' => 'unlimited',

        default => 'free',
    };
}

function plan_feature_matrix(): array
{
    return [
        'free' => [
            'label' => 'Free',
            'features' => [
                'players.manage' => true,
                'players.delete' => true,
                'games.create' => true,
                'lineups.generate' => true,
                'lineups.advanced_settings' => false,
                'lineup_templates.manage' => true,
                'pitch_counts.manage' => false,
                'team_members.manage' => false,
                'teams.multiple' => false,
                'history.full' => false,
                'remove_watermark' => false,
                'print.watermark_free' => false,
                'print.custom_branding' => false,
            ],
            'limits' => [
                'players_per_team' => 14,
                'games_per_team' => 12,
                'team_members_per_team' => 0,
                'teams_per_account' => 1,
                'lineup_templates_per_team' => 1,
                'game_history_items' => 3,
                'manual_lineup_history_items' => 3,
            ],
        ],

        'coach' => [
            'label' => 'Coach',
            'features' => [
                'players.manage' => true,
                'players.delete' => true,
                'games.create' => true,
                'lineups.generate' => true,
                'lineups.advanced_settings' => false,
                'lineup_templates.manage' => true,
                'pitch_counts.manage' => false,
                'team_members.manage' => false,
                'teams.multiple' => false,
                'history.full' => false,
                'remove_watermark' => true,
                'print.watermark_free' => true,
                'print.custom_branding' => true,
            ],
            'limits' => [
                'players_per_team' => 25,
                'games_per_team' => 20,
                'team_members_per_team' => 3,
                'teams_per_account' => 2,
                'lineup_templates_per_team' => 5,
                'game_history_items' => 10,
                'manual_lineup_history_items' => 12,
            ],
        ],

        'coachplus' => [
            'label' => 'Coach Plus',
            'features' => [
                'players.manage' => true,
                'players.delete' => true,
                'games.create' => true,
                'lineups.generate' => true,
                'lineups.advanced_settings' => true,
                'lineup_templates.manage' => true,
                'pitch_counts.manage' => true,
                'team_members.manage' => true,
                'teams.multiple' => true,
                'history.full' => true,
                'remove_watermark' => true,
                'print.watermark_free' => true,
                'print.custom_branding' => true,
            ],
            'limits' => [
                'players_per_team' => null,
                'games_per_team' => null,
                'team_members_per_team' => 3,
                'teams_per_account' => 3,
                'lineup_templates_per_team' => 10,
                'game_history_items' => null,
                'manual_lineup_history_items' => null,
            ],
        ],

        'unlimited' => [
            'label' => 'Unlimited',
            'features' => [
                'players.manage' => true,
                'players.delete' => true,
                'games.create' => true,
                'lineups.generate' => true,
                'lineups.advanced_settings' => true,
                'lineup_templates.manage' => true,
                'pitch_counts.manage' => true,
                'team_members.manage' => true,
                'teams.multiple' => true,
                'history.full' => true,
                'remove_watermark' => true,
                'print.watermark_free' => true,
                'print.custom_branding' => true,
            ],
            'limits' => [
                'players_per_team' => null,
                'games_per_team' => null,
                'team_members_per_team' => null,
                'teams_per_account' => null,
                'lineup_templates_per_team' => null,
                'game_history_items' => null,
                'manual_lineup_history_items' => null,
                ],
        ],
    ];
}
function billing_team_id(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT parent_team_id
        FROM teams
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $parentId = $stmt->fetchColumn();

    if ($parentId !== false && (int)$parentId > 0) {
        return (int)$parentId;
    }

    return $teamId;
}

function get_team_subscription_context(int $teamId): array
{
    $teamId = billing_team_id($teamId);
    if ($teamId <= 0) {
        return [
            'plan_key' => 'free',
            'subscription_status' => 'inactive',
            'billing_override' => 0,
            'is_paid' => false,
        ];
    }

    $stmt = db()->prepare("
        SELECT plan_key, subscription_status, billing_override
        FROM teams
        WHERE id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        return [
            'plan_key' => 'free',
            'subscription_status' => 'inactive',
            'billing_override' => 0,
            'is_paid' => false,
        ];
    }

    $planKey = normalize_plan_key((string)($team['plan_key'] ?? 'free'));
    $status = strtolower(trim((string)($team['subscription_status'] ?? 'inactive')));
    $billingOverride = (int)($team['billing_override'] ?? 0);

    $isPaid = $billingOverride === 1
        || in_array($status, ['active', 'trialing'], true);

    if (!$isPaid) {
        $planKey = 'free';
    }

    return [
        'plan_key' => $planKey,
        'subscription_status' => $status,
        'billing_override' => $billingOverride,
        'is_paid' => $isPaid,
    ];
}

function current_team_plan_key(int $teamId): string
{
    $context = get_team_subscription_context($teamId);
    return normalize_plan_key((string)$context['plan_key']);
}

function team_can_use_feature(int $teamId, string $featureKey): bool
{
    if (function_exists('billing_enforcement_enabled') && !billing_enforcement_enabled()) {
        return true;
    }

    $planKey = current_team_plan_key($teamId);
    $matrix = plan_feature_matrix();

    if (!isset($matrix[$planKey])) {
        return true;
    }

    if (!array_key_exists($featureKey, $matrix[$planKey]['features'] ?? [])) {
        return true;
    }

    return (bool)$matrix[$planKey]['features'][$featureKey];
}

function team_feature_limit(int $teamId, string $limitKey): ?int
{
    $planKey = current_team_plan_key($teamId);
    $matrix = plan_feature_matrix();

    if (!isset($matrix[$planKey])) {
        return null;
    }

    $limit = $matrix[$planKey]['limits'][$limitKey] ?? null;

    return $limit === null ? null : (int)$limit;
}

function require_team_feature(
    int $teamId,
    string $featureKey,
    string $message = 'This feature requires an upgraded plan.'
): void {
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

    $allowedPages = [
        'billing.php',
        'billing_start.php',
        'billing_success.php',
        'create_customer_portal.php',
        'stripe_webhook.php',
        'login.php',
        'logout.php',
        'signup.php',
        'forgot_password.php',
        'reset_password.php',
    ];

    if (in_array($currentScript, $allowedPages, true)) {
        return;
    }

    if (team_can_use_feature($teamId, $featureKey)) {
        return;
    }

    redirect_to_billing_upgrade($message, $featureKey);
}

function count_team_players_for_limit(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM players
        WHERE team_id = :team_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}

function count_team_members_for_limit(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM team_memberships
        WHERE team_id = :team_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}

function count_team_games_for_limit(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
          AND deleted_at IS NULL
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}

function assert_team_limit_available(
    int $teamId,
    string $limitKey,
    int $currentCount,
    string $message
): void {
    if (function_exists('billing_enforcement_enabled') && !billing_enforcement_enabled()) {
        return;
    }

    $limit = team_feature_limit($teamId, $limitKey);

    if ($limit === null) {
        return;
    }

    if ($currentCount >= $limit) {
        redirect_to_billing_upgrade($message, $limitKey);
    }
}
function billing_upgrade_url(string $reasonKey = ''): string
{
    $url = 'billing.php';

    if ($reasonKey !== '') {
        $url .= '?' . http_build_query([
            'upgrade_reason' => $reasonKey,
        ]);
    }

    return $url;
}

function redirect_to_billing_upgrade(string $message, string $reasonKey = ''): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['billing_required_message'] = $message;

    header('Location: ' . billing_upgrade_url($reasonKey));
    exit;
}

function count_owned_teams(int $teamId): int
{
    $billingTeamId = billing_team_id($teamId);

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM teams
        WHERE id = :team_id
           OR parent_team_id = :team_id
    ");

    $stmt->execute([
        'team_id' => $billingTeamId,
    ]);

    return (int)$stmt->fetchColumn();
}
function count_user_active_teams(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM team_memberships tm
        INNER JOIN teams t ON t.id = tm.team_id
        WHERE tm.user_id = :user_id
          AND COALESCE(t.is_archived, 0) = 0
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return (int)$stmt->fetchColumn();
}
function count_team_lineup_templates_for_limit(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM lineup_templates
        WHERE team_id = :team_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}
function team_game_history_limit(int $teamId): ?int
{
    return team_feature_limit($teamId, 'game_history_items');
}

function team_manual_lineup_history_limit(int $teamId): ?int
{
    return team_feature_limit($teamId, 'manual_lineup_history_items');
}
