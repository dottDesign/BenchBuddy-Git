<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tournaments/tournament_functions.php';

require_login();

$pageTitle = 'Edit Tournament';
$currentPage = 'tournaments';

$teamId = current_team_id();
$tournamentId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

$error = '';
$tournament = null;
$ruleSets = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    if ($tournamentId <= 0) {
        throw new RuntimeException('No tournament selected.');
    }

    $tournament = get_tournament_by_id($teamId, $tournamentId);

    if (!$tournament) {
        throw new RuntimeException('Tournament not found.');
    }

    $ruleSets = get_pitch_rule_sets(true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();

        $name = trim((string)($_POST['name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $ruleSetId = (int)($_POST['rule_set_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Tournament name is required.');
        }

        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            throw new RuntimeException('Start date must use YYYY-MM-DD format.');
        }

        if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new RuntimeException('End date must use YYYY-MM-DD format.');
        }

        if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
            throw new RuntimeException('End date cannot be before start date.');
        }

        $stmt = db()->prepare("
            UPDATE tournaments
            SET
                name = :name,
                location = :location,
                start_date = :start_date,
                end_date = :end_date,
                rule_set_id = :rule_set_id,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
              AND team_id = :team_id
            LIMIT 1
        ");

        $stmt->execute([
            'name' => $name,
            'location' => $location !== '' ? $location : null,
            'start_date' => $startDate !== '' ? $startDate : null,
            'end_date' => $endDate !== '' ? $endDate : null,
            'rule_set_id' => $ruleSetId > 0 ? $ruleSetId : null,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $tournamentId,
            'team_id' => $teamId,
        ]);

        flash_redirect('ok', 'Tournament updated.', 'tournament.php?id=' . $tournamentId);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Edit Tournament</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($tournament): ?>
  <div class="card">
    <h2><?= h((string)$tournament['name']) ?></h2>

    <form method="post" action="tournament_edit.php?id=<?= (int)$tournament['id'] ?>">
      <?= csrf_field() ?>

      <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">

      <label for="name">Tournament Name</label>
      <input
        type="text"
        id="name"
        name="name"
        value="<?= h((string)$tournament['name']) ?>"
        required
      >

      <label for="location" style="margin-top:12px;">Location</label>
      <input
        type="text"
        id="location"
        name="location"
        value="<?= h((string)($tournament['location'] ?? '')) ?>"
      >

      <div class="game-meta-grid" style="margin-top:12px;">
        <div class="form-field">
          <label for="start_date">Start Date</label>
          <input
            type="date"
            id="start_date"
            name="start_date"
            value="<?= h((string)($tournament['start_date'] ?? '')) ?>"
          >
        </div>

        <div class="form-field">
          <label for="end_date">End Date</label>
          <input
            type="date"
            id="end_date"
            name="end_date"
            value="<?= h((string)($tournament['end_date'] ?? '')) ?>"
          >
        </div>
      </div>

      <label for="rule_set_id" style="margin-top:12px;">Pitch Rule Set</label>
      <select id="rule_set_id" name="rule_set_id">
        <option value="">Use team default</option>

        <?php foreach ($ruleSets as $ruleSet): ?>
          <option
            value="<?= (int)$ruleSet['id'] ?>"
            <?= (int)($tournament['rule_set_id'] ?? 0) === (int)$ruleSet['id'] ? 'selected' : '' ?>
          >
            <?= h((string)$ruleSet['label']) ?>
            <?php if (!empty($ruleSet['season'])): ?>
              | <?= h((string)$ruleSet['season']) ?>
            <?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="notes" style="margin-top:12px;">Notes</label>
      <textarea id="notes" name="notes" rows="5"><?= h((string)($tournament['notes'] ?? '')) ?></textarea>

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit">Save Tournament</button>
        <a class="btn btn-secondary" href="tournament.php?id=<?= (int)$tournament['id'] ?>">Cancel</a>
        <a class="btn btn-secondary" href="tournaments.php">Back to Tournaments</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
