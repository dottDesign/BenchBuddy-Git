<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

$teamId = current_team_id();
$teamName = 'BenchBuddy';
$teamLogo = '';
$currentPage = 'blank_lineup';

$showFreeWatermark = false;

if (
    function_exists('billing_enforcement_enabled')
    && billing_enforcement_enabled()
) {
    $showFreeWatermark = !team_can_use_feature($teamId, 'remove_watermark');
}
$team = null;
$team = null;

if ($teamId > 0) {
    $team = get_team_by_id($teamId);

    if ($team) {
        $teamName = trim((string)($team['print_brand_name'] ?? ''));

        if ($teamName === '') {
            $teamName = trim((string)($team['name'] ?? 'BenchBuddy'));
        }

        $teamLogo = trim((string)($team['print_logo_path'] ?? ''));
    }
}
$innings = isset($_GET['innings']) ? (int)$_GET['innings'] : 7;
if (!in_array($innings, [5, 6, 7, 8, 9], true)) {
    $innings = 7;
}
$battingSpots = isset($_GET['spots']) ? (int)$_GET['spots'] : 12;
if ($battingSpots < 9) {
    $battingSpots = 9;
}
if ($battingSpots > 20) {
    $battingSpots = 20;
}
$fieldPositionsCount = 9;
$benchRows = max(0, $battingSpots - $fieldPositionsCount);
$positions = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];

$orientation = isset($_GET['orientation']) ? trim((string)$_GET['orientation']) : 'portrait';

if (!in_array($orientation, ['portrait', 'landscape'], true)) {
    $orientation = 'portrait';
}
function render_free_print_watermark(bool $showFreeWatermark): void
{
    if (!$showFreeWatermark) {
        return;
    }
    ?>
    <div class="free-print-watermark" aria-hidden="true">
      <div class="free-print-watermark-main">
        GENERATED WITH<br>
        BENCHBOSS FREE
      </div>
      <div class="free-print-watermark-sub">
        Upgrade for watermark-free printables
      </div>
    </div>
    <?php
}
require_once __DIR__ . '/includes/header.php';
?>

