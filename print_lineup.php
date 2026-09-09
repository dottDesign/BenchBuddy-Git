<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/lineup_engine.php';

$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';

if ($labelMode === '') {
    $labelMode = 'both';
}

require_login();

$teamId = current_team_id();
$team = get_team_by_id($teamId);
$planKey = current_team_plan_key($teamId);
$showFreeWatermark = $planKey === 'free';
$canUsePrintBranding = team_can_use_feature($teamId, 'print.custom_branding');

$printBrandName = $canUsePrintBranding && !empty($team['print_brand_name'])
    ? (string)$team['print_brand_name']
    : (string)($team['name'] ?? 'BenchBuddy');

$printLogoPath = $canUsePrintBranding && !empty($team['print_logo_path'])
    ? (string)$team['print_logo_path']
    : '';
$gameId = (int)($_GET['game_id'] ?? 0);

if ($teamId <= 0 || $gameId <= 0) {
    die('Invalid game.');
}


$game = get_game_by_id($teamId, $gameId);
if (!$game) {
    die('Game not found.');
}

if (($game['status'] ?? '') !== 'generated' && ($game['status'] ?? '') !== 'locked') {
    die('This game does not have a saved lineup yet.');
}

$result = get_generated_lineup_result($teamId, $gameId);
if (!is_array($result) || empty($result)) {
    die('No saved lineup data found for this game.');
}
$roster = get_game_roster($teamId, $gameId);

$positions = $result['positions'] ?? [];
$displayPositions = game_display_positions_for_roster_count(count($roster));
$isEightPlayerMode = count($positions) === 8;
$innings = (int)($result['innings'] ?? 7);
$lineupGrid = $result['lineup_grid'] ?? [];


$benchGrid = $result['bench_grid'] ?? [];
$benchCount = (int)($result['bench_count'] ?? 0);

$lateInningBenchPlan = print_late_inning_bench_plan(
    $result,
    $roster,
    $positions,
    $innings,
    $benchCount,
    6
);

$rosterMap = [];
foreach ($roster as $player) {
    $rosterMap[(int)$player['id']] = [
        'id' => (int)$player['id'],
        'first_name' => (string)($player['first_name'] ?? ''),
        'last_name' => (string)($player['last_name'] ?? ''),
        'name' => player_full_name($player),
        'jersey_number' => (string)($player['jersey_number'] ?? ''),
    ];
}

function normalize_print_player_cell($cell, array $rosterMap): ?array
{
    if (is_object($cell)) {
        $cell = (array)$cell;
    }

    if (!is_array($cell)) {
        return null;
    }

    $playerId = (int)($cell['id'] ?? $cell['player_id'] ?? 0);

    if ($playerId > 0 && isset($rosterMap[$playerId])) {
        return array_merge($cell, $rosterMap[$playerId]);
    }

    $firstName = trim((string)($cell['first_name'] ?? ''));
    $lastName = trim((string)($cell['last_name'] ?? ''));

    if (!isset($cell['name']) || trim((string)$cell['name']) === '') {
        $cell['name'] = trim($firstName . ' ' . $lastName);
    }

    return $cell;
}

function dugout_player_label($cell, string $labelMode, array $rosterMap): string
{
    $player = normalize_print_player_cell($cell, $rosterMap);

    if ($player === null) {
        return '';
    }

    return format_player_label($player, $labelMode);
}

