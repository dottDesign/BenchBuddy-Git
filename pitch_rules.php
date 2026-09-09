<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';


$canManagePitchRules = function_exists('is_admin_user') && is_admin_user();

$pageTitle = 'Admin Pitch Rules';
$currentPage = 'pitch_rules';

$message = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$error = '';

$editRuleSetId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingRuleSet = null;
$editingRestRules = [];

function pitch_rules_distinct_seasons(): array
{
    try {
        $stmt = db()->query("
            SELECT DISTINCT season
            FROM pitch_rule_sets
            WHERE season IS NOT NULL
              AND season <> ''
            ORDER BY season ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');
        if (!$canManagePitchRules) {
            throw new RuntimeException('Only admins can manage pitch rules.');
        }
        if ($action === 'import_pitch_rules') {
            if (empty($_FILES['pitch_rules_file']['tmp_name'])) {
                throw new RuntimeException('Please choose a pitch rules export file.');
            }

            $json = file_get_contents((string)$_FILES['pitch_rules_file']['tmp_name']);
            $data = json_decode((string)$json, true);

            if (!is_array($data) || empty($data['rule_sets']) || !is_array($data['rule_sets'])) {
                throw new RuntimeException('Invalid pitch rules export file.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            try {
                foreach ($data['rule_sets'] as $ruleSet) {
                    $label = trim((string)($ruleSet['label'] ?? ''));
                    $season = strtolower(trim((string)($ruleSet['season'] ?? 'spring')));
                    $country = trim((string)($ruleSet['country'] ?? ''));
                    $programName = trim((string)($ruleSet['program_name'] ?? ''));
                    $divisionLabel = trim((string)($ruleSet['division_label'] ?? ''));
                    $maxPitches = (int)($ruleSet['max_pitches_per_day'] ?? 0);
                    $isActive = (int)($ruleSet['is_active'] ?? 1);

                    if ($label === '' || $season === '' || $country === '' || $programName === '' || $divisionLabel === '' || $maxPitches <= 0) {
                        continue;
                    }

                    $find = $pdo->prepare("
                        SELECT id
                        FROM pitch_rule_sets
                        WHERE label = :label
                          AND season = :season
                          AND country = :country
                          AND program_name = :program_name
                          AND division_label = :division_label
                        LIMIT 1
                    ");

                    $find->execute([
                        'label' => $label,
                        'season' => $season,
                        'country' => $country,
                        'program_name' => $programName,
                        'division_label' => $divisionLabel,
                    ]);

                    $ruleSetId = (int)$find->fetchColumn();

                    if ($ruleSetId > 0) {
                        $update = $pdo->prepare("
                            UPDATE pitch_rule_sets
                            SET season = :season,
                                max_pitches_per_day = :max_pitches_per_day,
                                is_active = :is_active,
                                updated_at = NOW()
                            WHERE id = :id
                            LIMIT 1
                        ");

                        $update->execute([
                            'season' => $season,
                            'max_pitches_per_day' => $maxPitches,
                            'is_active' => $isActive,
                            'id' => $ruleSetId,
                        ]);
                    } else {
                        $insert = $pdo->prepare("
                            INSERT INTO pitch_rule_sets (
                                label,
                                season,
                                country,
                                program_name,
                                division_label,
                                max_pitches_per_day,
                                is_active
                            )
                            VALUES (
                                :label,
                                :season,
                                :country,
                                :program_name,
                                :division_label,
                                :max_pitches_per_day,
                                :is_active
                            )
                        ");

                        $insert->execute([
                            'label' => $label,
                            'season' => $season,
                            'country' => $country,
                            'program_name' => $programName,
                            'division_label' => $divisionLabel,
                            'max_pitches_per_day' => $maxPitches,
                            'is_active' => $isActive,
                        ]);

                        $ruleSetId = (int)$pdo->lastInsertId();
                    }

                    $deleteRules = $pdo->prepare("
                        DELETE FROM pitch_rest_rules
                        WHERE rule_set_id = :rule_set_id
                    ");
                    $deleteRules->execute(['rule_set_id' => $ruleSetId]);

                    $insertRule = $pdo->prepare("
                        INSERT INTO pitch_rest_rules (
                            rule_set_id,
                            min_pitches,
                            max_pitches,
                            rest_days,
                            sort_order
                        )
                        VALUES (
                            :rule_set_id,
                            :min_pitches,
                            :max_pitches,
                            :rest_days,
                            :sort_order
                        )
                    ");

                    foreach (($ruleSet['rest_rules'] ?? []) as $index => $restRule) {
                        $insertRule->execute([
                            'rule_set_id' => $ruleSetId,
                            'min_pitches' => (int)($restRule['min_pitches'] ?? 0),
                            'max_pitches' => isset($restRule['max_pitches']) && $restRule['max_pitches'] !== ''
                                ? (int)$restRule['max_pitches']
                                : null,
                            'rest_days' => (int)($restRule['rest_days'] ?? 0),
                            'sort_order' => (int)($restRule['sort_order'] ?? $index),
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            header('Location: pitch_rules.php?msg=' . urlencode('Pitch rules imported successfully.'));
            exit;
        }
        if ($action === 'save_rule_set') {
            $ruleSetId = (int)($_POST['rule_set_id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $season = strtolower(trim((string)($_POST['season'] ?? 'spring')));
            $country = trim((string)($_POST['country'] ?? ''));
            $programName = trim((string)($_POST['program_name'] ?? ''));
            $divisionLabel = trim((string)($_POST['division_label'] ?? ''));
            $maxPitches = (int)($_POST['max_pitches_per_day'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($label === '') {
                throw new RuntimeException('Rule set label is required.');
            }

            if ($season === '') {
                throw new RuntimeException('Season is required.');
            }

            if ($country === '') {
                throw new RuntimeException('Country is required.');
            }

            if ($programName === '') {
                throw new RuntimeException('Program name is required.');
            }

            if ($divisionLabel === '') {
                throw new RuntimeException('Division is required.');
            }

            if ($maxPitches <= 0) {
                throw new RuntimeException('Daily max pitches must be greater than 0.');
            }

            if ($ruleSetId > 0) {
                $stmt = db()->prepare("
                    UPDATE pitch_rule_sets
                    SET label = :label,
                        season = :season,
                        country = :country,
                        program_name = :program_name,
                        division_label = :division_label,
                        max_pitches_per_day = :max_pitches_per_day,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    'label' => $label,
                    'season' => $season,
                    'country' => $country,
                    'program_name' => $programName,
                    'division_label' => $divisionLabel,
                    'max_pitches_per_day' => $maxPitches,
                    'is_active' => $isActive,
                    'id' => $ruleSetId,
                ]);
            } else {
                $stmt = db()->prepare("
                    INSERT INTO pitch_rule_sets (
                        label,
                        season,
                        country,
                        program_name,
                        division_label,
                        max_pitches_per_day,
                        is_active
                    )
                    VALUES (
                        :label,
                        :season,
                        :country,
                        :program_name,
                        :division_label,
                        :max_pitches_per_day,
                        :is_active
                    )
                ");

                $stmt->execute([
                    'label' => $label,
                    'season' => $season,
                    'country' => $country,
                    'program_name' => $programName,
                    'division_label' => $divisionLabel,
                    'max_pitches_per_day' => $maxPitches,
                    'is_active' => $isActive,
                ]);

                $ruleSetId = (int)db()->lastInsertId();
            }

            header('Location: pitch_rules.php?edit=' . $ruleSetId . '&msg=' . urlencode('Rule set saved.'));
            exit;
        }

        if ($action === 'save_rest_rules') {
            $ruleSetId = (int)($_POST['rule_set_id'] ?? 0);

            if ($ruleSetId <= 0) {
                throw new RuntimeException('Invalid rule set.');
            }

            $minPitches = $_POST['min_pitches'] ?? [];
            $maxPitches = $_POST['max_pitches'] ?? [];
            $restDays = $_POST['rest_days'] ?? [];

            if (!is_array($minPitches) || !is_array($maxPitches) || !is_array($restDays)) {
                throw new RuntimeException('Invalid rest rule data.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            try {
                $delete = $pdo->prepare("
                    DELETE FROM pitch_rest_rules
                    WHERE rule_set_id = :rule_set_id
                ");
                $delete->execute(['rule_set_id' => $ruleSetId]);

                $insert = $pdo->prepare("
                    INSERT INTO pitch_rest_rules (
                        rule_set_id,
                        min_pitches,
                        max_pitches,
                        rest_days,
                        sort_order
                    )
                    VALUES (
                        :rule_set_id,
                        :min_pitches,
                        :max_pitches,
                        :rest_days,
                        :sort_order
                    )
                ");

                foreach ($minPitches as $index => $minValue) {
                    $min = (int)$minValue;
                    $maxRaw = trim((string)($maxPitches[$index] ?? ''));
                    $rest = (int)($restDays[$index] ?? 0);

                    if ($min <= 0) {
                        continue;
                    }

                    $insert->execute([
                        'rule_set_id' => $ruleSetId,
                        'min_pitches' => $min,
                        'max_pitches' => $maxRaw === '' ? null : (int)$maxRaw,
                        'rest_days' => $rest,
                        'sort_order' => $index,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            header('Location: pitch_rules.php?edit=' . $ruleSetId . '&msg=' . urlencode('Rest rules saved.'));
            exit;
        }

        if ($action === 'delete_rule_set') {
            $ruleSetId = (int)($_POST['rule_set_id'] ?? 0);

            if ($ruleSetId <= 0) {
                throw new RuntimeException('Invalid rule set.');
            }

            $stmt = db()->prepare("
                DELETE FROM pitch_rule_sets
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute(['id' => $ruleSetId]);

            header('Location: pitch_rules.php?msg=' . urlencode('Rule set deleted.'));
            exit;
        }
    }

    if ($editRuleSetId > 0) {
        $editingRuleSet = get_pitch_rule_set($editRuleSetId);

        if (!$editingRuleSet) {
            $editRuleSetId = 0;
        } else {
            $editingRestRules = get_pitch_rest_rules($editRuleSetId);
        }
    }

    $ruleSets = get_pitch_rule_sets(false);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $ruleSets = get_pitch_rule_sets(false);
}
if (isset($_GET['export']) && $_GET['export'] === 'pitch_rules') {
    if (!$canManagePitchRules) {
        throw new RuntimeException('Only admins can export pitch rules.');
    }

    $export = [
        'exported_at' => date('c'),
        'rule_sets' => [],
    ];

    foreach (get_pitch_rule_sets(false) as $ruleSet) {
        $ruleSet['rest_rules'] = get_pitch_rest_rules((int)$ruleSet['id']);

        unset($ruleSet['id']);

        foreach ($ruleSet['rest_rules'] as &$restRule) {
            unset($restRule['id'], $restRule['rule_set_id']);
        }

        unset($restRule);

        $export['rule_sets'][] = $ruleSet;
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="benchbuddy-pitch-rules-' . date('Y-m-d') . '.json"');

    echo json_encode($export, JSON_PRETTY_PRINT);
    exit;
}
$seasons = pitch_rules_distinct_seasons();
$countries = get_pitch_rule_distinct_values('country');
$programNames = get_pitch_rule_distinct_values('program_name');
$divisionLabels = get_pitch_rule_distinct_values('division_label');
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Pitch Rules</h1>
<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($canManagePitchRules): ?>
  <div class="card">
    <h2>Import / Export Pitch Rules</h2>

    <div class="stack-actions">
      <a class="btn" href="pitch_rules.php?export=pitch_rules">
        Export Pitch Rules
      </a>

      <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_pitch_rules">

        <label for="pitch_rules_file">Import JSON File</label>
        <input
          type="file"
          id="pitch_rules_file"
          name="pitch_rules_file"
          accept="application/json,.json"
          required
        >

        <button type="submit" class="btn btn-secondary">
          Import Pitch Rules
        </button>
      </form>
    </div>

    <p class="muted" style="margin-top:10px;">
      Export from staging, then import the JSON file on live, or the reverse.
    </p>
  </div>
<?php endif; ?>
<?php if ($canManagePitchRules): ?>
<div class="account-grid">
  <div class="card">
    <h2><?= $editingRuleSet ? 'Edit Rule Set' : 'Add Rule Set' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_rule_set">
      <input type="hidden" name="rule_set_id" value="<?= (int)($editingRuleSet['id'] ?? 0) ?>">

      <label for="label">Display Label</label>
      <input
        type="text"
        id="label"
        name="label"
        required
        value="<?= h((string)($editingRuleSet['label'] ?? '')) ?>"
        placeholder="Little League Ages 11-12"
      >

      <label for="season">Season</label>
      <?php
        $selectedSeason = trim((string)($editingRuleSet['season'] ?? 'spring'));

        if ($selectedSeason === '') {
            $selectedSeason = 'spring';
        }

        $seasonChoices = [
            'spring' => 'Spring',
            'summer' => 'Summer',
            'fall' => 'Fall',
            'winter' => 'Winter',
            'tournament' => 'Tournament',
        ];

        foreach ($seasons as $seasonOption) {
            $seasonOption = trim((string)$seasonOption);

            if ($seasonOption !== '' && !isset($seasonChoices[$seasonOption])) {
                $seasonChoices[$seasonOption] = ucwords(str_replace(['_', '-'], ' ', $seasonOption));
            }
        }
      ?>

      <select id="season" name="season" required>
        <?php foreach ($seasonChoices as $seasonValue => $seasonLabel): ?>
          <option value="<?= h((string)$seasonValue) ?>" <?= $selectedSeason === (string)$seasonValue ? 'selected' : '' ?>>
            <?= h((string)$seasonLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="country">Country</label>
      <input
        type="text"
        id="country"
        name="country"
        list="country_options"
        required
        value="<?= h((string)($editingRuleSet['country'] ?? '')) ?>"
        placeholder="USA"
      >

      <datalist id="country_options">
        <?php foreach ($countries as $country): ?>
          <option value="<?= h((string)$country) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <label for="program_name">League / Program</label>
      <input
        type="text"
        id="program_name"
        name="program_name"
        list="program_name_options"
        required
        value="<?= h((string)($editingRuleSet['program_name'] ?? '')) ?>"
        placeholder="Little League"
      >

      <datalist id="program_name_options">
        <?php foreach ($programNames as $programName): ?>
          <option value="<?= h((string)$programName) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <label for="division_label">Division</label>
      <input
        type="text"
        id="division_label"
        name="division_label"
        list="division_label_options"
        required
        value="<?= h((string)($editingRuleSet['division_label'] ?? '')) ?>"
        placeholder="11-12"
      >

      <datalist id="division_label_options">
        <?php foreach ($divisionLabels as $divisionLabel): ?>
          <option value="<?= h((string)$divisionLabel) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <label for="max_pitches_per_day">Daily Max Pitches</label>
      <input
        type="number"
        id="max_pitches_per_day"
        name="max_pitches_per_day"
        min="1"
        required
        value="<?= h((string)($editingRuleSet['max_pitches_per_day'] ?? '')) ?>"
      >

      <label class="toggle-row" for="is_active_toggle" style="margin-top:14px;">
        <span class="toggle-label-text">Active Rule Set</span>

        <span class="toggle-switch">
          <input
            type="checkbox"
            id="is_active_toggle"
            name="is_active"
            value="1"
            <?= !isset($editingRuleSet['is_active']) || (int)$editingRuleSet['is_active'] === 1 ? 'checked' : '' ?>
          >
          <span class="toggle-slider"></span>
        </span>
      </label>

      <div class="actions-row" style="margin-top:16px;">
        <button type="submit"><?= $editingRuleSet ? 'Save Rule Set' : 'Add Rule Set' ?></button>

        <?php if ($editingRuleSet): ?>
          <a class="btn btn-secondary" href="pitch_rules.php">New Rule Set</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
<?php endif; ?>
  <?php if ($editingRuleSet): ?>
    <div class="card">
      <h2>Rest Rules</h2>
      <p class="muted">
        Leave Max blank for the highest open-ended range.
      </p>

      <form method="post">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_rest_rules">
        <input type="hidden" name="rule_set_id" value="<?= (int)$editingRuleSet['id'] ?>">

        <div id="restRulesList">
          <?php
            $rows = !empty($editingRestRules)
                ? $editingRestRules
                : [
                    ['min_pitches' => 1, 'max_pitches' => 20, 'rest_days' => 0],
                    ['min_pitches' => 21, 'max_pitches' => 35, 'rest_days' => 1],
                    ['min_pitches' => 36, 'max_pitches' => 50, 'rest_days' => 2],
                    ['min_pitches' => 51, 'max_pitches' => 65, 'rest_days' => 3],
                    ['min_pitches' => 66, 'max_pitches' => null, 'rest_days' => 4],
                ];
          ?>

          <?php foreach ($rows as $row): ?>
            <div class="rest-rule-row" style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:10px; align-items:end; margin-bottom:10px;">
              <div>
                <label>Min</label>
                <input type="number" name="min_pitches[]" min="1" value="<?= h((string)$row['min_pitches']) ?>">
              </div>

              <div>
                <label>Max</label>
                <input type="number" name="max_pitches[]" min="1" value="<?= h((string)($row['max_pitches'] ?? '')) ?>">
              </div>

              <div>
                <label>Rest Days</label>
                <input type="number" name="rest_days[]" min="0" value="<?= h((string)$row['rest_days']) ?>">
              </div>

              <button type="button" class="btn btn-secondary" onclick="this.closest('.rest-rule-row').remove()">Remove</button>
            </div>
          <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-secondary" onclick="addRestRuleRow()">Add Rest Range</button>

        <div class="actions-row" style="margin-top:16px;">
          <button type="submit">Save Rest Rules</button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>




<?php
$ruleSetsByProgram = [];
foreach ($ruleSets as $ruleSet) {
    $programName = trim((string)($ruleSet['program_name'] ?? ''));
    if ($programName === '') {
        $programName = 'Other';
    }
    $ruleSetsByProgram[$programName][] = $ruleSet;
}
ksort($ruleSetsByProgram);
?>
<?php if (!empty($ruleSetsByProgram)): ?>
  <div class="card jump-nav-card">
    <h2>Jump to Program</h2>

    <div class="jump-nav-list">
      <?php foreach ($ruleSetsByProgram as $programName => $programRuleSets): ?>
        <?php
          $anchorId = 'program-' . strtolower(
              preg_replace('/[^a-z0-9]+/i', '-', $programName)
          );
        ?>

        <a
          class="jump-nav-link"
          href="#<?= h($anchorId) ?>"
        >
          <?= h((string)$programName) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

  <h2>Rule Sets</h2>

  <?php if (empty($ruleSets)): ?>
    <p class="muted">No pitch rule sets created yet.</p>

  <?php else: ?>

    <?php
    $ruleSetsByProgram = [];

    foreach ($ruleSets as $ruleSet) {
        $programName = trim((string)($ruleSet['program_name'] ?? ''));

        if ($programName === '') {
            $programName = 'Other';
        }

        $ruleSetsByProgram[$programName][] = $ruleSet;
    }

    ksort($ruleSetsByProgram);
    ?>

    <?php foreach ($ruleSetsByProgram as $programName => $programRuleSets): ?>
    <?php
    $anchorId = 'program-' . strtolower(
        preg_replace('/[^a-z0-9]+/i', '-', $programName)
    );
    ?>
    <div class="card">

    <section id="<?= h($anchorId) ?>" class="pitch-program-section">
        <div class="pitch-program-header">
          <div>
            <h2><?= h($programName) ?></h2>

            <p class="muted">
              <?= count($programRuleSets) ?>
              rule set<?= count($programRuleSets) === 1 ? '' : 's' ?>
            </p>
          </div>
        </div>

        <div class="pitch-rule-card-grid">

          <?php foreach ($programRuleSets as $ruleSet): ?>
            <?php $restRules = get_pitch_rest_rules((int)$ruleSet['id']); ?>

            <article class="pitch-rule-card">

              <div class="pitch-rule-card-header">
                <div>
                  <h3><?= h((string)$ruleSet['label']) ?></h3>

                  <p class="muted">
                    <?= h((string)$ruleSet['country']) ?>
                    · Season: <?= h(ucwords(str_replace(['_', '-'], ' ', (string)($ruleSet['season'] ?? 'spring')))) ?>
                    · <?= h((string)$ruleSet['division_label']) ?>
                  </p>
                </div>

                <span class="pill <?= (int)$ruleSet['is_active'] === 1 ? 'generated' : 'default' ?>">
                  <?= (int)$ruleSet['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                </span>
              </div>

              <div class="pitch-rule-max">
                <span>Daily Max</span>

                <strong>
                  <?= (int)$ruleSet['max_pitches_per_day'] ?> pitches
                </strong>
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

              <?php if ($canManagePitchRules): ?>
                <div class="inline-actions" style="margin-top:14px;">
                  <a
                    class="btn-sm"
                    href="pitch_rules.php?edit=<?= (int)$ruleSet['id'] ?>"
                  >
                    Edit
                  </a>

                  <form
                    method="post"
                    onsubmit="return confirm('Delete this rule set? This also deletes its rest rules.');"
                  >
                      <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_rule_set">

                    <input
                      type="hidden"
                      name="rule_set_id"
                      value="<?= (int)$ruleSet['id'] ?>"
                    >

                    <button type="submit" class="btn-sm btn-danger">
                      Delete
                    </button>
                  </form>
                </div>
              <?php endif; ?>

            </article>

          <?php endforeach; ?>

        </div>
      </section>
</div>
    <?php endforeach; ?>

  <?php endif; ?>


<script>
function addRestRuleRow() {
  const list = document.getElementById('restRulesList');

  if (!list) {
    return;
  }

  const row = document.createElement('div');
  row.className = 'rest-rule-row';
  row.style.cssText = 'display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:10px; align-items:end; margin-bottom:10px;';

  row.innerHTML = `
    <div>
      <label>Min</label>
      <input type="number" name="min_pitches[]" min="1" value="">
    </div>

    <div>
      <label>Max</label>
      <input type="number" name="max_pitches[]" min="1" value="">
    </div>

    <div>
      <label>Rest Days</label>
      <input type="number" name="rest_days[]" min="0" value="">
    </div>

    <button type="button" class="btn btn-secondary" onclick="this.closest('.rest-rule-row').remove()">Remove</button>
  `;

  list.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
