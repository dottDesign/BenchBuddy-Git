<?php
declare(strict_types=1);

$pageTitle = 'Privacy Policy';
$currentPage = 'privacy_policy';

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .legal-wrap {
    max-width: 980px;
    margin: 0 auto;
  }

  .legal-hero {
    padding: 28px;
    border-radius: 18px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    margin-bottom: 22px;
  }

  .legal-hero h1 {
    margin: 0 0 8px;
    color: #fff;
  }

  .legal-hero p {
    margin: 0;
    color: rgba(255,255,255,.85);
  }

  .legal-card {
    padding: 28px;
  }

  .legal-section {
    padding: 18px 0;
    border-bottom: 1px solid #e5e7eb;
  }

  .legal-section:last-child {
    border-bottom: 0;
  }

  .legal-section h2 {
    margin: 0 0 10px;
    font-size: 22px;
  }

  .legal-section h3 {
    margin: 18px 0 10px;
    font-size: 18px;
  }

  .legal-section p {
    margin: 0 0 10px;
    line-height: 1.65;
  }

  .legal-section ul {
    margin: 10px 0 0;
    padding-left: 22px;
  }

  .legal-section li {
    margin-bottom: 8px;
    line-height: 1.5;
  }

  .legal-contact {
    display: inline-flex;
    margin-top: 8px;
    font-weight: 700;
  }
</style>

<div class="legal-wrap">
  <div class="legal-hero">
    <h1>Privacy Policy</h1>
    <p>Last Updated: May 15, 2026</p>
  </div>

  <div class="card legal-card">

    <section class="legal-section">
      <h2>Introduction</h2>

      <p>
        BenchBuddy ("BenchBuddy", "we", "our", or "us") respects your privacy
        and is committed to protecting your information.
      </p>

      <p>
        This Privacy Policy explains how we collect, use, store, and protect
        information when you use the BenchBuddy platform.
      </p>
    </section>

    <section class="legal-section">
      <h2>Information We Collect</h2>

      <h3>Account Information</h3>
      <ul>
        <li>Full name</li>
        <li>Email address</li>
        <li>Password credentials stored securely and encrypted</li>
        <li>Team information</li>
        <li>User role information</li>
      </ul>

      <h3>Team & Coaching Data</h3>
      <ul>
        <li>Team names</li>
        <li>Seasons and divisions</li>
        <li>Player names and roster data</li>
        <li>Lineups and batting orders</li>
        <li>Pitch tracking and game data</li>
        <li>Game history</li>
        <li>Coaching notes and templates</li>
      </ul>

      <h3>Billing Information</h3>
      <p>
        Payments are securely processed through Stripe or other payment providers.
        BenchBuddy does not store full credit card information.
      </p>

      <h3>Technical Information</h3>
      <ul>
        <li>IP addresses</li>
        <li>Session information</li>
        <li>Browser and device details</li>
        <li>Login timestamps</li>
        <li>Security and audit logs</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>How We Use Information</h2>

      <ul>
        <li>Provide and operate BenchBuddy</li>
        <li>Authenticate users and secure accounts</li>
        <li>Manage subscriptions and billing</li>
        <li>Prevent abuse, fraud, and unauthorized access</li>
        <li>Improve platform performance and features</li>
        <li>Provide customer support</li>
        <li>Maintain operational and security records</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Cookies & Sessions</h2>

      <p>
        BenchBuddy uses cookies and session technologies for authentication,
        account security, platform functionality, abuse prevention,
        and user experience improvements.
      </p>
    </section>

    <section class="legal-section">
      <h2>Third-Party Services</h2>

      <p>
        BenchBuddy uses trusted third-party providers including Stripe
        for payment processing and related billing services.
      </p>
    </section>

    <section class="legal-section">
      <h2>Account Deletion</h2>

      <p>
        Users may request deletion of their account and associated team data.
        Certain security, billing, or audit records may be retained where
        reasonably necessary for fraud prevention, legal compliance,
        or operational integrity.
      </p>
    </section>

    <section class="legal-section">
      <h2>Security</h2>

      <p>
        BenchBuddy implements reasonable administrative, technical,
        and security safeguards to help protect user information.
        No system or online service can guarantee absolute security.
      </p>
    </section>

    <section class="legal-section">
      <h2>Children's Privacy</h2>

      <p>
        BenchBuddy is intended for coaches, parents, and team administrators.
        Player information entered into the platform is managed by authorized team staff.
      </p>
    </section>

    <section class="legal-section">
      <h2>Changes To This Policy</h2>

      <p>
        This Privacy Policy may be updated periodically to reflect platform updates,
        legal requirements, or operational changes.
      </p>
    </section>

    <section class="legal-section">
      <h2>Contact</h2>

      <p>
        Questions regarding privacy, data handling, or account deletion requests can be sent to:
      </p>

      <a class="legal-contact" href="mailto:benchbuddy.devworks@gmail.com">
        benchbuddy.devworks@gmail.com
      </a>
    </section>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
