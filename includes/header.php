<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/coach_helper_functions.php';

if (!isset($pageTitle)) {
    $pageTitle = 'BenchBuddy';
}

if (!isset($currentPage)) {
    $currentPage = '';
}

$teamId = isset($_GET['team_id']) && user_belongs_to_team(current_user_id(), (int)$_GET['team_id'])
    ? (int)$_GET['team_id']
    : current_team_id();
$userId = current_user_id();
$userName = current_user_name();
$userTeams = $userId > 0 ? get_user_teams($userId) : [];

$themeName = 'red';

if ($teamId > 0) {
    $stmt = db()->prepare("
        SELECT theme_color
        FROM teams
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $teamId,
    ]);

    $teamThemeColor = (string)($stmt->fetchColumn() ?: '');

    if ($teamThemeColor !== '') {
        $themeName = $teamThemeColor;
    }
}
$themeOptions = get_allowed_theme_colors();
$themeVars = $themeOptions[$themeName] ?? $themeOptions['red'];

$headerTeamName = 'BenchBuddy';
if ($teamId > 0) {
    $stmt = db()->prepare("
        SELECT *
        FROM teams
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $teamId,
    ]);

    $headerTeamRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($headerTeamRow && !empty($headerTeamRow['name'])) {
        $headerTeamName = (string)$headerTeamRow['name'];
    }
}

$requestUri = $_SERVER['REQUEST_URI'] ?? 'index.php';
$redirectTarget = basename(parse_url($requestUri, PHP_URL_PATH) ?? 'index.php');
$queryString = parse_url($requestUri, PHP_URL_QUERY);
if (!empty($queryString)) {
    $redirectTarget .= '?' . $queryString;
}
$labelMode = function_exists('player_label_mode')
    ? player_label_mode()
    : 'both';

$host = $_SERVER['HTTP_HOST'] ?? '';

$isProduction = in_array(
    $host,
    [
        'benchbuddy.devworks.space',
        'www.benchbuddy.devworks.space',
    ],
    true
);

$isStaging = !$isProduction && (
    str_contains($host, 'staging')
    || str_contains($host, 'stage')
    || str_contains($host, 'benchbuddy-staging')
);

