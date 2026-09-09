<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

$isLoggedIn = function_exists('is_logged_in') && is_logged_in();
$teamId = $isLoggedIn ? current_team_id() : 0;
$currentPlanKey = $teamId > 0 ? current_team_plan_key($teamId) : 'none';

$pageTitle = 'Billing';
$currentPage = 'billing';
$isStaging = strpos($_SERVER['HTTP_HOST'] ?? '', 'staging') !== false;
$team = $teamId > 0 ? get_team_by_id($teamId) : null;

$billingRequiredMessage = '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['billing_required_message'])) {
    $billingRequiredMessage = (string)$_SESSION['billing_required_message'];
    unset($_SESSION['billing_required_message']);
}

require_once __DIR__ . '/includes/header.php';

?>

<h1 class="page-title brand-title-font">Billing</h1>
<?php if ($billingRequiredMessage !== ''): ?>
  <div class="msg info">
    <?= h($billingRequiredMessage) ?>
  </div>
<?php endif; ?>

<?php if (!billing_enabled()): ?>
  <div class="card">
    <h2>Billing is not active yet</h2>
    <p class="muted">
      BenchBuddy is currently in beta. Paid plans are not required right now.
    </p>
  </div>
<?php else: ?>




    <?php
    $currentPlan = normalize_plan_key((string)($team['plan_key'] ?? 'free'));

    $plans = [
        'free' => [
            'name' => 'Free',
            'tagline' => 'For trying BenchBuddy with basic lineup tools.',
            'monthly' => '$0',
            'yearly' => '$0',
            'stripe_value_monthly' => '',
            'stripe_value_yearly' => '',
            'badge' => '',
            'features' => [
                'Basic player management',
                'Basic game creation',
                'Standard lineup generation',
                'Up to 10 players per team',
                'Up to 10 games per team',
                '3 game history items',
                '1 lineup template',
                'Print sheets with BenchBuddy Free watermark',
            ],
        ],

        'coach' => [
            'name' => 'Coach',
            'tagline' => 'For one coach managing a simple season.',
            'monthly' => '$2',
            'yearly' => '$20',
            'stripe_value_monthly' => 'coach_monthly',
            'stripe_value_yearly' => 'coach_yearly',
            'badge' => '',
            'features' => [
                'Everything in Free',
                'Up to 15 players per team',
                'Up to 20 games per team',
                'Past 10 games in game history',
                'Up to 12 lineup history items',
                'Up to 5 lineup templates',
                '1 additional team member',
                'Pitch count tracking',
                'Watermark-free printouts',
                'Custom branded printouts',
            ],
        ],

        'coachplus' => [
            'name' => 'Coach Plus',
            'tagline' => 'For coaches who want the full lineup toolkit.',
            'monthly' => '$5',
            'yearly' => '$50',
            'stripe_value_monthly' => 'coachplus_monthly',
            'stripe_value_yearly' => 'coachplus_yearly',
            'badge' => 'Best Value',
            'features' => [
                'Everything in Coach',
                'Unlimited players',
                'Unlimited games',
                'Full game history',
                'Full lineup history',
                'Up to 10 lineup templates',
                'Up to 3 additional team members',
                'Advanced lineup settings',
                'Pitch count and rest tracking',
                'Watermark-free printouts',
                'Custom branded printouts',
            ],
        ],

        'unlimited' => [
            'name' => 'Unlimited',
            'tagline' => 'For clubs, associations, and multi-team programs.',
            'monthly' => '$9',
            'yearly' => '$90',
            'stripe_value_monthly' => 'unlimited_monthly',
            'stripe_value_yearly' => 'unlimited_yearly',
            'badge' => 'For Clubs',
            'features' => [
                'Everything in Coach Plus',
                'Unlimited players',
                'Unlimited games',
                'Unlimited game history',
                'Unlimited lineup history',
                'Unlimited lineup templates',
                'Unlimited teams',
                'Unlimited team members',
                'Organization-friendly workflows',
                'Priority support',
            ],
        ],
    ];
    ?>

    <div class="billing-grid plan-comparison-grid">
      <?php foreach ($plans as $planKey => $plan): ?>
        <?php $isCurrent = $currentPlan === $planKey; ?>

        <div class="card plan-card
            <?= $isLoggedIn && $isCurrent ? 'current-plan' : '' ?>
            <?= $planKey === 'coachplus' ? 'featured-plan' : '' ?>
        ">
          <?php if ($plan['badge'] !== ''): ?>
            <div class="plan-badge"><?= h($plan['badge']) ?></div>
          <?php endif; ?>

          <div class="plan-card-header">
            <h2><?= h($plan['name']) ?></h2>
            <p class="muted"><?= h($plan['tagline']) ?></p>

            <div class="plan-price-stack">
              <p class="plan-price"><?= h($plan['monthly']) ?><span>/mo</span></p>
              <p class="plan-yearly-price"><?= h($plan['yearly']) ?>/year</p>
            </div>
          </div>

          <ul class="plan-feature-list">
            <?php foreach ($plan['features'] as $feature): ?>
              <li><?= h($feature) ?></li>
            <?php endforeach; ?>
          </ul>

          <div class="plan-card-footer">
              <?php if ($isLoggedIn && $isCurrent): ?>

                <span class="pill current">Current Plan</span>

              <?php elseif ($planKey === 'free'): ?>

                <span class="muted">Included with every account</span>

              <?php else: ?>

            <?php if ($isLoggedIn && $currentPlanKey !== ''): ?>
            <form method="post" action="billing_start.php" class="plan-button-row">
                <?= csrf_field() ?>
              <input type="hidden" name="billing_plan" value="<?= h($plan['stripe_value_monthly']) ?>">

              <input
                type="text"
                name="promo_code"
                placeholder="Promo code"
                class="promo-code-input"
              >

              <button type="submit" class="btn">Monthly</button>
            </form>

            <form method="post" action="billing_start.php" class="plan-button-row">
                <?= csrf_field() ?>
              <input type="hidden" name="billing_plan" value="<?= h($plan['stripe_value_yearly']) ?>">

              <input
                type="text"
                name="promo_code"
                placeholder="Promo code"
                class="promo-code-input"
              >

              <button type="submit" class="btn btn-secondary">Yearly</button>
            </form>
            <?php endif; ?>
               <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>





    <div class="card plan-comparison-table-card">
      <h2>Compare Plans</h2>
      <div class="table-wrap">
        <table class="plan-comparison-table">
          <thead>
            <tr>
              <th>Feature</th>
              <th>Free</th>
              <th>Coach</th>
              <th>Coach Plus</th>
              <th>Unlimited</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $compareRows = [
                ['Monthly Price', '$0', '$5/mo', '$9/mo', '$19/mo'],
                ['Yearly Price', '$0', '$50/year', '$90/year', '$190/year'],
                ['Players per team', '10', '15', 'Unlimited', 'Unlimited'],
                ['Games per team', '10', '20', 'Unlimited', 'Unlimited'],
                ['Game History', '3', 'Past 10', 'Full', 'Full'],
                ['Lineup History', '3', '12', 'Full', 'Full'],
                ['Generate lineups', 'Yes', 'Yes', 'Yes', 'Yes'],
                ['Watermark-free printouts', 'No', 'Yes', 'Yes', 'Yes'],
                ['Branded Printout', 'No', 'Yes', 'Yes', 'Yes'],
                ['Lineup templates', '1', '5', '10', 'Unlimited'],
                ['Advanced lineup settings', 'No', 'No', 'Yes', 'Yes'],
                ['Pitch count tracking', 'No', 'Yes', 'Yes', 'Yes'],
                ['Full game history', 'No', 'No', 'Yes', 'Yes'],
                ['Additional Team members', '0', '1', '3', 'Unlimited'],
                ['Multiple teams', 'No', 'No', 'No', 'Yes'],
                ['Best for', 'Trying BenchBuddy', 'Single coach', 'Competitive coach', 'Clubs and organizations'],
            ];
            ?>

            <?php foreach ($compareRows as $row): ?>
              <tr>
                <td><?= h($row[0]) ?></td>
                <td data-title="Free"><?= h($row[1]) ?></td>
                <td data-title="Coach"><?= h($row[2]) ?></td>
                <td data-title="Coach Plus"><?= h($row[3]) ?></td>
                <td data-title="Unlimited"><?= h($row[4]) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($isLoggedIn && $currentPlanKey !== ''): ?>
<div class="card">
  <h2>Manage Billing</h2>
  <p class="muted">Update your payment method, view invoices, or manage your subscription.</p>

  <form method="post" action="create_customer_portal.php">
      <?= csrf_field() ?>
    <button type="submit" class="btn btn-secondary">Manage Billing</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
