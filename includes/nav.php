<?php
$navUser = function_exists('get_current_user_record') ? get_current_user_record() : null;
$userName = is_array($navUser) ? (string)($navUser['full_name'] ?? '') : '';

$isAdmin = function_exists('is_admin_user') && is_admin_user();
$billingVisible = function_exists('billing_enabled') && billing_enabled();
$currentPage = $currentPage ?? '';

$navTeamId = function_exists('current_team_id') ? current_team_id() : 0;
$statsVisible = $navTeamId > 0 && function_exists('team_stats_enabled')
    ? team_stats_enabled($navTeamId)
    : true;
?>

<nav class="benchbuddy-nav" aria-label="Primary navigation">
  <a class="benchbuddy-nav-link <?= $currentPage === 'index' ? 'active' : '' ?>" href="index.php">Dashboard</a>


  <div class="benchbuddy-nav-item">
      <button type="button" class="benchbuddy-nav-trigger">
        <span>Team</span>
          <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M5.5 7.5L10 12l4.5-4.5" />
          </svg>
        </button>

    <div class="benchbuddy-mega-menu benchbuddy-full-menu">
        <div class="benchbuddy-menu-grid">
            <div class="benchbuddy-menu-feature">
          <h3>Team</h3>
          <p>Manage players, profiles, templates, and team preferences.</p>
          <a href="players.php" class="benchbuddy-feature-link">Manage Team →</a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Settings</div>
          <a class="<?= $currentPage === 'team_settings' ? 'active-subnav' : '' ?>" href="team_settings.php"><strong class="mega-nav-link-title"><i data-lucide="settings"></i>Team Settings</strong><span>Configure team defaults and preferences.</span></a>
          <a class="<?= $currentPage === 'pitch_rules' ? 'active-subnav' : '' ?>" href="pitch_rules.php"><strong class="mega-nav-link-title"><i data-lucide="activity"></i>Pitch Rules</strong><span>Review pitching restrictions.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Roster</div>
          <a class="<?= $currentPage === 'players' ? 'active-subnav' : '' ?>" href="players.php"><strong class="mega-nav-link-title"><i data-lucide="users"></i>Players</strong><span>Add/edit players, jersey numbers, and roles.</span></a>
          <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php"><strong class="mega-nav-link-title"><i data-lucide="badge"></i>Player Profiles</strong><span>Track usage, positions, and history.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Tools</div>
          <a class="<?= $currentPage === 'templates' ? 'active-subnav' : '' ?>" href="lineup_templates.php"><strong class="mega-nav-link-title"><i data-lucide="clipboard-list"></i>Lineup Templates</strong><span>Save reusable lineup structures.</span></a>

          <?php if ($statsVisible): ?>
            <a class="<?= $currentPage === 'player_stats' ? 'active-subnav' : '' ?>" href="player_stats.php"><strong class="mega-nav-link-title"><i data-lucide="bar-chart-3"></i>Player Stats</strong><span>View batting and pitching stats.</span></a>
          <?php endif; ?>
        </div>
        <?php include __DIR__ . '/mega_menu_search.php'; ?>

      </div>
    </div>
  </div>


  <div class="benchbuddy-nav-item">
      <button type="button" class="benchbuddy-nav-trigger">
        <span>Game Day</span>
          <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M5.5 7.5L10 12l4.5-4.5" />
          </svg>
        </button>

    <div class="benchbuddy-mega-menu benchbuddy-full-menu">
        <div class="benchbuddy-menu-grid">
            <div class="benchbuddy-menu-feature">
          <h3>Game Day</h3>
          <p>Build, manage, finalize, and print your lineup workflow.</p>
          <a href="games.php" class="benchbuddy-feature-link">Open Game Day →</a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Setup</div>
          <a class="<?= $currentPage === 'games' ? 'active-subnav' : '' ?>"  href="games.php"><strong class="mega-nav-link-title"><i data-lucide="calendar-plus"></i>Create Games</strong><span>Set innings, roster, home/away, and batting order.</span></a>
          <a class="<?= $currentPage === 'generate' ? 'active-subnav' : '' ?>"  href="generate.php"><strong class="mega-nav-link-title"><i data-lucide="layout-grid"></i>Build Lineup</strong><span>Automatically generate rotations.</span></a>
          <a class="<?= $currentPage === 'tournaments' ? 'active-subnav' : '' ?>"  href="tournaments.php"><strong class="mega-nav-link-title"><i data-lucide="trophy"></i>Tournament</strong><span>Group games, track tournament stats, and monitor pitching usage.</span></a>

        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Editing</div>
          <a class="<?= $currentPage === 'manual_lineup' ? 'active-subnav' : '' ?>" href="manual_lineup.php"><strong class="mega-nav-link-title"><i data-lucide="pencil"></i>Manual Lineup</strong><span>Edit inning-by-inning positions.</span></a>
          <a class="<?= $currentPage === 'templates' ? 'active-subnav' : '' ?>" href="lineup_templates.php"><strong class="mega-nav-link-title"><i data-lucide="layout-panel-top"></i>Templates</strong><span>Reuse lineup structures.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Finalize</div>
          <a class="<?= $currentPage === 'lock' ? 'active-subnav' : '' ?>" href="lock.php"><strong class="mega-nav-link-title"><i data-lucide="check-circle"></i>Finalize Game</strong><span>Lock lineups and save pitch counts.</span></a>
          <a class="<?= $currentPage === 'print_lineups' ? 'active-subnav' : '' ?>" href="print_lineups.php"><strong class="mega-nav-link-title"><i data-lucide="scroll"></i>Print Game Lineup</strong><span>Print any game lineups</span></a>

          <a class="<?= $currentPage === 'blank_lineup' ? 'active-subnav' : '' ?>" href="print_blank_lineup.php"><strong class="mega-nav-link-title"><i data-lucide="scroll"></i>Blank Sheets</strong><span>Printable dugout sheets.</span></a>
        </div>
        <?php include __DIR__ . '/mega_menu_search.php'; ?>
      </div>
    </div>
  </div>


  <div class="benchbuddy-nav-item">
    <button type="button" class="benchbuddy-nav-trigger">
      <span>Post Game</span>
      <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
        <path d="M5.5 7.5L10 12l4.5-4.5" />
      </svg>
    </button>

    <div class="benchbuddy-mega-menu benchbuddy-full-menu">
      <div class="benchbuddy-menu-grid">
        <div class="benchbuddy-menu-feature">
          <h3>Post Game</h3>
          <p>
            Finalize scores, pitch counts, innings played, player stats, and review completed game records.
          </p>
          <a href="history.php?status=locked" class="benchbuddy-feature-link">Open finalized games →</a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Finalize</div>

          <a class="<?= $currentPage === 'lock' ? 'active-subnav' : '' ?>" href="lock.php">
            <strong class="mega-nav-link-title"><i data-lucide="check-circle"></i>Finalize Game</strong>
            <span>Lock lineups, add pitch counts, and update actual innings played.</span>
          </a>

          <a class="<?= $currentPage === 'bulk_pitch_counts' ? 'active-subnav' : '' ?>" href="bulk_pitch_counts.php">
            <strong class="mega-nav-link-title"><i data-lucide="activity"></i>Add Pitch Counts</strong>
            <span>Quickly update pitcher workload after games.</span>
          </a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Stats</div>

          <?php if ($statsVisible): ?>
            <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php?status=locked">
              <strong class="mega-nav-link-title"><i data-lucide="trending-up"></i>Game Stats</strong>
              <span>Select a finalized game and open its stats page.</span>
            </a>

            <a class="<?= $currentPage === 'player_stats' ? 'active-subnav' : '' ?>" href="player_stats.php">
              <strong class="mega-nav-link-title"><i data-lucide="bar-chart-3"></i>Player Stats</strong>
              <span>View season batting and pitching summaries.</span>
            </a>
          <?php else: ?>
            <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php?status=locked">
              <strong class="mega-nav-link-title"><i data-lucide="check-circle"></i>Finalized Games</strong>
              <span>Review completed games and pitch records.</span>
            </a>
          <?php endif; ?>

          <a class="<?= $currentPage === 'import_stats' ? 'active-subnav' : '' ?>" href="import_game_stats.php">
            <strong class="mega-nav-link-title"><i data-lucide="import"></i>Import Stats</strong>
            <span>Import game stats from GameChanger or csv.</span>
          </a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Review</div>

          <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php?status=locked">
            <strong class="mega-nav-link-title"><i data-lucide="history"></i>Game History</strong>
            <span>Review archived lineups, bench assignments, and pitching logs.</span>
          </a>

          <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php">
            <strong class="mega-nav-link-title"><i data-lucide="pie-chart"></i>Player Usage</strong>
            <span>Review position usage, bench time, and player history.</span>
          </a>

          <a class="<?= $currentPage === 'player_usage_report' ? 'active-subnav' : '' ?>" href="player_usage_report.php">
            <strong class="mega-nav-link-title"><i data-lucide="group"></i>Position Usage</strong>
            <span>Review the full teams position usage, bench time</span>
          </a>
        </div>
        <?php require __DIR__ . '/mega_menu_search.php'; ?>
      </div>
    </div>
  </div>



  <div class="benchbuddy-nav-item">
      <button type="button" class="benchbuddy-nav-trigger">
        <span>Review</span>
          <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M5.5 7.5L10 12l4.5-4.5" />
          </svg>
        </button>

    <div class="benchbuddy-mega-menu benchbuddy-full-menu">
        <div class="benchbuddy-menu-grid">
            <div class="benchbuddy-menu-feature">
          <h3>Review</h3>
          <p>Review finalized games, cancelled games, and season pitching data.</p>
          <a class="benchbuddy-feature-link" href="history.php">Open History →</a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">History</div>
          <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php"><strong class="mega-nav-link-title"><i data-lucide="history"></i>Game History</strong><span>View archived games and rotations.</span></a>
          <a class="<?= $currentPage === 'cancelled' ? 'active-subnav' : '' ?>" href="cancelled_games.php"><strong class="mega-nav-link-title"><i data-lucide="circle-off"></i>Cancelled Games</strong><span>Track rainouts and removed games.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Pitching</div>
          <a class="<?= $currentPage === 'bulk_pitch_counts' ? 'active-subnav' : '' ?>" href="bulk_pitch_counts.php"><strong class="mega-nav-link-title"><i data-lucide="activity"></i>Add Pitch Counts</strong><span>Bulk update pitching records.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Reports</div>
          <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php"><strong class="mega-nav-link-title"><i data-lucide="pie-chart"></i>Player Usage</strong><span>Review position and bench tracking.</span></a>
        </div>
        <?php include __DIR__ . '/mega_menu_search.php'; ?>
      </div>
    </div>
  </div>

  <div class="benchbuddy-nav-item">
      <button type="button" class="benchbuddy-nav-trigger">
        <span>Account</span>
          <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M5.5 7.5L10 12l4.5-4.5" />
          </svg>
        </button>

    <div class="benchbuddy-mega-menu benchbuddy-full-menu">
        <div class="benchbuddy-menu-grid">
            <div class="benchbuddy-menu-feature">
          <h3>Account</h3>
          <p>Manage account settings, billing, rules, updates, and support pages.</p>
          <a class="benchbuddy-feature-link" href="account.php">Open Account →</a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Settings</div>
          <a class="<?= $currentPage === 'account' ? 'active-subnav' : '' ?>" href="account.php"><strong class="mega-nav-link-title"><i data-lucide="user-cog"></i>Account Settings</strong><span>Manage profile, teams, and preferences.</span></a>
          <a class="<?= $currentPage === 'email_preferences' ? 'active-subnav' : '' ?>" href="email_preferences.php"><strong class="mega-nav-link-title"><i data-lucide="mails"></i>Email Preferences</strong><span>Manage profile, teams, and preferences.</span></a>

          <?php if ($billingVisible): ?>
            <a class="<?= $currentPage === 'billing' ? 'active-subnav' : '' ?>" href="billing.php"><strong class="mega-nav-link-title"><i data-lucide="credit-card"></i>Billing</strong><span>Manage plans and subscriptions.</span></a>
          <?php endif; ?>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Resources</div>
          <a class="<?= $currentPage === 'features' ? 'active-subnav' : '' ?>" href="features.php"><strong class="mega-nav-link-title"><i data-lucide="sparkles"></i>What’s New</strong><span>Recent BenchBuddy updates.</span></a>
          <a class="<?= $currentPage === 'sitemap' ? 'active-subnav' : '' ?>" href="sitemap.php"><strong class="mega-nav-link-title"><i data-lucide="folder-tree"></i>Sitemap</strong><span>Browse available pages.</span></a>
          <a class="<?= $currentPage === 'coach_dashboard' ? 'active-subnav' : '' ?>" href="coach_dashboard.php"><strong class="mega-nav-link-title"><i data-lucide="layout-list"></i>Coach Dashboard</strong><span>Prep for the next game with pitching, stats.</span></a>
        </div>

        <div class="benchbuddy-menu-column">
          <div class="benchbuddy-menu-heading">Session</div>
          <a class="<?= $currentPage === 'cookie_policy' ? 'active-subnav' : '' ?>" href="cookie_policy.php"><strong class="mega-nav-link-title"><i data-lucide="cookie"></i>Cookie Policy</strong><span>Privacy and cookie details.</span></a>
          <a class="<?= $currentPage === 'logout' ? 'active-subnav' : '' ?>" href="logout.php"><strong class="mega-nav-link-title"><i data-lucide="log-out"></i>Logout</strong><span>End your current session.</span></a>
        </div>
        <?php include __DIR__ . '/mega_menu_search.php'; ?>
      </div>
    </div>
  </div>

  <?php if ($isAdmin): ?>
    <div class="benchbuddy-nav-item">
        <button type="button" class="benchbuddy-nav-trigger">
          <span>Admin</span>
              <svg class="benchbuddy-nav-arrow" viewBox="0 0 20 20" aria-hidden="true">
                  <path d="M5.5 7.5L10 12l4.5-4.5" />
                </svg>

          </button>

      <div class="benchbuddy-mega-menu benchbuddy-full-menu">
          <div class="benchbuddy-menu-grid">
              <div class="benchbuddy-menu-feature">
            <h3>Admin</h3>
            <p>Internal tools for users, platform metrics, features, and requests.</p>
            <a class="benchbuddy-feature-link" href="admin_dashboard.php">Open Admin →</a>
          </div>

          <div class="benchbuddy-menu-column">
            <div class="benchbuddy-menu-heading">Platform</div>
            <a class="<?= $currentPage === 'admin_dashboard' ? 'active-subnav' : '' ?>" href="admin_dashboard.php"><strong class="mega-nav-link-title"><i data-lucide="shield"></i>Admin Dashboard</strong><span>Platform overview and activity.</span></a>
            <a class="<?= $currentPage === 'admin_users' ? 'active-subnav' : '' ?>" href="admin_users.php"><strong class="mega-nav-link-title"><i data-lucide="users"></i>Admin Users</strong><span>Manage customer accounts.</span></a>
            <a class="<?= $currentPage === 'launch_readiness_check' ? 'active-subnav' : '' ?>" href="launch_readiness_check.php"><strong class="mega-nav-link-title"><i data-lucide="heart-pulse"></i>Health Check</strong><span>Diagnostic page for environment.</span></a>

          </div>

          <div class="benchbuddy-menu-column">
            <div class="benchbuddy-menu-heading">Features</div>
            <a class="<?= $currentPage === 'feature_admin' ? 'active-subnav' : '' ?>" href="feature_admin.php"><strong class="mega-nav-link-title"><i data-lucide="lightbulb"></i>Features Admin</strong><span>Manage feature announcements.</span></a>
            <a class="<?= $currentPage === 'admin_feature_requests' ? 'active-subnav' : '' ?>" href="admin_feature_requests.php"><strong class="mega-nav-link-title"><i data-lucide="package-plus"></i>Feature Requests</strong><span>Review customer requests.</span></a>
          </div>

          <div class="benchbuddy-menu-column">
            <div class="benchbuddy-menu-heading">Support</div>
            <a class="<?= $currentPage === 'admin_users' ? 'active-subnav' : '' ?>" href="admin_users.php"><strong class="mega-nav-link-title"><i data-lucide="heart-plus"></i>User Support</strong><span>Find accounts and assist users.</span></a>
            <a class="<?= $currentPage === 'admin_promo_codes' ? 'active-subnav' : '' ?>" href="admin_promo_codes.php"><strong class="mega-nav-link-title"><i data-lucide="tag-plus"></i>Promo Codes</strong><span>Create, edit, and track promo codes for trials, partnerships, and campaigns.</span></a>
          </div>
          <?php include __DIR__ . '/mega_menu_search.php'; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</nav>



