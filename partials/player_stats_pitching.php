<div id="pitching-stats-container">
<div class="card" style="margin-bottom:18px;">
  <h2>Pitching</h2>

  <?php if (empty($pitchingRows)): ?>
    <p class="muted">No pitching stats entered yet.</p>
  <?php else: ?>
  <div class="mobile-card-list">
      <?php foreach ($pitchingRows as $row): ?>
        <?php
          $ip = (float)$row['innings_pitched'];
          $earnedRuns = (int)$row['earned_runs'];
          $walks = (int)$row['walks'];
          $hitsAllowed = (int)$row['hits_allowed'];
          $era = calculate_era($earnedRuns, $ip);
          $whip = calculate_whip($walks, $hitsAllowed, $ip);
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

            <div>
              <strong><span class="stat-tip"
                      data-tip="Innings Pitched. One inning equals 3 outs recorded.">
                IP
                      </span></strong>
              <span><?= h(number_format($ip, 1)) ?></span>
            </div>

            <div>
              <strong><span class="stat-tip"
                      data-tip="Earned Run Average. (Earned Runs × 7) ÷ Innings Pitched.">
                ERA
                      </span></strong>
              <span><?= h(number_format($era, 2)) ?></span>
            </div>

            <div>
              <strong><span class="stat-tip"
                      data-tip="WHIP = (Walks + Hits Allowed) ÷ Innings Pitched.">
                WHIP
                      </span></strong>
              <span><?= h(number_format($whip, 2)) ?></span>
            </div>

            <?php if ((int)$row['pitches_thrown'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Total pitches thrown.">
                  Pitches
                        </span></strong>
                <span><?= (int)$row['pitches_thrown'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['hits_allowed'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Hits allowed while pitching.">
                  H
                        </span></strong>
                <span><?= (int)$row['hits_allowed'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['runs_allowed'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Runs allowed while pitching.">
                  R
                        </span></strong>
                <span><?= (int)$row['runs_allowed'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['earned_runs'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Earned Runs allowed. Runs scored without defensive errors.">
                  ER
                        </span></strong>
                <span><?= (int)$row['earned_runs'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['walks'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Walks issued while pitching.">
                  BB
                        </span></strong>
                <span><?= (int)$row['walks'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['strikeouts'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Strikeouts recorded while pitching.">
                  K
                        </span></strong>
                <span><?= (int)$row['strikeouts'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['wins'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Pitcher credited with the win.">
                  W
                        </span></strong>
                <span><?= (int)$row['wins'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['losses'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Pitcher charged with the loss.">
                  L
                        </span></strong>
                <span><?= (int)$row['losses'] ?></span>
              </div>
            <?php endif; ?>

            <?php if ((int)$row['saves'] > 0): ?>
              <div>
                <strong><span class="stat-tip"
                        data-tip="Save awarded for finishing a close game while preserving the lead.">
                  SV
                        </span></strong>
                <span><?= (int)$row['saves'] ?></span>
              </div>
            <?php endif; ?>

          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>
