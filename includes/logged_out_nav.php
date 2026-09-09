<?php
$currentPage = $currentPage ?? '';
?>

<nav class="benchbuddy-nav logged-out-nav" aria-label="Public navigation">

  <a
    class="benchbuddy-nav-link <?= $currentPage === 'index' ? 'active' : '' ?>"
    href="index.php"
  >
    Home
  </a>

  <a
    class="benchbuddy-nav-link <?= $currentPage === 'features' ? 'active' : '' ?>"
    href="features.php"
  >
    What’s New
  </a>

  <a
    class="benchbuddy-nav-link <?= $currentPage === 'pitch_rules' ? 'active' : '' ?>"
    href="pitch_rules.php"
  >
    Pitch Rules
  </a>

  <a
    class="benchbuddy-nav-link <?= $currentPage === 'billing' ? 'active' : '' ?>"
    href="billing.php"
  >
    Pricing
  </a>

  <a
    class="benchbuddy-nav-link <?= $currentPage === 'login' ? 'active' : '' ?>"
    href="login.php"
  >
    Login
  </a>

  <a
    class="benchbuddy-public-cta <?= $currentPage === 'signup' ? 'active' : '' ?>"
    href="signup.php"
  >
    Start Free
  </a>

</nav>

<button
  type="button"
  class="benchbuddy-mobile-toggle"
  id="benchbuddyMobileToggle"
  aria-expanded="false"
  aria-controls="benchbuddyMobileNav"
  aria-label="Open navigation"
>
  <span aria-hidden="true"></span>
  <span aria-hidden="true"></span>
</button>

<div
  class="benchbuddy-mobile-backdrop"
  id="benchbuddyMobileBackdrop"
  aria-hidden="true"
></div>

<aside
  class="benchbuddy-mobile-nav"
  id="benchbuddyMobileNav"
  aria-label="Mobile navigation"
  aria-hidden="true"
>
  <div class="benchbuddy-mobile-header">

    <div>
      <strong>BenchBuddy</strong>
      <div class="benchbuddy-mobile-user">
        Baseball lineup management
      </div>
    </div>

    <button
      type="button"
      class="benchbuddy-mobile-close"
      id="benchbuddyMobileClose"
      aria-label="Close navigation"
    >
      <span aria-hidden="true">×</span>
    </button>

  </div>

  <div class="benchbuddy-mobile-sections">

    <a
      class="benchbuddy-mobile-link <?= $currentPage === 'index' ? 'active-subnav' : '' ?>"
      href="index.php"
    >
      Home
    </a>

    <details>
      <summary>Product</summary>

      <a href="signup.php">Lineup Builder</a>
      <a href="signup.php">Manual Lineup Edits</a>
      <a href="signup.php">Pitch Counts</a>
      <a href="signup.php">Player Usage</a>
      <a href="signup.php">Game History</a>
    </details>

    <details>
      <summary>Resources</summary>

      <a
        class="<?= $currentPage === 'features' ? 'active-subnav' : '' ?>"
        href="features.php"
      >
        What’s New
      </a>

      <a
        class="<?= $currentPage === 'pitch_rules' ? 'active-subnav' : '' ?>"
        href="pitch_rules.php"
      >
        Pitch Rules
      </a>

      <a
        class="<?= $currentPage === 'sitemap' ? 'active-subnav' : '' ?>"
        href="sitemap.php"
      >
        Sitemap
      </a>

      <a
        class="<?= $currentPage === 'cookie_policy' ? 'active-subnav' : '' ?>"
        href="cookie_policy.php"
      >
        Cookie Policy
      </a>
    </details>

    <a
      class="benchbuddy-mobile-link <?= $currentPage === 'billing' ? 'active-subnav' : '' ?>"
      href="billing.php"
    >
      Pricing
    </a>

    <a
      class="benchbuddy-mobile-link <?= $currentPage === 'login' ? 'active-subnav' : '' ?>"
      href="login.php"
    >
      Login
    </a>

    <a
      class="benchbuddy-mobile-link <?= $currentPage === 'signup' ? 'active-subnav' : '' ?>"
      href="signup.php"
    >
      Start Free
    </a>

  </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const mobileToggle = document.getElementById('benchbuddyMobileToggle');
  const mobileClose = document.getElementById('benchbuddyMobileClose');
  const mobileBackdrop = document.getElementById('benchbuddyMobileBackdrop');
  const mobileNav = document.getElementById('benchbuddyMobileNav');

  function openMobileMenu() {
    document.body.classList.add('benchbuddy-mobile-open');

    if (mobileToggle) {
      mobileToggle.setAttribute('aria-expanded', 'true');
      mobileToggle.setAttribute('aria-label', 'Close navigation');
    }

    if (mobileNav) {
      mobileNav.setAttribute('aria-hidden', 'false');
    }

    if (mobileBackdrop) {
      mobileBackdrop.setAttribute('aria-hidden', 'false');
    }
  }

  function closeMobileMenu() {
    document.body.classList.remove('benchbuddy-mobile-open');

    if (mobileToggle) {
      mobileToggle.setAttribute('aria-expanded', 'false');
      mobileToggle.setAttribute('aria-label', 'Open navigation');
    }

    if (mobileNav) {
      mobileNav.setAttribute('aria-hidden', 'true');
    }

    if (mobileBackdrop) {
      mobileBackdrop.setAttribute('aria-hidden', 'true');
    }
  }

  if (mobileToggle) {
    mobileToggle.addEventListener('click', function () {
      const isOpen = document.body.classList.contains('benchbuddy-mobile-open');

      if (isOpen) {
        closeMobileMenu();
      } else {
        openMobileMenu();
      }
    });
  }

  if (mobileClose) {
    mobileClose.addEventListener('click', closeMobileMenu);
  }

  if (mobileBackdrop) {
    mobileBackdrop.addEventListener('click', closeMobileMenu);
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMobileMenu();
    }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 900) {
      closeMobileMenu();
    }
  });
});
</script>
