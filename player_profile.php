<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$teamId = current_team_id();
$pageTitle = 'Player Profile';
$currentPage = 'players';

$error = '';
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;

$player = null;
$seasonSummary = [];
$positionTotals = [];
$battingSummary = null;
$pitchingSummary = null;
$gameByGameRows = [];

try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($playerId <= 0) {
        throw new RuntimeException('No player selected.');
    }

    $player = get_player_by_id($teamId, $playerId);

    if (!$player) {
        throw new RuntimeException('Player not found for this team.');
    }

    $seasonSummary = get_player_season_summary($teamId, $playerId);
    $positionTotals = get_player_position_totals($teamId, $playerId);

    $stmt = db()->prepare("
        SELECT
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

    $battingSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = db()->prepare("
        SELECT
            COALESCE(pl.innings_pitched, 0) AS innings_pitched,
            COALESCE(pl.pitches_thrown, 0) AS pitches_thrown,
            COALESCE(pl.games_pitched, 0) AS games_pitched,

            COALESCE(ps.hits_allowed, 0) AS hits_allowed,
            COALESCE(ps.runs_allowed, 0) AS runs_allowed,
            COALESCE(ps.earned_runs, 0) AS earned_runs,
            COALESCE(ps.walks, 0) AS walks,
            COALESCE(ps.strikeouts, 0) AS strikeouts,
            COALESCE(ps.wins, 0) AS wins,
            COALESCE(ps.losses, 0) AS losses,
            COALESCE(ps.saves, 0) AS saves
        FROM
            (
                SELECT
                    pl.player_id,
                    COALESCE(SUM(pl.innings_pitched), 0) AS innings_pitched,
                    COALESCE(SUM(pl.pitches_thrown), 0) AS pitches_thrown,
                    COUNT(DISTINCT pl.game_db_id) AS games_pitched
                FROM pitch_log pl
                INNER JOIN games g
                    ON g.id = pl.game_db_id
                   AND g.team_id = pl.team_id
                WHERE pl.team_id = :team_id
                  AND pl.player_id = :player_id
                  AND g.deleted_at IS NULL
                  AND COALESCE(g.counts_toward_stats, 1) = 1
                GROUP BY pl.player_id
            ) pl
        LEFT JOIN
            (
                SELECT
                    player_id,
                    COALESCE(SUM(hits_allowed), 0) AS hits_allowed,
                    COALESCE(SUM(runs_allowed), 0) AS runs_allowed,
                    COALESCE(SUM(earned_runs), 0) AS earned_runs,
                    COALESCE(SUM(walks), 0) AS walks,
                    COALESCE(SUM(strikeouts), 0) AS strikeouts,
                    COALESCE(SUM(wins), 0) AS wins,
                    COALESCE(SUM(losses), 0) AS losses,
                    COALESCE(SUM(saves), 0) AS saves
                FROM player_pitching_stats
                WHERE team_id = :team_id_stats
                  AND player_id = :player_id_stats
                GROUP BY player_id
            ) ps ON ps.player_id = pl.player_id
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
        'team_id_stats' => $teamId,
        'player_id_stats' => $playerId,
    ]);

    $pitchingSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pitchingSummary) {
        $pitchingSummary = [
            'innings_pitched' => 0,
            'pitches_thrown' => 0,
            'games_pitched' => 0,
            'hits_allowed' => 0,
            'runs_allowed' => 0,
            'earned_runs' => 0,
            'walks' => 0,
            'strikeouts' => 0,
            'wins' => 0,
            'losses' => 0,
            'saves' => 0,
        ];
    }

    $stmt = db()->prepare("
        SELECT
            g.id AS game_db_id,
            g.game_id,
            g.game_date,
            g.home_away,

            COALESCE(bs.at_bats, 0) AS at_bats,
            COALESCE(bs.runs, 0) AS runs,
            COALESCE(bs.hits, 0) AS hits,
            COALESCE(bs.doubles_hit, 0) AS doubles_hit,
            COALESCE(bs.triples_hit, 0) AS triples_hit,
            COALESCE(bs.home_runs, 0) AS home_runs,
            COALESCE(bs.rbi, 0) AS rbi,
            COALESCE(bs.walks, 0) AS walks,
            COALESCE(bs.strikeouts, 0) AS strikeouts,
            COALESCE(bs.hit_by_pitch, 0) AS hit_by_pitch,
            COALESCE(bs.sacrifice_flies, 0) AS sacrifice_flies,
            COALESCE(bs.stolen_bases, 0) AS stolen_bases,

            COALESCE(pl.innings_pitched, 0) AS innings_pitched,
            COALESCE(pl.pitches_thrown, 0) AS pitches_thrown,

            COALESCE(ps.hits_allowed, 0) AS hits_allowed,
            COALESCE(ps.runs_allowed, 0) AS runs_allowed,
            COALESCE(ps.earned_runs, 0) AS earned_runs,
            COALESCE(ps.walks, 0) AS pitching_walks,
            COALESCE(ps.strikeouts, 0) AS pitching_strikeouts,
            COALESCE(ps.wins, 0) AS wins,
            COALESCE(ps.losses, 0) AS losses,
            COALESCE(ps.saves, 0) AS saves
        FROM game_roster gr
        INNER JOIN games g
            ON g.id = gr.game_db_id
           AND g.team_id = gr.team_id
        LEFT JOIN player_batting_game_stats bs
            ON bs.game_db_id = g.id
           AND bs.team_id = g.team_id
           AND bs.player_id = gr.player_id
        LEFT JOIN pitch_log pl
            ON pl.game_db_id = g.id
           AND pl.team_id = g.team_id
           AND pl.player_id = gr.player_id
           LEFT JOIN player_pitching_stats ps
               ON ps.game_id = g.id
              AND ps.team_id = g.team_id
              AND ps.player_id = gr.player_id
        WHERE gr.team_id = :team_id
          AND gr.player_id = :player_id
          AND g.deleted_at IS NULL
          AND COALESCE(g.counts_toward_stats, 1) = 1
        ORDER BY
            CASE WHEN g.game_date IS NULL THEN 1 ELSE 0 END,
            g.game_date DESC,
            g.id DESC
    ");

    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
    ]);

    $gameByGameRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Player Profile</h1>



