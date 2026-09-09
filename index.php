<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/functions.php";

$pageTitle = "Dashboard";
$currentPage = "index";
$coachName = "Coach";
$coachFirstName = "Coach";

if (is_logged_in()) {
    $userId = current_user_id();

    $stmt = db()->prepare("
        SELECT *
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "id" => $userId,
    ]);

    $dashboardUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dashboardUser) {
        /*
         * Try the common full-name fields first.
         */
        $coachName = trim((string)(
            $dashboardUser["full_name"]
            ?? $dashboardUser["name"]
            ?? $dashboardUser["display_name"]
            ?? ""
        ));

        /*
         * Otherwise, combine first and last name.
         */
        if ($coachName === "") {
            $firstName = trim((string)($dashboardUser["first_name"] ?? ""));
            $lastName = trim((string)($dashboardUser["last_name"] ?? ""));

            $coachName = trim($firstName . " " . $lastName);
        }

        if ($coachName === "") {
            $coachName = "Coach";
        }

        $nameParts = preg_split('/\s+/', $coachName);
        $coachFirstName = trim((string)($nameParts[0] ?? "Coach"));

        if ($coachFirstName === "") {
            $coachFirstName = "Coach";
        }
    }
}

$teamId = current_team_id();
$labelMode = function_exists('get_team_player_label_mode') && $teamId > 0
    ? get_team_player_label_mode($teamId)
    : 'both';

if (!is_string($labelMode) || trim($labelMode) === '') {
    $labelMode = 'both';
}
require_once __DIR__ . "/includes/header.php";

$error = "";
$stats = [
    "players_total" => 0,
    "players_active" => 0,
    "games_total" => 0,
    "games_draft" => 0,
    "games_generated" => 0,
    "games_locked" => 0,
    "games_cancelled" => 0,
];

$recentGames = [];
$seasonPitchTotals = [];
$nextDraftGame = null;
$nextGeneratedGame = null;
$seasonAlerts = [];
$firstGameNeedingPitchLogId = null;
$message = isset($_GET["msg"]) ? (string) $_GET["msg"] : "";
$seasonBenchTotals = [];
$benchFairness = [
    'lowest_bench_percentage' => null,
    'highest_bench_percentage' => null,
    'average_bench_percentage' => 0.0,
];

$hasActiveTeam = $teamId > 0;

if (!$hasActiveTeam) {
    $error = "No team selected for this account.";
} else {
    try {
        $firstGameNeedingPitchLogId = get_first_game_needing_pitch_log($teamId);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Pitch log shortcut failed: " . $e->getMessage();
        }
    }

    try {
        $stats = get_dashboard_stats($teamId);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Dashboard stats failed: " . $e->getMessage();
        }
    }

    try {
        $recentGames = get_recent_games_dashboard($teamId, 10);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Recent games failed: " . $e->getMessage();
        }
    }

    try {
        $seasonPitchTotals = get_season_pitch_totals_dashboard($teamId, 10);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Pitch totals failed: " . $e->getMessage();
        }
    }

    try {
        $nextDraftGame = get_next_game_by_status($teamId, "draft");
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Next draft failed: " . $e->getMessage();
        }
    }

    try {
        $nextGeneratedGame = get_next_game_by_status($teamId, "generated");
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Next generated failed: " . $e->getMessage();
        }
    }

    try {
        $seasonBenchTotals = get_season_bench_totals_dashboard($teamId, 25);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Bench totals failed: " . $e->getMessage();
        }
    }

    try {
        $seasonAlerts = get_season_alerts_dashboard($teamId);
    } catch (Throwable $e) {
        if ($error === "") {
            $error = "Season alerts failed: " . $e->getMessage();
        }
    }

    try {
        $benchFairness = get_bench_fairness_summary_dashboard($teamId);

        if (!is_array($benchFairness)) {
            $benchFairness = [
                'lowest_bench_percentage' => null,
                'highest_bench_percentage' => null,
                'average_bench_percentage' => 0.0,
            ];
        }

        if (!is_array($benchFairness['lowest_bench_percentage'] ?? null)) {
            $benchFairness['lowest_bench_percentage'] = null;
        }

        if (!is_array($benchFairness['highest_bench_percentage'] ?? null)) {
            $benchFairness['highest_bench_percentage'] = null;
        }
    } catch (Throwable $e) {
        $benchFairness = [
            'lowest_bench_percentage' => null,
            'highest_bench_percentage' => null,
            'average_bench_percentage' => 0.0,
        ];

        if ($error === '') {
            $error = 'Bench fairness failed: ' . $e->getMessage();
        }
    }
}
?>


