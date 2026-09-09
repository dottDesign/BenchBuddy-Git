<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_admin_user();

$pageTitle = 'Admin Users';
$currentPage = 'admin_users';

$message = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$error = '';
$users = [];

function admin_best_plan_key(?string $planKeys): string
{
    $keys = array_filter(array_map('trim', explode(',', (string)$planKeys)));

    $priority = [
        'unlimited' => 4,
        'coachplus' => 3,
        'coach' => 2,
        'free' => 1,
    ];

    $bestPlan = 'free';
    $bestScore = 0;

    foreach ($keys as $key) {
        $normalized = function_exists('normalize_plan_key')
            ? normalize_plan_key($key)
            : strtolower($key);

        $score = $priority[$normalized] ?? 1;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestPlan = $normalized;
        }
    }

    return $bestPlan;
}

function admin_display_plan_label(?string $planKeys): string
{
    return match (admin_best_plan_key($planKeys)) {
        'coach' => 'Coach',
        'coachplus' => 'Coach Plus',
        'unlimited' => 'Unlimited',
        default => 'Free',
    };
}

function admin_first_value(?string $csv, string $default = ''): string
{
    $values = array_filter(array_map('trim', explode(',', (string)$csv)));

    if (empty($values)) {
        return $default;
    }

    return strtolower((string)reset($values));
}

