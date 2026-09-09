<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Import Player Game Log';
$currentPage = 'players';

$teamId = current_team_id();
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;

$error = '';
$player = null;
$previewRows = [];
$existingGames = [];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function normalize_player_game_import_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace([' ', '-', '.', '/', '#'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);

    return trim((string)$header, '_');
}

function player_game_import_text(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return '';
}

function player_game_import_int(array $row, array $keys): int
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return max(0, (int)$row[$key]);
        }
    }

    return 0;
}

function player_game_import_float(array $row, array $keys): float
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return max(0, (float)$row[$key]);
        }
    }

    return 0.0;
}

function find_or_create_player_import_game(
    int $teamId,
    string $gameId,
    string $gameDate,
    string $homeAway
): int {
    $stmt = db()->prepare("
        SELECT id
        FROM games
        WHERE team_id = :team_id
          AND game_id = :game_id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_id' => $gameId,
    ]);

    $existingId = (int)$stmt->fetchColumn();

    if ($existingId > 0) {
        db()->prepare("
            UPDATE games
            SET
                game_date = COALESCE(:game_date, game_date),
                home_away = COALESCE(:home_away, home_away),
                counts_toward_stats = 1
            WHERE id = :id
              AND team_id = :team_id
            LIMIT 1
        ")->execute([
            'game_date' => $gameDate !== '' ? $gameDate : null,
            'home_away' => $homeAway !== '' ? $homeAway : null,
            'id' => $existingId,
            'team_id' => $teamId,
        ]);

        return $existingId;
    }

    $newGameId = create_game($teamId, $gameId, 7);

    db()->prepare("
        UPDATE games
        SET
            game_date = :game_date,
            home_away = :home_away,
            counts_toward_stats = 1
        WHERE id = :id
          AND team_id = :team_id
        LIMIT 1
    ")->execute([
        'game_date' => $gameDate !== '' ? $gameDate : null,
        'home_away' => $homeAway !== '' ? $homeAway : null,
        'id' => $newGameId,
        'team_id' => $teamId,
    ]);

    return (int)$newGameId;
}

function ensure_player_game_import_roster_row(int $teamId, int $gameDbId, int $playerId): void
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM game_roster
        WHERE team_id = :team_id
          AND game_db_id = :game_db_id
          AND player_id = :player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
        'player_id' => $playerId,
    ]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = db()->prepare("
        SELECT COALESCE(MAX(batting_order), 0) + 1
        FROM game_roster
        WHERE team_id = :team_id
          AND game_db_id = :game_db_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
    ]);

    $battingOrder = max(1, (int)$stmt->fetchColumn());

    db()->prepare("
        INSERT INTO game_roster (
            team_id,
            game_db_id,
            player_id,
            batting_order
        ) VALUES (
            :team_id,
            :game_db_id,
            :player_id,
            :batting_order
        )
    ")->execute([
        'team_id' => $teamId,
        'game_db_id' => $gameDbId,
        'player_id' => $playerId,
        'batting_order' => $battingOrder,
    ]);
}

function refresh_player_import_batting_summary(int $teamId, int $playerId): void
{
    $stmt = db()->prepare("
        SELECT
            COUNT(DISTINCT bs.game_db_id) AS games_played,
            COALESCE(SUM(bs.at_bats), 0) AS at_bats,
            COALESCE(SUM(bs.runs), 0) AS runs,
            COALESCE(SUM(bs.hits), 0) AS hits,
            COALESCE(SUM(bs.doubles_hit), 0) AS doubles_hit,
            COALESCE(SUM(bs.triples_hit), 0) AS triples_hit,
            COALESCE(SUM(bs.home_runs), 0) AS home_runs,
            COALESCE(SUM(bs.rbi), 0) AS rbi,
            COALESCE(SUM(bs.walks), 0) AS walks,
            COALESCE(SUM(bs.strikeouts), 0) AS strikeouts,
            COALESCE(SUM(bs.hit_by_pitch), 0) AS hit_by_pitch,
            COALESCE(SUM(bs.sacrifice_flies), 0) AS sacrifice_flies,
            COALESCE(SUM(bs.stolen_bases), 0) AS stolen_bases
        FROM player_batting_game_stats bs
        INNER JOIN games g
            ON g.id = bs.game_db_id
           AND g.team_id = bs.team_id
        WHERE bs.team_id = :team_id
          AND bs.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$summary) {
        return;
    }

    db()->prepare("
        DELETE FROM player_batting_stats
        WHERE team_id = :team_id
          AND player_id = :player_id
    ")->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    db()->prepare("
        INSERT INTO player_batting_stats (
            team_id,
            player_id,
            games_played,
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
            :player_id,
            :games_played,
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
    ")->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
        'games_played' => (int)$summary['games_played'],
        'at_bats' => (int)$summary['at_bats'],
        'runs' => (int)$summary['runs'],
        'hits' => (int)$summary['hits'],
        'doubles_hit' => (int)$summary['doubles_hit'],
        'triples_hit' => (int)$summary['triples_hit'],
        'home_runs' => (int)$summary['home_runs'],
        'rbi' => (int)$summary['rbi'],
        'walks' => (int)$summary['walks'],
        'strikeouts' => (int)$summary['strikeouts'],
        'hit_by_pitch' => (int)$summary['hit_by_pitch'],
        'sacrifice_flies' => (int)$summary['sacrifice_flies'],
        'stolen_bases' => (int)$summary['stolen_bases'],
    ]);
}

