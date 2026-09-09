<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_admin_user();

$pageTitle = 'Admin Metrics Dashboard';
$currentPage = 'admin_dashboard';

$error = '';

function metric_count(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function metric_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function metric_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return number_format(($part / $total) * 100, 1) . '%';
}

$planPrices = [
    'free' => 0,
    'coach' => 9,
    'coachplus' => 19,
    'unlimited' => 39,
];

$metrics = [];
$recentUsers = [];
$recentGames = [];
$topTeams = [];
$inactiveTeams = [];
$featureRequestRows = [];
$topReferrers = [];
$subscriptionChartLabels = [];
$subscriptionChartValues = [];
$estimatedMrr = 0.0;
$estimatedArr = 0.0;
$paidTeamPercent = '0%';

try {
    $metrics = [
        'total_users' => metric_count("SELECT COUNT(*) FROM users"),
        'active_users' => metric_count("SELECT COUNT(*) FROM users WHERE COALESCE(is_active, 1) = 1"),
        'new_users_7d' => metric_count("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'new_users_30d' => metric_count("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),

        'dau' => metric_count("SELECT COUNT(*) FROM users WHERE last_active_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'wau' => metric_count("SELECT COUNT(*) FROM users WHERE last_active_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'mau' => metric_count("SELECT COUNT(*) FROM users WHERE last_active_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),

        'online_users' => metric_count("
            SELECT COUNT(DISTINCT user_id)
            FROM user_sessions
            WHERE logged_out_at IS NULL
              AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        "),

        'total_teams' => metric_count("SELECT COUNT(*) FROM teams"),
        'active_teams' => metric_count("SELECT COUNT(*) FROM teams"),
        'inactive_teams_30d' => metric_count("
            SELECT COUNT(*)
            FROM teams t
            WHERE NOT EXISTS (
                SELECT 1
                FROM games g
                WHERE g.team_id = t.id
                  AND g.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
        "),

        'total_players' => metric_count("SELECT COUNT(*) FROM players"),
        'active_players' => metric_count("SELECT COUNT(*) FROM players"),

        'total_games' => metric_count("SELECT COUNT(*) FROM games"),
        'draft_games' => metric_count("SELECT COUNT(*) FROM games WHERE status = 'draft'"),
        'generated_games' => metric_count("SELECT COUNT(*) FROM games WHERE status = 'generated'"),
        'locked_games' => metric_count("SELECT COUNT(*) FROM games WHERE status = 'locked'"),
        'games_7d' => metric_count("SELECT COUNT(*) FROM games WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'games_30d' => metric_count("SELECT COUNT(*) FROM games WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),

        'lineup_templates' => metric_count("SELECT COUNT(*) FROM lineup_templates"),
        'lineup_history' => metric_count("SELECT COUNT(*) FROM lineup_history"),
        'lineup_drafts' => metric_count("SELECT COUNT(*) FROM lineup_drafts"),
        'pitch_logs' => metric_count("SELECT COUNT(*) FROM pitch_log"),

        'feature_requests' => metric_count("SELECT COUNT(*) FROM feature_requests"),
        'open_feature_requests' => metric_count("SELECT COUNT(*) FROM feature_requests WHERE status IN ('new', 'open', 'planned', 'reviewing')"),
        'feature_updates' => metric_count("SELECT COUNT(*) FROM feature_updates"),

        'signup_attempts_24h' => metric_count("SELECT COUNT(*) FROM signup_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'failed_signup_attempts_24h' => 0,

        'free_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE plan_key = 'free'"),
        'coach_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE plan_key = 'coach'"),
        'coachplus_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE plan_key = 'coachplus'"),
        'unlimited_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE plan_key = 'unlimited'"),
        'paid_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE plan_key <> 'free'"),
        'trialing_teams' => metric_count("SELECT COUNT(*) FROM teams WHERE subscription_status = 'trialing'"),
        'active_subscriptions' => metric_count("SELECT COUNT(*) FROM teams WHERE subscription_status = 'active'"),
        'admin_overrides' => metric_count("SELECT COUNT(*) FROM teams WHERE COALESCE(billing_override, 0) = 1"),

        'referrals_total' => metric_count("SELECT COUNT(*) FROM users WHERE referred_by_user_id IS NOT NULL"),

        'referred_paid_teams' => metric_count("
            SELECT COUNT(DISTINCT t.id)
            FROM users u
            INNER JOIN team_memberships tm ON tm.user_id = u.id
            INNER JOIN teams t ON t.id = tm.team_id
            WHERE u.referred_by_user_id IS NOT NULL
              AND t.plan_key <> 'free'
        "),
        'converted_referrals' => metric_count("SELECT COUNT(*) FROM referral_rewards WHERE reward_status IN ('pending', 'redeemed')"),
    ];

    $paidTeamPercent = metric_percent((int)$metrics['paid_teams'], (int)$metrics['total_teams']);

    $recentUsers = metric_rows("
        SELECT id, full_name, email, role, is_active, created_at, last_active_at
        FROM users
        ORDER BY id DESC
        LIMIT 10
    ");

    $recentGames = metric_rows("
        SELECT
            g.id,
            g.game_id,
            g.game_date,
            g.status,
            g.created_at,
            t.name AS team_name
        FROM games g
        LEFT JOIN teams t ON t.id = g.team_id
        ORDER BY g.id DESC
        LIMIT 10
    ");

    $topTeams = metric_rows("
        SELECT
            t.id,
            t.name,
            t.plan_key,
            COUNT(DISTINCT p.id) AS player_count,
            COUNT(DISTINCT g.id) AS game_count
        FROM teams t
        LEFT JOIN players p ON p.team_id = t.id
        LEFT JOIN games g ON g.team_id = t.id
        GROUP BY t.id, t.name, t.plan_key
        ORDER BY game_count DESC, player_count DESC
        LIMIT 10
    ");

    $inactiveTeams = metric_rows("
        SELECT
            t.id,
            t.name,
            t.plan_key,
            MAX(g.created_at) AS last_game_at,
            COUNT(g.id) AS game_count
        FROM teams t
        LEFT JOIN games g ON g.team_id = t.id
        GROUP BY t.id, t.name, t.plan_key
        HAVING last_game_at IS NULL OR last_game_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY last_game_at ASC
        LIMIT 10
    ");

    $featureRequestRows = metric_rows("
        SELECT status, COUNT(*) AS total
        FROM feature_requests
        GROUP BY status
        ORDER BY total DESC
    ");

    $topReferrers = metric_rows("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.referral_code,
            COUNT(r.id) AS referral_total
        FROM users u
        LEFT JOIN users r ON r.referred_by_user_id = u.id
        GROUP BY u.id, u.full_name, u.email, u.referral_code
        HAVING referral_total > 0
        ORDER BY referral_total DESC
        LIMIT 10
    ");

    $subscriptionRows = metric_rows("
        SELECT
            plan_key,
            billing_interval,
            subscription_status,
            COUNT(*) AS total
        FROM teams
        GROUP BY plan_key, billing_interval, subscription_status
        ORDER BY plan_key ASC
    ");

    foreach ($subscriptionRows as $row) {
        $planKey = (string)($row['plan_key'] ?? 'free');
        $billingInterval = (string)($row['billing_interval'] ?? 'monthly');
        $status = (string)($row['subscription_status'] ?? 'free');
        $total = (int)($row['total'] ?? 0);

        if (!isset($planPrices[$planKey])) {
            continue;
        }

        $monthlyPrice = (float)$planPrices[$planKey];

        if ($billingInterval === 'yearly') {
            $monthlyPrice = ($monthlyPrice * 10) / 12;
        }

        if ($status === 'active' || $status === 'trialing') {
            $estimatedMrr += $monthlyPrice * $total;
        }

        $label = ucfirst($planKey) . ' / ' . ucfirst($billingInterval);

        if ($status !== '') {
            $label .= ' / ' . ucfirst($status);
        }

        $subscriptionChartLabels[] = $label;
        $subscriptionChartValues[] = $total;
    }

    $estimatedArr = $estimatedMrr * 12;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .admin-metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
  }

  .metric-card {
    text-align: center;
  }

  .metric-label {
    font-size: 13px;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 8px;
  }

  .metric-value {
    font-size: 34px;
    font-weight: 900;
    line-height: 1;
  }

  .metric-sub {
    margin-top: 8px;
    font-size: 12px;
    color: #64748b;
  }

  .admin-dashboard-section {
    margin-bottom: 22px;
  }

  .admin-chart-wrap {
    position: relative;
    height: 320px;
    width: 100%;
  }

  .admin-quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }
</style>

<h1 class="page-title brand-title-font brand-title-font">Admin Metrics Dashboard</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card admin-dashboard-section">
  <h2>Quick Actions</h2>

  <div class="admin-quick-actions">
    <a class="btn" href="admin_users.php">Admin Users</a>
    <a class="btn btn-secondary" href="feature_admin.php">Feature Updates</a>
    <a class="btn btn-secondary" href="feature_requests_admin.php">Feature Requests</a>
    <a class="btn btn-secondary" href="billing.php">Billing</a>
    <a class="btn btn-secondary" href="features.php">What’s New</a>
  </div>
</div>

<div class="card admin-dashboard-section">
  <h2>Platform Snapshot</h2>

  <div class="admin-metrics-grid">
    <div class="card metric-card">
      <div class="metric-label">Total Users</div>
      <div class="metric-value"><?= number_format((int)($metrics['total_users'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['active_users'] ?? 0)) ?> active</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Online Now</div>
      <div class="metric-value"><?= number_format((int)($metrics['online_users'] ?? 0)) ?></div>
      <div class="metric-sub">last 5 minutes</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">DAU</div>
      <div class="metric-value"><?= number_format((int)($metrics['dau'] ?? 0)) ?></div>
      <div class="metric-sub">last 24 hours</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">WAU</div>
      <div class="metric-value"><?= number_format((int)($metrics['wau'] ?? 0)) ?></div>
      <div class="metric-sub">last 7 days</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">MAU</div>
      <div class="metric-value"><?= number_format((int)($metrics['mau'] ?? 0)) ?></div>
      <div class="metric-sub">last 30 days</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Teams</div>
      <div class="metric-value"><?= number_format((int)($metrics['active_teams'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['inactive_teams_30d'] ?? 0)) ?> inactive 30d</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Players</div>
      <div class="metric-value"><?= number_format((int)($metrics['total_players'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['active_players'] ?? 0)) ?> active</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Games</div>
      <div class="metric-value"><?= number_format((int)($metrics['total_games'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['games_30d'] ?? 0)) ?> last 30 days</div>
    </div>
  </div>
</div>

<div class="card admin-dashboard-section">
  <h2>Revenue Snapshot</h2>

  <div class="admin-metrics-grid">
    <div class="card metric-card">
      <div class="metric-label">Estimated MRR</div>
      <div class="metric-value">$<?= number_format((float)$estimatedMrr, 2) ?></div>
      <div class="metric-sub">based on plan counts</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Estimated ARR</div>
      <div class="metric-value">$<?= number_format((float)$estimatedArr, 2) ?></div>
      <div class="metric-sub">MRR x 12</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Paid Teams</div>
      <div class="metric-value"><?= number_format((int)($metrics['paid_teams'] ?? 0)) ?></div>
      <div class="metric-sub"><?= h($paidTeamPercent) ?> of teams</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Active Subscriptions</div>
      <div class="metric-value"><?= number_format((int)($metrics['active_subscriptions'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Trialing</div>
      <div class="metric-value"><?= number_format((int)($metrics['trialing_teams'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Admin Overrides</div>
      <div class="metric-value"><?= number_format((int)($metrics['admin_overrides'] ?? 0)) ?></div>
    </div>
  </div>

  <div class="admin-chart-wrap">
    <canvas id="subscriptionChart"></canvas>
  </div>
</div>

<div class="card admin-dashboard-section">
  <h2>Game & Lineup Activity</h2>

  <div class="admin-metrics-grid">
    <div class="card metric-card">
      <div class="metric-label">Draft Games</div>
      <div class="metric-value"><?= number_format((int)($metrics['draft_games'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Generated Lineups</div>
      <div class="metric-value"><?= number_format((int)($metrics['generated_games'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Finalized Games</div>
      <div class="metric-value"><?= number_format((int)($metrics['locked_games'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Lineup Drafts</div>
      <div class="metric-value"><?= number_format((int)($metrics['lineup_drafts'] ?? 0)) ?></div>
      <div class="metric-sub">autosave records</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Lineup History</div>
      <div class="metric-value"><?= number_format((int)($metrics['lineup_history'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Templates</div>
      <div class="metric-value"><?= number_format((int)($metrics['lineup_templates'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Pitch Logs</div>
      <div class="metric-value"><?= number_format((int)($metrics['pitch_logs'] ?? 0)) ?></div>
    </div>
  </div>
</div>

<div class="card admin-dashboard-section">
  <h2>Growth & Support</h2>

  <div class="admin-metrics-grid">
    <div class="card metric-card">
      <div class="metric-label">New Users 7 Days</div>
      <div class="metric-value"><?= number_format((int)($metrics['new_users_7d'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">New Users 30 Days</div>
      <div class="metric-value"><?= number_format((int)($metrics['new_users_30d'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Referrals</div>
      <div class="metric-value"><?= number_format((int)($metrics['referrals_total'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['referred_paid_teams'] ?? 0)) ?> paid teams</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Feature Requests</div>
      <div class="metric-value"><?= number_format((int)($metrics['feature_requests'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['open_feature_requests'] ?? 0)) ?> open</div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Feature Updates</div>
      <div class="metric-value"><?= number_format((int)($metrics['feature_updates'] ?? 0)) ?></div>
    </div>

    <div class="card metric-card">
      <div class="metric-label">Signup Attempts 24h</div>
      <div class="metric-value"><?= number_format((int)($metrics['signup_attempts_24h'] ?? 0)) ?></div>
      <div class="metric-sub"><?= number_format((int)($metrics['failed_signup_attempts_24h'] ?? 0)) ?> failed</div>
    </div>
  </div>
</div>

<div class="games-layout">
  <div class="card">
    <h2>Top Referrers</h2>

    <?php if (empty($topReferrers)): ?>
      <p class="muted">No referrals tracked yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Coach</th>
              <th>Code</th>
              <th>Referrals</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topReferrers as $referrer): ?>
              <tr>
                <td><?= h((string)($referrer['full_name'] ?? $referrer['email'] ?? '')) ?></td>
                <td><?= h((string)($referrer['referral_code'] ?? '')) ?></td>
                <td><?= (int)($referrer['referral_total'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Feature Request Status</h2>

    <?php if (empty($featureRequestRows)): ?>
      <p class="muted">No feature requests yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($featureRequestRows as $row): ?>
              <tr>
                <td><?= h(ucwords(str_replace('_', ' ', (string)($row['status'] ?? '')))) ?></td>
                <td><?= (int)($row['total'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="games-layout">
  <div class="card">
    <h2>Recent Users</h2>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentUsers as $user): ?>
            <tr>
              <td><?= h((string)($user['full_name'] ?? '')) ?></td>
              <td><?= h((string)($user['email'] ?? '')) ?></td>
              <td><?= h((string)($user['role'] ?? '')) ?></td>
              <td><?= h(date('M j, Y', strtotime((string)$user['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Recent Games</h2>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Game</th>
            <th>Team</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentGames as $game): ?>
            <tr>
              <td><?= h((string)($game['game_id'] ?? '')) ?></td>
              <td><?= h((string)($game['team_name'] ?? '')) ?></td>
              <td><?= h((string)($game['status'] ?? '')) ?></td>
              <td><?= h((string)($game['game_date'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="games-layout">
  <div class="card">
    <h2>Top Teams By Activity</h2>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Team</th>
            <th>Plan</th>
            <th>Players</th>
            <th>Games</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topTeams as $team): ?>
            <tr>
              <td><?= h((string)($team['name'] ?? '')) ?></td>
              <td><?= h((string)($team['plan_key'] ?? 'free')) ?></td>
              <td><?= (int)($team['player_count'] ?? 0) ?></td>
              <td><?= (int)($team['game_count'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Inactive Teams</h2>

    <?php if (empty($inactiveTeams)): ?>
      <p class="muted">No inactive teams found.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Team</th>
              <th>Plan</th>
              <th>Games</th>
              <th>Last Game</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inactiveTeams as $team): ?>
              <tr>
                <td><?= h((string)($team['name'] ?? '')) ?></td>
                <td><?= h((string)($team['plan_key'] ?? 'free')) ?></td>
                <td><?= (int)($team['game_count'] ?? 0) ?></td>
                <td>
                  <?= !empty($team['last_game_at'])
                      ? h(date('M j, Y', strtotime((string)$team['last_game_at'])))
                      : 'No games'
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const chartEl = document.getElementById('subscriptionChart');

  if (!chartEl) {
    return;
  }

  new Chart(chartEl, {
    type: 'bar',
    data: {
      labels: <?= json_encode($subscriptionChartLabels) ?>,
      datasets: [{
        label: 'Teams',
        data: <?= json_encode($subscriptionChartValues) ?>
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      }
    }
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