function admin_display_subscription_status(?string $statuses, int $hasBillingOverride): string
{
    if ($hasBillingOverride === 1 && str_contains(strtolower((string)$statuses), 'trialing')) {
        return 'Admin Trial';
    }

    if ($hasBillingOverride === 1) {
        return 'Admin Override';
    }

    $statuses = strtolower((string)$statuses);

    if (str_contains($statuses, 'active')) {
        return 'Active';
    }

    if (str_contains($statuses, 'trialing')) {
        return 'Trialing';
    }

    if (str_contains($statuses, 'past_due')) {
        return 'Past Due';
    }

    if (str_contains($statuses, 'canceled')) {
        return 'Canceled';
    }

    return 'Free';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_billing_settings') {
            update_app_setting('billing_enabled', !empty($_POST['billing_enabled']) ? '1' : '0');
            update_app_setting('billing_enforcement_enabled', !empty($_POST['billing_enforcement_enabled']) ? '1' : '0');

            header('Location: admin_users.php?msg=' . urlencode('Billing settings updated.'));
            exit;
        }

        if ($action === 'admin_update_coach_plan') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $planKey = normalize_plan_key((string)($_POST['plan_key'] ?? 'free'));
            $billingInterval = (string)($_POST['billing_interval'] ?? 'manual');
            $subscriptionStatus = (string)($_POST['subscription_status'] ?? 'active');

            if ($targetUserId <= 0) {
                throw new RuntimeException('Invalid coach selected.');
            }

            if (!in_array($planKey, ['free', 'coach', 'coachplus', 'unlimited'], true)) {
                throw new RuntimeException('Invalid plan selected.');
            }

            if (!in_array($billingInterval, ['free', 'monthly', 'yearly', 'manual'], true)) {
                throw new RuntimeException('Invalid billing interval.');
            }

            if (!in_array($subscriptionStatus, ['free', 'active', 'trialing', 'inactive', 'canceled'], true)) {
                throw new RuntimeException('Invalid subscription status.');
            }

            $teamStmt = db()->prepare("
                SELECT DISTINCT t.id
                FROM teams t
                INNER JOIN team_memberships tm ON tm.team_id = t.id
                WHERE tm.user_id = :user_id
                  AND COALESCE(t.is_archived, 0) = 0
            ");

            $teamStmt->execute([
                'user_id' => $targetUserId,
            ]);

            $teamIds = array_map('intval', $teamStmt->fetchAll(PDO::FETCH_COLUMN));

            if (empty($teamIds)) {
                throw new RuntimeException('No active team found for this user.');
            }

            $isFree = $planKey === 'free';
            $isTrial = $subscriptionStatus === 'trialing';

            $billingOverride = 1;
            $trialEndsAt = $isTrial ? date('Y-m-d H:i:s', strtotime('+30 days')) : null;
            $subscriptionCurrentPeriodEnd = null;

            if ($isFree && !$isTrial) {
                $billingInterval = 'free';
                $subscriptionStatus = 'free';
            }

            if ($isTrial && $billingInterval === 'free' && !$isFree) {
                $billingInterval = 'manual';
            }
            if ($billingOverride === 1 && !$isTrial) {
                $subscriptionCurrentPeriodEnd = null;
            }

            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

            $stmt = db()->prepare("
                UPDATE teams
                SET
                    plan_key = ?,
                    subscription_status = ?,
                    billing_interval = ?,
                    billing_override = ?,
                    is_trial = ?,
                    trial_ends_at = ?,
                    subscription_current_period_end = ?
                WHERE id IN ($placeholders)
            ");

            $stmt->execute(array_merge([
                $planKey,
                $subscriptionStatus,
                $billingInterval,
                $billingOverride,
                $isTrial ? 1 : 0,
                $trialEndsAt,
                $subscriptionCurrentPeriodEnd,
            ], $teamIds));

            if ($isTrial && $trialEndsAt !== null) {
                $userStmt = db()->prepare("
                    SELECT full_name, email
                    FROM users
                    WHERE id = :id
                    LIMIT 1
                ");

                $userStmt->execute([
                    'id' => $targetUserId,
                ]);

                $coach = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($coach) {
                    try {
                        send_trial_started_email(
                            (string)$coach['email'],
                            (string)$coach['full_name'],
                            'BenchBuddy Team',
                            date('F j, Y', strtotime($trialEndsAt))
                        );
                    } catch (Throwable $e) {
                        error_log('Trial started email failed: ' . $e->getMessage());
                    }
                }
            }

            header('Location: admin_users.php?msg=' . urlencode('Coach plan updated successfully.'));
            exit;

        }

        if ($action === 'impersonate_user') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);

            if ($targetUserId <= 0) {
                throw new RuntimeException('Invalid user selected.');
            }

            $stmt = db()->prepare("
                SELECT full_name, email
                FROM users
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                'id' => $targetUserId,
            ]);

            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                throw new RuntimeException('User not found.');
            }

            $name = trim((string)($targetUser['full_name'] ?? ''));

            if ($name === '') {
                $name = (string)($targetUser['email'] ?? 'this user');
            }

            start_user_impersonation($targetUserId);

            header('Location: index.php?msg=' . urlencode('You are now impersonating ' . $name . '.'));
            exit;
        }

        if ($action === 'stop_impersonation') {
            stop_user_impersonation();

            header('Location: admin_users.php?msg=' . urlencode('Impersonation ended.'));
            exit;
        }

        if ($action === 'delete_user_account') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);

            if ($targetUserId <= 0) {
                throw new RuntimeException('Invalid user selected.');
            }

            if ($targetUserId === current_user_id()) {
                throw new RuntimeException('You cannot delete your own admin account here.');
            }

            delete_user_account_and_data($targetUserId);

            header('Location: admin_users.php?msg=' . urlencode('User account deleted successfully.'));
            exit;
        }
    }

    $stmt = db()->query("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.role,
            u.is_active,
            u.created_at,
            u.last_active_at,
            COUNT(DISTINCT tm.team_id) AS team_count,
            COUNT(DISTINCT p.id) AS player_count,
            COUNT(DISTINCT active_sessions.session_id) AS active_session_count,
            GROUP_CONCAT(DISTINCT t.plan_key ORDER BY t.plan_key SEPARATOR ', ') AS plan_keys,
            GROUP_CONCAT(
                DISTINCT
                CASE
                    WHEN COALESCE(t.is_trial, 0) = 1
                      AND t.subscription_status = 'trialing'
                    THEN 'trialing'
                    WHEN COALESCE(t.billing_override, 0) = 1
                    THEN t.subscription_status
                    ELSE t.subscription_status
                END
                ORDER BY t.subscription_status
                SEPARATOR ', '
            ) AS subscription_statuses,            GROUP_CONCAT(DISTINCT t.billing_interval ORDER BY t.billing_interval SEPARATOR ', ') AS billing_intervals,
            MAX(COALESCE(t.billing_override, 0)) AS has_billing_override,
            MAX(
              CASE
                WHEN COALESCE(t.billing_override, 0) = 1
                  AND t.subscription_status <> 'trialing'
                THEN 0
                ELSE COALESCE(t.is_trial, 0)
              END
            ) AS is_trial,

            MIN(
              CASE
                WHEN COALESCE(t.is_trial, 0) = 1
                  AND t.subscription_status = 'trialing'
                THEN t.trial_ends_at
                ELSE NULL
              END
            ) AS trial_ends_at
        FROM users u
        LEFT JOIN team_memberships tm ON tm.user_id = u.id
        LEFT JOIN teams t ON t.id = tm.team_id
        LEFT JOIN players p ON p.team_id = tm.team_id
        LEFT JOIN user_sessions active_sessions
            ON active_sessions.user_id = u.id
           AND active_sessions.logged_out_at IS NULL
           AND active_sessions.last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY
            u.id,
            u.full_name,
            u.email,
            u.role,
            u.is_active,
            u.created_at,
            u.last_active_at
        ORDER BY u.full_name ASC, u.email ASC
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .admin-users-card-list {
    display: grid;
    gap: 16px;
  }

  .admin-user-card {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 16px;
    padding: 18px;
    background: #fff;
  }

  .admin-user-card-header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 14px;
  }

  .admin-user-card-header h3 {
    margin: 0;
  }

  .admin-user-card-header p {
    margin: 4px 0 0;
  }

  .admin-user-pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
  }

  .admin-user-card-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 14px;
  }

  .admin-user-card-grid div {
    background: #f8fafc;
    border-radius: 12px;
    padding: 10px;
  }

  .admin-user-card-grid span {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 4px;
  }

  .admin-user-card-grid strong {
    display: block;
    font-size: 14px;
  }

  .admin-user-plan-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: end;
    margin-top: 16px;
  }

  .admin-user-plan-controls select {
    min-width: 150px;
  }

  .admin-user-actions {
    margin-top: 14px;
  }

  @media (max-width: 900px) {
    .admin-user-card-header {
      display: block;
    }

    .admin-user-pill-row {
      justify-content: flex-start;
      margin-top: 10px;
    }

    .admin-user-card-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .admin-user-plan-controls {
      display: grid;
      grid-template-columns: 1fr;
    }

    .admin-user-plan-controls select,
    .admin-user-plan-controls button {
      width: 100%;
    }
  }
  .btn-danger {
      background: #dc2626;
      border-color: #dc2626;
      color: #fff;
  }

  .btn-danger:hover {
      background: #b91c1c;
      border-color: #b91c1c;
  }
