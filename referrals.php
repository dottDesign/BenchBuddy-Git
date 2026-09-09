<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Referrals';
$currentPage = 'referrals';

$userId = current_user_id();
$referralCode = ensure_user_referral_code($userId);

$referralUrl =
    'https://' .
    ($_SERVER['HTTP_HOST'] ?? 'benchbuddy.ca') .
    '/signup.php?ref=' .
    urlencode($referralCode);

$totalReferrals = 0;
$paidReferralTeams = 0;
$pendingRewards = 0;
$redeemedRewards = 0;
$recentReferrals = [];
$rewardRows = [];

try {
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM users
        WHERE referred_by_user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $totalReferrals = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM users u
        INNER JOIN team_memberships tm ON tm.user_id = u.id
        INNER JOIN teams t ON t.id = tm.team_id
        WHERE u.referred_by_user_id = :user_id
          AND t.plan_key <> 'free'
    ");
    $stmt->execute(['user_id' => $userId]);
    $paidReferralTeams = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM referral_rewards
        WHERE referrer_user_id = :user_id
          AND reward_status = 'pending'
    ");
    $stmt->execute(['user_id' => $userId]);
    $pendingRewards = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM referral_rewards
        WHERE referrer_user_id = :user_id
          AND reward_status = 'redeemed'
    ");
    $stmt->execute(['user_id' => $userId]);
    $redeemedRewards = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT full_name, email, created_at
        FROM users
        WHERE referred_by_user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['user_id' => $userId]);
    $recentReferrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = db()->prepare("
        SELECT
            rr.reward_type,
            rr.reward_status,
            rr.reward_value,
            rr.created_at,
            u.full_name AS referred_name,
            u.email AS referred_email
        FROM referral_rewards rr
        LEFT JOIN users u ON u.id = rr.referred_user_id
        WHERE rr.referrer_user_id = :user_id
        ORDER BY rr.created_at DESC
        LIMIT 20
    ");
    $stmt->execute(['user_id' => $userId]);
    $rewardRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentReferrals = [];
    $rewardRows = [];
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Referrals</h1>

<div class="card referral-hero">
  <p class="referral-eyebrow">BenchBuddy Referrals</p>
  <h2>Share BenchBuddy With Other Coaches</h2>
  <p>
    Invite another coach using your referral link. BenchBuddy tracks signups from your link and can reward you when referred teams upgrade to paid plans.
  </p>
</div>

<div class="card" style="margin-bottom:18px;">
  <h2>Your Referral Link</h2>

  <label for="referral_link">Copy and share this link</label>
  <input
    type="text"
    id="referral_link"
    readonly
    value="<?= h($referralUrl) ?>"
    onclick="this.select();"
  >

  <div class="actions-row" style="margin-top:14px;">
    <button
      type="button"
      class="btn"
      onclick="
        navigator.clipboard.writeText(document.getElementById('referral_link').value);
        this.textContent='Copied!';
      "
    >
      Copy Referral Link
    </button>

    <a
      target="_blank"
      class="btn btn-secondary"
      href="mailto:?subject=Try BenchBuddy&body=I thought you might like BenchBuddy for lineup planning and team management: <?= urlencode($referralUrl) ?>"
    >
      Share by Email
    </a>
  </div>
</div>

<div class="admin-metrics-grid" style="margin-bottom:18px;">
  <div class="card metric-card">
    <div class="metric-label">Referral Code</div>
    <div class="metric-value small-code"><?= h($referralCode) ?></div>
    <div class="metric-sub">Your unique code</div>
  </div>

  <div class="card metric-card">
    <div class="metric-label">Referral Signups</div>
    <div class="metric-value"><?= number_format($totalReferrals) ?></div>
    <div class="metric-sub">Coaches who joined from your link</div>
  </div>

  <div class="card metric-card">
    <div class="metric-label">Paid Referral Teams</div>
    <div class="metric-value"><?= number_format($paidReferralTeams) ?></div>
    <div class="metric-sub">Referred teams on paid plans</div>
  </div>

  <div class="card metric-card">
    <div class="metric-label">Pending Rewards</div>
    <div class="metric-value"><?= number_format($pendingRewards) ?></div>
    <div class="metric-sub">Earned, not yet redeemed</div>
  </div>

  <div class="card metric-card">
    <div class="metric-label">Redeemed Rewards</div>
    <div class="metric-value"><?= number_format($redeemedRewards) ?></div>
    <div class="metric-sub">Rewards already applied</div>
  </div>
</div>

<div class="account-grid">
  <div class="card">
    <h2>How It Works</h2>

    <ul class="feature-list">
      <li>Copy your referral link.</li>
      <li>Share it with another coach, team manager, or league contact.</li>
      <li>When they sign up through your link, the referral is tracked automatically.</li>
      <li>If their team upgrades later, BenchBuddy tracks that as a paid referral.</li>
      <li>Paid referrals can unlock free months, account credits, or coach perks.</li>
    </ul>
  </div>

  <div class="card">
    <h2>What Counts As A Successful Referral?</h2>

    <ul class="feature-list">
      <li>A new coach signs up using your referral link.</li>
      <li>They create or join a team.</li>
      <li>Their team upgrades to a paid subscription.</li>
      <li>Free accounts are tracked, but rewards are earned after a paid upgrade.</li>
    </ul>
  </div>
</div>

<div class="card" style="margin-top:18px;">
  <h2>Referral Rewards</h2>
  <p class="muted">
    Referral rewards are earned when a referred coach upgrades to a paid BenchBuddy subscription.
  </p>

  <div class="reward-grid">
    <div class="reward-card">
      <div class="reward-count">1</div>
      <h3>Successful Referral</h3>
      <p>Earn 1 free month of BenchBuddy or account credit toward your next billing cycle.</p>
    </div>

    <div class="reward-card">
      <div class="reward-count">3</div>
      <h3>Successful Referrals</h3>
      <p>Unlock premium team branding perks, exclusive dashboard themes, or early beta access.</p>
    </div>

    <div class="reward-card">
      <div class="reward-count">5</div>
      <h3>Successful Referrals</h3>
      <p>Earn 3 bonus months free, priority feature voting, and a Founding Coach badge.</p>
    </div>

    <div class="reward-card">
      <div class="reward-count">10</div>
      <h3>Successful Referrals</h3>
      <p>Earn 1 full year free, VIP product feedback access, and private coaching tools beta access.</p>
    </div>
  </div>

  <div class="msg info" style="margin-top:18px;">
    Referral rewards may be reviewed before being applied. Rewards are intended for valid new coach accounts and paid team upgrades.
  </div>
</div>

<div class="card" style="margin-top:18px;">
  <h2>Reward Status Guide</h2>

  <div class="reward-status-grid">
    <div><strong>Pending</strong><span>Reward has been earned and is waiting to be applied.</span></div>
    <div><strong>Approved</strong><span>Reward has been verified and is ready to apply.</span></div>
    <div><strong>Redeemed</strong><span>Reward has already been applied.</span></div>
    <div><strong>Expired</strong><span>Reward was not used before expiry.</span></div>
    <div><strong>Rejected</strong><span>Referral was invalid or did not qualify.</span></div>
  </div>
</div>

<div class="card" style="margin-top:18px;">
  <h2>Your Referral Rewards</h2>

  <?php if (empty($rewardRows)): ?>
    <p class="muted">No referral rewards yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Reward</th>
            <th>Referred Coach</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rewardRows as $reward): ?>
            <tr>
              <td><?= h((string)($reward['reward_value'] ?? 'Reward')) ?></td>
              <td><?= h((string)($reward['referred_name'] ?? $reward['referred_email'] ?? 'Coach')) ?></td>
              <td><?= h(ucwords(str_replace('_', ' ', (string)($reward['reward_status'] ?? 'pending')))) ?></td>
              <td>
                <?= !empty($reward['created_at'])
                    ? h(date('M j, Y', strtotime((string)$reward['created_at'])))
                    : 'Pending'
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="account-grid" style="margin-top:18px;">
  <div class="card">
    <h2>Good Places To Share</h2>

    <ul class="feature-list">
      <li>Team group chats</li>
      <li>League coach emails</li>
      <li>Baseball or softball Facebook groups</li>
      <li>Assistant coach conversations</li>
      <li>Tournament planning groups</li>
    </ul>
  </div>

  <div class="card">
    <h2>Share Message Idea</h2>
    <p class="muted">
      “I’ve been using BenchBuddy to manage lineups, player rotations, pitch counts, and team setup. It saves a lot of time on game day. Here’s my referral link if you want to try it.”
    </p>
  </div>
