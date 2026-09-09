<div id="batting-stats-container">
<div class="card" style="margin-bottom:18px;">
  <h2>Batting</h2>

  <?php if (empty($battingRows)): ?>
    <p class="muted">No player stats found.</p>
  <?php else: ?>


  <div class="mobile-card-list">
      <?php foreach ($battingRows as $row): ?>
        <?php
          $atBats = (int)$row['at_bats'];
          $hits = (int)$row['hits'];
          $doubles = (int)$row['doubles_hit'];
          $triples = (int)$row['triples_hit'];
          $homeRuns = (int)$row['home_runs'];
          $walks = (int)$row['walks'];
          $hbp = (int)$row['hit_by_pitch'];
          $sf = (int)$row['sacrifice_flies'];

          $avg = calculate_batting_average($hits, $atBats);
          $obp = calculate_obp($hits, $walks, $hbp, $atBats, $sf);
          $slg = calculate_slugging($hits, $doubles, $triples, $homeRuns, $atBats);
          $ops = calculate_ops($obp, $slg);
        ?>

        <article class="mobile-card">
          <div class="mobile-card-header">
            <div>
              <h3><?= h(player_full_name($row)) ?></h3>
              <?php if (!empty($row['jersey_number'])): ?>
                <p>#<?= h((string)$row['jersey_number']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="mobile-stat-grid">

            <div><strong>
              <span
                class="stat-tip"
                data-tip="Batting Average. Calculated as Hits ÷ At Bats."
              >
                AVG
              </span></strong>

              <span><?= h(format_baseball_rate($avg)) ?></span>
            </div>

            <div><strong>
              <span
                class="stat-tip"
                data-tip="On Base Percentage. (Hits + Walks + HBP) ÷ (AB + BB + HBP + SF)"
              >
                OBP
              </span></strong>

              <span><?= h(format_baseball_rate($obp)) ?></span>
            </div>

            <div><strong>
              <span
                class="stat-tip"
                data-tip="Slugging Percentage. Total Bases ÷ At Bats."
              >
                SLG
              </span></strong>

              <span><?= h(format_baseball_rate($slg)) ?></span>
            </div>

            <div><strong>
              <span
                class="stat-tip"
                data-tip="OPS = OBP + SLG. Measures overall offensive production."
              >
                OPS
              </span></strong>

              <span><?= h(format_baseball_rate($ops)) ?></span>
            </div>

            <?php if ((int)$row['at_bats'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="At Bats. Official batting appearances excluding walks, sacrifice flies, and hit by pitch."
                >
                  AB
                </span></strong>

                <span><?= (int)$row['at_bats'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['runs'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Runs scored."
                >
                  R
                </span></strong>

                <span><?= (int)$row['runs'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['hits'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Hits. Total singles, doubles, triples, and home runs."
                >
                  H
                </span></strong>

                <span><?= (int)$row['hits'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['doubles_hit'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Doubles. Hits where the batter reaches second base."
                >
                  2B
                </span></strong>

                <span><?= (int)$row['doubles_hit'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['triples_hit'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Triples. Hits where the batter reaches third base."
                >
                  3B
                </span></strong>

                <span><?= (int)$row['triples_hit'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['home_runs'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Home Runs. Batter scores by hitting the ball out or circling all bases safely."
                >
                  HR
                </span></strong>

                <span><?= (int)$row['home_runs'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['rbi'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Runs Batted In. Number of runners scored because of this batter."
                >
                  RBI
                </span></strong>

                <span><?= (int)$row['rbi'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['walks'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Walks. Batter awarded first base after four balls."
                >
                  BB
                </span></strong>

                <span><?= (int)$row['walks'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['strikeouts'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Strikeouts."
                >
                  K
                </span></strong>

                <span><?= (int)$row['strikeouts'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['hit_by_pitch'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Hit By Pitch. Batter awarded first base after being hit by a pitch."
                >
                  HBP
                </span></strong>

                <span><?= (int)$row['hit_by_pitch'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['sacrifice_flies'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Sacrifice Flies. Fly balls that score a runner."
                >
                  SF
                </span></strong>

                <span><?= (int)$row['sacrifice_flies'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['stolen_bases'] > 0): ?>
              <div><strong>
                <span
                  class="stat-tip"
                  data-tip="Stolen Bases. Bases advanced while the pitcher is delivering the pitch."
                >
                  SB
                </span></strong>

                <span><?= (int)$row['stolen_bases'] ?></span>
              </div>
            <?php endif; ?>

          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>