<button
  type="button"
  class="benchbuddy-mobile-toggle"
  id="benchbuddyMobileToggle"
  aria-expanded="false"
  aria-label="Open navigation"
>
  <span></span>
  <span></span>
</button>

<div class="benchbuddy-mobile-backdrop" id="benchbuddyMobileBackdrop"></div>
<aside class="benchbuddy-mobile-nav" id="benchbuddyMobileNav" aria-label="Mobile navigation">
  <div class="benchbuddy-mobile-header">
    <div>
      <strong>BenchBuddy</strong>

      <?php if ($userName !== ''): ?>
        <div class="benchbuddy-mobile-user"><?= h($userName) ?></div>
      <?php endif; ?>
    </div>

    <button
      type="button"
      class="benchbuddy-mobile-close"
      id="benchbuddyMobileClose"
      aria-label="Close navigation"
    >
      ×
    </button>
  </div>


  <form action="search.php" method="get" class="mobile-nav-search js-search-autocomplete">
      <?= csrf_field() ?>
      <input
          type="search"
          name="q"
          placeholder="Search BenchBuddy..."
          autocomplete="off"
      >

      <button type="submit"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search-icon lucide-search"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg></button>
  </form>

  <div class="benchbuddy-mobile-sections">
    <a class="dashboard_link <?= $currentPage === 'index' ? 'active-subnav' : '' ?>" href="index.php" class="benchbuddy-mobile-link">Dashboard</a>



    <details>
      <summary>Team</summary>
      <a class="<?= $currentPage === 'team_settings' ? 'active-subnav' : '' ?>" href="team_settings.php"><i data-lucide="settings"></i><span>Team Settings</span></a>
      <a class="<?= $currentPage === 'pitch_rules' ? 'active-subnav' : '' ?>" href="pitch_rules.php"><i data-lucide="activity"></i><span>Pitch Rules</span></a>
      <a class="<?= $currentPage === 'players' ? 'active-subnav' : '' ?>" href="players.php"><i data-lucide="users"></i><span>Players</span></a>
      <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php"><i data-lucide="badge"></i><span>Player Profiles</span></a>
      <a class="<?= $currentPage === 'templates' ? 'active-subnav' : '' ?>" href="lineup_templates.php"><i data-lucide="clipboard-list"></i><span>Lineup Templates</span></a>

      <?php if ($statsVisible): ?>
        <a class="<?= $currentPage === 'player_stats' ? 'active-subnav' : '' ?>" href="player_stats.php"><i data-lucide="bar-chart-3"></i><span>Player Stats</span></a>
      <?php endif; ?>
    </details>
    <details>
      <summary>Game Day</summary>
      <a class="<?= $currentPage === 'games' ? 'active-subnav' : '' ?>" href="games.php"><i data-lucide="calendar-plus"></i><span>Create Games</span></a>
      <a class="<?= $currentPage === 'generate' ? 'active-subnav' : '' ?>" href="generate.php"><i data-lucide="layout-grid"></i><span>Build Lineup</span></a>
      <a class="<?= $currentPage === 'manual_lineup' ? 'active-subnav' : '' ?>" href="manual_lineup.php"><i data-lucide="pencil"></i><span>Manual Lineup</span></a>
      <a class="<?= $currentPage === 'templates' ? 'active-subnav' : '' ?>" href="lineup_templates.php"><i data-lucide="clipboard-list"></i><span>Lineup Templates</span></a>
      <a class="<?= $currentPage === 'lock' ? 'active-subnav' : '' ?>" href="lock.php"><i data-lucide="check-circle"></i><span>Finalize Game</span></a>
      <a class="<?= $currentPage === 'blank_lineup' ? 'active-subnav' : '' ?>" href="print_blank_lineup.php"><i data-lucide="scroll"></i><span>Blank Sheets</span></a>
    </details>

    <details>
      <summary>Post Game</summary>
      <a class="<?= $currentPage === 'lock' ? 'active-subnav' : '' ?>" href="lock.php"><i data-lucide="check-circle"></i><span>Finalize Game</span></a>
      <a class="<?= $currentPage === 'bulk_pitch_counts' ? 'active-subnav' : '' ?>" href="bulk_pitch_counts.php"><i data-lucide="activity"></i><span>Add Pitch Counts</span></a>
      <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php?status=locked"><i data-lucide="trending-up"></i>Game Stats</a>
      <a class="<?= $currentPage === 'player_stats' ? 'active-subnav' : '' ?>" href="player_stats.php"><i data-lucide="bar-chart-3"></i><span>Player Stats</span></a>
      <a class="<?= $currentPage === 'import_stats' ? 'active-subnav' : '' ?>" href="import_game_stats.php"><strong><i data-lucide="import"></i>Import Stats</strong></a>
      <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php"><i data-lucide="history"></i>Game History</a>
      <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php"><i data-lucide="pie-chart"></i>Player Usage</a>
    </details>
    <details>
      <summary>Review</summary>
      <a class="<?= $currentPage === 'history' ? 'active-subnav' : '' ?>" href="history.php"><i data-lucide="history"></i><span>Game History</span></a>
      <a class="<?= $currentPage === 'cancelled' ? 'active-subnav' : '' ?>" href="cancelled_games.php"><i data-lucide="circle-off"></i><span>Cancelled Games</span></a>
      <a class="<?= $currentPage === 'bulk_pitch_counts' ? 'active-subnav' : '' ?>" href="bulk_pitch_counts.php"><i data-lucide="activity"></i><span>Add Pitch Counts</span></a>
      <a class="<?= $currentPage === 'players_profile' ? 'active-subnav' : '' ?>" href="player_profiles.php"><i data-lucide="pie-chart"></i>Player Usage</a>
      <a class="<?= $currentPage === 'player_usage_report' ? 'active-subnav' : '' ?>" href="player_usage_report.php"><i data-lucide="pie-chart"></i>Position Usage</a>

    </details>

    <details>
      <summary>Account</summary>
      <a class="<?= $currentPage === 'games' ? 'active-subnav' : '' ?>" href="account.php"><i data-lucide="user-cog"></i><span>Account Settings</span></a>
      <a class="<?= $currentPage === 'email_preferences' ? 'active-subnav' : '' ?>" href="email_preferences.php"><i data-lucide="mails"></i>Email Preferences</a>
      <?php if ($billingVisible): ?>
        <a href="billing.php"><i data-lucide="credit-card"></i><span>Billing</span></a>
      <?php endif; ?>
      <a class="<?= $currentPage === 'features' ? 'active-subnav' : '' ?>" href="features.php"><i data-lucide="sparkles"></i><span>What’s New</span></a>
      <a class="<?= $currentPage === 'sitemap' ? 'active-subnav' : '' ?>" href="sitemap.php"><i data-lucide="folder-tree"></i><span>Sitemap</span></a>
      <a class="<?= $currentPage === 'coach_dashboard' ? 'active-subnav' : '' ?>" href="coach_dashboard.php"><strong><i data-lucide="layout-list"></i>Coach Dashboard</strong></a>
      <a class="<?= $currentPage === 'cookie_policy' ? 'active-subnav' : '' ?>" href="cookie_policy.php"><i data-lucide="cookie"></i><span>Cookie Policy</span></a>
      <a class="<?= $currentPage === 'logout' ? 'active-subnav' : '' ?>" href="logout.php"><i data-lucide="log-out"></i><span>Logout</span></a>
    </details>

    <?php if ($isAdmin): ?>
      <details>
        <summary>Admin</summary>
        <a class="<?= $currentPage === 'admin_dashboard' ? 'active-subnav' : '' ?>" href="admin_dashboard.php"><i data-lucide="shield"></i><span>Admin Dashboard</span></a>
        <a class="<?= $currentPage === 'admin_users' ? 'active-subnav' : '' ?>" href="admin_users.php"><i data-lucide="users"></i><span>Admin Users</span></a>
        <a class="<?= $currentPage === 'launch_readiness_check' ? 'active-subnav' : '' ?>" href="launch_readiness_check.php"><i data-lucide="heart-pulse"></i>Health Check<span></a>
        <a class="<?= $currentPage === 'admin_users' ? 'active-subnav' : '' ?>" href="feature_admin.php"><i data-lucide="lightbulb"></i><span>Features Admin</span></a>
        <a class="<?= $currentPage === 'feature_admin' ? 'active-subnav' : '' ?>" href="feature_admin.php"><i data-lucide="package-plus"></i><span>Feature Requests</span></a>
        <a class="<?= $currentPage === 'admin_users' ? 'active-subnav' : '' ?>" href="admin_users.php"><i data-lucide="heart-plus"></i>User Support</a>
        <a class="<?= $currentPage === 'admin_promo_codes' ? 'active-subnav' : '' ?>" href="admin_promo_codes.php"><i data-lucide="heart-plus"></i>Promo Codes</a>
      </details>
    <?php endif; ?>
  </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const mobileToggle = document.getElementById('benchbuddyMobileToggle');
  const mobileClose = document.getElementById('benchbuddyMobileClose');
  const mobileBackdrop = document.getElementById('benchbuddyMobileBackdrop');
  const navItems = document.querySelectorAll('.benchbuddy-nav-item');

  function closeAllMenus() {
    navItems.forEach(function (item) {
      item.classList.remove('is-open');

      const trigger = item.querySelector('.benchbuddy-nav-trigger');

      if (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function openMobileMenu() {
    document.body.classList.add('benchbuddy-mobile-open');

    if (mobileToggle) {
      mobileToggle.setAttribute('aria-expanded', 'true');
    }
  }

  function closeMobileMenu() {
    document.body.classList.remove('benchbuddy-mobile-open');

    if (mobileToggle) {
      mobileToggle.setAttribute('aria-expanded', 'false');
    }
  }

  navItems.forEach(function (item) {
    const trigger = item.querySelector('.benchbuddy-nav-trigger');

    if (!trigger) {
      return;
    }

    trigger.setAttribute('aria-expanded', 'false');

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      const isOpen = item.classList.contains('is-open');

      closeAllMenus();

      if (!isOpen) {
        item.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });
  });

  document.addEventListener('click', function (event) {
    const openItem = event.target.closest('.benchbuddy-nav-item.is-open');

    if (!openItem) {
      closeAllMenus();
      return;
    }

    const clickedTrigger = event.target.closest('.benchbuddy-nav-trigger');
    const clickedLink = event.target.closest('a');
    const clickedSearch = event.target.closest('.js-search-autocomplete');

    if (clickedTrigger || clickedSearch) {
      return;
    }

    if (clickedLink) {
      closeAllMenus();
      return;
    }

    closeAllMenus();
  });

  if (mobileToggle) {
    mobileToggle.addEventListener('click', function (event) {
      event.preventDefault();
      openMobileMenu();
    });
  }

  if (mobileClose) {
    mobileClose.addEventListener('click', function (event) {
      event.preventDefault();
      closeMobileMenu();
    });
  }

  if (mobileBackdrop) {
    mobileBackdrop.addEventListener('click', closeMobileMenu);
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAllMenus();
      closeMobileMenu();
    }
  });
});


