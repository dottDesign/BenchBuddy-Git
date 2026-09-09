<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';


$teamId = current_team_id();
$pageTitle = 'Dashboard';
$currentPage = 'index';
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';
$error = '';
$stats = [
    'players_total' => 0,
    'players_active' => 0,
    'games_total' => 0,
    'games_draft' => 0,
    'games_generated' => 0,
    'games_locked' => 0,
    'games_cancelled' => 0,
];

$recentGames = [];
$seasonPitchTotals = [];
$nextDraftGame = null;
$nextGeneratedGame = null;
$seasonAlerts = [];
$firstGameNeedingPitchLogId = null;
$message = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$seasonBenchTotals = [];
$benchFairness = [
    'least_benched' => null,
    'most_benched' => null,
    'average_bench_times' => 0,
];

$hasActiveTeam = $teamId > 0;

if (!$hasActiveTeam) {
    $error = 'No team selected for this account.';
} else {
    try {
        $firstGameNeedingPitchLogId = get_first_game_needing_pitch_log($teamId);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Pitch log shortcut failed: ' . $e->getMessage();
        }
    }

    try {
        $stats = get_dashboard_stats($teamId);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Dashboard stats failed: ' . $e->getMessage();
        }
    }

    try {
        $recentGames = get_recent_games_dashboard($teamId, 10);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Recent games failed: ' . $e->getMessage();
        }
    }

    try {
        $seasonPitchTotals = get_season_pitch_totals_dashboard($teamId, 10);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Pitch totals failed: ' . $e->getMessage();
        }
    }

    try {
        $nextDraftGame = get_next_game_by_status($teamId, 'draft');
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Next draft failed: ' . $e->getMessage();
        }
    }

    try {
        $nextGeneratedGame = get_next_game_by_status($teamId, 'generated');
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Next generated failed: ' . $e->getMessage();
        }
    }

    try {
        $seasonBenchTotals = get_season_bench_totals_dashboard($teamId, 25);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Bench totals failed: ' . $e->getMessage();
        }
    }

    try {
        $seasonAlerts = get_season_alerts_dashboard($teamId);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Season alerts failed: ' . $e->getMessage();
        }
    }

    try {
        $benchFairness = get_bench_fairness_summary_dashboard($teamId);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Bench fairness failed: ' . $e->getMessage();
        }
    }
}

?>


<?php if (is_logged_in()): ?>

<h1 class="page-title brand-title-font">Dashboard</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$hasActiveTeam): ?>
  <div class="card">
    <h2>Get Started</h2>
    <p class="muted">
      Your account does not currently have an active team selected.
    </p>

    <div class="actions-row">
      <a class="btn" href="account.php">Open Account</a>
    </div>

    <p class="muted" style="margin-top:12px;">
      From Account, you can switch teams or confirm your current team setup.
    </p>
  </div>
<?php else: ?>

<div class="card" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff;">
  <h2 style="margin-top:0;">Game lineup control center</h2>
  <p style="max-width:760px; line-height:1.5; color:#e8efff;">
    Manage players, build batting orders, build inning-by-inning defensive lineups,
    finalize completed games, and review archived history from one place.
  </p>

  <div class="actions-row" style="margin-top:18px;">
    <a class="btn" href="games.php" style="background:#fff; color:#374151;">Create or edit games</a>
    <a class="btn btn-secondary" href="generate.php">Build Lineup</a>
    <a class="btn btn-secondary" href="lock.php">Finalize Game</a>
  </div>
</div>

<div class="meta" style="margin-bottom:24px;">
  <div class="meta-box">
    <div class="meta-label">Players</div>
    <div class="meta-value"><?= (int)$stats['players_total'] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Active Players</div>
    <div class="meta-value"><?= (int)$stats['players_active'] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Draft Games</div>
    <div class="meta-value"><?= (int)$stats['games_draft'] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Built Lineups</div>
    <div class="meta-value"><?= (int)$stats['games_generated'] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Finalized Games</div>
    <div class="meta-value"><?= (int)$stats['games_locked'] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Cancelled Games</div>
    <div class="meta-value"><?= (int)($stats['games_cancelled'] ?? 0) ?></div>
  </div>