</div>

<div class="card" style="margin-top:18px;">
  <h2>Recent Referrals</h2>

  <?php if (empty($recentReferrals)): ?>
    <p class="muted">No referrals yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Signed Up</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentReferrals as $referral): ?>
            <tr>
              <td><?= h((string)($referral['full_name'] ?? '')) ?></td>
              <td><?= h((string)($referral['email'] ?? '')) ?></td>
              <td><?= h(date('M j, Y', strtotime((string)$referral['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<style>
  .referral-hero {
    margin-bottom: 18px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
  }

  .referral-hero h2 {
    color: #fff;
    margin-top: 0;
  }

  .referral-hero p {
    color: rgba(255,255,255,.86);
  }

  .referral-eyebrow {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    opacity: .9;
  }

  .admin-metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px;
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
    line-height: 1.1;
  }

  .metric-value.small-code {
    font-size: 22px;
    word-break: break-word;
  }

  .metric-sub {
    margin-top: 8px;
    font-size: 12px;
    color: #64748b;
  }

  .reward-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-top: 16px;
  }

  .reward-card {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 16px;
    padding: 16px;
    background: #fff;
  }

  .reward-card h3 {
    margin: 8px 0;
  }

  .reward-card p {
    margin: 0;
    color: #64748b;
    line-height: 1.5;
  }

  .reward-count {
    width: 42px;
    height: 42px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    font-weight: 900;
    background: var(--primary);
    color: #fff;
  }

  .reward-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
  }

  .reward-status-grid div {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    padding: 14px;
    background: #fff;
  }

  .reward-status-grid strong {
    display: block;
    margin-bottom: 6px;
  }

  .reward-status-grid span {
    display: block;
    color: #64748b;
    font-size: 13px;
    line-height: 1.45;
  }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
