<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$pageTitle = 'Account';
$currentPage = 'account';

$message = '';
$error = '';
$user = null;
$userTeams = [];
$userId = current_user_id();
$requestedTeamId = isset($_GET['team_id'])
    ? (int)$_GET['team_id']
    : 0;

if (
    $requestedTeamId > 0 &&
    user_belongs_to_team($userId, $requestedTeamId)
) {
    set_current_team($requestedTeamId);
    $_SESSION['current_team_id'] = $requestedTeamId;
    $currentTeamId = $requestedTeamId;
} else {
    $currentTeamId = current_team_id();
}
$currentTeam = null;
$currentTeamMembers = [];
$inviteLink = '';
$archivedTeams = [];
$featureRequests = [];

if (function_exists('current_user_id')) {
    $stmt = db()->prepare("
        SELECT id, title, description, status, admin_notes, created_at, updated_at
        FROM feature_requests
        WHERE user_id = :user_id
          AND user_dismissed_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);
    $featureRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $user = get_current_user_record();
    $referralCode = ensure_user_referral_code($userId);
    $referralUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'benchbuddy.ca') . '/signup.php?ref=' . urlencode($referralCode);
    if (!$user) {
        throw new RuntimeException('User account not found.');
    }

    $userTeams = get_user_teams($userId);
    $archivedTeams = get_archived_user_teams($userId);

    if ($currentTeamId > 0) {
        $currentTeam = get_team_by_id($currentTeamId);
        $currentTeamMembers = get_team_members($currentTeamId);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'update_profile':
                $fullName = (string)($_POST['full_name'] ?? '');
                $email = (string)($_POST['email'] ?? '');

                update_current_user_profile($fullName, $email);

                flash_redirect('ok', 'Profile updated successfully.', 'account.php');
                break;
            case 'update_theme_color':
                $themeColor = (string)($_POST['theme_color'] ?? 'red');

                update_user_theme_color($userId, $themeColor);

                flash_redirect('ok', 'Theme updated successfully.', 'account.php');
                break;
            case 'update_password':
                $currentPassword = (string)($_POST['current_password'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');

                update_current_user_password($currentPassword, $newPassword, $confirmPassword);

                flash_redirect('ok', 'Password updated successfully.', 'account.php');
                break;
                case 'update_print_branding':
                    if ($currentTeamId <= 0) {
                        throw new RuntimeException('No active team selected.');
                    }

                    if (!current_user_is_head_coach($currentTeamId)) {
                        throw new RuntimeException('Only a Head Coach can update team branding.');
                    }

                    $themeColor = (string)($_POST['theme_color'] ?? 'red');
                    update_user_theme_color($userId, $themeColor);

                    if (!team_can_use_feature($currentTeamId, 'print.custom_branding')) {
                        flash_redirect('ok', 'Theme updated successfully.', 'account.php');
                        break;
                    }

                    $printBrandName = trim((string)($_POST['print_brand_name'] ?? ''));

                    if (mb_strlen($printBrandName) > 150) {
                        throw new RuntimeException('Brand name must be 150 characters or less.');
                    }

                    $currentLogoPath = (string)($currentTeam['print_logo_path'] ?? '');
                    $newLogoPath = $currentLogoPath;

                    if (!empty($_POST['remove_print_logo'])) {
                        if ($currentLogoPath !== '') {
                            $oldLogoFile = __DIR__ . '/' . ltrim($currentLogoPath, '/');

                            if (is_file($oldLogoFile)) {
                                @unlink($oldLogoFile);
                            }
                        }

                        $newLogoPath = null;
                    }

                    if (!empty($_FILES['print_logo']['name'])) {
                        if ((int)($_FILES['print_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('Logo upload failed. Please try again.');
                        }

                        $maxBytes = 2 * 1024 * 1024;

                        if ((int)($_FILES['print_logo']['size'] ?? 0) > $maxBytes) {
                            throw new RuntimeException('Logo file must be 2MB or smaller.');
                        }

                        $tmpPath = (string)$_FILES['print_logo']['tmp_name'];
                        $imageInfo = @getimagesize($tmpPath);

                        if ($imageInfo === false) {
                            throw new RuntimeException('Logo must be a valid image file.');
                        }

                        $mimeType = (string)($imageInfo['mime'] ?? '');

                        $allowedTypes = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ];

                        if (!isset($allowedTypes[$mimeType])) {
                            throw new RuntimeException('Logo must be a JPG, PNG, or WebP image.');
                        }

                        $uploadDir = __DIR__ . '/uploads/team-logos';

                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        if (!is_writable($uploadDir)) {
                            throw new RuntimeException('Logo upload folder is not writable.');
                        }

                        $extension = $allowedTypes[$mimeType];
                        $fileName = 'team-' . $currentTeamId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
                        $destination = $uploadDir . '/' . $fileName;

                        if (!move_uploaded_file($tmpPath, $destination)) {
                            throw new RuntimeException('Could not save uploaded logo.');
                        }

                        if ($currentLogoPath !== '') {
                            $oldLogoFile = __DIR__ . '/' . ltrim($currentLogoPath, '/');

                            if (is_file($oldLogoFile)) {
                                @unlink($oldLogoFile);
                            }
                        }

                        $newLogoPath = 'uploads/team-logos/' . $fileName;
                    }

                    $stmt = db()->prepare("
                        UPDATE teams
                        SET print_brand_name = :print_brand_name,
                            print_logo_path = :print_logo_path
                        WHERE id = :team_id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        'print_brand_name' => $printBrandName !== '' ? $printBrandName : null,
                        'print_logo_path' => $newLogoPath !== '' ? $newLogoPath : null,
                        'team_id' => $currentTeamId,
                    ]);

                    flash_redirect('ok', 'Branding and appearance updated successfully.', 'account.php');
                    break;
                    case 'update_current_team':
                        if ($currentTeamId <= 0) {
                            throw new RuntimeException('No active team selected.');
                        }

                        if (!current_user_is_head_coach($currentTeamId)) {
                            throw new RuntimeException('Only a Head Coach can update team details.');
                        }

                        $teamName = trim((string)($_POST['team_name'] ?? ''));
                        $seasonLabel = trim((string)($_POST['season_label'] ?? ''));
                        $pitchRuleSetId = (int)($_POST['pitch_rule_set_id'] ?? 0);

                        if ($pitchRuleSetId <= 0) {
                            throw new RuntimeException('Pitch count rules are required.');
                        }

                        $ruleSet = get_pitch_rule_set($pitchRuleSetId);

                        if (!$ruleSet || (int)($ruleSet['is_active'] ?? 0) !== 1) {
                            throw new RuntimeException('Invalid pitch count rules selected.');
                        }

                        $division = (string)$ruleSet['division_label'];

                        if ($teamName === '') {
                            throw new RuntimeException('Team name is required.');
                        }

                        if ($division === '') {
                            throw new RuntimeException('Team division is required.');
                        }

                        $stmt = db()->prepare("
                            UPDATE teams
                            SET
                                name = :name,
                                season_label = :season_label,
                                division = :division,
                                pitch_rule_set_id = :pitch_rule_set_id
                            WHERE id = :team_id
                            LIMIT 1
                        ");

                        $stmt->execute([
                            'name' => $teamName,
                            'season_label' => $seasonLabel !== '' ? $seasonLabel : null,
                            'division' => $division,
                            'pitch_rule_set_id' => $pitchRuleSetId > 0 ? $pitchRuleSetId : null,
                            'team_id' => $currentTeamId,
                        ]);

                        flash_redirect('ok', 'Team details updated successfully.', 'account.php');
                        break;
                case 'create_team':
                    $teamName = trim((string)($_POST['team_name'] ?? ''));
                    $seasonLabel = trim((string)($_POST['season_label'] ?? ''));
                    $pitchRuleSetId = (int)($_POST['pitch_rule_set_id'] ?? 0);

                    if ($pitchRuleSetId <= 0) {
                        throw new RuntimeException('Pitch count rules are required.');
                    }

                    $ruleSet = get_pitch_rule_set($pitchRuleSetId);

                    if (!$ruleSet || (int)($ruleSet['is_active'] ?? 0) !== 1) {
                        throw new RuntimeException('Invalid pitch count rules selected.');
                    }
                    $division = (string)$ruleSet['division_label'];

                    if ($teamName === '') {
                        throw new RuntimeException('Team name is required.');
                    }

                    if ($division === '') {
                        throw new RuntimeException('Team division is required.');
                    }

                    $newTeamId = create_team_for_current_user(
                        $teamName,
                        $seasonLabel !== '' ? $seasonLabel : null,
                        $division
                    );
                    $stmt = db()->prepare("
                        UPDATE teams
                        SET pitch_rule_set_id = :pitch_rule_set_id
                        WHERE id = :team_id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        'pitch_rule_set_id' => $pitchRuleSetId,
                        'team_id' => $newTeamId,
                    ]);


                    set_current_team($newTeamId);

                    flash_redirect('ok', 'Team created successfully.', 'account.php');
                    break;

                    case 'add_team_member':
                        if ($currentTeamId <= 0) {
                            throw new RuntimeException('No active team selected.');
                        }

                        if (!current_user_is_head_coach($currentTeamId)) {
                            throw new RuntimeException('Only a Head Coach can manage team members.');
                        }

                        $currentTeamMemberCount = function_exists('count_team_members_for_limit')
                            ? count_team_members_for_limit($currentTeamId)
                            : count($currentTeamMembers);

                        assert_team_limit_available(
                            $currentTeamId,
                            'team_members_per_team',
                            $currentTeamMemberCount,
                            'Your current plan has reached its team member limit. Upgrade to add more coaches.'
                        );

                        $memberEmail = strtolower(trim((string)($_POST['member_email'] ?? '')));
                        $memberRole = normalize_team_role((string)($_POST['member_role'] ?? 'assistant_coach'));

                        if ($memberEmail === '') {
                            throw new RuntimeException('Email is required.');
                        }


                $existingUserId = find_user_id_by_email($memberEmail);

                if ($existingUserId > 0) {
                    add_user_to_team_with_role($existingUserId, $currentTeamId, $memberRole);
                    $message = 'Team member added successfully.';
                } else {
                    $invite = create_team_invitation($currentTeamId, $memberEmail, $memberRole);

                    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                        ? 'https'
                        : 'http';

                    $host = $_SERVER['HTTP_HOST'] ?? 'benchbuddy.ca';

                    $inviteLink = $scheme . '://' . $host . '/signup.php?invite=' . urlencode((string)$invite['invite_token']);

                    $teamNameForEmail = (string)($currentTeam['name'] ?? 'your team');
                    $inviterName = (string)($user['full_name'] ?? 'A coach');

                    try {
                        send_team_invitation_email(
                            $memberEmail,
                            $teamNameForEmail,
                            $inviterName,
                            $inviteLink
                        );
                    } catch (Throwable $e) {
                        error_log('Team invitation email failed: ' . $e->getMessage());
                    }

                    $message = 'Invite created successfully. An invitation email has been sent.';
                }

                $stmt = db()->prepare("
                    SELECT
                        tm.user_id,
                        tm.team_id,
                        tm.role,
                        u.full_name,
                        u.email,
                        u.is_active
                    FROM team_memberships tm
                    INNER JOIN users u
                        ON u.id = tm.user_id
                    WHERE tm.team_id = :team_id
                    ORDER BY
                        CASE tm.role
                            WHEN 'head_coach' THEN 1
                            WHEN 'assistant_coach' THEN 2
                            ELSE 3
                        END,
                        u.full_name ASC,
                        u.email ASC
                ");

                $stmt->execute([
                    'team_id' => $currentTeamId,
                ]);

                $currentTeamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $userTeams = get_user_teams($userId);
                $archivedTeams = get_archived_user_teams($userId);
                break;

                case 'disable_2fa':
                    $stmt = db()->prepare("
                        UPDATE users
                        SET
                            two_factor_enabled = 0,
                            two_factor_secret = NULL,
                            two_factor_confirmed_at = NULL
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        'id' => $userId,
                    ]);

                    flash_redirect('ok', 'Two-factor authentication has been disabled.', 'account.php');
                    break;
            case 'update_team_role':
                if ($currentTeamId <= 0) {
                    throw new RuntimeException('No active team selected.');
                }

                if (!current_user_is_head_coach($currentTeamId)) {
                    throw new RuntimeException('Only a Head Coach can change team roles.');
                }

                $memberUserId = (int)($_POST['member_user_id'] ?? 0);
                $memberRole = normalize_team_role((string)($_POST['member_role'] ?? 'assistant_coach'));

                update_team_member_role($currentTeamId, $memberUserId, $memberRole);

                flash_redirect('ok', 'Team role updated successfully.', 'account.php');
                break;
            case 'remove_team_member':
                if ($currentTeamId <= 0) {
                    throw new RuntimeException('No active team selected.');
                }

                if (!current_user_is_head_coach($currentTeamId)) {
                    throw new RuntimeException('Only a Head Coach can remove team members.');
                }

                $memberUserId = (int)($_POST['member_user_id'] ?? 0);

                if ($memberUserId === $userId) {
                    throw new RuntimeException('You cannot remove yourself from the current team here.');
                }

                remove_team_member($currentTeamId, $memberUserId);

                flash_redirect('ok', 'Team member removed successfully.', 'account.php');
                break;
                case 'archive_current_team':
                    if ($currentTeamId <= 0) {
                        throw new RuntimeException('No active team selected.');
                    }

                    if (!current_user_is_head_coach($currentTeamId)) {
                        throw new RuntimeException('Only a Head Coach can archive this team.');
                    }

                    $pdo = db();
                    $pdo->beginTransaction();

                    try {
                        archive_team($currentTeamId);

                        $stmt = $pdo->prepare("
                            UPDATE players
                            SET archived_at = NOW()
                            WHERE team_id = :team_id
                              AND archived_at IS NULL
                        ");

                        $stmt->execute([
                            'team_id' => $currentTeamId,
                        ]);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    set_current_team(0);

                    flash_redirect('ok', 'Team and associated players archived successfully.', 'account.php');
                    break;
            case 'switch_team':
                $newTeamId = (int)($_POST['team_id'] ?? 0);

                if ($newTeamId <= 0) {
                    throw new RuntimeException('Invalid team selected.');
                }

                if (!user_belongs_to_team($userId, $newTeamId)) {
                    throw new RuntimeException('You do not belong to that team.');
                }

                set_current_team($newTeamId);

                flash_redirect('ok', 'Switched team successfully.', 'account.php');
                break;
                case 'set_default_team':
                    $defaultTeamId = (int)($_POST['team_id'] ?? 0);

                    if ($defaultTeamId <= 0 || !user_belongs_to_team($userId, $defaultTeamId)) {
                        throw new RuntimeException('Invalid default team selected.');
                    }

                    $stmt = db()->prepare("
                        UPDATE users
                        SET default_team_id = :team_id
                        WHERE id = :user_id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        'team_id' => $defaultTeamId,
                        'user_id' => $userId,
                    ]);

                    set_current_team($defaultTeamId);

                    flash_redirect('ok', 'Default team updated.', 'account.php');
                    break;
            case 'restore_team':
                $restoreTeamId = (int)($_POST['team_id'] ?? 0);

                if ($restoreTeamId <= 0) {
                    throw new RuntimeException('Invalid team selected.');
                }

                if (!user_belongs_to_team($userId, $restoreTeamId)) {
                    throw new RuntimeException('You do not belong to that team.');
                }

                if (!current_user_is_head_coach($restoreTeamId)) {
                    throw new RuntimeException('Only a Head Coach can restore this team.');
                }

                restore_team($restoreTeamId);
                set_current_team($restoreTeamId);

                flash_redirect('ok', 'Team restored successfully.', 'account.php');
                break;
                case 'update_player_label_mode':
                    if ($currentTeamId <= 0) {
                        throw new RuntimeException('No active team selected.');
                    }

                    $mode = (string)($_POST['player_label_mode'] ?? 'both');

                    update_team_player_label_mode($currentTeamId, $mode);

                    flash_redirect('ok', 'Player display preference updated.', 'account.php');
                    break;

                case 'update_stats_setting':
                    if ($currentTeamId <= 0) {
                        throw new RuntimeException('No active team selected.');
                    }

                    if (!current_user_is_head_coach($currentTeamId)) {
                        throw new RuntimeException('Only a Head Coach can update team stats settings.');
                    }

                    update_team_stats_enabled(
                        $currentTeamId,
                        !empty($_POST['stats_enabled'])
                    );

                    flash_redirect('ok', 'Stats setting updated.', 'account.php');
                    break;

                case 'dismiss_feature_request':
                $requestId = (int)($_POST['request_id'] ?? 0);

                if ($requestId <= 0) {
                    throw new RuntimeException('Invalid feature request.');
                }

                $stmt = db()->prepare("
                    UPDATE feature_requests
                    SET user_dismissed_at = NOW()
                    WHERE id = :id
                    AND user_id = :user_id
                    AND status = 'declined'
                    LIMIT 1
                ");

                $stmt->execute([
                    'id' => $requestId,
                    'user_id' => $userId,
                ]);

                flash_redirect('ok', 'Feature request dismissed.', 'account.php');
                break;
            default:
                throw new RuntimeException('Invalid account action.');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

    $currentTheme = get_user_theme_color($userId);
    $canUsePrintBranding = $currentTeamId > 0

        && team_can_use_feature($currentTeamId, 'print.custom_branding');

        $pitchRuleSets = function_exists('get_pitch_rule_sets')
            ? get_pitch_rule_sets(true)
            : [];

            $user = get_current_user_record();

            $referralCode = ensure_user_referral_code((int)$user['id']);

            $referralUrl =
                'https://' .
                ($_SERVER['HTTP_HOST'] ?? 'benchbuddy.ca') .
                '/signup.php?ref=' .
                urlencode($referralCode);



                $archivedTeamRows = [];

                $archivedTeamStmt = db()->prepare("
                    SELECT
                        t.id,
                        t.name,
                        t.season_label,
                        t.division,
                        t.archived_at,
                        t.created_at,
                        tm.role
                    FROM team_memberships tm
                    INNER JOIN teams t
                        ON t.id = tm.team_id
                    WHERE tm.user_id = :user_id
                      AND t.archived_at IS NOT NULL
                    ORDER BY t.archived_at DESC, t.id DESC
                ");

                $archivedTeamStmt->execute([
                    'user_id' => $userId,
                ]);

                $archivedTeamRows = $archivedTeamStmt->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font brand-title-font">Account</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="account-grid">
  <div class="card">
    <h2>Profile</h2>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">

      <label for="full_name">Full Name</label>
      <input
        type="text"
        id="full_name"
        name="full_name"
        required
        value="<?= h((string)($user['full_name'] ?? '')) ?>"
      >

      <label for="email">Email</label>
      <input
        type="email"
        id="email"
        name="email"
        required
        value="<?= h((string)($user['email'] ?? '')) ?>"
      >

      <div class="actions-row">
        <button type="submit">Save Profile</button>
      </div>
    </form>
  </div>

  <?php $statsEnabled = team_stats_enabled($teamId); ?>

  <div class="card" style="margin-bottom:16px;">
    <h2>Individual Stats Recording</h2>

    <p class="muted">
      Turn this off if you only want lineup, roster, fairness, and pitch count tools.
      Don't worry, pitch counts will still work, sometimes we just dont need everything.
    </p>

    <form method="post" action="account.php">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_stats_setting">

      <label class="ios-toggle-wrap" for="stats_enabled">
        <span class="ios-toggle-label">Enable player stat recording</span>
        <span class="ios-toggle">
          <input
            type="checkbox"
            id="stats_enabled"
            name="stats_enabled"
            value="1"
            <?= $statsEnabled ? 'checked' : '' ?>
          >
          <span class="ios-toggle-slider"></span>
        </span>
      </label>

      <div class="actions-row" style="margin-top:14px;">
        <button type="submit">Save Stats Setting</button>
      </div>
    </form>
  </div>
<!--
  <div class="card">
    <h2>Invite Coaches</h2>
    <p class="muted">
      Share BenchBuddy with another coach. When they sign up with your link, we’ll track the referral.
    </p>

    <label for="referral_link">Your Referral Link</label>
    <input
      type="text"
      id="referral_link"
      readonly
      value="<?= h($referralUrl) ?>"
      onclick="this.select();"
    >

    <div class="actions-row" style="margin-top:12px;">
      <button
        type="button"
        class="btn"
        onclick="navigator.clipboard.writeText(document.getElementById('referral_link').value); this.textContent='Copied!';"
      >
        Copy Link
      </button>
    </div>
  </div>-->

  <?php
  $team = get_team_by_id(current_team_id());

  $planKey = (string)($team['plan_key'] ?? 'free');
  $subscriptionStatus = (string)($team['subscription_status'] ?? 'inactive');
  $renewalDate = $team['subscription_current_period_end'] ?? null;
  $normalizedPlanKey = function_exists('normalize_plan_key')

      ? normalize_plan_key($planKey)

      : $planKey;

  $prettyPlan = match ($normalizedPlanKey) {

      'coach' => 'Coach',
      'coachplus' => 'Coach Plus',
      'unlimited' => 'Unlimited',

      default => 'Free',

  };

  $statusClass = match ($subscriptionStatus) {
      'active' => 'status-active',
      'past_due' => 'status-warning',
      'canceled', 'inactive' => 'status-inactive',
      default => 'status-neutral',
  };
  ?>
  <div class="card billing-summary-card">
    <div class="billing-header">
      <div>
        <p class="billing-eyebrow">Current Plan</p>

        <div class="billing-plan-row">
          <h2><?= h($prettyPlan) ?></h2>

          <div class="billing-status-pill <?= h($statusClass) ?>">
            <?= h(ucwords(str_replace('_', ' ', $subscriptionStatus))) ?>
          </div>
        </div>

        <?php if (
            (string)($team['subscription_status'] ?? '') === 'trialing'
            && (int)($team['is_trial'] ?? 0) === 1
            && !empty($team['trial_ends_at'])
        ): ?>
          <p class="billing-trial-note">
            Your free trial ends
            <strong>
              <?= h(date('M j, Y', strtotime((string)$team['trial_ends_at']))) ?>
            </strong>
          </p>
        <?php endif; ?>
      </div>

      <a href="billing.php" class="btn">
        Manage Billing
      </a>
    </div>

    <div class="billing-summary-grid">
      <div class="billing-summary-item">
        <span class="billing-label">Renewal Date</span>

        <strong>
          <?= h(format_local_datetime($renewalDate, 'No active renewal')) ?>
        </strong>
      </div>

      <div class="billing-summary-item">
        <span class="billing-label">Current Team</span>

        <strong>
          <?= h((string)($team['name'] ?? 'Unknown Team')) ?>
        </strong>
      </div>

      <div class="billing-summary-item">
        <span class="billing-label">Included Features</span>

        <strong>
          <?= match ($normalizedPlanKey) {
              'coach' => 'Core Coaching Tools',
              'coachplus' => 'Advanced Coaching Tools',
              'unlimited' => 'Unlimited Access',
              default => 'Basic Features',
          } ?>
        </strong>
      </div>

      <div class="billing-summary-item">
        <span class="billing-label">Billing Cycle</span>

        <strong>
          <?= $renewalDate ? 'Active Subscription' : 'No Billing Active' ?>
        </strong>
      </div>
    </div>
  </div>
  <div class="card">
    <h2>Player Display</h2>
    <p class="muted">
      Choose how player names appear across lineup pages and print sheets.
    </p>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_player_label_mode">

      <label for="player_label_mode">Default player display</label>
      <select name="player_label_mode" id="player_label_mode">
        <?php
          $currentLabelMode = get_team_player_label_mode(current_team_id());
        ?>

        <?php foreach (player_label_mode_options() as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= $currentLabelMode === $value ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="margin-top:14px;">
        <button type="submit" class="btn">Save Player Display</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Change Password</h2>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_password">

      <label for="current_password">Current Password</label>
      <input
        type="password"
        id="current_password"
        name="current_password"
        required
      >

      <label for="new_password">New Password</label>
      <input
        type="password"
        id="new_password"
        name="new_password"
        required
        minlength="8"
      >

      <label for="confirm_password">Confirm New Password</label>
      <input
        type="password"
        id="confirm_password"
        name="confirm_password"
        required
        minlength="8"
      >

      <div class="actions-row">
        <button type="submit">Update Password</button>
      </div>
    </form>
  </div>

  <section class="card">
      <h2>Two-Factor Authentication</h2>
      <?php if ((int)($user['two_factor_enabled'] ?? 0) === 1): ?>
              <span class="badge badge-success">Enabled</span>
          <?php else: ?>
              <span class="badge badge-secondary">Disabled</span>
          <?php endif; ?>
      <?php if ((int)($user['two_factor_enabled'] ?? 0) === 1): ?>


          <div class="actions-row">
              <a href="two_factor_setup.php" class="btn btn-secondary">
                  Manage 2FA
              </a>
          </div>

          <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="disable_2fa">

              <button type="submit" class="btn btn-danger">
                  Disable 2FA
              </button>
          </form>

      <?php else: ?>

          <p class="muted">
              Protect your account with an authenticator app such as Google Authenticator, Microsoft Authenticator, Authy, or 1Password.
          </p>

          <div class="actions-row">
              <a href="two_factor_setup.php" class="btn">
                  Enable 2FA
              </a>
          </div>

      <?php endif; ?>
  </section>


  <section class="card account-section">
      <div class="section-header">
          <div>
              <h2>My Feature Requests</h2>
              <p class="muted">Track the status of ideas you have submitted.</p>
          </div>

          <a class="btn" href="feature_request_form.php">
              Submit Request
          </a>
      </div>

      <?php if (empty($featureRequests)): ?>
          <p class="muted">You have not submitted any feature requests yet.</p>
          <?php else: ?>
            <div class="request-list compact-request-list">
              <?php foreach ($featureRequests as $request): ?>
                <?php
                  $requestTitle = (string)$request['title'];
                  $requestDescription = (string)$request['description'];
                  $requestStatus = (string)$request['status'];
                  $requestDate = date('M j, Y', strtotime((string)$request['created_at']));
                  $requestNotes = (string)($request['admin_notes'] ?? '');
                ?>

                <article class="request-card compact-request-card">
                  <div>
                    <span class="status-pill status-<?= h($requestStatus) ?>">
                      <?= h(ucwords(str_replace('_', ' ', $requestStatus))) ?>
                    </span>

                    <h3><?= h($requestTitle) ?></h3>

                    <p class="muted small">
                      Submitted <?= h($requestDate) ?>
                    </p>
                  </div>

                  <div class="request-actions">
                    <button
                      type="button"
                      class="btn btn-secondary"
                      onclick="openFeatureRequestModal(this)"
                      data-title="<?= h($requestTitle) ?>"
                      data-status="<?= h(ucwords(str_replace('_', ' ', $requestStatus))) ?>"
                      data-date="<?= h($requestDate) ?>"
                      data-description="<?= h($requestDescription) ?>"
                      data-notes="<?= h($requestNotes) ?>"
                    >
                      View
                    </button>

                    <?php if ((string)$request['status'] === 'declined'): ?>
                      <form method="post" onsubmit="return confirm('Remove this declined request from your list?');">
                          <?= csrf_field() ?>
                        <input type="hidden" name="action" value="dismiss_feature_request">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <button type="submit" class="btn btn-secondary">Dismiss</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
  </section>


  <div id="featureRequestModal" class="help-modal-backdrop hide">
    <div class="help-modal">
      <div class="help-modal-header">
        <div>
          <h2 id="featureRequestModalTitle">Feature Request</h2>
          <p id="featureRequestModalMeta" class="muted" style="margin:6px 0 0;"></p>
        </div>

        <button type="button" class="help-modal-close" onclick="closeFeatureRequestModal()">×</button>
      </div>

      <div class="help-modal-body">
        <div class="help-step">
          <strong>Request</strong>
          <p id="featureRequestModalDescription"></p>
        </div>

        <div id="featureRequestModalNotesWrap" class="help-step hide">
          <strong>Admin update</strong>
          <p id="featureRequestModalNotes"></p>
        </div>
      </div>

      <div class="help-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeFeatureRequestModal()">Close</button>
      </div>
    </div>
  </div>

</div>

<div class="card">
  <h2>Your Teams</h2>

  <?php if (empty($userTeams)): ?>
    <p class="muted">You are not assigned to any teams yet.</p>
  <?php else: ?>


    <div class="cards">
      <?php foreach ($userTeams as $team): ?>
        <div class="mobile-card">
          <div class="mobile-card-title"><?= h((string)($team['name'] ?? '')) ?></div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Role</span>
            <?= h(display_team_role((string)($team['role'] ?? 'assistant_coach'))) ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Season</span>
            <?= h((string)($team['season_label'] ?? '')) ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Division</span>
            <?= h((string)($team['division'] ?? '')) ?>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Current</span>
            <?= (int)($team['id'] ?? 0) === $currentTeamId ? 'Yes' : 'No' ?>
          </div>
          <div class="member-actions">
            <?php if ((int)$team['id'] === $currentTeamId): ?>
              <span class="muted">Current Team</span>
            <?php else: ?>
              <form method="post">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="switch_team">
                <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                <button type="submit" class="btn">Switch</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<div class="account-grid">


<div class="card">
  <h2>Create New Team</h2>
  <p class="muted">
    Add another team to your account. Your plan controls how many active teams you can manage.
  </p>

  <form method="post">
      <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_team">

    <label for="new_team_name">Team Name</label>
    <input
      type="text"
      id="new_team_name"
      name="team_name"
      required
      maxlength="150"
      placeholder="Example: Spring 11U Red"
    >

    <label for="new_season_label">Season Label</label>
    <input
      type="text"
      id="new_season_label"
      name="season_label"
      maxlength="50"
      placeholder="Example: Spring 2026"
    >

    <label for="new_pitch_rule_set_id">Division</label>
    <select id="new_pitch_rule_set_id" name="pitch_rule_set_id">
      <option value="">-- Select Division --</option>

      <?php foreach ($pitchRuleSets as $ruleSet): ?>
        <option value="<?= (int)$ruleSet['id'] ?>">
          <?= h((string)$ruleSet['program_name']) ?> ·
          <?= h((string)$ruleSet['division_label']) ?>

        </option>
      <?php endforeach; ?>
    </select>

    <div class="actions-row" style="margin-top:14px;">
      <button type="submit">Create Team</button>
    </div>
  </form>
</div>

  <div class="card">
    <h2>Lineup Templates</h2>
    <a href="lineup_templates.php" class="btn"><strong>Edit Lineup Templates</strong></a>
      <p><span class="muted">Manage reusable lineup patterns for future games.</span></p>
  </div>


<div class="card">
  <h2>Add Team Member</h2>

  <?php if ($currentTeamId <= 0): ?>
    <p class="muted">No active team selected.</p>
  <?php elseif (!current_user_is_head_coach($currentTeamId)): ?>
    <p class="muted">Only a Head Coach can add team members.</p>
  <?php else: ?>
    <p class="muted">Add an existing user directly, or create an invite for someone new.</p>

    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_team_member">

      <label for="member_email">Email</label>
      <input
        type="email"
        id="member_email"
        name="member_email"
        required
        placeholder="coach@example.com"
      >

      <label for="member_role">Role</label>
      <select id="member_role" name="member_role" required>
        <option value="head_coach">Head Coach</option>
        <option value="assistant_coach">Assistant Coach</option>
      </select>

      <div class="actions-row">
        <button type="submit">Add Team Member</button>
      </div>
    </form>

    <?php if ($inviteLink !== ''): ?>
      <div class="invite-box">
        <div><strong>Share this signup link:</strong></div>
        <div class="invite-link"><?= h($inviteLink) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>


<div class="card">
  <h2>Current Team Members</h2>

  <?php if (count($currentTeamMembers) === 0): ?>
    <p class="muted">No team members found.</p>
  <?php else: ?>
    <div class="cards">
      <?php foreach ($currentTeamMembers as $member): ?>
        <div class="mobile-card">
          <div class="mobile-card-title">
            <?= h((string)($member['full_name'] ?? '')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Email</span>
            <?= h((string)($member['email'] ?? '')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Role</span>
            <?= h(display_team_role((string)($member['role'] ?? 'assistant_coach'))) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Status</span>
            <?= ((int)($member['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Account Info</h2>

  <div class="meta">
    <div class="meta-box">
      <div class="meta-label">Status</div>
      <div class="meta-value"><?= ((int)($user['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive' ?></div>
    </div>

    <div class="meta-box">
      <div class="meta-label">Created</div>
      <div class="meta-value"><?= h((string)($user['created_at'] ?? '')) ?></div>
    </div>
  </div>
</div>


<div class="account-grid">
<div class="card" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff;">
  <h2>Team Danger Zone</h2>

  <?php if ($currentTeamId <= 0 || !$currentTeam): ?>
    <p class="muted" style="color:#e8efff;">No active team selected.</p>
  <?php elseif (!current_user_is_head_coach($currentTeamId)): ?>
    <p class="muted" style="color:#e8efff;">Only a Head Coach can archive the current team.</p>
  <?php else: ?>
    <p class="muted" style="color:#e8efff;">
        Archiving hides this team from normal use and archives its players, while keeping games and history.
    </p>

    <form method="post" onsubmit="return confirm('Archive this team? You can restore it later.');">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="archive_current_team">
      <button type="submit" class="btn btn-secondary">Archive Team</button>
    </form>
  <?php endif; ?>
</div>


<?php
$archivedTeams = get_archived_user_teams($userId);

if (!is_array($archivedTeams)) {
    $archivedTeams = [];
}
?>


<div class="card">
  <h2>Archived Teams</h2>

  <?php if (count($archivedTeamRows) === 0): ?>

    <p class="muted">No archived teams.</p>

  <?php else: ?>

    <div class="cards">
      <?php foreach ($archivedTeamRows as $archivedTeam): ?>

        <div class="mobile-card">
          <div class="mobile-card-title">
            <?= h((string)($archivedTeam['name'] ?? 'Archived Team')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Role</span>

            <?= h(display_team_role(
                (string)($archivedTeam['role'] ?? 'assistant_coach')
            )) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Season</span>

            <?= h((string)($archivedTeam['season_label'] ?? '')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Division</span>

            <?= h((string)($archivedTeam['division'] ?? '')) ?>
          </div>

          <div class="mobile-card-row">
            <span class="mobile-card-label">Archived</span>

            <?php
            $archivedAt = trim(
                (string)($archivedTeam['archived_at'] ?? '')
            );
            ?>

            <?= $archivedAt !== ''
                ? h(date('M j, Y g:i A', strtotime($archivedAt)))
                : 'Unknown'
            ?>
          </div>

          <div class="member-actions">
            <?php if (
                normalize_team_role(
                    (string)($archivedTeam['role'] ?? '')
                ) === 'head_coach'
            ): ?>

              <form
                method="post"
                action="account.php"
                onsubmit="return confirm('Restore this team?');"
              >
                <?= csrf_field() ?>

                <input
                  type="hidden"
                  name="action"
                  value="restore_team"
                >

                <input
                  type="hidden"
                  name="team_id"
                  value="<?= (int)$archivedTeam['id'] ?>"
                >

                <button type="submit" class="btn">
                  Restore
                </button>
              </form>

            <?php else: ?>

              <span class="muted">Head Coach only</span>

            <?php endif; ?>
          </div>
        </div>

      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>


</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
