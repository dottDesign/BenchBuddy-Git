<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Player Profiles';
$currentPage = 'players_profile';

$error = '';
$players = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    $players = get_all_players($teamId);

    usort($players, function (array $a, array $b): int {
        $nameA = player_full_name($a);
        $nameB = player_full_name($b);
        return strcasecmp($nameA, $nameB);
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Player Profiles</h1>

<div class="actions-row" style="margin-bottom:18px;">
  <a class="btn btn-secondary" href="players.php">Back to Players</a>
</div>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (empty($players)): ?>
  <div class="card">
    <p class="muted">No players found for this team yet.</p>
  </div>
<?php else: ?>
  <div class="profiles-grid">
    <?php foreach ($players as $player): ?>
      <?php
       $fullName = trim((string)(
            $player['name']
            ?? player_full_name($player)
        ));
        $jerseyNumber = trim((string)($player['jersey_number'] ?? ''));
        $pitchingRole = ucfirst((string)($player['pitching_role'] ?? 'none'));
        $catchingRole = ucfirst((string)($player['catching_role'] ?? 'none'));
        $canPlay = $player['can_play'] ?? [];
        $cannotPlay = $player['cannot_play'] ?? [];
      ?>
      <div class="card profile-card">
        <div class="profile-card-header">
          <div>
            <h2 class="profile-name">
              <?php
              $fullName = trim((string)(
                  $player['name']
                  ?? player_full_name($player)
              ));
              ?>
              <?= h($fullName !== '' ? $fullName : 'Unnamed Player') ?>
            </h2>
            <?php if ($jerseyNumber !== ''): ?>
              <div class="profile-subtitle">#<?= h($jerseyNumber) ?></div>
            <?php endif; ?>
          </div>

          <span class="pill <?= !empty($player['active']) ? 'generated' : 'default' ?>">
            <?= !empty($player['active']) ? 'Active' : 'Inactive' ?>
          </span>
        </div>

        <div class="profile-meta-grid">
          <div class="profile-meta-box">
            <div class="profile-meta-label">Pitching</div>
            <div class="profile-meta-value"><?= h($pitchingRole) ?></div>
          </div>

          <div class="profile-meta-box">
            <div class="profile-meta-label">Catching</div>
            <div class="profile-meta-value"><?= h($catchingRole) ?></div>
          </div>
        </div>

        <div class="profile-section">
          <div class="profile-section-label">Can Play</div>
          <?php if (empty($canPlay)): ?>
            <div class="muted">None selected</div>
          <?php else: ?>
            <div class="position-chip-row">
              <?php foreach ($canPlay as $pos): ?>
                <span class="position-chip can"><?= h((string)$pos) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="profile-section">
          <div class="profile-section-label">Cannot Play</div>
          <?php if (empty($cannotPlay)): ?>
            <div class="muted">None selected</div>
          <?php else: ?>
            <div class="position-chip-row">
              <?php foreach ($cannotPlay as $pos): ?>
                <span class="position-chip cannot"><?= h((string)$pos) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="actions-row" style="margin-top:16px;">
          <a class="btn btn-secondary" href="player_profile.php?player_id=<?= (int)$player['id'] ?>">
            View Full Profile
          </a>
          <a class="btn btn-secondary" href="players.php?edit=<?= (int)$player['id'] ?>">
            Edit Player
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<style>
.profiles-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
}

.profile-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.profile-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.profile-name {
  margin: 0;
  font-size: 22px;
  line-height: 1.2;
}

.profile-subtitle {
  margin-top: 4px;
  color: #6b7280;
  font-weight: 600;
}

.profile-meta-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.profile-meta-box {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px;
  background: #fafafa;
}

.profile-meta-label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #6b7280;
  margin-bottom: 6px;
}

.profile-meta-value {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}

.profile-section {
  display: grid;
  gap: 8px;
}

.profile-section-label {
  font-size: 14px;
  font-weight: 700;
  color: #374151;
}

.position-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.position-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  padding: 8px 12px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
  border: 1px solid #d1d5db;
}

.position-chip.can {
  background: #ecfdf5;
  border-color: #10b981;
  color: #065f46;
}

.position-chip.cannot {
  background: #fef2f2;
  border-color: #ef4444;
  color: #991b1b;
}

@media (max-width: 640px) {
  .profile-meta-grid {
    grid-template-columns: 1fr;
  }

  .profile-card-header {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