<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($player && $error === ''): ?>

  <div class="card">
    <h2>
      <?php if (!empty($player['jersey_number'])): ?>
        #<?= h((string)$player['jersey_number']) ?>
      <?php endif; ?>
      <?= h((string)$player['name']) ?>
    </h2>

    <div class="meta">
      <div class="meta-box">
        <div class="meta-label">Status</div>
        <div class="meta-value"><?= !empty($player['active']) ? 'Active' : 'Inactive' ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Pitching Role</div>
        <div class="meta-value"><?= h(ucfirst((string)($player['pitching_role'] ?? 'none'))) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Can Play</div>
        <div class="meta-value"><?= h(implode(', ', $player['can_play'] ?? [])) ?></div>
      </div>

      <div class="meta-box">
        <div class="meta-label">Cannot Play</div>
        <div class="meta-value"><?= h(implode(', ', $player['cannot_play'] ?? [])) ?></div>
      </div>
    </div>
  </div>

  <div class="meta" style="margin-bottom:24px;">
    <div class="meta-box">
      <div class="meta-label">Games Rostered</div>
      <div class="meta-value"><?= count($gameByGameRows) ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Bench Innings</div>
      <div class="meta-value"><?= (int)($seasonSummary['total_bench_times'] ?? 0) ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Games Benched</div>
      <div class="meta-value"><?= (int)($seasonSummary['games_benched'] ?? 0) ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Innings Pitched</div>
      <div class="meta-value"><?= h(number_format((float)($pitchingSummary['innings_pitched'] ?? 0), 1)) ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Total Pitches</div>
      <div class="meta-value"><?= (int)($pitchingSummary['pitches_thrown'] ?? 0) ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Games Pitched</div>
      <div class="meta-value"><?= (int)($pitchingSummary['games_pitched'] ?? 0) ?></div>
    </div>
  </div>

  <?php if (team_stats_enabled($teamId) && is_array($battingSummary)): ?>
    <?php
      $atBats = (int)$battingSummary['at_bats'];
      $hits = (int)$battingSummary['hits'];
      $doubles = (int)$battingSummary['doubles_hit'];
      $triples = (int)$battingSummary['triples_hit'];
      $homeRuns = (int)$battingSummary['home_runs'];
      $walks = (int)$battingSummary['walks'];
      $hbp = (int)$battingSummary['hit_by_pitch'];
      $sf = (int)$battingSummary['sacrifice_flies'];

      $avg = calculate_batting_average($hits, $atBats);
      $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
      $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
      $ops = calculate_ops($obp, $slg);
    ?>

    <div class="card" style="margin-bottom:16px;">
      <h2>Season Batting</h2>

      <div class="player-profile-stat-grid">
        <div class="profile-stat-box"><span>AVG</span><strong><?= h(format_baseball_rate($avg)) ?></strong></div>
        <div class="profile-stat-box"><span>OBP</span><strong><?= h(format_baseball_rate($obp)) ?></strong></div>
        <div class="profile-stat-box"><span>SLG</span><strong><?= h(format_baseball_rate($slg)) ?></strong></div>
        <div class="profile-stat-box"><span>OPS</span><strong><?= h(format_baseball_rate($ops)) ?></strong></div>
        <div class="profile-stat-box"><span>AB</span><strong><?= $atBats ?></strong></div>
        <div class="profile-stat-box"><span>H</span><strong><?= $hits ?></strong></div>
        <div class="profile-stat-box"><span>RBI</span><strong><?= (int)$battingSummary['rbi'] ?></strong></div>
        <div class="profile-stat-box"><span>BB</span><strong><?= $walks ?></strong></div>
        <div class="profile-stat-box"><span>K</span><strong><?= (int)$battingSummary['strikeouts'] ?></strong></div>
        <div class="profile-stat-box"><span>SB</span><strong><?= (int)$battingSummary['stolen_bases'] ?></strong></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (team_stats_enabled($teamId) && is_array($pitchingSummary)): ?>
    <?php
      $ip = (float)$pitchingSummary['innings_pitched'];
      $earnedRuns = (int)$pitchingSummary['earned_runs'];
      $pitchWalks = (int)$pitchingSummary['walks'];
      $hitsAllowed = (int)$pitchingSummary['hits_allowed'];

      $era = calculate_era($earnedRuns, $ip);
      $whip = calculate_whip($pitchWalks, $hitsAllowed, $ip);
    ?>

    <div class="card" style="margin-bottom:16px;">
      <h2>Season Pitching</h2>

      <div class="player-profile-stat-grid">
        <div class="profile-stat-box"><span>IP</span><strong><?= h(number_format($ip, 1)) ?></strong></div>
        <div class="profile-stat-box"><span>ERA</span><strong><?= h(number_format($era, 2)) ?></strong></div>
        <div class="profile-stat-box"><span>WHIP</span><strong><?= h(number_format($whip, 2)) ?></strong></div>
        <div class="profile-stat-box"><span>Pitches</span><strong><?= (int)$pitchingSummary['pitches_thrown'] ?></strong></div>
        <div class="profile-stat-box"><span>K</span><strong><?= (int)$pitchingSummary['strikeouts'] ?></strong></div>
        <div class="profile-stat-box"><span>BB</span><strong><?= $pitchWalks ?></strong></div>
        <div class="profile-stat-box"><span>W</span><strong><?= (int)$pitchingSummary['wins'] ?></strong></div>
        <div class="profile-stat-box"><span>L</span><strong><?= (int)$pitchingSummary['losses'] ?></strong></div>
        <div class="profile-stat-box"><span>SV</span><strong><?= (int)$pitchingSummary['saves'] ?></strong></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Position Usage</h2>

    <?php if (empty($positionTotals)): ?>
      <p class="muted">No defensive innings recorded yet.</p>
    <?php else: ?>
      <div class="position-usage-grid">
        <?php foreach ($positionTotals as $position => $innings): ?>
          <div class="position-usage-card">
            <div class="position-name"><?= h((string)$position) ?></div>
            <div class="position-innings"><?= h(number_format((float)$innings, 1)) ?></div>
            <div class="position-label">innings played</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <details class="suggested-lineup-toggle">
      <summary>
        <span>Game-by-Game Stats</span>
        <small><?= count($gameByGameRows) ?> Games</small>
      </summary>

      <div class="suggested-lineup-content">
        <?php if (empty($gameByGameRows)): ?>
          <p class="muted">No game-by-game stats found for this player.</p>
        <?php else: ?>
          <div class="mobile-card-list">
            <?php foreach ($gameByGameRows as $row): ?>
              <?php
                $ab = (int)$row['at_bats'];
                $hits = (int)$row['hits'];
                $doubles = (int)$row['doubles_hit'];
                $triples = (int)$row['triples_hit'];
                $hr = (int)$row['home_runs'];
                $walks = (int)$row['walks'];
                $hbp = (int)$row['hit_by_pitch'];
                $sf = (int)$row['sacrifice_flies'];

                $gameAvg = calculate_batting_average($hits, $ab);
                $gameObp = calculate_obp($hits, $walks, $hbp, $ab, $sf);
                $gameSlg = calculate_slugging($hits, $doubles, $triples, $hr, $ab);
                $gameOps = calculate_ops($gameObp, $gameSlg);

                $gameIp = (float)$row['innings_pitched'];
                $gamePitches = (int)$row['pitches_thrown'];
              ?>

              <article class="mobile-card">
                <div class="mobile-card-header">
                  <div>
                    <h3><?= h((string)$row['game_id']) ?></h3>
                    <p>
                      <?= !empty($row['game_date']) ? h((string)$row['game_date']) : 'Date not set' ?>
                      <?php if (!empty($row['home_away'])): ?>
                        | <?= h(ucfirst((string)$row['home_away'])) ?>
                      <?php endif; ?>
                    </p>
                  </div>
                </div>

                <div class="mobile-stat-grid">
                    <span>Batting</span>
                  <div><strong>AB</strong><span><?= $ab ?></span></div>
                  <div><strong>H</strong><span><?= $hits ?></span></div>
                  <div><strong>R</strong><span><?= (int)$row['runs'] ?></span></div>
                  <div><strong>RBI</strong><span><?= (int)$row['rbi'] ?></span></div>
                  <div><strong>2B</strong><span><?= (int)$row['doubles_hit'] ?></span></div>
                  <div><strong>3B</strong><span><?= (int)$row['triples_hit'] ?></span></div>
                  <div><strong>HR</strong><span><?= (int)$row['home_runs'] ?></span></div>
                  <div><strong>BB</strong><span><?= (int)$row['walks'] ?></span></div>
                  <div><strong>K</strong><span><?= (int)$row['strikeouts'] ?></span></div>
                  <div><strong>SB</strong><span><?= (int)$row['stolen_bases'] ?></span></div>
                  <div><strong>AVG</strong><span><?= h(format_baseball_rate($gameAvg)) ?></span></div>
                  <div><strong>OPS</strong><span><?= h(format_baseball_rate($gameOps)) ?></span></div>
                  <?php if ($gameIp > 0 || $gamePitches > 0): ?>
                  <br/><br/><span>Pitching</span>

                      <div><strong>IP</strong><span><?= h(number_format($gameIp, 1)) ?></span></div>
                      <div><strong>Pitches</strong><span><?= $gamePitches ?></span></div>
                      <div><strong>PK</strong><span><?= (int)$row['pitching_strikeouts'] ?></span></div>
                      <div><strong>PBB</strong><span><?= (int)$row['pitching_walks'] ?></span></div>
                      <div><strong>ER</strong><span><?= (int)$row['earned_runs'] ?></span></div>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
  </div>

  <div class="actions-row" style="margin-top:20px;">
    <a class="btn btn-secondary" href="players.php">Back to Players</a>
    <a class="btn" href="print_player_profile.php?player_id=<?= (int)$playerId ?>" target="_blank">
      Print Profile
    </a>

    <a class="btn btn-secondary" href="export_player_profile.php?player_id=<?= (int)$playerId ?>">
       Export Player Stats
     </a>

    <a class="btn btn-secondary" href="import_game_stats.php?player_id=<?= (int)$playerId ?>">
      Import Player Stats
    </a>

    <a class="btn btn-secondary" href="import_player_games_stats.php?player_id=<?= (int)$playerId ?>">
      Import Player Game Log
    </a>
  </div>

<?php endif; ?>

<style>
.player-profile-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: 12px;
}

.profile-stat-box {
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px;
  background: #f8fafc;
  text-align: center;
}

.profile-stat-box span {
  display: block;
  font-size: 12px;
  font-weight: 800;
  color: #64748b;
  margin-bottom: 6px;
}

.profile-stat-box strong {
  display: block;
  font-size: 24px;
  font-weight: 900;
  color: #0f172a;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