$titlePrefix = $isStaging ? 'Staging - ' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($titlePrefix . $pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <?php
  function asset_url(string $path): string
  {
      $fullPath = $_SERVER['DOCUMENT_ROOT'] . $path;
      if (is_file($fullPath)) {
          return $path . '?v=' . filemtime($fullPath);
      }
      return $path;
  }
  ?>
  <?php
  $cssFiles = [
      'auth.css',
      'base.css',
      'cards.css',
      'coach-helper.css',
      'components.css',
      'forms.css',
      'home.css',
      'layout.css',
      'manual-lineup.css',
      'navigation.css',
      'print.css',
      'responsive.css',
      'tables.css',

  ];
  ?>
  <?php foreach ($cssFiles as $cssFile): ?>
    <link rel="stylesheet" href="/assets/css/<?= h($cssFile) ?>?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/' . $cssFile) ?>">
  <?php endforeach; ?>
    <style>
    :root {
      --primary: <?= h($themeVars['primary']) ?>;
      --primary-dark: <?= h($themeVars['primary_dark']) ?>;
      --nav-bg: <?= h($themeVars['nav_bg']) ?>;
      --nav-text: <?= h($themeVars['nav_text']) ?>;
    }
    </style>
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="BenchBuddy">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">

  <meta name="theme-color" content="#6e0000">

  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="shortcut icon" href="/assets/favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&display=swap" rel="stylesheet">

  <meta property="og:title" content="BenchBuddy">
  <meta property="og:description" content="The Dugout’s Smartest Clipboard. Build inning-by-inning baseball lineups in seconds.">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://benchbuddy.devworks.space">
  <meta property="og:image" content="https://benchbuddy.devworks.space/assets/social-preview.png">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="BenchBuddy">
  <meta name="twitter:description" content="The Dugout’s Smartest Clipboard. Build inning-by-inning baseball lineups in seconds.">
  <meta name="twitter:image" content="https://benchbuddy.devworks.space/assets/social-preview.png">
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

  <?php if ($isProduction): ?>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-FNBZW1WHCV"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-FNBZW1WHCV');
  </script>
  <?php endif; ?>

  <?php if ($isProduction && !empty($_SESSION['ga_events'])): ?>
  <script>
  <?php foreach ($_SESSION['ga_events'] as $event): ?>
  gtag('event', '<?= htmlspecialchars($event, ENT_QUOTES) ?>');
  <?php endforeach; ?>
  </script>
  <?php unset($_SESSION['ga_events']); ?>
  <?php endif; ?>

</head>
<body class="<?= is_logged_in() ? 'logged-in' : 'logged-out' ?> <?= $currentPage ?>">
<div class="no-print">
<?php if ($isStaging): ?>
  <div class="staging-banner">
    STAGING ENVIRONMENT · Changes here do not affect the live site
  </div>
<?php endif; ?>
  <header class="site-header">
    <div class="site-header-inner">
      <div class="site-header-left">
        <a href="index.php" class="site-logo">
          <img src="/assets/header_logo.svg" alt="BenchBuddy" class="brand-logo">
        </a>
        <?php
        $headerTeamLogo = '';

        if (
            function_exists('current_team_id') &&
            function_exists('get_team_by_id')
        ) {
            $headerCurrentTeamId = $teamId;

            if ($headerCurrentTeamId > 0) {
                $stmt = db()->prepare("
                    SELECT *
                    FROM teams
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    'id' => $headerCurrentTeamId,
                ]);

                $headerTeam = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($headerTeam)) {
                    $headerTeamLogo = trim((string)($headerTeam['print_logo_path'] ?? ''));

                    if ($headerTeamName === '') {
                        $headerTeamName = trim((string)($headerTeam['name'] ?? ''));
                    }
                }
            }
        }
        ?>

        <?php if ($headerTeamLogo !== ''): ?>
          <div class="site-team-brand">
            <img
              src="<?= h($headerTeamLogo) ?>"
              alt="<?= h($headerTeamName !== '' ? $headerTeamName : 'Team') ?> logo"
              class="site-team-logo"
            >
          </div>
        <?php elseif ($headerTeamName !== ''): ?>
          <div class="site-team-name"><?= h($headerTeamName) ?></div>
        <?php endif; ?>




      </div>

      <?php if (is_logged_in()): ?>
          <?php
          $navTeams = get_user_teams(current_user_id());
          $activeTeamId = $teamId;
          ?>

          <?php if (count($navTeams) > 1): ?>
              <form method="post" action="switch_team.php" class="team-switcher">
                  <?= csrf_field() ?>
                  <input type="hidden" name="redirect" value="<?= h(basename($_SERVER['PHP_SELF'])) ?>">

                  <select name="team_id" onchange="this.form.submit()">
                      <?php foreach ($navTeams as $navTeam): ?>
                          <option
                              value="<?= (int)$navTeam['id'] ?>"
                              <?= (int)$navTeam['id'] === $activeTeamId ? 'selected' : '' ?>
                          >
                              <?= h($navTeam['name']) ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
              </form>
          <?php endif; ?>
      <?php endif; ?>
      <?php if (is_logged_in()): ?>
        <?php include __DIR__ . '/nav.php'; ?>
        <?php else: ?>
        <?php include __DIR__ . '/logged_out_nav.php'; ?>
        <?php endif; ?>



  </header>

  <div class="beta-banner">
    ⚠️ BenchBuddy is currently in <strong>Beta</strong>. You may encounter bugs or unfinished features.
    <a href="https://wa.me/17788781422">Let me know if I can help.</a>
  </div>


</div>
  <main class="page-wrap">
  <?php $flash = function_exists('flash_get') ? flash_get() : null; ?>

  <?php if ($flash): ?>
    <div class="msg <?= h((string)$flash['type']) ?>">
      <?= h((string)$flash['message']) ?>
    </div>
  <?php endif; ?>
<?php if (is_impersonating()): ?>
  <div class="msg info" style="margin-bottom:16px;">
    Support Mode: You are impersonating this account.
    <a href="admin_users.php" style="font-weight:bold; margin-left:8px;">Return to Admin</a>
  </div>
<?php endif; ?>
