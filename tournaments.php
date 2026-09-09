<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tournaments/tournament_functions.php';

require_login();

$pageTitle = 'Tournaments';
$currentPage = 'tournaments';

$teamId = current_team_id();
$error = '';
$tournaments = [];
$ruleSets = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    $ruleSets = get_pitch_rule_sets(true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_tournament') {
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

            $stmt = db()->prepare("
                INSERT INTO tournaments (
                    team_id,
                    name,
                    location,
                    start_date,
                    end_date,
                    rule_set_id,
                    notes,
                    created_at,
                    updated_at
                ) VALUES (
                    :team_id,
                    :name,
                    :location,
                    :start_date,
                    :end_date,
                    :rule_set_id,
                    :notes,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                'team_id' => $teamId,
                'name' => $name,
                'location' => $location !== '' ? $location : null,
                'start_date' => $startDate !== '' ? $startDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'rule_set_id' => $ruleSetId > 0 ? $ruleSetId : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $tournamentId = (int)db()->lastInsertId();

            flash_redirect('ok', 'Tournament created.', 'tournament.php?id=' . $tournamentId);
        }
    }

    $tournaments = get_tournaments_for_team($teamId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Tournaments</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>


<div class="card">
  <h2>Your Tournaments</h2>

  <?php if (empty($tournaments)): ?>
    <p class="muted">No tournaments created yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Tournament</th>
            <th>Dates</th>
            <th>Location</th>
            <th>Games</th>
            <th>Rule Set</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tournaments as $tournament): ?>
            <?php
              $tournamentId = (int)$tournament['id'];
              $gameCount = get_tournament_game_count($teamId, $tournamentId);
            ?>
            <tr>
              <td><?= h((string)$tournament['name']) ?></td>
              <td>
                <?= !empty($tournament['start_date']) ? h((string)$tournament['start_date']) : 'No start' ?>
                <?php if (!empty($tournament['end_date'])): ?>
                  to <?= h((string)$tournament['end_date']) ?>
                <?php endif; ?>
              </td>
              <td><?= h((string)($tournament['location'] ?? '')) ?></td>
              <td><?= (int)$gameCount ?></td>
              <td><?= h((string)($tournament['rule_set_label'] ?? 'Team default')) ?></td>
              <td>
                  <a class="btn btn-secondary" href="tournament.php?id=<?= $tournamentId ?>">
                    Open
                  </a>

                  <a class="btn btn-secondary" href="tournament_edit.php?id=<?= $tournamentId ?>">
                    Edit
                  </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


<div class="card">
  <h2>Create A New Tournament</h2>

  <form method="post" action="tournaments.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_tournament">

    <label for="name">Tournament Name</label>
    <input type="text" id="name" name="name" required>

    <label for="location" style="margin-top:12px;">Location</label>
    <input type="text" id="location" name="location">

    <div class="game-meta-grid" style="margin-top:12px;">
      <div class="form-field">
        <label for="start_date">Start Date</label>
        <input type="date" id="start_date" name="start_date">
      </div>

      <div class="form-field">
        <label for="end_date">End Date</label>
        <input type="date" id="end_date" name="end_date">
      </div>
    </div>

    <label for="rule_set_id" style="margin-top:12px;">Pitch Rule Set</label>
    <select id="rule_set_id" name="rule_set_id">
      <option value="">Use team default</option>
      <?php foreach ($ruleSets as $ruleSet): ?>
        <option value="<?= (int)$ruleSet['id'] ?>">
          <?= h((string)$ruleSet['label']) ?>
          <?php if (!empty($ruleSet['season'])): ?>
            | <?= h((string)$ruleSet['season']) ?>
          <?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="notes" style="margin-top:12px;">Notes</label>
    <textarea id="notes" name="notes" rows="4"></textarea>

    <div class="actions-row" style="margin-top:18px;">
      <button type="submit">Create Tournament</button>
    </div>
  </form>
</div>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