<style>
  :root {
    --border: #000;
    --muted: #444;
    --light: #f3f3f3;
    --lighter: #fafafa;
  }

  .toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
    padding: 14px;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    background: #fafafa;
    margin-bottom: 18px;
  }

  .toolbar-group {
    display: grid;
    gap: 6px;
  }

  .toolbar label {
    font-size: 13px;
    font-weight: 700;
    color: #374151;
  }

  .toolbar select,
  .toolbar input[type="text"] {
    min-width: 160px;
    padding: 10px 12px;
    font-size: 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
  }
  input:read-only {
    background-color: #eaeaea !important;
    color: #666 !important;
    border: 1px solid #ccc !important;
    cursor: not-allowed !important;
  }
  .toolbar button {
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #111;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
  }

  .orientation-control {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .orientation-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: 1.5px solid #111;
    border-radius: 8px;
    background: #fff;
  }

  .orientation-icon span {
    display: block;
    border: 2px solid #111;
    border-radius: 3px;
    background: #f3f4f6;
  }

  .orientation-icon.portrait-icon span {
    width: 13px;
    height: 21px;
  }

  .orientation-icon.landscape-icon span {
    width: 22px;
    height: 14px;
  }

  .print-mode-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: 1.5px solid #111;
    border-radius: 8px;
    background: #fff;
    gap: 3px;
  }

  .print-mode-icon span {
    display: block;
    border: 2px solid #111;
    border-radius: 3px;
    background: #f3f4f6;
  }

  .print-mode-icon.single-icon .page-one {
    width: 15px;
    height: 21px;
  }

  .print-mode-icon.single-icon .page-two {
    display: none;
  }

  .print-mode-icon.split-icon .page-one,
  .print-mode-icon.split-icon .page-two {
    width: 9px;
    height: 20px;
  }

  .sheet {
    max-width: 1200px;
    margin: 0 auto;
  }

  .sheet-header {
    text-align: center;
    border: 2px solid var(--border);
    padding: 14px 16px;
    margin-bottom: 18px;
  }

  .sheet-subtitle {
    font-size: 15px;
    color: var(--muted);
  }

  .print-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 230px;
    gap: 18px;
    align-items: start;
  }

  .field-bench-column {
    min-width: 0;
  }

  .section {
    margin-bottom: 18px;
  }

  .section-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 8px;
    padding-bottom: 4px;
    border-bottom: 2px solid #000;
  }

  .table-wrap {
    overflow: visible;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }

  th,
  td {
    border: 1.5px solid var(--border);
    padding: 8px 6px;
    vertical-align: middle;
    text-align: center;
    word-wrap: break-word;
  }

  thead th {
    background: var(--light);
    font-size: 11px;
  }

  .position-col {
    width: 70px;
    font-weight: bold;
    background: var(--lighter);
    text-align: center;
  }

  .inning-cell,
  .bench-table td {
    height: 43px;
    font-size: 12px;
    line-height: 1.25;
  }

  .small-table {
    table-layout: auto;
  }

  .small-table th,
  .small-table td {
    font-size: 12px;
    padding: 7px 6px;
  }

  .small-table tbody tr:nth-child(odd) {
    background: #e6e6e6;
  }

  .batting-order-col {
    width: 42px;
    font-weight: bold;
    background: var(--lighter);
    text-align: center;
  }

  .batting-section table tr {
    height: 40px;
  }

  .diagonal {
    position: relative;
    width: 70px;
    height: 50px;
    padding: 0;
    vertical-align: middle;
    overflow: hidden;
  }

  .diagonal .diag-line {
    position: absolute;
    inset: 0;
    display: block;
    background:
      linear-gradient(
        to bottom left,
        transparent 49%,
        #000 49.5%,
        #000 50.5%,
        transparent 51%
      );
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }

  .diagonal .pos,
  .diagonal .inn {
    position: absolute;
    font-size: 11px;
    font-weight: bold;
    z-index: 1;
    transform: rotate(35deg);
  }

  .diagonal .pos {
    bottom: 12px;
    left: 2px;
  }

  .diagonal .inn {
    top: 12px;
    right: 9px;
  }

  @media (max-width: 700px) {
    .print-layout {
      grid-template-columns: 1fr;
    }
  }

  @page {
    size: portrait;
    margin: 0.35in;
  }

  @media print {
    html,
    body {
      margin: 0;
      padding: 0;
      width: 100%;
      background: #fff;
    }

    body {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    .no-print,
    .toolbar {
      display: none !important;
    }

    .sheet {
      width: 100%;
      max-width: none;
      margin: 0;
    }

    .sheet-header {
      padding: 8px 10px;
      margin-bottom: 10px;
    }

    .print-layout {
      grid-template-columns: minmax(0, 1fr) 190px;
      gap: 10px;
    }

    .section {
      margin-bottom: 10px;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .section-title {
      font-size: 14px;
      margin-bottom: 5px;
    }

    table,
    tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }

    th,
    td {
      padding: 5px 4px;
      font-size: 10px;
      line-height: 1.15;
    }

    .inning-cell,
    .bench-table td {
      height: 34px;
      font-size: 10px;
    }

    .small-table th,
    .small-table td {
      font-size: 10px;
      padding: 5px 4px;
    }

    .batting-order-col {
      width: 32px;
    }

    .small-table tbody tr:nth-child(odd) {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    body.print-portrait .sheet {
      transform: scale(0.94);
      transform-origin: top left;
      width: 106.3%;
    }

    body.print-landscape {
      page: landscape-page;
    }

    body.print-landscape .sheet {
      transform: scale(0.98);
      transform-origin: top left;
      width: 102%;
    }

    @page landscape-page {
      size: landscape;
      margin: 0.25in;
    }

    body.print-split .print-layout {
      display: block;
    }

    body.print-split .print-page-field {
      page-break-after: always;
      break-after: page;
      page-break-inside: avoid;
      break-inside: avoid;
      transform: scale(0.92);
      transform-origin: top left;
      width: 100%;
    }

    body.print-split.print-landscape .print-page-field {
      transform: scale(0.98);
    }

    body.print-split .print-page-batting {
      max-width: 700px;
      margin: 0 auto;
      page-break-after: auto;
      break-after: auto;
    }

    body.print-split .section {
      margin-bottom: 6px;
    }

    body.print-split .section-title {
      font-size: 12px;
      margin-bottom: 4px;
      padding-bottom: 2px;
    }

    body.print-split th,
    body.print-split td {
      padding: 3px;
      font-size: 9px;
      line-height: 1.05;
    }

    body.print-split .inning-cell,
    body.print-split .bench-table td {
      height: 35px;
      font-size: 9px;
    }

    body.print-split .diagonal {
      height: 38px;
    }

    body.print-split .diagonal .pos,
    body.print-split .diagonal .inn {
      font-size: 9px;
    }
  }

  .print-page {
    position: relative;
    overflow: hidden;
  }

  .free-print-watermark {
      position: absolute;
          inset: 0;
          z-index: 50;
          pointer-events: none;
          display: flex;
          align-items: center;
          justify-content: space-between;
          transform: rotate(-28deg);
          opacity: 0.05;
          text-align: center;
          flex-wrap: nowrap;
          align-content: space-around;

  }

  .free-print-watermark-main {
    font-size: 72px;
    line-height: 0.95;
    font-weight: 900;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #9f1239;
  }

  .free-print-watermark-sub {
    margin-top: 22px;
    font-size: 24px;
    font-weight: 800;
    color: #9f1239;
    text-transform: uppercase;
  }

  @media print {
    .print-page {
      position: relative;
      overflow: hidden;
      page-break-after: always;
    }

    .print-page:last-child {
      page-break-after: auto;
    }

    .free-print-watermark {
      position: absolute;
      inset: 0;
      z-index: 50;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
  }

  .sheet-brand-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
  }

  .sheet-brand-logo {
    max-width: 90px;
    max-height: 70px;
    object-fit: contain;
  }

  .sheet-brand-title {
    font-size: 26px;
    font-weight: 900;
    line-height: 1.1;
  }
</style>
  <div class="sheet">
    <div class="no-print">
      <form method="get" class="toolbar">
        <div class="toolbar-group">
          <label for="innings">Innings</label>
          <select name="innings" id="innings" onchange="this.form.submit()">
            <option value="5" <?= $innings === 5 ? 'selected' : '' ?>>5 innings</option>
            <option value="6" <?= $innings === 6 ? 'selected' : '' ?>>6 innings</option>
            <option value="7" <?= $innings === 7 ? 'selected' : '' ?>>7 innings</option>
            <option value="8" <?= $innings === 8 ? 'selected' : '' ?>>8 innings</option>
            <option value="9" <?= $innings === 9 ? 'selected' : '' ?>>9 innings</option>
          </select>
        </div>
        <div class="toolbar-group">
          <label for="spots">Batting Spots</label>
          <select name="spots" id="spots" onchange="this.form.submit()">
            <?php for ($i = 9; $i <= 20; $i++): ?>
              <option value="<?= $i ?>" <?= $battingSpots === $i ? 'selected' : '' ?>>
                <?= $i ?> players
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="toolbar-group">
          <label for="bench_preview">Bench Spots </label>
          <input type="text" id="bench_preview" value="<?= $benchRows ?>" readonly>
        </div>
        <div class="toolbar-group orientation-group">
          <label for="orientation">Print Orientation</label>
          <div class="orientation-control">
            <select name="orientation" id="orientation" onchange="setPrintOrientation(this.value)">
              <option value="portrait">Portrait</option>
              <option value="landscape">Landscape</option>
            </select>
            <span id="orientationIcon" class="orientation-icon portrait-icon" aria-hidden="true">
              <span></span>
            </span>
          </div>
        </div>
        <div class="toolbar-group">
          <label for="print_mode">Print Layout</label>
          <div class="orientation-control">
            <select id="print_mode" onchange="setPrintMode(this.value)">
              <option value="single">Single Page</option>
              <option value="split">Two Pages</option>
            </select>
            <span id="printModeIcon" class="print-mode-icon single-icon" aria-hidden="true">
              <span class="page-one"></span>
              <span class="page-two"></span>
            </span>
          </div>
        </div>
        <button type="button" onclick="printBlankLineup()">Print</button>
      </form>
    </div>
    <div class="sheet-header">
      <div class="sheet-brand-header">
        <?php if ($teamLogo !== ''): ?>
          <img
            src="<?= h($teamLogo) ?>"
            alt="<?= h($teamName) ?> logo"
            class="sheet-brand-logo"
          >
        <?php endif; ?>

        <div>
          <div class="sheet-brand-title"><?= h($teamName) ?></div>
          <div class="sheet-subtitle">Blank Lineup Sheet · Game</div>
        </div>
      </div>
    </div>
    <div class="print-layout">
      <div class="print-page print-page-field">
          <?php render_free_print_watermark($showFreeWatermark); ?>
        <div class="field-bench-column">
          <div class="section">
            <div class="section-title">Field Lineup</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="diagonal">
                      <span class="diag-line"></span>
                      <span class="pos">Position</span>
                      <span class="inn">Inning</span>
                    </th>
                    <?php for ($i = 1; $i <= $innings; $i++): ?>
                      <th><?= h(ordinal($i)) ?></th>
                    <?php endfor; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($positions as $position): ?>
                    <tr>
                      <td class="position-col"><?= h($position) ?></td>
                      <?php for ($i = 1; $i <= $innings; $i++): ?>
                        <td class="inning-cell"></td>
                      <?php endfor; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php if ($benchRows > 0): ?>
            <div class="section bench-section">
              <div class="section-title">Bench</div>
              <div class="table-wrap">
                <table class="bench-table">
                  <thead>
                    <tr>
                      <th class="position-col">Bench</th>
                      <?php for ($i = 1; $i <= $innings; $i++): ?>
                        <th><?= h(ordinal($i)) ?></th>
                      <?php endfor; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php for ($b = 1; $b <= $benchRows; $b++): ?>
                      <tr>
                        <td class="position-col">B<?= $b ?></td>
                        <?php for ($i = 1; $i <= $innings; $i++): ?>
                          <td class="inning-cell"></td>
                        <?php endfor; ?>
                      </tr>
                    <?php endfor; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="print-page print-page-batting">
          <?php render_free_print_watermark($showFreeWatermark); ?>

        <div class="section batting-section">
          <div class="section-title">Batting Order</div>
          <table class="small-table">
            <thead>
              <tr>
                <th class="batting-order-col">#</th>
                <th>Batter</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($i = 1; $i <= $battingSpots; $i++): ?>
                <tr>
                  <td class="batting-order-col"><?= $i ?></td>
                  <td></td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
  function setPrintOrientation(mode) {
    document.body.classList.remove('print-portrait', 'print-landscape');

    const icon = document.getElementById('orientationIcon');
    if (icon) {
      icon.classList.remove('portrait-icon', 'landscape-icon');
    }

    if (mode === 'landscape') {
      document.body.classList.add('print-landscape');

      if (icon) {
        icon.classList.add('landscape-icon');
      }
    } else {
      document.body.classList.add('print-portrait');

      if (icon) {
        icon.classList.add('portrait-icon');
      }
    }

    localStorage.setItem('blankLineupPrintOrientation', mode);
  }

  function printBlankLineup() {
    const orientation = document.getElementById('orientation').value;
    const printMode = document.getElementById('print_mode').value;

    setPrintOrientation(orientation);
    setPrintMode(printMode);

    window.print();
  }

  document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('blankLineupPrintOrientation') || 'portrait';
    const select = document.getElementById('orientation');

    if (select) {
      select.value = saved;
      setPrintOrientation(saved);
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
    const savedMode = localStorage.getItem('blankLineupPrintMode') || 'single';
    const printModeSelect = document.getElementById('print_mode');

    if (printModeSelect) {
      printModeSelect.value = savedMode;
      setPrintMode(savedMode);
    }
  });

  function setPrintMode(mode) {
    document.body.classList.remove('print-single', 'print-split');
    document.body.classList.add(mode === 'split' ? 'print-split' : 'print-single');

    const icon = document.getElementById('printModeIcon');

    if (icon) {
      icon.classList.remove('single-icon', 'split-icon');
      icon.classList.add(mode === 'split' ? 'split-icon' : 'single-icon');
    }

    localStorage.setItem('blankLineupPrintMode', mode);
  }

  document.addEventListener('DOMContentLoaded', function () {
    const savedMode = localStorage.getItem('blankLineupPrintMode') || 'single';
    const printModeSelect = document.getElementById('print_mode');

    if (printModeSelect) {
      printModeSelect.value = savedMode;
      setPrintMode(savedMode);
    }
  });


  let updateTimer;

  document.getElementById('spots')?.addEventListener('change', function () {
    clearTimeout(updateTimer);
    updateTimer = setTimeout(() => {
      this.form.submit();
    }, 150);
  });

  document.getElementById('innings')?.addEventListener('change', function () {
    clearTimeout(updateTimer);
    updateTimer = setTimeout(() => {
      this.form.submit();
    }, 150);
  });

  function updateBenchPreview() {
    const spotsSelect = document.getElementById('spots');
    const benchPreview = document.getElementById('bench_preview');

    if (!spotsSelect || !benchPreview) {
      return;
    }

    const battingSpots = parseInt(spotsSelect.value, 10) || 9;
    const fieldPositions = 9;
    const benchSpots = Math.max(0, battingSpots - fieldPositions);

    benchPreview.value = benchSpots;
  }

  document.addEventListener('DOMContentLoaded', updateBenchPreview);
  </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
