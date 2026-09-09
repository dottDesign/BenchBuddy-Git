<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Billing Success';
$_SESSION['ga_events'][] = 'subscription_started';
$_SESSION['ga_events'][] = 'subscription_converted';
$currentPage = 'billing';

require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
  <h1>Subscription Started</h1>
  <p class="muted">
    Thanks! Your subscription has been started. Billing status may take a moment to sync.
  </p>

  <div class="actions-row">
    <a class="btn" href="billing.php">Back to Billing</a>
    <a class="btn btn-secondary" href="index.php">Go to Dashboard</a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