document.addEventListener('DOMContentLoaded', function () {
  const forms = document.querySelectorAll('.js-search-autocomplete');

  forms.forEach(function (form) {
    const input = form.querySelector('input[name="q"]');
    const box = form.querySelector('.mega-search-suggestions');

    if (!input || !box) {
      return;
    }

    let timer = null;
    let activeIndex = -1;

    function closeSuggestions() {
      box.hidden = true;
      box.innerHTML = '';
      activeIndex = -1;
    }

    function renderSuggestions(items) {
      if (!items.length) {
        closeSuggestions();
        return;
      }

      box.innerHTML = items.map(function (item, index) {
        return `
          <a
            class="mega-search-suggestion"
            href="${item.url}"
            data-index="${index}"
          >
            <strong>${item.title}</strong>
            <span>${item.type} · ${item.subtitle}</span>
          </a>
        `;
      }).join('');

      box.hidden = false;
      activeIndex = -1;
    }

    input.addEventListener('input', function () {
      const value = input.value.trim();

      clearTimeout(timer);

      if (value.length < 2) {
        closeSuggestions();
        return;
      }

      timer = setTimeout(function () {
        fetch('/search_suggest.php?q=' + encodeURIComponent(value), {
          credentials: 'same-origin'
        })
          .then(function (response) {
            return response.json();
          })
          .then(renderSuggestions)
          .catch(closeSuggestions);
      }, 180);
    });

    input.addEventListener('keydown', function (event) {
      const links = Array.from(box.querySelectorAll('.mega-search-suggestion'));

      if (box.hidden || !links.length) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex = Math.min(activeIndex + 1, links.length - 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        links[activeIndex].click();
        return;
      } else if (event.key === 'Escape') {
        closeSuggestions();
        return;
      } else {
        return;
      }

      links.forEach(function (link, index) {
        link.classList.toggle('is-active', index === activeIndex);
      });
    });
  });
});

</script>