</style>

<h1 class="page-title brand-title-font">Admin Users</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (is_impersonating()): ?>
  <div class="card">
    <h2>Impersonation Active</h2>
    <p class="muted">You are currently impersonating <?= h(current_user_name()) ?>.</p>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="stop_impersonation">
      <button type="submit" class="btn btn-secondary">Stop Impersonation</button>
    </form>
  </div>
<?php endif; ?>

<?php if (current_user_role() === 'admin'): ?>
  <div class="card">
    <h2>Billing Controls</h2>
    <p class="muted">
      Control whether billing is visible and whether plan limits are enforced.
    </p>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_billing_settings">

      <label class="toggle-row" for="billing_enabled_toggle">
        <span class="toggle-label-text">Billing enabled</span>
        <span class="toggle-switch">
          <input
            type="checkbox"
            id="billing_enabled_toggle"
            name="billing_enabled"
            value="1"
            <?= billing_enabled() ? 'checked' : '' ?>
          >
          <span class="toggle-slider"></span>
        </span>
      </label>

      <label class="toggle-row" for="billing_enforcement_enabled_toggle">
        <span class="toggle-label-text">Enforce plan limits</span>
        <span class="toggle-switch">
          <input
            type="checkbox"
            id="billing_enforcement_enabled_toggle"
            name="billing_enforcement_enabled"
            value="1"
            <?= billing_enforcement_enabled() ? 'checked' : '' ?>
          >
          <span class="toggle-slider"></span>
        </span>
      </label>

      <button type="submit" class="btn">Save Billing Settings</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2>All Users</h2>

  <?php if (empty($users)): ?>
    <p class="muted">No users found.</p>
  <?php else: ?>
    <div class="admin-users-card-list">
      <?php foreach ($users as $row): ?>
        <?php
          $currentPlanKey = admin_best_plan_key($row['plan_keys'] ?? '');
          $currentBillingInterval = admin_first_value($row['billing_intervals'] ?? '', 'free');
          $currentSubscriptionStatus = admin_first_value($row['subscription_statuses'] ?? '', 'free');
          $isTrial = (
              str_contains(strtolower((string)($row['subscription_statuses'] ?? '')), 'trialing')
              && (int)($row['is_trial'] ?? 0) === 1
              && !empty($row['trial_ends_at'])
          );
        ?>

        <article class="admin-user-card">
          <div class="admin-user-card-header">
            <div>
              <h3><?= h((string)$row['full_name']) ?></h3>
              <p class="muted"><?= h((string)$row['email']) ?></p>
            </div>

            <div class="admin-user-pill-row">
              <span class="pill">
                <?= h(((int)$row['is_active'] === 1) ? 'Active' : 'Inactive') ?>
              </span>

              <span class="pill">
                <?= h(admin_display_plan_label($row['plan_keys'] ?? '')) ?>
              </span>

              <span class="pill">
                <?= h(admin_display_subscription_status(
                    $row['subscription_statuses'] ?? '',
                    (int)($row['has_billing_override'] ?? 0)
                )) ?>
              </span>

              <?php if ($isTrial): ?>
                <span class="pill status-warning">
                  Trial ends <?= h(date('M j, Y', strtotime((string)$row['trial_ends_at']))) ?>
                </span>
              <?php endif; ?>

              <?php if ((int)($row['active_session_count'] ?? 0) > 0): ?>
                <span class="pill locked">
                  Online · <?= (int)$row['active_session_count'] ?> device<?= (int)$row['active_session_count'] === 1 ? '' : 's' ?>
                </span>
              <?php else: ?>
                <span class="pill draft">Offline</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="admin-user-card-grid">
            <div>
              <span>Role</span>
              <strong><?= h((string)$row['role']) ?></strong>
            </div>

            <div>
              <span>Teams</span>
              <strong><?= (int)$row['team_count'] ?></strong>
            </div>

            <div>
              <span>Players</span>
              <strong><?= (int)$row['player_count'] ?></strong>
            </div>

            <div>
              <span>Last Active</span>
              <strong><?= h(format_local_datetime($row['last_active_at'] ?? null, 'Never')) ?></strong>
            </div>

            <div>
              <span>Created</span>
              <strong><?= h(date('M j, Y', strtotime((string)$row['created_at']))) ?></strong>
            </div>

            <div>
              <span>Billing Interval</span>
              <strong><?= h(ucwords(str_replace('_', ' ', $currentBillingInterval))) ?></strong>
            </div>

            <div>
              <span>Subscription Status</span>
              <strong><?= h(ucwords(str_replace('_', ' ', $currentSubscriptionStatus))) ?></strong>
            </div>

            <div>
              <span>Trial</span>
              <strong>
                <?php if ($isTrial): ?>
                  Ends <?= h(date('M j, Y', strtotime((string)$row['trial_ends_at']))) ?>
                <?php else: ?>
                  No active trial
                <?php endif; ?>
              </strong>
            </div>
          </div>

          <?php if ((int)$row['is_active'] === 1): ?>
            <form method="post" class="admin-plan-form admin-user-plan-controls">
                <?= csrf_field() ?>
              <input type="hidden" name="action" value="admin_update_coach_plan">
              <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">

              <select name="plan_key">
                <option value="free" <?= $currentPlanKey === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="coach" <?= $currentPlanKey === 'coach' ? 'selected' : '' ?>>Coach</option>
                <option value="coachplus" <?= $currentPlanKey === 'coachplus' ? 'selected' : '' ?>>Coach Plus</option>
                <option value="unlimited" <?= $currentPlanKey === 'unlimited' ? 'selected' : '' ?>>Unlimited</option>
              </select>

              <select name="billing_interval">
                <option value="free" <?= $currentBillingInterval === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="monthly" <?= $currentBillingInterval === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="yearly" <?= $currentBillingInterval === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                <option value="manual" <?= $currentBillingInterval === 'manual' ? 'selected' : '' ?>>Manual</option>
              </select>

              <select name="subscription_status">
                <option value="free" <?= $currentSubscriptionStatus === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="active" <?= $currentSubscriptionStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="trialing" <?= $currentSubscriptionStatus === 'trialing' ? 'selected' : '' ?>>Trialing</option>
                <option value="inactive" <?= $currentSubscriptionStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="canceled" <?= $currentSubscriptionStatus === 'canceled' ? 'selected' : '' ?>>Canceled</option>
              </select>

              <button type="submit" class="btn-sm">Save Plan</button>
            </form>
          <?php endif; ?>

          <div class="admin-user-actions">
            <?php if ((int)$row['is_active'] === 1 && (int)$row['id'] !== current_user_id()): ?>
              <form method="post">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="impersonate_user">
                <input type="hidden" name="target_user_id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn-sm">Impersonate</button>
              </form>
            <?php else: ?>
              <span class="muted"><?= ((int)$row['is_active'] === 1) ? 'Current user' : 'Inactive' ?></span>
            <?php endif; ?>

            <?php if ((int)$row['id'] !== current_user_id()): ?>
              <form
                method="post"
                onsubmit="
                  const confirmation = prompt(
                    'This will permanently delete the account and related data.\n\nType DELETE to continue.'
                  );

                  if (confirmation !== 'DELETE') {
                    alert('Account deletion cancelled.');
                    return false;
                  }

                  return true;
                "
                style="margin-top:10px;"
              >
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_user_account">
                <input type="hidden" name="target_user_id" value="<?= (int)$row['id'] ?>">

                <button type="submit" class="btn btn-sm btn-danger">
                  Delete Account
                </button>
              </form>
            <?php endif; ?>


          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
