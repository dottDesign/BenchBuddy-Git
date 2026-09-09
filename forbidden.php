<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pageTitle = 'Access Denied';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Access Denied</h1>

<div class="card">
  <p>You are logged in but do not have permission to access this area.</p>
  <a href="index.php" class="btn">Return to Dashboard</a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
