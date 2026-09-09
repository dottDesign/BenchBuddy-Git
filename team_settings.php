<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$pageTitle = 'Team Settings';
$currentPage = 'team_settings';

$userId = current_user_id();

$requestedTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

if ($requestedTeamId > 0 && user_belongs_to_team($userId, $requestedTeamId)) {
    $teamId = $requestedTeamId;
    set_current_team($teamId);
    $_SESSION['current_team_id'] = $teamId;
} else {
    $teamId = current_team_id();
}

function load_team_settings_team(int $teamId): ?array
{
    if ($teamId <= 0) {
        return null;
    }
    $stmt = db()->prepare("
        SELECT *
        FROM teams
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $teamId,
    ]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    return $team ?: null;
}

function team_settings_season_options(array $pitchRuleSets): array
{
    $options = [
        'spring' => 'Spring',
        'summer' => 'Summer',
        'fall' => 'Fall',
        'winter' => 'Winter',
        'tournament' => 'Tournament',
    ];

    foreach ($pitchRuleSets as $ruleSet) {
        $season = trim((string)($ruleSet['season'] ?? ''));

        if ($season === '') {
            continue;
        }

        $options[$season] = ucwords(str_replace(['_', '-'], ' ', $season));
    }

    return $options;
}

function team_settings_rule_set_matches_season(array $ruleSet, string $season): bool
{
    $ruleSetSeason = trim((string)($ruleSet['season'] ?? 'spring'));

    if ($ruleSetSeason === '') {
        $ruleSetSeason = 'spring';
    }

    return strtolower($ruleSetSeason) === strtolower($season);
}