</div>

<div class="dash-grid">
  <div>
    <div class="card">
      <h2>Season Alerts</h2>

      <?php if (empty($seasonAlerts)): ?>
        <p class="muted">No active alerts right now.</p>
      <?php else: ?>
        <div class="alerts-list">
          <?php foreach ($seasonAlerts as $alert): ?>
            <div class="alert-item alert-<?= h($alert['level']) ?>">
              <div class="alert-item-title"><?= h($alert['title']) ?></div>
              <div class="alert-item-text"><?= h($alert['text']) ?></div>

              <?php if (!empty($alert['action_url']) && !empty($alert['action_label'])): ?>
                <div class="alert-item-actions">
                  <a class="btn" href="<?= h($alert['action_url']) ?>">
                    <?= h($alert['action_label']) ?>
                  </a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Quick Actions</h2>

      <div class="quick-links-grid">
        <a href="players.php" class="quick-link-card">
          <strong>Manage Players</strong>
          <span class="muted">Add players, jersey numbers, and positions.</span>
        </a>

        <a href="games.php" class="quick-link-card">
          <strong>Set Game Roster</strong>
          <span class="muted">Create a game and adjust batting order.</span>
        </a>

        <a href="generate.php" class="quick-link-card">
          <strong>Build Lineup</strong>
          <span class="muted">Run the inning solver for a game.</span>
        </a>

        <a href="lock.php" class="quick-link-card">
          <strong>Finalize Game</strong>
          <span class="muted">Record pitching, pitch counts, and archive.</span>
        </a>

        <a href="history.php" class="quick-link-card">
          <strong>Review History</strong>
          <span class="muted">Browse archived games and summaries.</span>
        </a>
      </div>
    </div>

    <div class="card">
      <h2>Recent Games</h2>

      <?php if (empty($recentGames)): ?>
        <p class="muted">No games yet.</p>
      <?php else: ?>

        <div class="dashboard-game-list">

          <?php foreach ($recentGames as $game): ?>

            <div class="dashboard-game-row">

              <div class="dashboard-game-main">
                <div class="dashboard-game-title">
                  <?= h((string)$game['game_id']) ?>
                </div>

                <div class="dashboard-game-meta">
                  <span class="pill <?= h(status_class((string)$game['status'])) ?>">
                    <?= h((string)$game['status']) ?>
                  </span>

                  <span>
                    <?= !empty($game['home_away'])
                        ? h(ucfirst((string)$game['home_away']))
                        : 'Home/Away not set'
                    ?>
                  </span>

                  <span>
                    <?= (int)$game['roster_size'] ?> players
                  </span>

                  <span>
                    <?= (int)$game['bench_count'] ?> bench
                  </span>
                </div>

                <div class="dashboard-game-dates">
                  Created <?= format_datetime_12h((string)($game['created_at'] ?? '')) ?>

                  <?php if (!empty($game['locked_at'])): ?>
                    • Locked <?= format_datetime_12h((string)$game['locked_at']) ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="dashboard-game-actions">

                <?php if (($game['status'] ?? '') === 'draft'): ?>

                  <a class="btn" href="games.php?edit=<?= (int)$game['id'] ?>">
                    Open
                  </a>

                <?php elseif (($game['status'] ?? '') === 'generated'): ?>

                  <a class="btn" href="manual_lineup.php?game_id=<?= (int)$game['id'] ?>">
                    Edit Lineup
                  </a>

                  <?php elseif (($game['status'] ?? '') === 'locked'): ?>

                     <?php if (team_stats_enabled($teamId)): ?>
                       <a class="btn" href="game_stats.php?game_id=<?= (int)$game['id'] ?>">
                         View Stats
                       </a>
                     <?php else: ?>
                       <a class="btn btn-secondary" href="history.php">
                         View History
                       </a>
                     <?php endif; ?>

                <?php endif; ?>

              </div>

            </div>

          <?php endforeach; ?>

        </div>

      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Bench Fairness</h2>

      <?php
        $least = $benchFairness['least_benched'] ?? null;
        $most = $benchFairness['most_benched'] ?? null;
      ?>

      <?php if (!$least && !$most): ?>
        <p class="muted">No bench data yet.</p>
      <?php else: ?>
        <div class="meta">
          <div class="meta-box">
            <div class="meta-label">Least Benched</div>
            <div class="meta-value">
              <?php if ($least): ?>
              <?= h(format_player_label($least, $labelMode)) ?>
                <div class="muted"><?= (int)$least['total_bench_times'] ?> bench innings</div>
              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </div>

          <div class="meta-box">
            <div class="meta-label">Most Benched</div>
            <div class="meta-value">
              <?php if ($most): ?>
              <?= h(format_player_label($most, $labelMode)) ?>
                <div class="muted"><?= (int)$most['total_bench_times'] ?> bench innings</div>
              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </div>

          <div class="meta-box">
            <div class="meta-label">Average Bench Innings</div>
            <div class="meta-value">
              <?= h((string)$benchFairness['average_bench_times']) ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h2>Next Actions</h2>

      <?php if ($nextDraftGame): ?>
        <div class="next-step-card">
          <div style="font-weight:bold; margin-bottom:6px;">Finish roster setup</div>
          <div class="muted" style="margin-bottom:10px;">
            <?= h((string)$nextDraftGame['game_id']) ?> | Draft | <?= (int)$nextDraftGame['innings'] ?> innings
          </div>
          <a class="btn" href="games.php?edit=<?= (int)$nextDraftGame['id'] ?>">Open game roster</a>
        </div>
      <?php endif; ?>

      <?php if ($nextGeneratedGame): ?>
        <div class="next-step-card">
          <div style="font-weight:bold; margin-bottom:6px;">Finalize built game</div>
          <div class="muted" style="margin-bottom:10px;">
            <?= h((string)$nextGeneratedGame['game_id']) ?> | Built | Roster <?= (int)$nextGeneratedGame['roster_size'] ?>
          </div>
          <a class="btn" href="lock.php?game_id=<?= (int)$nextGeneratedGame['id'] ?>">Finalize this game</a>
        </div>
      <?php endif; ?>

      <?php if (!$nextDraftGame && !$nextGeneratedGame): ?>
        <p class="muted">No pending actions right now.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Season Pitching Leaders</h2>

      <?php if (empty($seasonPitchTotals)): ?>
        <p class="muted">No finalized pitching data yet.</p>
      <?php else: ?>
        <div class="dashboard-card-grid">
          <?php foreach ($seasonPitchTotals as $row): ?>
            <div class="dashboard-player-card">
              <div class="dashboard-player-name">
                <?= h(format_player_label($row, $labelMode)) ?>
              </div>

              <div class="dashboard-stat-row">
                <div>
                  <span>Innings</span>
                  <strong><?= (int)$row['total_innings'] ?></strong>
                </div>

                <div>
                  <span>Pitches</span>
                  <strong>
                    <?php if ((int)$row['total_pitches'] === 0 && $firstGameNeedingPitchLogId): ?>
                      <a class="btn-sm" href="lock.php?game_id=<?= (int)$firstGameNeedingPitchLogId ?>">Add</a>
                    <?php else: ?>
                      <?= (int)$row['total_pitches'] ?>
                    <?php endif; ?>
                  </strong>
                </div>

                <div>
                  <span>Games</span>
                  <strong><?= (int)$row['games_pitched'] ?></strong>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Season Bench Totals</h2>

      <?php if (empty($seasonBenchTotals)): ?>
        <p class="muted">No bench data from finalized games yet.</p>
      <?php else: ?>
        <div class="dashboard-card-grid">
          <?php foreach ($seasonBenchTotals as $row): ?>
            <div class="dashboard-player-card">
              <div class="dashboard-player-name">
                <?= h(format_player_label($row, $labelMode)) ?>
              </div>

              <div class="dashboard-stat-row">
                <div>
                  <span>Benched</span>
                  <strong><?= (int)$row['total_bench_times'] ?></strong>
                </div>

                <div>
                  <span>Games</span>
                  <strong><?= (int)$row['games_benched'] ?></strong>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>
    <?php else: ?>
        <?php if (!is_logged_in()): ?>
            <section class="bb-public-hero">
            <div class="bb-hero-copy">
                <p class="bb-eyebrow">Built for baseball coaches</p>
                <h1>The Dugout’s Smartest Clipboard.</h1>
                <p>
                Build fair inning-by-inning lineups, print clean dugout sheets, manage pitch counts,
                and keep every player rotation organized.
                </p>

                <div class="bb-hero-actions">
                <a href="signup.php" class="btn">Start Free</a>
                <a href="login.php" class="btn btn-secondary">Coach Login</a>
                </div>
            </div>

            <div class="bb-hero-panel">
                <h2>Game Day, Simplified</h2>
                <ul>
                <li>⚾ Smart lineup generator</li>
                <li>🧠 Manual lineup edits</li>
                <li>🖨️ Printable dugout sheets</li>
                <li>📊 Pitch count tracking</li>
                <li>📈 Player fairness insights</li>
                </ul>
                <div class="panel-cta">
                <a href="signup.php" class="btn">Create Your First Lineup</a>
                </div>
            </div>
            </section>

            <section class="bb-category-row">
            <a href="signup.php" class="bb-category-card">
                <strong>Build Lineups</strong>
                <span>Create balanced rotations in seconds.</span>
            </a>

            <a href="signup.php" class="bb-category-card">
                <strong>Print Sheets</strong>
                <span>Lock and print clean game-day cards.</span>
            </a>

            <a href="signup.php" class="bb-category-card">
                <strong>Track Pitching</strong>
                <span>Record innings and pitch counts.</span>
            </a>

            <a href="signup.php" class="bb-category-card">
                <strong>Review History</strong>
                <span>See past games, bench time, and usage.</span>
            </a>
            </section>

            <section class="bb-story-block">
            <div>
                <p class="bb-eyebrow">Why BenchBuddy?</p>
                <h2>Less clipboard chaos. More coaching.</h2>
                <p>
                BenchBuddy helps youth baseball coaches make faster, fairer decisions without
                juggling spreadsheets, paper notes, or last-minute lineup math.
                </p>
                <a href="signup.php" class="btn">Create Your Team</a>
            </div>
            </section>
            <section class="bb-previews">

                <div class="bb-hero-preview">
                  <div class="bb-preview-header">
                    <div>
                      <p class="bb-eyebrow">Live Preview</p>
                      <h2>Game Day, Simplified</h2>
                    </div>
                    <span class="bb-status-dot">Ready</span>
                  </div>

                  <div class="bb-mini-lineup">
                    <div class="bb-mini-row bb-mini-head">
                      <span>POS</span>
                      <span>1st</span>
                      <span>2nd</span>
                      <span>3rd</span>
                    </div>

                    <div class="bb-mini-row">
                      <strong>P</strong>
                      <span>#12 Max</span>
                      <span>#8 Eli</span>
                      <span>#4 Noah</span>
                    </div>

                    <div class="bb-mini-row">
                      <strong>C</strong>
                      <span>#3 Leo</span>
                      <span>#12 Max</span>
                      <span>#8 Eli</span>
                    </div>

                    <div class="bb-mini-row">
                      <strong>1B</strong>
                      <span>#9 Jack</span>
                      <span>#6 Owen</span>
                      <span>#3 Leo</span>
                    </div>

                    <div class="bb-mini-row">
                      <strong>SS</strong>
                      <span>#4 Noah</span>
                      <span>#9 Jack</span>
                      <span>#6 Owen</span>
                    </div>
                  </div>

                  <div class="bb-preview-stats">
                    <div>
                      <strong>92%</strong>
                      <span>Fairness</span>
                    </div>
                    <div>
                      <strong>7</strong>
                      <span>Innings</span>
                    </div>
                    <div>
                      <strong>Print</strong>
                      <span>Ready</span>
                    </div>
                  </div>
                </div>
                <div class="bb-pitch-preview">
                  <div class="bb-pitch-preview-header">
                    <strong>Pitch Count Preview</strong>
                    <span>Rest rules ready</span>
                  </div>

                  <div class="bb-pitch-row">
                    <span>#12 Max</span>
                    <strong>42 pitches</strong>
                    <em>Available tomorrow</em>
                  </div>

                  <div class="bb-pitch-row">
                    <span>#8 Eli</span>
                    <strong>28 pitches</strong>
                    <em>Available now</em>
                  </div>

                  <div class="bb-pitch-row bb-pitch-warning">
                    <span>#4 Noah</span>
                    <strong>63 pitches</strong>
                    <em>Needs rest</em>
                  </div>
                </div>

            </section>

            <section class="bb-rules">
              <div class="bb-container">
                <div class="bb-rules-header">
                  <h2>Built Around Real Baseball Rules</h2>
                  <p>
                    BenchBuddy isn’t guessing. Every lineup follows structured youth baseball guidelines
                    so you can coach with confidence.
                  </p>
                </div>

                <div class="bb-rules-grid">

                  <div class="bb-rule-card">
                    <h3>Pitch Count & Rest</h3>
                    <ul>
                      <li>Tracks pitch counts per outing</li>
                      <li>Automatically enforces rest days</li>
                      <li>Prevents illegal consecutive pitching</li>
                      <li>Flags unavailable pitchers</li>
                    </ul>
                  </div>

                  <div class="bb-rule-card">
                    <h3>Position Safety</h3>
                    <ul>
                      <li>Respects “Can Play” and “Cannot Play”</li>
                      <li>Locks critical positions (Pitcher, Catcher)</li>
                      <li>Prevents unsafe assignments</li>
                      <li>Supports primary and emergency roles</li>
                    </ul>
                  </div>

                  <div class="bb-rule-card">
                    <h3>Fair Play Rotation</h3>
                    <ul>
                      <li>Balances bench time across players</li>
                      <li>Avoids consecutive benching</li>
                      <li>Ensures equal defensive opportunities</li>
                      <li>Optional override for flexibility</li>
                    </ul>
                  </div>

                  <div class="bb-rule-card">
                    <h3>League Awareness</h3>
                    <ul>
                      <li>Supports youth league pitch limits</li>
                      <li>Adjusts for division rules</li>
                      <li>Handles inning-based play tracking</li>
                      <li>Designed for real game conditions</li>
                    </ul>
                  </div>

                </div>

                <div class="bb-age-groups">
                  <strong>Currently Designed for:</strong>
                  <span>🇺🇸 Amateur Athletic Union</span>
                  <span>🇺🇸 American Legion Baseball Baseball</span>
                  <span>🇨🇦 BC Minor Baseball</span>
                  <span>🇺🇸 Babe Ruth League</span>
                  <span>🇺🇸 Cal Ripken Baseball</span>
                  <span>🇨🇦/🇺🇸 Little League Baseball</span>
                  <span>🇺🇸 Perfect Game</span>
                  <span>🇺🇸 USA PONY Baseball</span>
                  <span>🇺🇸 USSSA Baseball</span>


                </div>
              </div>
            </section>

        <?php endif; ?>

    <?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