function suggested_game_match_id(array $row, array $existingGames): int
{
    $csvGameId = strtolower(trim((string)($row['game_id'] ?? '')));
    $csvGameDate = trim((string)($row['game_date'] ?? ''));

    if ($csvGameId !== '') {
        foreach ($existingGames as $game) {
            if (strtolower(trim((string)($game['game_id'] ?? ''))) === $csvGameId) {
                return (int)$game['id'];
            }
        }
    }

    if ($csvGameDate !== '') {
        foreach ($existingGames as $game) {
            if (trim((string)($game['game_date'] ?? '')) === $csvGameDate) {
                return (int)$game['id'];
            }
        }
    }

    return 0;
}

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected.');
    }

    if ($playerId <= 0) {
        throw new RuntimeException('No player selected.');
    }

    if (!team_stats_enabled($teamId)) {
        throw new RuntimeException('Stats are not enabled for this team.');
    }

    $player = get_player_by_id($teamId, $playerId);

    if (!$player) {
        throw new RuntimeException('Player not found.');
    }

    $existingGames = get_all_games($teamId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'preview_import') {
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

            $headersRaw = fgetcsv($handle);

            if (!$headersRaw) {
                throw new RuntimeException('CSV file is empty.');
            }

            $headers = array_map(
                fn($header): string => normalize_player_game_import_header((string)$header),
                $headersRaw
            );

            $rowNumber = 1;
            $previewRows = [];

            while (($data = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (count(array_filter($data, fn($value): bool => trim((string)$value) !== '')) === 0) {
                    continue;
                }

                $row = [];

                foreach ($headers as $index => $header) {
                    $row[$header] = $data[$index] ?? '';
                }

                $gameId = player_game_import_text($row, ['game_id', 'game']);
                $gameDate = player_game_import_text($row, ['game_date', 'date']);
                $homeAway = strtolower(player_game_import_text($row, ['home_away', 'homeaway']));

                if ($gameId === '') {
                    $gameId = 'Imported Game ' . $rowNumber;
                }

                if ($gameDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)) {
                    throw new RuntimeException('Invalid date on row ' . $rowNumber . '. Use YYYY-MM-DD.');
                }

                if (!in_array($homeAway, ['', 'home', 'away'], true)) {
                    $homeAway = '';
                }

                $previewRows[] = [
                    'row_number' => $rowNumber,
                    'game_id' => $gameId,
                    'game_date' => $gameDate,
                    'home_away' => $homeAway,

                    'at_bats' => player_game_import_int($row, ['ab', 'at_bats']),
                    'runs' => player_game_import_int($row, ['r', 'runs']),
                    'hits' => player_game_import_int($row, ['h', 'hits']),
                    'doubles_hit' => player_game_import_int($row, ['2b', 'doubles', 'doubles_hit']),
                    'triples_hit' => player_game_import_int($row, ['3b', 'triples', 'triples_hit']),
                    'home_runs' => player_game_import_int($row, ['hr', 'home_runs']),
                    'rbi' => player_game_import_int($row, ['rbi']),
                    'walks' => player_game_import_int($row, ['bb', 'walks']),
                    'strikeouts' => player_game_import_int($row, ['k', 'strikeouts']),
                    'hit_by_pitch' => player_game_import_int($row, ['hbp', 'hit_by_pitch']),
                    'sacrifice_flies' => player_game_import_int($row, ['sf', 'sacrifice_flies']),
                    'stolen_bases' => player_game_import_int($row, ['sb', 'stolen_bases']),

                    'innings_pitched' => player_game_import_float($row, ['ip', 'innings_pitched']),
                    'pitches_thrown' => player_game_import_int($row, ['pitches', 'pitch_count', 'pitches_thrown']),
                    'hits_allowed' => player_game_import_int($row, ['hits_allowed', 'ha']),
                    'runs_allowed' => player_game_import_int($row, ['runs_allowed', 'ra']),
                    'earned_runs' => player_game_import_int($row, ['er', 'earned_runs']),
                    'pitching_walks' => player_game_import_int($row, ['pitching_bb', 'pbb', 'pitcher_walks']),
                    'pitching_strikeouts' => player_game_import_int($row, ['pitching_k', 'pk', 'pitcher_strikeouts']),
                    'wins' => player_game_import_int($row, ['w', 'wins']),
                    'losses' => player_game_import_int($row, ['l', 'losses']),
                    'saves' => player_game_import_int($row, ['sv', 'saves']),
                ];
            }

            fclose($handle);

            if (empty($previewRows)) {
                throw new RuntimeException('No stat rows were found.');
            }

            $_SESSION['player_games_import_preview'] = [
                'team_id' => $teamId,
                'player_id' => $playerId,
                'rows' => $previewRows,
            ];
        }

        if ($action === 'confirm_import') {
            $import = $_SESSION['player_games_import_preview'] ?? null;

            if (
                !$import ||
                (int)($import['team_id'] ?? 0) !== $teamId ||
                (int)($import['player_id'] ?? 0) !== $playerId
            ) {
                throw new RuntimeException('Import preview expired. Upload the CSV again.');
            }

            $rows = $import['rows'] ?? [];

            if (!is_array($rows) || empty($rows)) {
                throw new RuntimeException('No rows available to import.');
            }

            $gameMatches = $_POST['game_match'] ?? [];

            if (!is_array($gameMatches)) {
                $gameMatches = [];
            }

            $pdo = db();
            $pdo->beginTransaction();

            try {
                $battingInsert = $pdo->prepare("
                    INSERT INTO player_batting_game_stats (
                        team_id,
                        game_db_id,
                        player_id,
                        games_played,
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
                        1,
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
                ");

                $pitchingInsert = $pdo->prepare("
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
                ");

                $imported = 0;

                foreach ($rows as $index => $row) {
                    $selectedMatch = (string)($gameMatches[$index] ?? 'create');

                    if ($selectedMatch !== 'create' && (int)$selectedMatch > 0) {
                        $gameDbId = (int)$selectedMatch;

                        $matchedGame = get_game_by_id($teamId, $gameDbId);

                        if (!$matchedGame) {
                            throw new RuntimeException('Selected game match was not found.');
                        }

                        db()->prepare("
                            UPDATE games
                            SET counts_toward_stats = 1
                            WHERE id = :id
                              AND team_id = :team_id
                            LIMIT 1
                        ")->execute([
                            'id' => $gameDbId,
                            'team_id' => $teamId,
                        ]);
                    } else {
                        $gameDbId = find_or_create_player_import_game(
                            $teamId,
                            (string)$row['game_id'],
                            (string)$row['game_date'],
                            (string)$row['home_away']
                        );
                    }

                    ensure_player_game_import_roster_row($teamId, $gameDbId, $playerId);

                    $pdo->prepare("
                        DELETE FROM player_batting_game_stats
                        WHERE team_id = :team_id
                          AND game_db_id = :game_db_id
                          AND player_id = :player_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $gameDbId,
                        'player_id' => $playerId,
                    ]);

                    $pdo->prepare("
                        DELETE FROM player_pitching_stats
                        WHERE team_id = :team_id
                          AND game_id = :game_id
                          AND player_id = :player_id
                    ")->execute([
                        'team_id' => $teamId,
                        'game_id' => $gameDbId,
                        'player_id' => $playerId,
                    ]);

                    $battingInsert->execute([
                        'team_id' => $teamId,
                        'game_db_id' => $gameDbId,
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
                        (int)$row['pitching_walks'] > 0 ||
                        (int)$row['hits_allowed'] > 0 ||
                        (int)$row['runs_allowed'] > 0
                    ) {
                        $pitchingInsert->execute([
                            'team_id' => $teamId,
                            'game_id' => $gameDbId,
                            'player_id' => $playerId,
                            'innings_pitched' => (float)$row['innings_pitched'],
                            'pitches_thrown' => (int)$row['pitches_thrown'],
                            'hits_allowed' => (int)$row['hits_allowed'],
                            'runs_allowed' => (int)$row['runs_allowed'],
                            'earned_runs' => (int)$row['earned_runs'],
                            'walks' => (int)$row['pitching_walks'],
                            'strikeouts' => (int)$row['pitching_strikeouts'],
                            'wins' => (int)$row['wins'],
                            'losses' => (int)$row['losses'],
                            'saves' => (int)$row['saves'],
                        ]);
                    }

                    $imported++;
                }

                refresh_player_import_batting_summary($teamId, $playerId);

                $pdo->commit();
                unset($_SESSION['player_games_import_preview']);

                flash_redirect(
                    'ok',
                    'Imported ' . $imported . ' game stat row(s).',
                    'player_profile.php?player_id=' . $playerId
                );
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

<h1 class="page-title brand-title-font">Import Player Game Log</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($player): ?>
  <div class="card">
    <h2><?= h(player_full_name($player)) ?></h2>
    <p class="muted">
      Upload one CSV with one row per game. On the preview screen, match each row to an existing BenchBuddy game or create a new game from the CSV.
    </p>
  </div>

  <div class="card">
    <h2>Upload CSV</h2>

    <form method="post" action="import_player_games_stats.php?player_id=<?= (int)$playerId ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="preview_import">

      <label for="stats_csv">Player Game Log CSV</label>
      <input type="file" id="stats_csv" name="stats_csv" accept=".csv,text/csv" required>

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit">Preview Import</button>

        <a class="btn btn-secondary" href="download_player_games_stats_template.php?player_id=<?= (int)$playerId ?>">
          Download Sample CSV
        </a>

        <a class="btn btn-secondary" href="player_profile.php?player_id=<?= (int)$playerId ?>">
          Back to Profile
        </a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!empty($previewRows)): ?>
  <div class="card">
    <h2>Preview Import</h2>

    <p class="muted">
      Choose the BenchBuddy game for each CSV row. If the game is not already in BenchBuddy, leave it set to create a new game.
    </p>

    <form method="post" action="import_player_games_stats.php?player_id=<?= (int)$playerId ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_import">

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Match Game</th>
              <th>CSV Game</th>
              <th>Date</th>
              <th>H/A</th>
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
            <?php foreach ($previewRows as $index => $row): ?>
              <?php $suggestedMatchId = suggested_game_match_id($row, $existingGames); ?>

              <tr>
                <td>
                  <select name="game_match[<?= (int)$index ?>]">
                    <option value="create" <?= $suggestedMatchId <= 0 ? 'selected' : '' ?>>
                      Create new game from CSV
                    </option>

                    <?php foreach ($existingGames as $game): ?>
                      <option
                        value="<?= (int)$game['id'] ?>"
                        <?= $suggestedMatchId === (int)$game['id'] ? 'selected' : '' ?>
                      >
                        <?= h((string)$game['game_id']) ?>
                        <?php if (!empty($game['game_date'])): ?>
                          | <?= h((string)$game['game_date']) ?>
                        <?php endif; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>

                <td><?= h((string)$row['game_id']) ?></td>
                <td><?= h((string)$row['game_date']) ?></td>
                <td><?= h((string)$row['home_away']) ?></td>
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

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit" onclick="return confirm('Import these game stats for this player?');">
          Confirm Import
        </button>

        <a class="btn btn-secondary" href="import_player_games_stats.php?player_id=<?= (int)$playerId ?>">
          Cancel
        </a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2>CSV Format</h2>

  <p class="muted">
    Use one row per game. Date must use YYYY-MM-DD. Home/Away should be home, away, or blank.
  </p>

  <pre style="white-space:pre-wrap;">game_id,game_date,home_away,ab,r,h,2b,3b,hr,rbi,bb,k,hbp,sf,sb,ip,pitches,hits_allowed,runs_allowed,er,pitching_bb,pitching_k,w,l,sv</pre>

  <p class="muted">
    Example:
  </p>

  <pre style="white-space:pre-wrap;">Game 1,2026-04-10,home,3,1,2,1,0,0,2,1,0,0,0,1,0,0,0,0,0,0,0,0,0,0</pre>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