function dugout_roster_label(array $player, string $labelMode): string
{
    return format_player_label($player, $labelMode);
}
function print_late_inning_bench_plan(
    array $lineupResult,
    array $roster,
    array $positions,
    int $innings,
    int $benchCount,
    int $lateStartInning = 6
): array {
    $playerMap = [];

    foreach ($roster as $player) {
        $playerId = (int)($player['id'] ?? 0);

        if ($playerId <= 0) {
            continue;
        }

        $playerMap[$playerId] = [
            'player' => $player,
            'played_innings' => 0,
            'bench_innings' => 0,
        ];
    }

    $lineupGrid = $lineupResult['lineup_grid'] ?? [];
    $benchGrid = $lineupResult['bench_grid'] ?? [];

    $blankLateInnings = [];

    for ($inning = 1; $inning <= $innings; $inning++) {
        $hasLateBlank = false;

        foreach ($positions as $position) {
            $cell = $lineupGrid[$position][$inning] ?? null;

            if (!is_array($cell) || (int)($cell['id'] ?? 0) <= 0) {
                if ($inning >= $lateStartInning) {
                    $hasLateBlank = true;
                }

                continue;
            }

            $playerId = (int)$cell['id'];

            if (isset($playerMap[$playerId])) {
                $playerMap[$playerId]['played_innings']++;
            }
        }

        for ($slot = 0; $slot < $benchCount; $slot++) {
            $cell = $benchGrid[$slot][$inning] ?? null;

            if (!is_array($cell) || (int)($cell['id'] ?? 0) <= 0) {
                if ($inning >= $lateStartInning) {
                    $hasLateBlank = true;
                }

                continue;
            }

            $playerId = (int)$cell['id'];

            if (isset($playerMap[$playerId])) {
                $playerMap[$playerId]['bench_innings']++;
            }
        }

        if ($inning >= $lateStartInning && $hasLateBlank) {
            $blankLateInnings[] = $inning;
        }
    }

    $blankLateInnings = array_values(array_unique($blankLateInnings));
    $remainingInnings = count($blankLateInnings);

    if ($remainingInnings <= 0) {
        return [
            'has_blank_late_innings' => false,
            'blank_innings' => [],
            'can_bench' => [],
            'cannot_bench' => [],
        ];
    }

    $activeRosterCount = count($roster);
    $fieldersPerInning = count($positions);
    $totalBenchSlots = max(0, ($activeRosterCount - $fieldersPerInning) * $innings);

    $maxBenchInnings = $activeRosterCount > 0
        ? (int)ceil($totalBenchSlots / $activeRosterCount)
        : 0;

    $minimumDefensiveInnings = max(0, $innings - $maxBenchInnings);

    $canBench = [];
    $cannotBench = [];

    foreach ($playerMap as $row) {
        $played = (int)$row['played_innings'];
        $benched = (int)$row['bench_innings'];

        $neededDefensiveInnings = max(0, $minimumDefensiveInnings - $played);
        $benchRoom = max(0, $maxBenchInnings - $benched);

        $item = [
            'player' => $row['player'],
            'played_innings' => $played,
            'bench_innings' => $benched,
            'needed_defensive_innings' => $neededDefensiveInnings,
            'bench_room' => $benchRoom,
            'max_bench_innings' => $maxBenchInnings,
            'minimum_defensive_innings' => $minimumDefensiveInnings,
        ];

        if ($benchRoom <= 0 || $neededDefensiveInnings >= $remainingInnings) {
            $cannotBench[] = $item;
            continue;
        }

        $canBench[] = $item;
    }

    usort($canBench, function (array $a, array $b): int {
        return $a['bench_room'] <=> $b['bench_room'];
    });

    usort($cannotBench, function (array $a, array $b): int {
        return $b['bench_innings'] <=> $a['bench_innings'];
    });

    return [
        'has_blank_late_innings' => true,
        'blank_innings' => $blankLateInnings,
        'max_bench_innings' => $maxBenchInnings,
        'minimum_defensive_innings' => $minimumDefensiveInnings,
        'can_bench' => $canBench,
        'cannot_bench' => $cannotBench,
    ];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Printable Lineup</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --border: #000;
      --muted: #444;
      --light: #f3f3f3;
      --lighter: #fafafa;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      padding: 0;
    }

    body {
      margin: 20px;
      font-family: Arial, Helvetica, sans-serif;
      color: #000;
      background: #fff;
    }

    /*
    |--------------------------------------------------------------------------
    | Screen controls
    |--------------------------------------------------------------------------
    */

    .print-actions {
      display: flex;
      gap: 10px;
      justify-content: space-between;
      align-items: flex-end;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .print-actions-left,
    .print-actions-right {
      display: flex;
      gap: 10px;
      align-items: flex-end;
      flex-wrap: wrap;
    }

    .print-actions button,
    .print-actions a,
    .print-actions select {
      border: 1px solid #000;
      background: #fff;
      color: #000;
      padding: 10px 14px;
      text-decoration: none;
      font-size: 14px;
      cursor: pointer;
    }

    .print-actions label {
      display: block;
      margin-bottom: 4px;
      font-size: 13px;
      font-weight: 700;
    }

    .orientation-control {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .orientation-icon,
    .print-mode-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border: 1.5px solid #111;
      border-radius: 8px;
      background: #fff;
    }

    .orientation-icon span,
    .print-mode-icon span {
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
      gap: 3px;
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

    /*
    |--------------------------------------------------------------------------
    | Main sheet
    |--------------------------------------------------------------------------
    */

    .sheet {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
    }

    .sheet-header {
      padding: 14px 16px;
      margin-bottom: 18px;
      border: 2px solid var(--border);
    }

    .print-brand-header {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .print-brand-logo {
      max-width: 120px;
      max-height: 40px;
      object-fit: contain;
    }

    .print-brand-name {
      font-size: 24px;
      font-weight: 900;
      color: #111827;
      line-height: 1.1;
    }

    .print-brand-subtitle {
      margin-top: 2px;
      font-size: 13px;
      font-weight: 700;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .sheet-subtitle {
      font-size: 15px;
      color: var(--muted);
    }

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */

    .print-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 230px;
      gap: 18px;
      align-items: start;
    }

    .field-bench-column {
      min-width: 0;
    }

    .print-page {
      position: relative;
    }

    .section {
      margin-bottom: 18px;
    }

    .section-title {
      margin-bottom: 8px;
      padding-bottom: 4px;
      border-bottom: 2px solid #000;
      font-size: 18px;
      font-weight: 700;
    }

    .table-wrap {
      overflow: visible;
    }

    .bench-section {
      width: 100%;
      max-width: 100%;
    }

    .lineup-width table {
      width: 100%;
    }

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    th,
    td {
      padding: 8px 6px;
      border: 1.5px solid var(--border);
      vertical-align: middle;
      text-align: center;
      overflow-wrap: anywhere;
    }

    thead th {
      background: var(--light);
      font-size: 11px;
    }

    .position-col {
      width: 70px;
      font-weight: 700;
      background: var(--lighter);
    }

    .inning-cell {
      height: 43px;
      font-size: 12px;
      line-height: 1.25;
    }

    .bench-table td {
      height: 50px;
    }

    /*
    |--------------------------------------------------------------------------
    | Batting order — normal/single-page dimensions
    |--------------------------------------------------------------------------
    */

    .batting-section {
      align-self: start;
    }

    .batting-section table {
      height: 355px;
      table-layout: auto;
    }

    .small-table th,
    .small-table td {
      padding: 7px 6px;
      font-size: 12px;
    }

    .small-table tbody tr:nth-child(odd) {
      background: #e6e6e6;
    }

    .batting-order-col {
      width: 42px;
      font-weight: 700;
      background: var(--lighter);
    }

    /*
    |--------------------------------------------------------------------------
    | Diagonal position/inning heading
    |--------------------------------------------------------------------------
    */

    .diagonal {
      position: relative;
      width: 70px;
      height: 50px;
      padding: 0;
      overflow: hidden;
      vertical-align: middle;
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

    .diagonal .pos {
      position: absolute;
      bottom: 12px;
      left: 2px;
      z-index: 1;
      font-size: 11px;
      font-weight: 700;
      transform: rotate(35deg);
    }

    .diagonal .inn {
      position: absolute;
      top: 12px;
      right: 9px;
      z-index: 1;
      font-size: 11px;
      font-weight: 700;
      transform: rotate(35deg);
    }

    /*
    |--------------------------------------------------------------------------
    | Late-inning options
    |--------------------------------------------------------------------------
    */

    .print-late-inning-box {
      margin-top: 12px;
      padding: 8px 10px;
      border: 2px solid #000;
    }

    .print-late-inning-box h3 {
      margin: 0 0 6px;
      font-size: 14px;
      font-weight: 800;
    }

    .print-late-inning-box p {
      margin: 4px 0 8px;
      font-size: 11px;
      line-height: 1.25;
    }

    .print-mini-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
      font-size: 10px;
    }

    .print-mini-table th,
    .print-mini-table td {
      padding: 4px 5px;
      border: 1px solid #000;
      text-align: left;
      vertical-align: middle;
    }

    .print-mini-table th {
      font-weight: 800;
      background: #f2f2f2;
    }

    .print-warning {
      font-size: 10px;
      font-weight: 700;
      line-height: 1.25;
    }

    .notes-box {
      min-height: 80px;
      padding: 10px;
      border: 2px solid var(--border);
    }

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    */

    .free-print-watermark {
      position: absolute;
      inset: 0;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: space-between;
      align-content: space-around;
      flex-wrap: nowrap;
      pointer-events: none;
      opacity: 0.05;
      text-align: center;
      transform: rotate(-28deg);
    }

    .free-print-watermark-main {
      color: #9f1239;
      font-size: 72px;
      line-height: 0.95;
      font-weight: 900;
      letter-spacing: 2px;
      text-transform: uppercase;
    }

    .free-print-watermark-sub {
      margin-top: 22px;
      color: #9f1239;
      font-size: 24px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .print-layout-group.is-disabled {
      opacity: 0.45;
    }

    .print-layout-group.is-disabled select {
      cursor: not-allowed;
      background: #e5e7eb;
      color: #6b7280;
      border-color: #9ca3af;
    }

    .print-layout-group.is-disabled .print-mode-icon {
      background: #e5e7eb;
      border-color: #9ca3af;
    }

    .print-layout-group.is-disabled .print-mode-icon span {
      border-color: #6b7280;
      background: #d1d5db;
    }
    /*
    |--------------------------------------------------------------------------
    | Responsive screen layout
    |--------------------------------------------------------------------------
    */

    @media screen and (max-width: 700px) {
      .print-layout {
        grid-template-columns: 1fr;
      }

      .bench-section {
        max-width: 100%;
      }
    }

    @media screen and (max-width: 400px) {
      .lower-grid {
        grid-template-columns: 1fr;
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Printed page orientations
    |--------------------------------------------------------------------------
    */

    @page {
      size: portrait;
      margin: 0.35in;
    }

    @page portraitPage {
      size: portrait;
      margin: 0.35in;
    }

    @page landscapePage {
      size: landscape;
      margin: 0.35in;
    }

    body.portrait {
      page: portraitPage;
    }

    body.landscape {
      page: landscapePage;
    }

    /*
    |--------------------------------------------------------------------------
    | Base print styles
    |--------------------------------------------------------------------------
    */

    @media print {
      html,
      body {
        width: 100%;
        margin: 0;
        padding: 0;
        background: #fff;
      }

      body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .print-actions {
        display: none !important;
      }

      .sheet {
        width: 100%;
        max-width: none;
        margin: 0;
      }

      .sheet-header {
        padding: 8px 10px;
        margin: 0 0 10px;
        page-break-after: avoid;
        break-after: avoid;
      }

      .print-brand-header {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      .print-brand-logo {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .print-layout {
        grid-template-columns: minmax(0, 1fr) 190px;
        gap: 10px;
      }

      .section {
        margin-bottom: 10px;
      }

      .section-title {
        margin-bottom: 5px;
        font-size: 14px;
      }

      table {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      th,
      td {
        padding: 5px 4px;
        font-size: 10px;
        line-height: 1.15;
      }

      .inning-cell {
        height: 34px;
        font-size: 10px;
      }

      .small-table th,
      .small-table td {
        padding: 5px 4px;
        font-size: 10px;
      }

      .batting-order-col {
        width: 32px;
      }

      .small-table tbody tr:nth-child(odd) {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .free-print-watermark {
        position: absolute;
        inset: 0;
        z-index: 50;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      /*
      |--------------------------------------------------------------------------
      | Single-page layout
      |--------------------------------------------------------------------------
      | No forced page breaks. The compact batting-order styling above remains.
      */

      body.print-single .print-layout {
        display: grid;
      }

      body.print-single .print-page {
        page-break-before: auto;
        break-before: auto;
        page-break-after: auto;
        break-after: auto;
      }

      /*
      |--------------------------------------------------------------------------
      | Two-page layout
      |--------------------------------------------------------------------------
      | Page 1: field and bench
      | Page 2: large batting order
      */

      body.print-split .print-layout {
        display: block;
      }

      body.print-split .print-page-field {
        width: 100%;
        margin: 0;
        padding: 0;
        transform: none;
        page-break-before: auto;
        break-before: auto;
        page-break-after: always;
        break-after: page;
        page-break-inside: auto;
        break-inside: auto;
      }

      body.print-split .print-page-batting {
        width: 100%;
        max-width: none;
        min-height: 0;
        margin: 0;
        padding: 0;
        transform: none;
        page-break-before: auto;
        break-before: auto;
        page-break-after: auto;
        break-after: auto;
        page-break-inside: auto;
        break-inside: auto;
      }

      /*
      |--------------------------------------------------------------------------
      | Large batting order — two-page option only
      |--------------------------------------------------------------------------
      */

      body.print-split .print-page-batting .batting-section {
        width: 100%;
        margin: 0;
      }

      body.print-split .print-page-batting .section-title {
        margin: 0 0 14px;
        padding-bottom: 10px;
        border-bottom-width: 3px;
        font-size: 26pt;
        line-height: 1.1;
        text-align: center;
      }

      body.print-split .print-page-batting .batting-section table {
        width: 100%;
        height: auto;
        table-layout: fixed;
      }

      body.print-split .print-page-batting .small-table thead th {
        padding: 12px 14px;
        font-size: 15pt;
        line-height: 1.2;
      }

      body.print-split .print-page-batting .small-table tbody tr {
        height: 48px;
      }

      body.print-split .print-page-batting .small-table tbody td {
        padding: 10px 14px;
        font-size: 18pt;
        line-height: 1.2;
        font-weight: 700;
      }

      body.print-split .print-page-batting .batting-order-col {
        width: 80px;
        font-size: 20pt;
        font-weight: 900;
      }

      /*
      |--------------------------------------------------------------------------
      | Separate late-options sheet
      |--------------------------------------------------------------------------
      */

      body.late-options-separate .print-page-late-options {
        page-break-before: always;
        break-before: page;
        page-break-after: auto;
        break-after: auto;
      }

      body.late-options-inline .print-page-late-options {
        page-break-before: auto;
        break-before: auto;
        page-break-after: auto;
        break-after: auto;
      }

      .print-late-inning-box {
        page-break-inside: avoid;
        break-inside: avoid;
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Landscape adjustments
    |--------------------------------------------------------------------------
    */

    @media print {
      body.landscape .sheet {
        width: 100%;
      }

      body.landscape .print-layout {
        width: 100%;
      }

      body.print-split.landscape .print-page-field {
        width: 100%;
        transform: none;
      }

      body.print-split.landscape .print-page-batting {
        width: 100%;
        transform: none;
      }

      body.print-split.landscape
        .print-page-batting
        .small-table
        tbody
        tr {
        height: 40px;
      }

      body.print-split.landscape
        .print-page-batting
        .small-table
        tbody
        td {
        padding: 7px 12px;
        font-size: 16pt;
      }
    }
    @media print {
      /*
      |--------------------------------------------------------------------------
      | Landscape two-page mode — fit field lineup and bench on page 1
      |--------------------------------------------------------------------------
      */

      body.print-split.landscape .print-page-field {
        page-break-after: always;
        break-after: page;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-split.landscape .print-page-field .section {
        margin-bottom: 6px;
      }

      body.print-split.landscape .print-page-field .section-title {
        margin-bottom: 3px;
        padding-bottom: 2px;
        font-size: 11px;
        line-height: 1;
      }

      body.print-split.landscape .print-page-field table {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-split.landscape .print-page-field th,
      body.print-split.landscape .print-page-field td {
        padding: 2px 3px;
        font-size: 8px;
        line-height: 1;
      }

      body.print-split.landscape .print-page-field thead th {
        font-size: 8px;
      }

      body.print-split.landscape .print-page-field .inning-cell {
        height: 24px;
        font-size: 8px;
        line-height: 1;
      }

      body.print-split.landscape .print-page-field .bench-table td {
        height: 22px;
      }

      body.print-split.landscape .print-page-field .position-col {
        width: 48px;
      }

      body.print-split.landscape .print-page-field .diagonal {
        width: 48px;
        height: 30px;
      }

      body.print-split.landscape .print-page-field .diagonal .pos,
      body.print-split.landscape .print-page-field .diagonal .inn {
        font-size: 7px;
      }

      body.print-split.landscape .print-page-field .diagonal .pos {
        bottom: 7px;
        left: 0;
      }

      body.print-split.landscape .print-page-field .diagonal .inn {
        top: 7px;
        right: 4px;
      }

      body.print-split.landscape .bench-section {
        margin-top: 2px;
        margin-bottom: 0;
      }

      body.print-split.landscape .sheet-header {
        padding: 5px 8px;
        margin-bottom: 5px;
      }

      body.print-split.landscape .print-brand-logo {
        max-height: 28px;
        max-width: 90px;
      }

      body.print-split.landscape .print-brand-name {
        font-size: 16px;
      }

      body.print-split.landscape .print-brand-subtitle {
        font-size: 9px;
      }
    }

    @media print {
      /*
      |--------------------------------------------------------------------------
      | Single-page landscape — keep field lineup and bench together
      |--------------------------------------------------------------------------
      */

      body.print-single.landscape .print-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 190px;
        gap: 10px;
        align-items: start;
      }

      body.print-single.landscape .print-page-field {
        width: 100%;
        margin: 0;
        padding: 0;
        page-break-before: auto;
        break-before: auto;
        page-break-after: auto;
        break-after: auto;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-single.landscape .field-bench-column {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-single.landscape .print-page-field .section {
        margin-bottom: 5px;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-single.landscape .print-page-field .section-title {
        margin-bottom: 3px;
        padding-bottom: 2px;
        font-size: 11px;
        line-height: 1;
      }

      body.print-single.landscape .print-page-field table {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-single.landscape .print-page-field th,
      body.print-single.landscape .print-page-field td {
        padding: 2px 3px;
        font-size: 8px;
        line-height: 1;
      }

      body.print-single.landscape .print-page-field thead th {
        font-size: 8px;
      }

      body.print-single.landscape .print-page-field .inning-cell {
        height: 23px;
        font-size: 8px;
        line-height: 1;
      }

      body.print-single.landscape .print-page-field .bench-table td {
        height: 21px;
      }

      body.print-single.landscape .print-page-field .position-col {
        width: 46px;
      }

      body.print-single.landscape .print-page-field .diagonal {
        width: 46px;
        height: 29px;
      }

      body.print-single.landscape .print-page-field .diagonal .pos,
      body.print-single.landscape .print-page-field .diagonal .inn {
        font-size: 7px;
      }

      body.print-single.landscape .print-page-field .diagonal .pos {
        bottom: 7px;
        left: 0;
      }

      body.print-single.landscape .print-page-field .diagonal .inn {
        top: 7px;
        right: 4px;
      }

      body.print-single.landscape .bench-section {
        margin-top: 2px;
        margin-bottom: 0;
        page-break-before: avoid;
        break-before: avoid;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.print-single.landscape .sheet-header {
        padding: 5px 8px;
        margin-bottom: 5px;
      }

      body.print-single.landscape .print-brand-logo {
        max-width: 90px;
        max-height: 28px;
      }

      body.print-single.landscape .print-brand-name {
        font-size: 16px;
      }

      body.print-single.landscape .print-brand-subtitle {
        font-size: 9px;
      }

      /*
      |--------------------------------------------------------------------------
      | Keep batting order compact beside the field lineup
      |--------------------------------------------------------------------------
      */

      body.print-single.landscape .print-page-batting {
        width: 190px;
        min-width: 190px;
        page-break-before: auto;
        break-before: auto;
        page-break-after: auto;
        break-after: auto;
      }

      body.print-single.landscape .print-page-batting .batting-section table {
        height: auto;
      }

      body.print-single.landscape .print-page-batting .small-table th,
      body.print-single.landscape .print-page-batting .small-table td {
        padding: 3px 3px;
        font-size: 8px;
        line-height: 1;
      }

      body.print-single.landscape .print-page-batting .small-table tbody tr {
        height: 22px;
      }

      body.print-single.landscape .print-page-batting .batting-order-col {
        width: 28px;
      }
    }
    @media print {
      /*
      |--------------------------------------------------------------------------
      | Mixed orientation
      |--------------------------------------------------------------------------
      | Page 1: field and bench in landscape
      | Page 2: batting order in portrait
      |--------------------------------------------------------------------------
      */

      body.mixed-orientation {
        page: auto;
      }

      body.mixed-orientation .sheet-header {
        page: landscapePage;
      }

      body.mixed-orientation .print-layout {
        display: block;
      }

      /*
      |--------------------------------------------------------------------------
      | Page 1 — landscape field lineup and bench
      |--------------------------------------------------------------------------
      */

      body.mixed-orientation .print-page-field {
        page: landscapePage;
        width: 100%;
        margin: 0;
        padding: 0;

        page-break-before: auto;
        break-before: auto;

        page-break-after: always;
        break-after: page;

        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.mixed-orientation .field-bench-column {
        width: 100%;
      }

      body.mixed-orientation
        .print-page-field
        .section {
        margin-bottom: 6px;
      }

      body.mixed-orientation
        .print-page-field
        .section-title {
        margin-bottom: 3px;
        padding-bottom: 2px;
        font-size: 11px;
        line-height: 1.1;
      }

      body.mixed-orientation
        .print-page-field
        th,
      body.mixed-orientation
        .print-page-field
        td {
        padding: 3px 4px;
        font-size: 9px;
        line-height: 1.05;
      }

      body.mixed-orientation
        .print-page-field
        .inning-cell {
        height: 27px;
        font-size: 9px;
      }

      body.mixed-orientation
        .print-page-field
        .bench-table
        td {
        height: 25px;
      }

      body.mixed-orientation
        .print-page-field
        .position-col {
        width: 52px;
      }

      body.mixed-orientation
        .print-page-field
        .diagonal {
        width: 52px;
        height: 34px;
      }

      body.mixed-orientation
        .print-page-field
        .diagonal
        .pos,
      body.mixed-orientation
        .print-page-field
        .diagonal
        .inn {
        font-size: 7px;
      }

      body.mixed-orientation
        .print-page-field
        .diagonal
        .pos {
        bottom: 8px;
        left: 0;
      }

      body.mixed-orientation
        .print-page-field
        .diagonal
        .inn {
        top: 8px;
        right: 4px;
      }

      /*
      |--------------------------------------------------------------------------
      | Page 2 — portrait batting order
      |--------------------------------------------------------------------------
      */

      body.mixed-orientation .print-page-batting {
        page: portraitPage;
        width: 100%;
        max-width: none;
        min-height: 0;
        margin: 0;
        padding: 0;

        page-break-before: always;
        break-before: page;

        page-break-after: auto;
        break-after: auto;

        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.mixed-orientation
        .print-page-batting
        .batting-section {
        width: 100%;
        max-width: 7.4in;
        margin: 0 auto;
      }

      body.mixed-orientation
        .print-page-batting
        .section-title {
        margin: 0 0 16px;
        padding-bottom: 10px;
        border-bottom: 3px solid #000;
        font-size: 26pt;
        line-height: 1.1;
        text-align: center;
      }

      body.mixed-orientation
        .print-page-batting
        table {
        width: 100%;
        height: auto;
        table-layout: fixed;
      }

      body.mixed-orientation
        .print-page-batting
        thead
        th {
        padding: 12px 14px;
        font-size: 15pt;
      }

      body.mixed-orientation
        .print-page-batting
        tbody
        tr {
        height: 52px;
      }

      body.mixed-orientation
        .print-page-batting
        tbody
        td {
        padding: 10px 14px;
        font-size: 18pt;
        line-height: 1.2;
        font-weight: 700;
      }

      body.mixed-orientation
        .print-page-batting
        .batting-order-col {
        width: 82px;
        font-size: 20pt;
        font-weight: 900;
      }

      /*
      |--------------------------------------------------------------------------
      | Late-options sheet
      |--------------------------------------------------------------------------
      */

      body.mixed-orientation
        .print-page-late-options {
        page: landscapePage;
      }

      body.mixed-orientation.late-options-separate
        .print-page-late-options {
        page-break-before: always;
        break-before: page;
      }
    }
    @media print {
      /*
      |--------------------------------------------------------------------------
      | Fill page 1 in landscape and mixed layouts
      |--------------------------------------------------------------------------
      */

      body.landscape .print-page-field,
      body.mixed-orientation .print-page-field {
        height: 7.12in;
        min-height: 7.12in;
        max-height: 7.12in;
        overflow: hidden;
      }

      body.landscape .print-page-field .field-bench-column,
      body.mixed-orientation .print-page-field .field-bench-column {
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100%;
        min-height: 0;
      }

      /*
       * Divide the available page height based on the number of rows.
       * A nine-row field receives more space than a two- or three-row bench.
       */

      body.landscape .print-page-field .field-bench-column > .section:first-child,
      body.mixed-orientation .print-page-field .field-bench-column > .section:first-child {
          flex-grow: var(--field-flex-weight);
        flex-basis: 0;
        display: flex;
        flex-direction: column;
        min-height: 0;
        margin-bottom: 6px;
      }

      body.landscape .print-page-field .bench-section,
      body.mixed-orientation .print-page-field .bench-section {
          flex-grow: var(--bench-flex-weight);
        flex-basis: 0;
        display: flex;
        flex-direction: column;
        min-height: 0;
        margin-top: 0;
        margin-bottom: 0;
      }

      body.landscape .print-page-field .table-wrap,
      body.mixed-orientation .print-page-field .table-wrap {
        flex: 1 1 auto;
        display: flex;
        min-height: 0;
        overflow: hidden;
      }

      body.landscape .print-page-field table,
      body.mixed-orientation .print-page-field table {
        width: 100%;
        height: 100%;
        table-layout: fixed;
      }

      /*
       * Allow the browser to distribute the unused height across all rows.
       * These override the earlier 21px, 23px, 25px and 27px row heights.
       */

      body.landscape .print-page-field tbody tr,
      body.mixed-orientation .print-page-field tbody tr {
        height: auto;
      }

      body.landscape .print-page-field .inning-cell,
      body.mixed-orientation .print-page-field .inning-cell,
      body.landscape .print-page-field .bench-table td,
      body.mixed-orientation .print-page-field .bench-table td {
        height: auto;
        min-height: 0;
      }
      body.landscape .print-page-field .inning-cell,
      body.mixed-orientation .print-page-field .inning-cell {
        font-size: 14px;
        font-weight: 600;
        line-height: 1.15;
      }
      body.landscape .print-page-field th,
      body.landscape .print-page-field td,
      body.mixed-orientation .print-page-field th,
      body.mixed-orientation .print-page-field td {
        padding: 4px 5px;
        font-size: 12px;
        line-height: 1.1;
      }

      body.landscape .print-page-field .section-title,
      body.mixed-orientation .print-page-field .section-title {
        flex: 0 0 auto;
        margin: 0 0 4px;
        padding-bottom: 3px;
        font-size: 12px;
        line-height: 1.1;
      }
    }
    @media print {
      /*
      |--------------------------------------------------------------------------
      | Mixed orientation page 1 correction
      |--------------------------------------------------------------------------
      | Avoid Chrome's flex/table pagination issue.
      */

      body.mixed-orientation .print-page-field {
        height: 6.95in;
        min-height: 6.95in;
        max-height: 6.95in;
        overflow: hidden;
      }

      body.mixed-orientation .print-page-field .field-bench-column {
        display: grid;
        grid-template-rows:
          minmax(0, var(--field-grid-track))
          minmax(0, var(--bench-grid-track));
        gap: 6px;
        width: 100%;
        height: 100%;
      }

      body.mixed-orientation
        .print-page-field
        .field-bench-column
        > .section:first-child,
      body.mixed-orientation
        .print-page-field
        .bench-section {
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        min-height: 0;
        margin: 0;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.mixed-orientation .print-page-field .table-wrap {
        display: block;
        width: 100%;
        height: 100%;
        min-height: 0;
        overflow: hidden;
      }

      body.mixed-orientation .print-page-field table {
        width: 100%;
        height: 100%;
        table-layout: fixed;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.mixed-orientation .print-page-field thead {
        display: table-header-group;
      }

      body.mixed-orientation .print-page-field tbody {
        height: 100%;
      }

      body.mixed-orientation .print-page-field tbody tr {
        height: auto;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      body.mixed-orientation .print-page-field .inning-cell,
      body.mixed-orientation .print-page-field .bench-table td {
        height: auto;
      }

      /*
       * Do not create two separate page breaks around the batting page.
       * The field page already ends with break-after: page.
       */
      body.mixed-orientation .print-page-batting {
        page-break-before: auto;
        break-before: auto;
      }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="print-actions">
      <div class="print-actions-left">
          <a href="generate.php?game_id=<?= (int)$game['id'] ?>">Back</a>
        <div class="orientation-group">
          <label for="orientation">Orientation</label>
          <div class="orientation-control">
            <select id="orientation" onchange="setOrientation(this.value)">
              <option value="portrait">Portrait</option>
              <option value="landscape">Landscape</option>
                <option value="mixed">Mixed: Field Landscape / Batting Portrait</option>
            </select>
            <span id="orientationIcon" class="orientation-icon portrait-icon" aria-hidden="true">
              <span></span>
            </span>
          </div>
        </div>
        <div class="print-layout-group" id="print-mode-group">
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
        <button type="button" onclick="printLineup()">Print</button>
      </div>


      <div class="print-layout-group">
        <label for="late_options_mode">Late Options</label>
        <div class="orientation-control">
          <select id="late_options_mode" onchange="setLateOptionsMode(this.value)">
            <option value="inline">Inline</option>
            <option value="separate">Separate Sheet</option>
          </select>
        </div>
      </div>



      <div class="print-actions-right">
        <form method="get" action="print_lineup.php" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
          <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">

          <div>
            <label for="label_mode">Player Display</label>
            <select name="label_mode" id="label_mode" onchange="this.form.submit()">
              <?php foreach (player_label_mode_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $labelMode === $value ? 'selected' : '' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
    </div>

    <div class="sheet-header">
        <div class="print-brand-header">
            <?php if ($canUsePrintBranding && $printLogoPath !== ''): ?>
              <img
                src="<?= h($printLogoPath) ?>"
                alt="<?= h($printBrandName) ?>"
                class="print-brand-logo"
              >
            <?php endif; ?>

          <div>
            <div class="print-brand-name"><?= h($printBrandName) ?></div>
            <div class="print-brand-subtitle"><?= h((string)$game['game_id']) ?></div>
          </div>
        </div>
    </div>

    <div class="print-layout">
      <div class="print-page print-page-field">
          <?php render_free_print_watermark($showFreeWatermark); ?>
          <div
            class="field-bench-column"
            style="
              --field-flex-weight: <?= max(1, count($displayPositions)) ?>;
              --bench-flex-weight: <?= max(1, $benchCount) ?>;
              --field-grid-track: <?= max(1, count($displayPositions)) ?>fr;
              --bench-grid-track: <?= max(1, $benchCount) ?>fr;
            "
          >
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
                  <?php foreach ($displayPositions as $position): ?>
                    <?php $isOpenCenterField = $isEightPlayerMode && $position === 'CF'; ?>
                    <tr>
                      <th class="position-col"><?= h((string)$position) ?></th>

                      <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
                        <td class="inning-cell <?= $isOpenCenterField ? 'open-position-cell' : '' ?>">
                          <?php if ($isOpenCenterField): ?>
                            <span class="open-position-label"></span>
                          <?php else: ?>
                            <?= h(dugout_player_label($lineupGrid[$position][$inning] ?? null, $labelMode, $rosterMap)) ?>
                          <?php endif; ?>
                        </td>
                      <?php endfor; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="section bench-section">
            <?php if ($benchCount <= 0): ?>
            <?php else: ?>
            <div class="section-title">Bench</div>
              <div class="table-wrap lineup-width">
                <table class="bench-table">
                  <thead>
                    <tr>
                      <th class="position-col">Bench</th>
                      <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
                        <th><?= h(ordinal($inning)) ?></th>
                      <?php endfor; ?>
                    </tr>
                  </thead>

                  <tbody>
                    <?php for ($slot = 0; $slot < $benchCount; $slot++): ?>
                      <tr>
                        <th class="position-col">B<?= $slot + 1 ?></th>
                        <?php for ($inning = 1; $inning <= $innings; $inning++): ?>
                          <td class="inning-cell">
                            <?= h(dugout_player_label($benchGrid[$slot][$inning] ?? null, $labelMode, $rosterMap)) ?>
                          </td>
                        <?php endfor; ?>
                      </tr>
                    <?php endfor; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>


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
              <?php foreach ($roster as $player): ?>
                <tr>
                  <td class="batting-order-col"><?= (int)($player['batting_order'] ?? 0) ?></td>
                  <td><?= h(dugout_roster_label($player, $labelMode)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if (is_array($lateInningBenchPlan) && !empty($lateInningBenchPlan['has_blank_late_innings'])): ?>
      <div class="print-page print-page-late-options">
        <?php render_free_print_watermark($showFreeWatermark); ?>

        <div class="print-late-inning-box">
          <h3>Late Inning Bench Options</h3>

          <p>
            Innings <?= h(implode(', ', array_map('strval', $lateInningBenchPlan['blank_innings'] ?? []))) ?>
            are blank. Use this sheet when deciding who can sit later.
          </p>

          <div class="late-options-grid">
            <div>
              <h4>Players Who Can Still Be Benched</h4>

              <?php if (!empty($lateInningBenchPlan['can_bench'])): ?>
                <table class="print-mini-table">
                  <thead>
                    <tr>
                      <th>Player</th>
                      <th>Can Sit</th>
                      <th>Already Benched</th>
                      <th>Played</th>
                    </tr>
                  </thead>

                  <tbody>
                    <?php foreach ($lateInningBenchPlan['can_bench'] as $item): ?>
                      <tr>
                        <td><?= h(format_player_label($item['player'], $labelMode)) ?></td>
                        <td><?= (int)$item['bench_room'] ?></td>
                        <td><?= (int)$item['bench_innings'] ?></td>
                        <td><?= (int)$item['played_innings'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <p><strong>No players can safely be benched again based on the current fair-play calculation.</strong></p>
              <?php endif; ?>
            </div>

            <div>
              <h4>Players Who Cannot Be Benched</h4>

              <?php if (!empty($lateInningBenchPlan['cannot_bench'])): ?>
                <table class="print-mini-table">
                  <thead>
                    <tr>
                      <th>Player</th>
                      <th>Already Benched</th>
                      <th>Must Play</th>
                    </tr>
                  </thead>

                  <tbody>
                    <?php foreach ($lateInningBenchPlan['cannot_bench'] as $item): ?>
                      <tr>
                        <td><?= h(format_player_label($item['player'], $labelMode)) ?></td>
                        <td><?= (int)$item['bench_innings'] ?></td>
                        <td>Yes</td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <p>No players are currently locked into playing the remaining blank inning(s).</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="late-options-notes">
            <h4>Coach Notes</h4>
            <div class="notes-box"></div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <script>
    function setOrientation(mode) {
      document.body.classList.remove(
        'portrait',
        'landscape',
        'mixed-orientation'
      );

      if (mode === 'mixed') {
        document.body.classList.add('mixed-orientation');
      } else {
        document.body.classList.add(mode);
      }

      const icon =
        document.getElementById('orientationIcon');

      const printModeSelect =
        document.getElementById('print_mode');

      const printModeGroup =
        document.getElementById('print-mode-group');

      if (icon) {
        icon.classList.remove(
          'portrait-icon',
          'landscape-icon'
        );

        icon.classList.add(
          mode === 'portrait'
            ? 'portrait-icon'
            : 'landscape-icon'
        );
      }

      if (printModeSelect) {
        if (mode === 'mixed') {
          printModeSelect.value = 'split';
          setPrintMode('split');

          printModeSelect.disabled = true;
          printModeSelect.setAttribute(
            'aria-disabled',
            'true'
          );
        } else {
          printModeSelect.disabled = false;
          printModeSelect.removeAttribute(
            'aria-disabled'
          );
        }
      }

      if (printModeGroup) {
        printModeGroup.classList.toggle(
          'is-disabled',
          mode === 'mixed'
        );
      }

      localStorage.setItem(
        'printOrientation',
        mode
      );
    }

    function setPrintMode(mode) {
      document.body.classList.remove(
        'print-single',
        'print-split'
      );

      document.body.classList.add(
        mode === 'split'
          ? 'print-split'
          : 'print-single'
      );

      const icon =
        document.getElementById('printModeIcon');

      if (icon) {
        icon.classList.remove(
          'single-icon',
          'split-icon'
        );

        icon.classList.add(
          mode === 'split'
            ? 'split-icon'
            : 'single-icon'
        );
      }

      localStorage.setItem(
        'printLineupPrintMode',
        mode
      );
    }

    function setLateOptionsMode(mode) {
      document.body.classList.remove(
        'late-options-inline',
        'late-options-separate'
      );

      document.body.classList.add(
        mode === 'separate'
          ? 'late-options-separate'
          : 'late-options-inline'
      );

      localStorage.setItem(
        'printLateOptionsMode',
        mode
      );
    }

    function printLineup() {
      const orientationSelect =
        document.getElementById('orientation');

      const printModeSelect =
        document.getElementById('print_mode');

      const lateOptionsSelect =
        document.getElementById('late_options_mode');

      const orientation =
        orientationSelect?.value || 'portrait';

      let printMode =
        printModeSelect?.value || 'single';

      const lateOptionsMode =
        lateOptionsSelect?.value || 'inline';

      /*
       * Mixed always requires the split layout.
       */
      if (orientation === 'mixed') {
        printMode = 'split';
      }

      setPrintMode(printMode);
      setLateOptionsMode(lateOptionsMode);
      setOrientation(orientation);

      window.print();
    }

    document.addEventListener('DOMContentLoaded', function () {
      /*
       * Restore print mode first so Mixed orientation can override it.
       */
      const savedPrintMode =
        localStorage.getItem('printLineupPrintMode') || 'single';

      const printModeSelect =
        document.getElementById('print_mode');

      if (printModeSelect) {
        printModeSelect.value = savedPrintMode;
        setPrintMode(savedPrintMode);
      }

      const savedOrientation =
        localStorage.getItem('printOrientation') || 'portrait';

      const orientationSelect =
        document.getElementById('orientation');

      if (orientationSelect) {
        orientationSelect.value = savedOrientation;
        setOrientation(savedOrientation);
      }

      const savedLateOptionsMode =
        localStorage.getItem('printLateOptionsMode') || 'inline';

      const lateOptionsSelect =
        document.getElementById('late_options_mode');

      if (lateOptionsSelect) {
        lateOptionsSelect.value = savedLateOptionsMode;
        setLateOptionsMode(savedLateOptionsMode);
      }
    });
    </script>
</body>
</html>