<?php if (is_logged_in()): ?>

<h1 class="page-title brand-title-font">
    Welcome back, <?= h($coachFirstName) ?>!
</h1>

<?php if ($message !== ""): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ""): ?>
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
    <div class="meta-value"><?= (int) $stats["players_total"] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Active Players</div>
    <div class="meta-value"><?= (int) $stats["players_active"] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Draft Games</div>
    <div class="meta-value"><?= (int) $stats["games_draft"] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Built Lineups</div>
    <div class="meta-value"><?= (int) $stats["games_generated"] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Finalized Games</div>
    <div class="meta-value"><?= (int) $stats["games_locked"] ?></div>
  </div>

  <div class="meta-box">
    <div class="meta-label">Cancelled Games</div>
    <div class="meta-value"><?= (int) ($stats["games_cancelled"] ?? 0) ?></div>
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
            <div class="alert-item alert-<?= h($alert["level"]) ?>">
              <div class="alert-item-title"><?= h($alert["title"]) ?></div>
              <div class="alert-item-text"><?= h($alert["text"]) ?></div>

              <?php if (
                  !empty($alert["action_url"]) &&
                  !empty($alert["action_label"])
              ): ?>
                <div class="alert-item-actions">
                  <a class="btn" href="<?= h($alert["action_url"]) ?>">
                    <?= h($alert["action_label"]) ?>
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
                  <?= h((string) $game["game_id"]) ?>
                </div>

                <div class="dashboard-game-meta">
                  <span class="pill <?= h(
                      status_class((string) $game["status"]),
                  ) ?>">
                    <?= h((string) $game["status"]) ?>
                  </span>

                  <span>
                    <?= !empty($game["home_away"])
                        ? h(ucfirst((string) $game["home_away"]))
                        : "Home/Away not set" ?>
                  </span>

                  <span>
                    <?= (int) $game["roster_size"] ?> players
                  </span>

                  <span>
                    <?= (int) $game["bench_count"] ?> bench
                  </span>
                </div>

                <div class="dashboard-game-dates">
                  Created <?= format_datetime_12h(
                      (string) ($game["created_at"] ?? ""),
                  ) ?>

                  <?php if (!empty($game["locked_at"])): ?>
                    • Locked <?= format_datetime_12h(
                        (string) $game["locked_at"],
                    ) ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="dashboard-game-actions">

                <?php if (($game["status"] ?? "") === "draft"): ?>

                  <a class="btn" href="games.php?edit=<?= (int) $game["id"] ?>">
                    Open
                  </a>

                <?php elseif (($game["status"] ?? "") === "generated"): ?>

                  <a class="btn" href="manual_lineup.php?game_id=<?= (int) $game[
                      "id"
                  ] ?>">
                    Edit Lineup
                  </a>

                  <?php elseif (($game["status"] ?? "") === "locked"): ?>

                     <?php if (team_stats_enabled($teamId)): ?>
                       <a class="btn" href="game_stats.php?game_id=<?= (int) $game[
                           "id"
                       ] ?>">
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
      $lowestBenchPercentage =
          $benchFairness['lowest_bench_percentage'] ?? null;

      $highestBenchPercentage =
          $benchFairness['highest_bench_percentage'] ?? null;

      if (!is_array($lowestBenchPercentage)) {
          $lowestBenchPercentage = null;
      }

      if (!is_array($highestBenchPercentage)) {
          $highestBenchPercentage = null;
      }
      ?>

      <?php if (
          $lowestBenchPercentage === null &&
          $highestBenchPercentage === null
      ): ?>

        <p class="muted">No finalized bench data yet.</p>

      <?php else: ?>

        <div class="meta">

          <div class="meta-box">
            <div class="meta-label">Lowest Bench Rate</div>

            <div class="meta-value">
              <?php if ($lowestBenchPercentage !== null): ?>

                <?= h(format_player_label(
                    $lowestBenchPercentage,
                    $labelMode
                )) ?>

                <div class="muted">
                  <?= h(number_format(
                      (float)($lowestBenchPercentage['bench_percentage'] ?? 0),
                      1
                  )) ?>% benched

                  <?php if (
                      isset($lowestBenchPercentage['total_bench_innings']) &&
                      isset($lowestBenchPercentage['total_eligible_innings'])
                  ): ?>
                    ·
                    <?= (int)$lowestBenchPercentage['total_bench_innings'] ?>
                    of
                    <?= (int)$lowestBenchPercentage['total_eligible_innings'] ?>
                    innings
                  <?php endif; ?>
                </div>

              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </div>

          <div class="meta-box">
            <div class="meta-label">Highest Bench Rate</div>

            <div class="meta-value">
              <?php if ($highestBenchPercentage !== null): ?>

                <?= h(format_player_label(
                    $highestBenchPercentage,
                    $labelMode
                )) ?>

                <div class="muted">
                  <?= h(number_format(
                      (float)($highestBenchPercentage['bench_percentage'] ?? 0),
                      1
                  )) ?>% benched

                  <?php if (
                      isset($highestBenchPercentage['total_bench_innings']) &&
                      isset($highestBenchPercentage['total_eligible_innings'])
                  ): ?>
                    ·
                    <?= (int)$highestBenchPercentage['total_bench_innings'] ?>
                    of
                    <?= (int)$highestBenchPercentage['total_eligible_innings'] ?>
                    innings
                  <?php endif; ?>
                </div>

              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </div>

          <div class="meta-box">
            <div class="meta-label">Team Average Bench Rate</div>

            <div class="meta-value">
              <?= h(number_format(
                  (float)($benchFairness['average_bench_percentage'] ?? 0),
                  1
              )) ?>%
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
            <?= h(
                (string) $nextDraftGame["game_id"],
            ) ?> | Draft | <?= (int) $nextDraftGame["innings"] ?> innings
          </div>
          <a class="btn" href="games.php?edit=<?= (int) $nextDraftGame[
              "id"
          ] ?>">Open game roster</a>
        </div>
      <?php endif; ?>

      <?php if ($nextGeneratedGame): ?>
        <div class="next-step-card">
          <div style="font-weight:bold; margin-bottom:6px;">Finalize built game</div>
          <div class="muted" style="margin-bottom:10px;">
            <?= h(
                (string) $nextGeneratedGame["game_id"],
            ) ?> | Built | Roster <?= (int) $nextGeneratedGame["roster_size"] ?>
          </div>
          <a class="btn" href="lock.php?game_id=<?= (int) $nextGeneratedGame[
              "id"
          ] ?>">Finalize this game</a>
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
                  <strong><?= (int) $row["total_innings"] ?></strong>
                </div>

                <div>
                  <span>Pitches</span>
                  <strong>
                    <?php if (
                        (int) $row["total_pitches"] === 0 &&
                        $firstGameNeedingPitchLogId
                    ): ?>
                      <a class="btn-sm" href="lock.php?game_id=<?= (int) $firstGameNeedingPitchLogId ?>">Add</a>
                    <?php else: ?>
                      <?= (int) $row["total_pitches"] ?>
                    <?php endif; ?>
                  </strong>
                </div>

                <div>
                  <span>Games</span>
                  <strong><?= (int) $row["games_pitched"] ?></strong>
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
                  <strong><?= (int) $row["total_bench_times"] ?></strong>
                </div>

                <div>
                  <span>Games</span>
                  <strong><?= (int) $row["games_benched"] ?></strong>
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

        <section class="bb-hero">
            <div class="bb-hero-content">
                <h1 class="brand-title-font">
                    The Dugout's Smartest Clipboard.
                </h1>
                <p>
                    Build fair lineups, manage pitch counts,
                    track player rotations, and prepare every inning
                    before game day.
                </p>
                <div class="bb-hero-actions">
                    <a href="signup.php" class="btn">
                        Start Free
                    </a>
                    <a href="login.php" class="btn btn-secondary">
                        Coach Login
                    </a>
                </div>
                <div class="bb-hero-proof">
                    ✓ No spreadsheets
                    ✓ No lineup cards
                    ✓ No guesswork
                </div>
            </div>
            <div class="bb-hero-image">
                <img src="assets/images/benchbuddy-dashboard-game-lineup-season-alerts.png" alt="BenchBuddy Dashboard">
            </div>
        </section>
        <section class="bb-proof-bar">
            <span>Currently designed for:</span>
            <span>🇺🇸 Amateur Athletic Union</span>
            <span>🇺🇸 American Legion Baseball</span>
            <span>🇨🇦 BC Minor Baseball</span>
            <span>🇺🇸 Babe Ruth League</span>
            <span>🇺🇸 Cal Ripken Baseball</span>
            <span>🇨🇦/🇺🇸 Little League Baseball</span>
            <span>🇺🇸 Perfect Game</span>
            <span>🇺🇸 USA PONY Baseball</span>
            <span>🇺🇸 USSSA Baseball</span>
        </section>
        <section class="bb-features">
            <div class="bb-features-header">
                <p class="bb-eyebrow">Key BenchBuddy Features</p>
                <h2 class="brand-title-font">Everything you need for game day.</h2>
                <p>
                    Build fair lineups, manage player rotations, track pitch counts and rest requirements, print game-day sheets, and keep every roster, game, and season record organized from one simple dashboard.
                </p>
            </div>
            <div class="bb-features-grid">
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-hammer-icon lucide-hammer">
                            <path d="m15 12-9.373 9.373a1 1 0 0 1-3.001-3L12 9" />
                            <path d="m18 15 4-4" />
                            <path d="m21.5 11.5-1.914-1.914A2 2 0 0 1 19 8.172v-.344a2 2 0 0 0-.586-1.414l-1.657-1.657A6 6 0 0 0 12.516 3H9l1.243 1.243A6 6 0 0 1 12 8.485V10l2 2h1.172a2 2 0 0 1 1.414.586L18.5 14.5" /></svg> Lineup Builder</h3>
                    <p>
                        Generate inning-by-inning defensive lineups automatically.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-network-icon lucide-chart-network">
                            <path d="m13.11 7.664 1.78 2.672" />
                            <path d="m14.162 12.788-3.324 1.424" />
                            <path d="m20 4-6.06 1.515" />
                            <path d="M3 3v16a2 2 0 0 0 2 2h16" />
                            <circle cx="12" cy="6" r="2" />
                            <circle cx="16" cy="12" r="2" />
                            <circle cx="9" cy="15" r="2" /></svg> Pitch Count Tracking</h3>
                    <p>
                        Enforce pitch count limits and rest requirements automatically.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-scale-icon lucide-scale">
                            <path d="M12 3v18" />
                            <path d="m19 8 3 8a5 5 0 0 1-6 0zV7" />
                            <path d="M3 7h1a17 17 0 0 0 8-2 17 17 0 0 0 8 2h1" />
                            <path d="m5 8 3 8a5 5 0 0 1-6 0zV7" />
                            <path d="M7 21h10" /></svg> Bench Fairness</h3>
                    <p>
                        Balance defensive innings and bench time across the entire roster.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-scan-heart-icon lucide-scan-heart">
                            <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                            <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                            <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                            <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                            <path d="M7.828 13.07A3 3 0 0 1 12 8.764a3 3 0 0 1 4.172 4.306l-3.447 3.62a1 1 0 0 1-1.449 0z" /></svg> Position Safety Rules</h3>
                    <p>
                        Respect player position restrictions and eligibility settings.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-printer-icon lucide-printer">
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                            <path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6" />
                            <rect x="6" y="14" width="12" height="8" rx="1" /></svg> Printable Dugout Sheets</h3>
                    <p>
                        Print clean lineup cards and game-day coaching sheets instantly.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clipboard-clock-icon lucide-clipboard-clock">
                            <path d="M16 14v2.2l1.6 1" />
                            <path d="M16 4h2a2 2 0 0 1 2 2v.832" />
                            <path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h2" />
                            <circle cx="16" cy="16" r="6" />
                            <rect x="8" y="2" width="8" height="4" rx="1" /></svg> Game History</h3>
                    <p>
                        Review previous lineups, pitch logs, and completed games anytime.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round-check-icon lucide-user-round-check">
                            <path d="M2 21a8 8 0 0 1 13.292-6" />
                            <circle cx="10" cy="8" r="5" />
                            <path d="m16 19 2 2 4-4" /></svg> Player Availability</h3>
                    <p>
                        Quickly choose who is attending and build rosters for each game.
                    </p>
                </div>
                <div class="bb-feature-card">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-pen-icon lucide-file-pen">
                            <path d="M12.659 22H18a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v9.34" />
                            <path d="M14 2v5a1 1 0 0 0 1 1h5" />
                            <path d="M10.378 12.622a1 1 0 0 1 3 3.003L8.36 20.637a2 2 0 0 1-.854.506l-2.867.837a.5.5 0 0 1-.62-.62l.836-2.869a2 2 0 0 1 .506-.853z" /></svg> Manual Lineup Editing</h3>
                    <p>
                        Fine-tune any generated lineup while keeping your coaching flexibility.
                    </p>
                </div>
            </div>
        </section>
        <section id="how-it-works" class="bb-process-section">
            <div class="bb-process-header">
                <p class="bb-eyebrow">How BenchBuddy Works</p>
                <h2 class="brand-title-font">Your lineup, ready in minutes.</h2>
            </div>
            <div class="bb-process-card">
                <div class="bb-process-image">
                    <video autoplay muted loop playsinline preload="auto">
                        <source src="assets/images/demo.mp4" type="video/mp4">
                    </video>
                </div>
                <div class="bb-process-content">
                    <div class="bb-process-step">
                        <span class="bb-process-number">01</span>
                        <div>
                            <h3>Add Your Players</h3>
                            <p>
                                Create your roster, assign jersey numbers,
                                pitching roles, and position preferences.
                            </p>
                        </div>
                    </div>
                    <div class="bb-process-step">
                        <span class="bb-process-number">02</span>
                        <div>
                            <h3>Create a Game</h3>
                            <p>
                                Select attending players and arrange the batting order.
                            </p>
                        </div>
                    </div>
                    <div class="bb-process-step">
                        <span class="bb-process-number">03</span>
                        <div>
                            <h3>Generate the Lineup</h3>
                            <p>
                                BenchBuddy automatically builds defensive positions,
                                bench rotations, and pitching assignments.
                            </p>
                        </div>
                    </div>
                    <div class="bb-process-step">
                        <span class="bb-process-number">04</span>
                        <div>
                            <h3>Print & Coach</h3>
                            <p>
                                Finalize the lineup, print game sheets, and track
                                pitching history after the game.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section class="bb-showcase">
            <div class="bb-showcase-copy">
                <span>Lineup Generator</span>
                <h2 class="brand-title-font">Every inning planned before the first pitch.</h2>
                <p>BenchBuddy handles the lineup math automatically, balancing positions, bench time, and pitching rules, while giving coaches complete control to fine-tune every inning.</p>
            </div>
            <div class="bb-showcase-card">
                <img src="assets/images/baseball-lineup-warnings-and-innings-positions.png" alt="Lineup Preview">
            </div>
        </section>
        <section class="bb-benefits">
            <div>
                <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-hourglass-icon lucide-hourglass"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg> Save Time</h3>
                <p>
                    Build lineups in seconds instead of 30 minutes.
                </p>
            </div>
            <div>
                <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shelving-unit-icon lucide-shelving-unit"><path d="M12 12V9a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3"/><path d="M16 20v-3a1 1 0 0 0-1-1h-2a1 1 0 0 0-1 1v3"/><path d="M20 22V2"/><path d="M4 12h16"/><path d="M4 20h16"/><path d="M4 2v20"/><path d="M4 4h16"/></svg> Stay Organized</h3>
                <p>
                    Everything lives in one place.
                </p>
            </div>
            <div>
                <h3><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shield-check-icon lucide-shield-check"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg> Coach With Confidence</h3>
                <p>
                    Pitch counts and fairness automatically tracked.
                </p>
            </div>
        </section>
        <section class="bb-testimonials">
            <div class="bb-features-header">
                <p class="bb-eyebrow">Trusted By Coaches</p>
                <h2 class="brand-title-font">What coaches are saying.</h2>
            </div>
            <div class="bb-testimonial-slider">
                <div class="bb-slide active">
                    <blockquote>
                        “BenchBuddy saves me hours every week and makes game day stress-free.”
                    </blockquote>
                    <span>Head Coach, U12 Baseball</span>
                </div>
                <div class="bb-slide">
                    <blockquote>
                        “The lineup generator alone is worth it. Fair rotations in seconds.”
                    </blockquote>
                    <span>Assistant Coach, Little League</span>
                </div>
                <div class="bb-slide">
                    <blockquote>
                        “Pitch count tracking and lineup management in one place is a game changer.”
                    </blockquote>
                    <span>Travel Ball Coach</span>
                </div>
            </div>
        </section>
        <section class="bb-final-cta">
            <h2 class="brand-title-font">Ready for your next game?</h2>
            <p>
                Create your first team and build your first lineup free.
            </p>
            <a href="signup.php" class="btn">
                Start Free
            </a>
        </section>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            const slides = document.querySelectorAll('.bb-slide');

            if (!slides.length) return;

            let current = 0;

            setInterval(function () {

                slides[current].classList.remove('active');

                current++;

                if (current >= slides.length) {
                    current = 0;
                }

                slides[current].classList.add('active');

            }, 5000);

        });
        </script>
        <?php endif; ?>

    <?php endif; ?>
<?php require_once __DIR__ . "/includes/footer.php"; ?>
