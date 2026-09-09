<?php
declare(strict_types=1);
$pageTitle = "About BenchBuddy";
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . "/includes/header.php";

$lineupRules = function_exists("benchbuddy_lineup_rules")? benchbuddy_lineup_rules():[];
$ageGroupRules = function_exists("benchbuddy_age_group_rules")? benchbuddy_age_group_rules():[];
?>

<h1 class="page-title brand-title-font brand-title-font">About BenchBuddy</h1>

<div class="card" style="max-width:800px;">
  <p>
    BenchBuddy is a lineup management tool designed to help coaches build fair, balanced,
    and rule-compliant baseball and softball lineups.
  </p>

  <p>
    It simplifies roster management, automates position rotation, and ensures players
    get equal opportunities while respecting pitching and positional constraints.
  </p>

  <p>
    Whether you're coaching youth teams or managing competitive rosters,
    BenchBuddy helps you focus on the game instead of the logistics.
  </p>
</div>

<div class="card" style="margin-bottom:16px;">
  <h2>How BenchBuddy Builds Lineups</h2>
  <p class="muted" style="margin-bottom:16px;">
    BenchBuddy builds lineups inning by inning using roster size, player eligibility, pitching and catching roles,
    fairness balancing, and defensive assignment rules.
  </p>

  <div class="about-rule-grid">
    <?php foreach ($lineupRules as $rule): ?>
      <div class="about-rule-card">
        <h3><?= h((string) $rule["title"]) ?></h3>
        <p><?= h((string) $rule["text"]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-bottom:16px;">
  <h2>Common Youth Baseball Rules by Age Group</h2>
  <p class="muted" style="margin-bottom:16px;">
    These are common guidelines and coaching expectations by age group. Local league and tournament rules may vary.
  </p>

  <div class="about-age-grid">
    <?php foreach ($ageGroupRules as $group): ?>
      <div class="about-age-card">
        <h3><?= h((string) $group["age_group"]) ?></h3>

        <div class="about-age-row">
          <strong>Typical Game Length:</strong>
          <span><?= h((string) $group["innings"]) ?></span>
        </div>

        <div class="about-age-row">
          <strong>Defense:</strong>
          <span><?= h((string) $group["defense"]) ?></span>
        </div>

        <div class="about-age-row">
          <strong>Pitching:</strong>
          <span><?= h((string) $group["pitching"]) ?></span>
        </div>

        <div class="about-age-row">
          <strong>Main Coaching Focus:</strong>
          <span><?= h((string) $group["focus"]) ?></span>
        </div>

        <div class="about-age-row">
          <strong>How BenchBuddy Helps:</strong>
          <span><?= h((string) $group["benchbuddy"]) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Important Note</h2>
  <p>
    BenchBuddy helps coaches organize fair and practical lineups, but it does not replace official league rules.
    Always follow your local league, tournament, and safety requirements for player eligibility, pitch counts,
    innings limits, and defensive rules.
  </p>
</div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
