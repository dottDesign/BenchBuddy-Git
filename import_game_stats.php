<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Import Game Stats';
$currentPage = 'import_stats';

$teamId = current_team_id();
$error = '';
$message = '';
$games = [];
$previewRows = [];
$selectedGameId = 0;
$selectedPlayerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
$selectedPlayer = null;

if ($selectedPlayerId > 0) {
    $selectedPlayer = get_player_by_id($teamId, $selectedPlayerId);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function normalize_import_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace([' ', '-', '.', '/', '#'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);

    return trim((string)$header, '_');
}

function import_int(array $row, array $keys): int
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return max(0, (int)$row[$key]);
        }
    }

    return 0;
}

function import_float(array $row, array $keys): float
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return max(0, (float)$row[$key]);
        }
    }

    return 0.0;
}

function import_text(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return '';
}

function import_player_display_name(array $player): string
{
    $firstName = trim((string)($player['first_name'] ?? ''));
    $lastName = trim((string)($player['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);

    if ($fullName !== '') {
        return $fullName;
    }

    $name = trim((string)($player['name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return 'Player #' . (int)($player['id'] ?? 0);
}

function normalize_gamechanger_stat_value(mixed $value, bool $decimal = false): string
{
    $value = trim((string)$value);

    if ($value === '' || $value === '-' || strtoupper($value) === 'N/A') {
        return $decimal ? '0.0' : '0';
    }

    if (str_starts_with($value, '.')) {
        $value = '0' . $value;
    }

    if (!is_numeric($value)) {
        return $decimal ? '0.0' : '0';
    }

    return $decimal ? (string)((float)$value) : (string)((int)$value);
}

function convert_gamechanger_rows_to_benchbuddy(array $csvRows): array
{
    /*
        GameChanger format:
        row 0 = section labels like Batting / Pitching / Fielding
        row 1 = actual headers like Number / Last / First / AB / H / RBI
        row 2+ = player data

        We use column positions because GameChanger repeats headers like:
        H, R, BB, SO, HBP, SB, CS, LOB, HR
    */

    $convertedRows = [];

    if (count($csvRows) < 3) {
        return $convertedRows;
    }

    $dataRows = array_slice($csvRows, 2);

    foreach ($dataRows as $row) {
        $row = array_pad($row, 180, '');

        $number = trim((string)($row[0] ?? ''));
        $last = trim((string)($row[1] ?? ''));
        $first = trim((string)($row[2] ?? ''));

        if (
            strtolower($number) === 'totals' ||
            strtolower($number) === 'glossary' ||
            strtolower($last) === 'totals' ||
            strtolower($last) === 'glossary' ||
            strtolower($first) === 'totals' ||
            strtolower($first) === 'glossary'
        ) {
            continue;
        }

        if ($number === '' && $last === '' && $first === '') {
            continue;
        }

        $playerName = trim($first . ' ' . $last);

        $convertedRows[] = [
            'player_name' => $playerName,
            'jersey_number' => $number,

            // Batting section
            'ab' => normalize_gamechanger_stat_value($row[5] ?? 0),
            'r' => normalize_gamechanger_stat_value($row[16] ?? 0),
            'h' => normalize_gamechanger_stat_value($row[10] ?? 0),
            '2b' => normalize_gamechanger_stat_value($row[12] ?? 0),
            '3b' => normalize_gamechanger_stat_value($row[13] ?? 0),
            'hr' => normalize_gamechanger_stat_value($row[14] ?? 0),
            'rbi' => normalize_gamechanger_stat_value($row[15] ?? 0),
            'bb' => normalize_gamechanger_stat_value($row[17] ?? 0),
            'k' => normalize_gamechanger_stat_value($row[18] ?? 0),
            'hbp' => normalize_gamechanger_stat_value($row[20] ?? 0),
            'sf' => normalize_gamechanger_stat_value($row[22] ?? 0),
            'sb' => normalize_gamechanger_stat_value($row[25] ?? 0),

            // Pitching section
            'ip' => normalize_gamechanger_stat_value($row[54] ?? 0, true),
            'pitches' => normalize_gamechanger_stat_value($row[58] ?? 0),
            'hits_allowed' => normalize_gamechanger_stat_value($row[66] ?? 0),
            'runs_allowed' => normalize_gamechanger_stat_value($row[67] ?? 0),
            'er' => normalize_gamechanger_stat_value($row[68] ?? 0),
            'pitching_bb' => normalize_gamechanger_stat_value($row[69] ?? 0),
            'pitching_k' => normalize_gamechanger_stat_value($row[70] ?? 0),
            'w' => normalize_gamechanger_stat_value($row[59] ?? 0),
            'l' => normalize_gamechanger_stat_value($row[60] ?? 0),
            'sv' => normalize_gamechanger_stat_value($row[61] ?? 0),
        ];
    }

    return $convertedRows;
}

function find_import_player(int $teamId, array $row, array $players): ?array
{
    $playerId = import_int($row, ['player_id', 'id']);
    $jersey = import_text($row, ['jersey', 'jersey_number', 'number']);
    $name = strtolower(import_text($row, ['player', 'player_name', 'name']));

    foreach ($players as $player) {
        if ($playerId > 0 && (int)$player['id'] === $playerId) {
            return $player;
        }
    }

    if ($jersey !== '') {
        foreach ($players as $player) {
            if ((string)($player['jersey_number'] ?? '') === $jersey) {
                return $player;
            }
        }
    }

    if ($name !== '') {
        foreach ($players as $player) {
            $playerName = strtolower(player_full_name($player));

            if ($playerName === $name) {
                return $player;
            }
        }
    }

    return null;
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    if (!team_stats_enabled($teamId)) {
        throw new RuntimeException('Stats are not enabled for this team.');
    }

    $games = get_all_games($teamId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'preview_import') {
            $selectedGameId = (int)($_POST['game_db_id'] ?? 0);
            $importType = trim((string)($_POST['import_type'] ?? 'benchbuddy'));

            if ($selectedGameId <= 0) {
                throw new RuntimeException('Please select a game.');
            }

            $game = get_game_by_id($teamId, $selectedGameId);

            if (!$game) {
                throw new RuntimeException('Game not found for this team.');
            }

            if (empty($_FILES['stats_csv']['tmp_name'])) {
                throw new RuntimeException('Please upload a CSV file.');
            }

            $file = $_FILES['stats_csv'];

            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('CSV upload failed.');
            }

            $handle = fopen((string)$file['tmp_name'], 'r');

            if (!$handle) {
                throw new RuntimeException('Could not read uploaded CSV.');
            }

            $csvRows = [];

            while (($csvRow = fgetcsv($handle)) !== false) {
                $csvRows[] = $csvRow;
            }

            fclose($handle);

            if (empty($csvRows)) {
                throw new RuntimeException('CSV file is empty.');
            }

            if ($importType === 'gamechanger') {
                $convertedRows = convert_gamechanger_rows_to_benchbuddy($csvRows);

                if (empty($convertedRows)) {
                    throw new RuntimeException('No GameChanger stat rows were found in the CSV.');
                }

                $headers = array_keys($convertedRows[0]);
                $csvDataRows = [];

                foreach ($convertedRows as $convertedRow) {
                    $csvDataRows[] = array_values($convertedRow);
                }
            } else {
                $headersRaw = $csvRows[0] ?? [];

                if (empty($headersRaw)) {
                    throw new RuntimeException('CSV file is empty.');
                }

                $headers = array_map(
                    fn($header): string => normalize_import_header((string)$header),
                    $headersRaw
                );

                $csvDataRows = array_slice($csvRows, 1);
            }

            if ($selectedPlayerId > 0 && $selectedPlayer) {
                $players = [$selectedPlayer];
            } else {
                $players = get_game_roster($teamId, $selectedGameId);

                if (empty($players)) {
                    $players = get_active_players($teamId);
                }
            }

            $rowNumber = 1;
            $previewRows = [];

            foreach ($csvDataRows as $data) {
                $rowNumber++;

                if (count(array_filter($data, fn($value): bool => trim((string)$value) !== '')) === 0) {
                    continue;
                }

                $row = [];

                foreach ($headers as $index => $header) {
                    $row[$header] = $data[$index] ?? '';
                }

                $player = find_import_player($teamId, $row, $players);

                $previewRows[] = [
                    'row_number' => $rowNumber,
                    'matched' => $player !== null,
                    'player_id' => $player ? (int)$player['id'] : 0,
                    'player_name' => $player ? import_player_display_name($player) : import_text($row, ['player', 'player_name', 'name']),
                    'jersey_number' => $player ? (string)($player['jersey_number'] ?? '') : import_text($row, ['jersey', 'jersey_number', 'number']),

                    'at_bats' => import_int($row, ['ab', 'at_bats']),
                    'runs' => import_int($row, ['r', 'runs']),
                    'hits' => import_int($row, ['h', 'hits']),
                    'doubles_hit' => import_int($row, ['2b', 'doubles', 'doubles_hit']),
                    'triples_hit' => import_int($row, ['3b', 'triples', 'triples_hit']),
                    'home_runs' => import_int($row, ['hr', 'home_runs']),
                    'rbi' => import_int($row, ['rbi']),
                    'walks' => import_int($row, ['bb', 'walks']),
                    'strikeouts' => import_int($row, ['k', 'strikeouts']),
                    'hit_by_pitch' => import_int($row, ['hbp', 'hit_by_pitch']),
                    'sacrifice_flies' => import_int($row, ['sf', 'sacrifice_flies']),
                    'stolen_bases' => import_int($row, ['sb', 'stolen_bases']),

                    'innings_pitched' => import_float($row, ['ip', 'innings_pitched']),
                    'pitches_thrown' => import_int($row, ['pitches', 'pitch_count', 'pitches_thrown']),
                    'hits_allowed' => import_int($row, ['hits_allowed', 'ha']),
                    'runs_allowed' => import_int($row, ['runs_allowed', 'ra']),
                    'earned_runs' => import_int($row, ['er', 'earned_runs']),
                    'pitching_walks' => import_int($row, ['pitching_bb', 'pbb', 'pitcher_walks']),
                    'pitching_strikeouts' => import_int($row, ['pitching_k', 'pk', 'pitcher_strikeouts']),
                    'wins' => import_int($row, ['w', 'wins']),
                    'losses' => import_int($row, ['l', 'losses']),
                    'saves' => import_int($row, ['sv', 'saves']),
                ];
            }

            if (empty($previewRows)) {
                throw new RuntimeException('No stat rows were found in the CSV.');
            }

            $_SESSION['stats_import_preview'] = [
                'team_id' => $teamId,
                'game_db_id' => $selectedGameId,
                'player_id' => $selectedPlayerId,
                'rows' => $previewRows,
            ];
        }

        if ($action === 'confirm_import') {
            $import = $_SESSION['stats_import_preview'] ?? null;

            if (!$import || (int)($import['team_id'] ?? 0) !== $teamId) {
                throw new RuntimeException('Import preview expired. Upload the CSV again.');
            }

            $selectedGameId = (int)$import['game_db_id'];
            $importPlayerId = (int)($import['player_id'] ?? 0);
            $rows = $import['rows'] ?? [];

            $game = get_game_by_id($teamId, $selectedGameId);

            if (!$game) {
                throw new RuntimeException('Game not found for this team.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            try {
                /*
                    Re-import behavior:
                    - Full-game import clears old rows for that game, then rebuilds them.
                    - Single-player import clears only that player's rows for that game.
                    This prevents stale pitch counts/stat rows from remaining after re-import.
                */

                if ($importPlayerId > 0) {
                    $pdo->prepare("
                        DELETE FROM pitch_log
                        WHERE team_id = :team_id
                          AND game_db_id = :game_id
                          AND player_id = :player_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                        'player_id' => $importPlayerId,
                    ]);

                    $pdo->prepare("
                        DELETE FROM player_pitching_stats
                        WHERE team_id = :team_id
                          AND game_id = :game_id
                          AND player_id = :player_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                        'player_id' => $importPlayerId,
                    ]);

                    $pdo->prepare("
                        DELETE FROM player_batting_game_stats
                        WHERE team_id = :team_id
                          AND game_db_id = :game_id
                          AND player_id = :player_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                        'player_id' => $importPlayerId,
                    ]);
                } else {
                    $pdo->prepare("
                        DELETE FROM pitch_log
                        WHERE team_id = :team_id
                          AND game_db_id = :game_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                    ]);

                    $pdo->prepare("
                        DELETE FROM player_pitching_stats
                        WHERE team_id = :team_id
                          AND game_id = :game_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                    ]);

                    $pdo->prepare("
                        DELETE FROM player_batting_game_stats
                        WHERE team_id = :team_id
                          AND game_db_id = :game_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $selectedGameId,
                    ]);
                }

                $battingStmt = $pdo->prepare("
                    INSERT INTO player_batting_game_stats (
                        team_id,
                        game_db_id,
                        player_id,
                        at_bats,
                        runs,
                        hits,
                        doubles_hit,
                        triples_hit,
                        home_runs,
                        rbi,
                        walks,
                        strikeouts,
                        hit_by_pitch,
                        sacrifice_flies,
                        stolen_bases,
                        created_at,
                        updated_at
                    ) VALUES (
                        :team_id,
                        :game_db_id,
                        :player_id,
                        :at_bats,
                        :runs,
                        :hits,
                        :doubles_hit,
                        :triples_hit,
                        :home_runs,
                        :rbi,
                        :walks,
                        :strikeouts,
                        :hit_by_pitch,
                        :sacrifice_flies,
                        :stolen_bases,
                        NOW(),
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        at_bats = VALUES(at_bats),
                        runs = VALUES(runs),
                        hits = VALUES(hits),
                        doubles_hit = VALUES(doubles_hit),
                        triples_hit = VALUES(triples_hit),
                        home_runs = VALUES(home_runs),
                        rbi = VALUES(rbi),
                        walks = VALUES(walks),
                        strikeouts = VALUES(strikeouts),
                        hit_by_pitch = VALUES(hit_by_pitch),
                        sacrifice_flies = VALUES(sacrifice_flies),
                        stolen_bases = VALUES(stolen_bases),
                        updated_at = NOW()
                ");

                $pitchingStmt = $pdo->prepare("
                    INSERT INTO player_pitching_stats (
                        team_id,
                        game_id,
                        player_id,
                        innings_pitched,
                        pitches_thrown,
                        hits_allowed,
                        runs_allowed,
                        earned_runs,
                        walks,
                        strikeouts,
                        wins,
                        losses,
                        saves,
                        created_at,
                        updated_at
                    ) VALUES (
                        :team_id,
                        :game_id,
                        :player_id,
                        :innings_pitched,
                        :pitches_thrown,
                        :hits_allowed,
                        :runs_allowed,
                        :earned_runs,
                        :walks,
                        :strikeouts,
                        :wins,
                        :losses,
                        :saves,
                        NOW(),
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        innings_pitched = VALUES(innings_pitched),
                        pitches_thrown = VALUES(pitches_thrown),
                        hits_allowed = VALUES(hits_allowed),
                        runs_allowed = VALUES(runs_allowed),
                        earned_runs = VALUES(earned_runs),
                        walks = VALUES(walks),
                        strikeouts = VALUES(strikeouts),
                        wins = VALUES(wins),
                        losses = VALUES(losses),
                        saves = VALUES(saves),
                        updated_at = NOW()
                ");

                $pitchLogStmt = $pdo->prepare("
                    INSERT INTO pitch_log (
                        team_id,
                        game_db_id,
                        player_id,
                        innings_pitched,
                        pitches_thrown,
                        created_at
                    ) VALUES (
                        :team_id,
                        :game_db_id,
                        :player_id,
                        :innings_pitched,
                        :pitches_thrown,
                        NOW()
                    )
                ");

                $imported = 0;

                foreach ($rows as $row) {
                    if (empty($row['matched']) || (int)$row['player_id'] <= 0) {
                        continue;
                    }

                    $playerId = (int)$row['player_id'];

                    $battingStmt->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $selectedGameId,
                        'player_id' => $playerId,
                        'at_bats' => (int)$row['at_bats'],
                        'runs' => (int)$row['runs'],
                        'hits' => (int)$row['hits'],
                        'doubles_hit' => (int)$row['doubles_hit'],
                        'triples_hit' => (int)$row['triples_hit'],
                        'home_runs' => (int)$row['home_runs'],
                        'rbi' => (int)$row['rbi'],
                        'walks' => (int)$row['walks'],
                        'strikeouts' => (int)$row['strikeouts'],
                        'hit_by_pitch' => (int)$row['hit_by_pitch'],
                        'sacrifice_flies' => (int)$row['sacrifice_flies'],
                        'stolen_bases' => (int)$row['stolen_bases'],
                    ]);

                    if (
                        (float)$row['innings_pitched'] > 0 ||
                        (int)$row['pitches_thrown'] > 0 ||
                        (int)$row['earned_runs'] > 0 ||
                        (int)$row['pitching_strikeouts'] > 0 ||
                        (int)$row['pitching_walks'] > 0
                    ) {
                        $inningsPitched = round(max(0, (float)$row['innings_pitched']), 1);
                        $pitchesThrown = max(0, (int)$row['pitches_thrown']);

                        $pitchingStmt->execute([
                            'team_id' => $teamId,
                            'game_id' => $selectedGameId,
                            'player_id' => $playerId,
                            'innings_pitched' => $inningsPitched,
                            'pitches_thrown' => $pitchesThrown,
                            'hits_allowed' => (int)$row['hits_allowed'],
                            'runs_allowed' => (int)$row['runs_allowed'],
                            'earned_runs' => (int)$row['earned_runs'],
                            'walks' => (int)$row['pitching_walks'],
                            'strikeouts' => (int)$row['pitching_strikeouts'],
                            'wins' => (int)$row['wins'],
                            'losses' => (int)$row['losses'],
                            'saves' => (int)$row['saves'],
                        ]);

                        if ($inningsPitched > 0 || $pitchesThrown > 0) {
                            $pitchLogStmt->execute([
                                'team_id' => $teamId,
                                'game_db_id' => $selectedGameId,
                                'player_id' => $playerId,
                                'innings_pitched' => $inningsPitched,
                                'pitches_thrown' => $pitchesThrown,
                            ]);
                        }
                    }

                    $imported++;
                }

                sync_game_batting_stats_to_player_batting_stats($teamId, $selectedGameId);

                $pdo->commit();

                unset($_SESSION['stats_import_preview']);

                flash_redirect('ok', 'Imported stats for ' . $imported . ' player(s).', 'player_stats.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Import Game Stats</h1>

<?php if ($selectedPlayer): ?>
  <div class="card">
    <h2>Importing Stats For <?= h(player_full_name($selectedPlayer)) ?></h2>
    <p class="muted">
      Upload one row for this player, or a full CSV. BenchBuddy will only match this player.
    </p>
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Upload CSV</h2>

  <p class="muted">
    Upload a CSV with player names or jersey numbers and game stats. BenchBuddy will match rows to the selected game roster before importing.
  </p>

  <form method="post" action="import_game_stats.php<?= $selectedPlayerId > 0 ? '?player_id=' . (int)$selectedPlayerId : '' ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <label for="import_type">Import Type</label>
    <select name="import_type" id="import_type">
      <option value="gamechanger">GameChanger CSV</option>
      <option value="benchbuddy">BenchBuddy Template CSV</option>

    </select>

    <input type="hidden" name="action" value="preview_import">

    <label for="game_db_id">Game</label>
    <select name="game_db_id" id="game_db_id" required>
      <option value="">-- Select Game --</option>
      <?php foreach ($games as $game): ?>
        <option value="<?= (int)$game['id'] ?>" <?= $selectedGameId === (int)$game['id'] ? 'selected' : '' ?>>
          <?= h((string)$game['game_id']) ?>
          | <?= !empty($game['game_date']) ? h((string)$game['game_date']) : 'No date' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="stats_csv" style="margin-top:14px;">Stats CSV</label>
    <input type="file" id="stats_csv" name="stats_csv" accept=".csv,text/csv" required>

    <div class="actions-row" style="margin-top:18px;">
      <button type="submit">Preview Import</button>
      <a class="btn btn-secondary" href="player_stats.php">Back to Stats</a>
    </div>
  </form>
</div>

<?php if (!empty($previewRows)): ?>
  <div class="card">
    <h2>Preview Import</h2>

    <p class="muted">
      Rows marked unmatched will be skipped. Match by Player ID first, then jersey number, then exact player name.
    </p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Player</th>
            <th>Jersey</th>
            <th>AB</th>
            <th>H</th>
            <th>RBI</th>
            <th>BB</th>
            <th>K</th>
            <th>IP</th>
            <th>Pitches</th>
            <th>ER</th>
            <th>PK</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($previewRows as $row): ?>
            <tr>
              <td>
                <span class="pill <?= !empty($row['matched']) ? 'generated' : 'draft' ?>">
                  <?= !empty($row['matched']) ? 'Matched' : 'Unmatched' ?>
                </span>
              </td>
              <td><?= h((string)$row['player_name']) ?></td>
              <td><?= h((string)$row['jersey_number']) ?></td>
              <td><?= (int)$row['at_bats'] ?></td>
              <td><?= (int)$row['hits'] ?></td>
              <td><?= (int)$row['rbi'] ?></td>
              <td><?= (int)$row['walks'] ?></td>
              <td><?= (int)$row['strikeouts'] ?></td>
              <td><?= h(number_format((float)$row['innings_pitched'], 1)) ?></td>
              <td><?= (int)$row['pitches_thrown'] ?></td>
              <td><?= (int)$row['earned_runs'] ?></td>
              <td><?= (int)$row['pitching_strikeouts'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" action="import_game_stats.php<?= $selectedPlayerId > 0 ? '?player_id=' . (int)$selectedPlayerId : '' ?>" style="margin-top:18px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_import">

      <div class="actions-row">
        <button type="submit" onclick="return confirm('Import these stats? Existing stats for this game/player will be replaced.');">
          Confirm Import
        </button>

        <a class="btn btn-secondary" href="import_game_stats.php<?= $selectedPlayerId > 0 ? '?player_id=' . (int)$selectedPlayerId : '' ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Supported CSV Columns</h2>

  <p class="muted">
    Headers are flexible. BenchBuddy accepts common names like AB, H, RBI, BB, K, IP, Pitches, ER, Pitching K, and Pitching BB.
  </p>

  <pre style="white-space:pre-wrap;">player_name,jersey_number,ab,r,h,2b,3b,hr,rbi,bb,k,hbp,sf,sb,ip,pitches,hits_allowed,runs_allowed,er,pitching_bb,pitching_k,w,l,sv</pre>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