$message = '';
$error = '';
$team = null;
$pitchRuleSets = [];
$currentTheme = get_user_theme_color($userId);

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if (!user_belongs_to_team($userId, $teamId)) {
        throw new RuntimeException('You do not have access to this team.');
    }

    $team = load_team_settings_team($teamId);

    if (!$team) {
        throw new RuntimeException('Team not found.');
    }

    $pitchRuleSets = function_exists('get_pitch_rule_sets')
        ? get_pitch_rule_sets(true)
        : [];

    $seasonOptions = team_settings_season_options($pitchRuleSets);
    $currentSeason = trim((string)($team['current_season'] ?? 'spring'));

    if ($currentSeason === '' || !isset($seasonOptions[$currentSeason])) {
        $currentSeason = 'spring';
    }

    $canUsePrintBranding = function_exists('team_can_use_feature')
        && team_can_use_feature($teamId, 'print.custom_branding');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_team_settings') {
            if (!current_user_is_head_coach($teamId)) {
                throw new RuntimeException('Only a Head Coach can update team settings.');
            }

            $name = trim((string)($_POST['team_name'] ?? ''));
            $seasonLabel = trim((string)($_POST['season_label'] ?? ''));
            $currentSeason = trim((string)($_POST['current_season'] ?? 'spring'));
            $pitchRuleSetId = (int)($_POST['pitch_rule_set_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Team name is required.');
            }

            if ($currentSeason === '') {
                throw new RuntimeException('Season is required.');
            }

            if ($pitchRuleSetId <= 0) {
                throw new RuntimeException('Pitch count rules are required.');
            }

            $ruleSet = get_pitch_rule_set($pitchRuleSetId);

            if (!$ruleSet || (int)($ruleSet['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Invalid pitch count rules selected.');
            }

            if (!team_settings_rule_set_matches_season($ruleSet, $currentSeason)) {
                throw new RuntimeException('Selected pitch count rules do not match the selected season.');
            }

            $division = trim((string)($ruleSet['division_label'] ?? ''));

            if ($division === '') {
                throw new RuntimeException('Selected pitch rules are missing a division.');
            }

            $stmt = db()->prepare("
                UPDATE teams
                SET
                    name = :name,
                    season_label = :season_label,
                    current_season = :current_season,
                    division = :division,
                    pitch_rule_set_id = :pitch_rule_set_id
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'name' => $name,
                'season_label' => $seasonLabel !== '' ? $seasonLabel : null,
                'current_season' => $currentSeason,
                'division' => $division,
                'pitch_rule_set_id' => $pitchRuleSetId,
                'team_id' => $teamId,
            ]);

            flash_redirect('ok', 'Team settings updated successfully.', 'team_settings.php?team_id=' . $teamId);
        }

        if ($action === 'update_theme_color') {
            $themeColor = (string)($_POST['theme_color'] ?? 'red');

            $stmt = db()->prepare("
                UPDATE teams
                SET theme_color = :theme_color
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'theme_color' => $themeColor,
                'team_id' => $teamId,
            ]);

            flash_redirect('ok', 'Theme updated successfully.', 'team_settings.php?team_id=' . $teamId);
        }

        if ($action === 'update_print_branding') {
            if (!current_user_is_head_coach($teamId)) {
                throw new RuntimeException('Only a Head Coach can update team branding.');
            }

            $themeColor = (string)($_POST['theme_color'] ?? 'red');
            $stmt = db()->prepare("
                UPDATE teams
                SET theme_color = :theme_color
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'theme_color' => $themeColor,
                'team_id' => $teamId,
            ]);

            if (!$canUsePrintBranding) {
                flash_redirect('ok', 'Theme updated successfully.', 'team_settings.php?team_id=' . $teamId);
            }

            $printBrandName = trim((string)($_POST['print_brand_name'] ?? ''));

            if (mb_strlen($printBrandName) > 150) {
                throw new RuntimeException('Brand name must be 150 characters or less.');
            }

            $currentLogoPath = (string)($team['print_logo_path'] ?? '');
            $newLogoPath = $currentLogoPath;

            if (!empty($_POST['remove_print_logo'])) {
                if ($currentLogoPath !== '') {
                    $oldLogoFile = __DIR__ . '/' . ltrim($currentLogoPath, '/');

                    if (is_file($oldLogoFile)) {
                        @unlink($oldLogoFile);
                    }
                }

                $newLogoPath = null;
            }

            if (!empty($_FILES['print_logo']['name'])) {
                if ((int)($_FILES['print_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Logo upload failed. Please try again.');
                }

                if ((int)($_FILES['print_logo']['size'] ?? 0) > 2 * 1024 * 1024) {
                    throw new RuntimeException('Logo file must be 2MB or smaller.');
                }

                $tmpPath = (string)$_FILES['print_logo']['tmp_name'];
                $imageInfo = @getimagesize($tmpPath);

                if ($imageInfo === false) {
                    throw new RuntimeException('Logo must be a valid image file.');
                }

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $mimeType = (string)($imageInfo['mime'] ?? '');

                if (!isset($allowedTypes[$mimeType])) {
                    throw new RuntimeException('Logo must be a JPG, PNG, or WebP image.');
                }

                $uploadDir = __DIR__ . '/uploads/team-logos';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                if (!is_writable($uploadDir)) {
                    throw new RuntimeException('Logo upload folder is not writable.');
                }

                $extension = $allowedTypes[$mimeType];
                $fileName = 'team-' . $teamId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
                $destination = $uploadDir . '/' . $fileName;

                if (!move_uploaded_file($tmpPath, $destination)) {
                    throw new RuntimeException('Could not save uploaded logo.');
                }

                if ($currentLogoPath !== '') {
                    $oldLogoFile = __DIR__ . '/' . ltrim($currentLogoPath, '/');

                    if (is_file($oldLogoFile)) {
                        @unlink($oldLogoFile);
                    }
                }

                $newLogoPath = 'uploads/team-logos/' . $fileName;
            }

            $stmt = db()->prepare("
                UPDATE teams
                SET
                    print_brand_name = :print_brand_name,
                    print_logo_path = :print_logo_path
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'print_brand_name' => $printBrandName !== '' ? $printBrandName : null,
                'print_logo_path' => $newLogoPath !== '' ? $newLogoPath : null,
                'team_id' => $teamId,
            ]);

            flash_redirect('ok', 'Branding and appearance updated successfully.', 'team_settings.php?team_id=' . $teamId);
        }

        throw new RuntimeException('Invalid team settings action.');
    }

    $team = load_team_settings_team($teamId);
    $currentTheme = (string)($team['theme_color'] ?? 'red');
    $seasonOptions = team_settings_season_options($pitchRuleSets);
    $currentSeason = trim((string)($team['current_season'] ?? 'spring'));

    if ($currentSeason === '' || !isset($seasonOptions[$currentSeason])) {
        $currentSeason = 'spring';
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$seasonOptions = $seasonOptions ?? team_settings_season_options($pitchRuleSets);
$currentSeason = $currentSeason ?? trim((string)($team['current_season'] ?? 'spring'));

if ($currentSeason === '' || !isset($seasonOptions[$currentSeason])) {
    $currentSeason = 'spring';
}

$filteredPitchRuleSets = array_values(array_filter(
    $pitchRuleSets,
    static fn(array $ruleSet): bool => team_settings_rule_set_matches_season($ruleSet, $currentSeason)
));

$currentRuleSetId = (int)($team['pitch_rule_set_id'] ?? 0);
$currentRuleSet = null;

foreach ($pitchRuleSets as $ruleSet) {
    if ((int)$ruleSet['id'] === $currentRuleSetId && team_settings_rule_set_matches_season($ruleSet, $currentSeason)) {
        $currentRuleSet = $ruleSet;
        break;
    }
}

$canUsePrintBranding = $teamId > 0
    && function_exists('team_can_use_feature')
    && team_can_use_feature($teamId, 'print.custom_branding');

$settingsTeams = get_user_teams($userId);

require_once __DIR__ . '/includes/header.php';
?>



<style>
  .team-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
  }

  @media (max-width: 900px) {
    .team-settings-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<h1 class="page-title brand-title-font">Team Settings</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="team-settings-grid">

  <?php if (count($settingsTeams) > 1): ?>
    <div class="card">
      <h2>Switch Team</h2>

      <label for="team_switch_id">Current Team</label>

      <select id="team_switch_id">
        <?php foreach ($settingsTeams as $settingsTeam): ?>
          <option
            value="<?= (int)$settingsTeam['id'] ?>"
            <?= (int)$settingsTeam['id'] === (int)$teamId ? 'selected' : '' ?>
          >
            <?= h((string)$settingsTeam['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <script>
      document.getElementById('team_switch_id')?.addEventListener('change', function () {
        window.location.href = 'team_settings.php?team_id=' + encodeURIComponent(this.value);
      });
      </script>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Team Settings</h2>

    <?php if (!$team): ?>
      <p class="muted">No team loaded.</p>
    <?php elseif (!current_user_is_head_coach($teamId)): ?>
      <p class="muted">Only a Head Coach can update team settings.</p>
    <?php else: ?>
      <form method="post" action="team_settings.php?team_id=<?= (int)$teamId ?>">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_team_settings">

        <label for="team_name">Team Name</label>
        <input
          type="text"
          id="team_name"
          name="team_name"
          required
          maxlength="150"
          value="<?= h((string)($team['name'] ?? '')) ?>"
        >

        <label for="season_label">Season Label</label>
        <input
          type="text"
          id="season_label"
          name="season_label"
          maxlength="50"
          value="<?= h((string)($team['season_label'] ?? '')) ?>"
          placeholder="Example: Spring 2026"
        >

        <label for="current_season">Pitch Rules Season</label>
        <select id="current_season" name="current_season" required>
          <?php foreach ($seasonOptions as $seasonValue => $seasonLabelOption): ?>
            <option
              value="<?= h((string)$seasonValue) ?>"
              <?= $currentSeason === (string)$seasonValue ? 'selected' : '' ?>
            >
              <?= h((string)$seasonLabelOption) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <p class="muted" style="margin-top:6px;">
          Choose which season’s pitch count rules apply to this team right now.
        </p>

        <label for="pitch_rule_set_id">Division / Pitch Rules</label>
        <select id="pitch_rule_set_id" name="pitch_rule_set_id" required>
          <option value="">-- Select Division --</option>

          <?php foreach ($pitchRuleSets as $ruleSet): ?>
            <?php
              $ruleSeason = trim((string)($ruleSet['season'] ?? 'spring'));

              if ($ruleSeason === '') {
                  $ruleSeason = 'spring';
              }
            ?>

            <option
              value="<?= (int)$ruleSet['id'] ?>"
              data-season="<?= h(strtolower($ruleSeason)) ?>"
              <?= $currentRuleSetId === (int)$ruleSet['id'] ? 'selected' : '' ?>
            >
              <?= h((string)$ruleSet['program_name']) ?> ·
              <?= h((string)$ruleSet['season'] ?? 'spring') ?> ·
              <?= h((string)$ruleSet['division_label']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <p id="no_pitch_rules_for_season" class="muted" style="display:none;margin-top:6px;">
          No active pitch rule sets are available for this season. Add one in Pitch Rules first.
        </p>

        <div class="actions-row">
          <button type="submit">Save Team Settings</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Team Info</h2>

    <div class="meta">
      <div class="meta-box">
        <div class="meta-label">Current Team</div>
        <div class="meta-value"><?= h((string)($team['name'] ?? '')) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Season Label</div>
        <div class="meta-value"><?= h((string)($team['season_label'] ?? '')) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Pitch Rules Season</div>
        <div class="meta-value"><?= h((string)($seasonOptions[$currentSeason] ?? $currentSeason)) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Division</div>
        <div class="meta-value">
          <?php if ($currentRuleSet): ?>
            <?= h((string)$currentRuleSet['program_name']) ?>
            ·
            <?= h((string)$currentRuleSet['division_label']) ?>
          <?php else: ?>
            Not set
          <?php endif; ?>
        </div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Team ID</div>
        <div class="meta-value"><?= (int)($team['id'] ?? 0) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Current Pitch Rules</h2>

    <?php if (!$currentRuleSet): ?>
      <p class="muted">No pitch rules selected for this team.</p>
    <?php else: ?>
      <?php $restRules = get_pitch_rest_rules((int)$currentRuleSet['id']); ?>

      <article class="pitch-rule-card">
        <div class="pitch-rule-card-header">
          <div>
            <h3><?= h((string)$currentRuleSet['label']) ?></h3>

            <p class="muted">
              <?= h((string)$currentRuleSet['country']) ?>
              · <?= h((string)($currentRuleSet['season'] ?? 'spring')) ?>
              · <?= h((string)$currentRuleSet['program_name']) ?>
              · <?= h((string)$currentRuleSet['division_label']) ?>
            </p>
          </div>

          <span class="pill generated">Active</span>
        </div>

        <div class="pitch-rule-max">
          <span>Daily Max</span>
          <strong><?= (int)$currentRuleSet['max_pitches_per_day'] ?> pitches</strong>
        </div>

        <div class="pitch-rest-rules">
          <h4>Rest Rules</h4>

          <?php if (empty($restRules)): ?>
            <p class="muted">No rest rules added yet.</p>
          <?php else: ?>
            <div class="rest-rule-pill-list">
              <?php foreach ($restRules as $restRule): ?>
                <?php
                  $max = $restRule['max_pitches'] ?? null;
                  $range = $max === null || $max === ''
                      ? ((int)$restRule['min_pitches']) . '+'
                      : ((int)$restRule['min_pitches']) . '-' . ((int)$max);
                ?>

                <span class="rest-rule-pill">
                  <?= h($range) ?> pitches =
                  <?= (int)$restRule['rest_days'] ?>
                  day<?= (int)$restRule['rest_days'] === 1 ? '' : 's' ?> rest
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Branding & Appearance</h2>

    <p class="muted">
      Customize your BenchBuddy theme and how your team appears on printable game day sheets.
    </p>

    <?php if ($teamId <= 0 || !$team): ?>
      <p class="muted">No active team selected.</p>

    <?php elseif (!current_user_is_head_coach($teamId)): ?>
      <p class="muted">Only a Head Coach can update team branding.</p>

    <?php elseif ($canUsePrintBranding): ?>
      <form method="post" action="team_settings.php?team_id=<?= (int)$teamId ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_print_branding">

        <label for="theme_color">Theme Color</label>
        <select name="theme_color" id="theme_color">
          <?php foreach (get_allowed_theme_colors() as $key => $theme): ?>
            <option value="<?= h($key) ?>" <?= $currentTheme === $key ? 'selected' : '' ?>>
              <?= h($theme['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="print_brand_name">Print Brand Name</label>
        <input
          type="text"
          id="print_brand_name"
          name="print_brand_name"
          maxlength="150"
          value="<?= h((string)($team['print_brand_name'] ?? '')) ?>"
          placeholder="<?= h((string)($team['name'] ?? 'Your Team Name')) ?>"
        >

        <label for="print_logo">Team Logo</label>
        <input
          type="file"
          id="print_logo"
          name="print_logo"
          accept="image/png,image/jpeg,image/webp"
        >

        <div class="actions-row" style="margin-top:14px;">
          <button type="submit">Save Branding & Appearance</button>
        </div>
      </form>

    <?php else: ?>
      <form method="post" action="team_settings.php?team_id=<?= (int)$teamId ?>">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_theme_color">

        <label for="theme_color">Theme Color</label>
        <select name="theme_color" id="theme_color">
          <?php foreach (get_allowed_theme_colors() as $key => $theme): ?>
            <option value="<?= h($key) ?>" <?= $currentTheme === $key ? 'selected' : '' ?>>
              <?= h($theme['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="actions-row" style="margin-top:14px;">
          <button type="submit">Save Theme</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Lineup Templates</h2>

    <a href="lineup_templates.php" class="btn">
      <strong>Edit Lineup Templates</strong>
    </a>

    <p>
      <span class="muted">Manage reusable lineup patterns for future games.</span>
    </p>
  </div>
</div>

<script>
(function () {
  const seasonSelect = document.getElementById('current_season');
  const ruleSelect = document.getElementById('pitch_rule_set_id');
  const emptyMessage = document.getElementById('no_pitch_rules_for_season');

  if (!seasonSelect || !ruleSelect) {
    return;
  }

  function normalize(value) {
    return String(value || '').trim().toLowerCase();
  }

  function filterRuleSets() {
    const selectedSeason = normalize(seasonSelect.value);
    let visibleCount = 0;
    let selectedStillVisible = false;

    Array.from(ruleSelect.options).forEach(function (option) {
      if (option.value === '') {
        option.hidden = false;
        option.disabled = false;
        return;
      }

      const optionSeason = normalize(option.dataset.season || 'spring');
      const visible = optionSeason === selectedSeason;

      option.hidden = !visible;
      option.disabled = !visible;

      if (visible) {
        visibleCount++;
      }

      if (visible && option.selected) {
        selectedStillVisible = true;
      }
    });

    if (!selectedStillVisible) {
      ruleSelect.value = '';
    }

    if (emptyMessage) {
      emptyMessage.style.display = visibleCount === 0 ? 'block' : 'none';
    }
  }

  seasonSelect.addEventListener('change', filterRuleSets);
  filterRuleSets();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
