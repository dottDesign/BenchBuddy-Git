<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Sign Up';
$currentPage = 'signup';

$error = '';

$referralCode = trim((string)(
    $_POST['referral_code']
    ?? $_GET['ref']
    ?? ''
));

$referralCode = substr($referralCode, 0, 100);

if ($referralCode !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['benchbuddy_referral_code'] = $referralCode;
}

$promoCode = strtoupper(trim((string)(
    $_POST['promo_code']
    ?? $_GET['promo']
    ?? ''
)));

$promoCode = substr($promoCode, 0, 50);

$inviteToken = trim((string)(
    $_POST['invite_token']
    ?? $_GET['invite']
    ?? ''
));

$invite = $inviteToken !== ''
    ? get_invitation_by_token($inviteToken)
    : null;

$isInviteSignup = $invite !== null;
$division = '';

$pitchRuleSets = function_exists('get_pitch_rule_sets')
    ? get_pitch_rule_sets(true)
    : [];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $startedAt = (int)($_POST['signup_started_at'] ?? 0);
        $honeypot = trim((string)($_POST['website'] ?? ''));

        if ($honeypot !== '') {
            throw new RuntimeException('Signup could not be completed.');
        }

        if ($startedAt <= 0 || time() - $startedAt < 4) {
            throw new RuntimeException('Signup submitted too quickly. Please try again.');
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM signup_attempts
            WHERE ip_address = :ip_address
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");

        $stmt->execute([
            'ip_address' => $ip,
        ]);

        if ((int)$stmt->fetchColumn() >= 5) {
            throw new RuntimeException('Too many signup attempts. Please try again later.');
        }

        $stmt = db()->prepare("
            INSERT INTO signup_attempts (
                ip_address,
                user_agent,
                email,
                created_at
            ) VALUES (
                :ip_address,
                :user_agent,
                :email,
                NOW()
            )
        ");

        $stmt->execute([
            'ip_address' => $ip,
            'user_agent' => substr($userAgent, 0, 255),
            'email' => $email,
        ]);

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $teamName = trim((string)($_POST['team_name'] ?? ''));
        $seasonLabel = trim((string)($_POST['season_label'] ?? ''));
        $pitchRuleSetId = (int)($_POST['pitch_rule_set_id'] ?? 0);

        $inviteToken = trim((string)($_POST['invite_token'] ?? ''));
        $invite = $inviteToken !== '' ? get_invitation_by_token($inviteToken) : null;
        $isInviteSignup = $invite !== null;

        if ($fullName === '') {
            throw new RuntimeException('Full name is required.');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }

        if ($password === '') {
            throw new RuntimeException('Password is required.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException('Passwords do not match.');
        }

        if (!$isInviteSignup) {
            if ($teamName === '') {
                throw new RuntimeException('Team name is required.');
            }

            if ($pitchRuleSetId <= 0) {
                throw new RuntimeException('Pitch count rules are required.');
            }

            $ruleSet = get_pitch_rule_set($pitchRuleSetId);

            if (!$ruleSet || (int)($ruleSet['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Invalid pitch count rules selected.');
            }

            $division = (string)$ruleSet['division_label'];

            if ($division === '') {
                throw new RuntimeException('Selected pitch rules are missing a division.');
            }
        }

        $userId = register_user($fullName, $email, $password);
        $emailPreferencesToken = bin2hex(random_bytes(32));

        $stmt = db()->prepare("
            UPDATE users
            SET email_preferences_token = :email_preferences_token
            WHERE id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'email_preferences_token' => $emailPreferencesToken,
            'user_id' => $userId,
        ]);

        $referralCode = trim((string)($_SESSION['benchbuddy_referral_code'] ?? $_POST['referral_code'] ?? ''));

        if ($referralCode !== '') {
            $referrerId = find_user_id_by_referral_code($referralCode);

            if ($referrerId > 0 && $referrerId !== $userId) {
                $stmt = db()->prepare("
                    UPDATE users
                    SET
                        referred_by_user_id = :referrer_id,
                        referred_by_code = :referral_code,
                        referral_signup_at = NOW()
                    WHERE id = :user_id
                    LIMIT 1
                ");

                $stmt->execute([
                    'referrer_id' => $referrerId,
                    'referral_code' => $referralCode,
                    'user_id' => $userId,
                ]);
            }

            unset($_SESSION['benchbuddy_referral_code']);
        }
        if ($isInviteSignup) {
            accept_team_invitation($userId, $inviteToken);
        } else {
            $newTeamId = create_team_for_user_id(
                $userId,
                $teamName,
                $seasonLabel !== '' ? $seasonLabel : null,
                $division
            );
            if ((int)$newTeamId <= 0) {
                throw new RuntimeException(
                    'Your team could not be created. Please try again.'
                );

            }
            $promoCode = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
            $promoCode = substr($promoCode, 0, 50);

            $trialDays = 30;
            $promo = null;

            if ($promoCode !== '' && function_exists('get_valid_promo_code')) {
                $promo = get_valid_promo_code($promoCode);

                if ($promo) {
                    $trialDays = max(1, (int)$promo['trial_days']);
                }
            }

            $trialEndsAt = (new DateTimeImmutable())
                ->modify('+' . $trialDays . ' days')
                ->format('Y-m-d H:i:s');

            $stmt = db()->prepare("
                UPDATE teams
                SET
                    trial_ends_at = :trial_ends_at,
                    signup_promo_code = :promo_code
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'trial_ends_at' => $trialEndsAt,
                'promo_code' => $promo ? $promoCode : null,
                'team_id' => (int)$newTeamId,
            ]);

            if ($promo) {
                db()->prepare("
                    UPDATE promo_codes
                    SET
                        redemption_count = redemption_count + 1,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ")->execute([
                    'id' => (int)$promo['id'],
                ]);
            }
            $_SESSION['ga_events'][] = 'team_created';

            $stmt = db()->prepare("
                UPDATE teams
                SET pitch_rule_set_id = :pitch_rule_set_id
                WHERE id = :team_id
                LIMIT 1
            ");

            $stmt->execute([
                'pitch_rule_set_id' => $pitchRuleSetId,
                'team_id' => (int)$newTeamId,
            ]);
        }

        login_user_by_id($userId);


        try {
            error_log('WELCOME EMAIL ABOUT TO SEND TO: ' . $email);

            send_welcome_email($email, $fullName);

            error_log('WELCOME EMAIL SENT TO: ' . $email);
        } catch (Throwable $e) {
            error_log('WELCOME EMAIL FAILED: ' . $e->getMessage());
        }
        $adminReferralSource = $referralCode !== ''
            ? $referralCode
            : 'Direct / no referral';

        $adminTeamName = $isInviteSignup
            ? (string)($invite['team_name'] ?? 'Joined invited team')
            : $teamName;

        $adminPlanStatus = 'Free signup';

        try {
            send_admin_new_signup_email(
                $fullName,
                $email,
                $adminTeamName,
                $adminReferralSource,
                $adminPlanStatus
            );
        } catch (Throwable $e) {
            error_log('Admin signup notification failed: ' . $e->getMessage());
        }
        $_SESSION['ga_events'][] = 'signup_completed';
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Sign Up</h1>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="account-grid">
  <div class="card">
    <form method="post">
        <?= csrf_field() ?>
      <input type="hidden" name="invite_token" value="<?= h($inviteToken) ?>">
      <input type="hidden" name="signup_started_at" value="<?= time() ?>">
      <input type="hidden" name="referral_code" value="<?= h($referralCode) ?>">

      <div style="position:absolute; left:-9999px;" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <label for="full_name">Full Name</label>
      <input
        type="text"
        id="full_name"
        name="full_name"
        required
        value="<?= h((string)($_POST['full_name'] ?? '')) ?>"
      >

      <label for="email">Email</label>
      <input
        type="email"
        id="email"
        name="email"
        required
        value="<?= h((string)($_POST['email'] ?? '')) ?>"
      >

      <label for="password">Password</label>
      <input
        type="password"
        id="password"
        name="password"
        required
        minlength="8"
      >

      <label for="confirm_password">Confirm Password</label>
      <input
        type="password"
        id="confirm_password"
        name="confirm_password"
        required
        minlength="8"
      >

      <?php if (!$isInviteSignup): ?>
        <label for="team_name">Team Name</label>
        <input
          type="text"
          id="team_name"
          name="team_name"
          required
          value="<?= h((string)($_POST['team_name'] ?? '')) ?>"
        >

        <label for="season_label">Season Label</label>
        <input
          type="text"
          id="season_label"
          name="season_label"
          value="<?= h((string)($_POST['season_label'] ?? '')) ?>"
          placeholder="Spring 2026"
        >

        <label for="pitch_rule_set_id">Division</label>
        <select id="pitch_rule_set_id" name="pitch_rule_set_id" required>
          <option value="">-- Select Division --</option>
          <?php
          $selectedRuleSetId = (int)($_POST['pitch_rule_set_id'] ?? 0);
          foreach ($pitchRuleSets as $ruleSet):
          ?>
            <option
              value="<?= (int)$ruleSet['id'] ?>"
              <?= $selectedRuleSetId === (int)$ruleSet['id'] ? 'selected' : '' ?>
            >
              <?= h((string)$ruleSet['program_name']) ?>
              ·
              <?= h((string)$ruleSet['division_label']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="form-field">
          <label for="promo_code">Promo Code</label>

          <input
            type="text"
            id="promo_code"
            name="promo_code"
            value="<?= h($promoCode) ?>"
            placeholder="Optional"
            maxlength="50"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
          >

          <?php if ($promoCode !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <small class="muted">
              Promo code applied from your signup link.
            </small>
          <?php endif; ?>
        </div>

      <?php else: ?>

        <div class="msg info" style="margin-top:16px;">
          You are signing up to join
          <strong><?= h((string)$invite['team_name']) ?></strong>.
        </div>

      <?php endif; ?>

      <div class="actions-row">
        <button type="submit">Create Account</button>
      </div>
    </form>

    <div class="auth-links">
      <a href="login.php">Already have an account? Log in</a>
    </div>
  </div>

  <aside class="card">
    <?php if ($isInviteSignup): ?>
      <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-arrow-right-icon lucide-square-arrow-right"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg> What happens next</h2>
      <ul class="feature-list">
        <li>Your user account is created</li>
        <li>You are added to the invited team automatically</li>
        <li>Your team role is assigned from the invitation</li>
        <li>You are switched into that team after signup</li>
      </ul>
    <?php else: ?>
      <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package-open-icon lucide-package-open"><path d="M12 22v-9"/><path d="M15.17 2.21a1.67 1.67 0 0 1 1.63 0L21 4.57a1.93 1.93 0 0 1 0 3.36L8.82 14.79a1.655 1.655 0 0 1-1.64 0L3 12.43a1.93 1.93 0 0 1 0-3.36z"/><path d="M20 13v3.87a2.06 2.06 0 0 1-1.11 1.83l-6 3.08a1.93 1.93 0 0 1-1.78 0l-6-3.08A2.06 2.06 0 0 1 4 16.87V13"/><path d="M21 12.43a1.93 1.93 0 0 0 0-3.36L8.83 2.2a1.64 1.64 0 0 0-1.63 0L3 4.57a1.93 1.93 0 0 0 0 3.36l12.18 6.86a1.636 1.636 0 0 0 1.63 0z"/></svg> Included with signup</h2>
      <ul class="feature-list">
        <li>Your user account is created</li>
        <li>Your first team is created automatically</li>
        <li>You are added to that team as Head Coach</li>
        <li>You can add other coaches later</li>
        <li>You can create more teams from inside the app</li>
      </ul>
      <h2 style="margin-top:18px;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-gem-icon lucide-gem"><path d="M10.5 3 8 9l4 13 4-13-2.5-6"/><path d="M17 3a2 2 0 0 1 1.6.8l3 4a2 2 0 0 1 .013 2.382l-7.99 10.986a2 2 0 0 1-3.247 0l-7.99-10.986A2 2 0 0 1 2.4 7.8l2.998-3.997A2 2 0 0 1 7 3z"/><path d="M2 9h20"/></svg> Free Trial</h2>
      <p class="muted">
        Start with Coach Plus for 30 days. No credit card required. After the trial, your team moves to the Free plan unless you upgrade.
      </p>
      <h2 style="margin-top:18px;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-tag-plus-icon lucide-tag-plus"><path d="M16 13h6"/><path d="m16.5 6.5-3.914-3.914A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l1.79-1.79"/><path d="M19 10v6"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg> Promo Codes</h2>

      <p class="muted" style="margin-bottom:0;">
        Have a promo code from a clinic, league, tournament, or BenchBuddy partner? Enter it during signup to unlock an extended free trial when eligible.
      </p>
    <?php endif; ?>
  </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
