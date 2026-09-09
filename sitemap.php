<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Sitemap';
$currentPage = 'sitemap';
$billingVisible = function_exists('billing_enabled') && billing_enabled();
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Sitemap</h1>

<div class="account-grid">
  <div class="card">
    <h2>Main Pages</h2>
    <ul class="feature-list">
      <li><a href="index.php">Home / Dashboard</a></li>
      <li><a href="signup.php">Sign Up</a></li>
      <li><a href="login.php">Login</a></li>
      <li><a href="features.php">What’s New</a></li>
      <li><a href="privacy_policy.php">Privacy Policy</a></li>
      <li><a href="terms.php">Terms of Service</a></li>
      <li><a href="cookie_policy.php">Cookie Policy</a></li>
    </ul>
  </div>

  <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
    <div class="card">
      <h2>Coach Tools</h2>
      <ul class="feature-list">
        <li><a href="players.php">Players</a></li>
        <?php if (team_stats_enabled($teamId)): ?><li><a href="player_stats.php">Player Stats</a></li><?php endif; ?>
        <li><a href="games.php">Games</a></li>
        <li><a href="generate.php">Build Lineup</a></li>
        <li><a href="lock.php">Finalize Game</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="cancelled_games.php">Cancelled Games</a></li>
        <li><a href="bulk_pitch_counts.php">Add Pitch Counts</a></li>
        <li><a href="lineup_templates.php">Lineup Templates</a></li>
      </ul>
    </div>

    <div class="card">
      <h2>Account</h2>
      <ul class="feature-list">
        <li><a href="account.php">Account Settings</a></li>
        <?php if ($billingVisible): ?><li><a href="billing.php">Billing</a></li><?php endif; ?>
        <li><a href="referrals.php">Referrals</a></li>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (function_exists('is_admin_user') && is_admin_user()): ?>
    <div class="card">
      <h2>Admin</h2>
      <ul class="feature-list">
        <li><a href="admin_dashboard.php">Admin Dashboard</a></li>
        <li><a href="admin_users.php">Admin Users</a></li>
        <li><a href="feature_admin.php">Features Admin</a></li>
        <li><a href="admin_feature_requests.php">Feature Requests Admin</a></li>
      </ul>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
