<?php
declare(strict_types=1);

$pageTitle = 'Cookie Policy';
$currentPage = 'cookie_policy';

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
    <h1>Cookie Policy</h1>
    <p>Last Updated: May 15, 2026</p>
  </div>

  <div class="card legal-card">

    <section class="legal-section">
      <h2>What Are Cookies</h2>
      <p>
        Cookies are small text files stored on your device that help websites
        function properly, improve security, and enhance user experience.
      </p>
    </section>

    <section class="legal-section">
      <h2>Essential Cookies</h2>
      <p>
        BenchBuddy uses cookies and session technologies required for core platform functionality, including:
      </p>

      <ul>
        <li>User authentication and login sessions</li>
        <li>Session management</li>
        <li>Account security</li>
        <li>Maintaining platform functionality</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Security & Abuse Prevention</h2>
      <p>
        BenchBuddy may use temporary cookies, sessions, and related technologies to help protect the platform.
      </p>

      <ul>
        <li>Prevent spam and automated bot signups</li>
        <li>Detect suspicious or abusive activity</li>
        <li>Rate-limit malicious requests</li>
        <li>Maintain platform stability and security</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Preference Cookies</h2>
      <p>
        Preference cookies may store user settings such as theme colors,
        display preferences, and account-related interface settings.
      </p>
    </section>

    <section class="legal-section">
      <h2>Billing & Payment Cookies</h2>
      <p>
        BenchBuddy may use Stripe or other payment providers for subscription billing.
        These providers may use cookies and security technologies required for:
      </p>

      <ul>
        <li>Secure payment processing</li>
        <li>Fraud prevention</li>
        <li>Transaction verification</li>
        <li>Subscription management</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Analytics & Performance</h2>
      <p>
        BenchBuddy may use analytics or diagnostic technologies to understand
        platform usage, improve features, and monitor application performance.
      </p>
    </section>

    <section class="legal-section">
      <h2>Managing Cookies</h2>
      <p>
        Most web browsers allow you to control, block, or remove cookies through browser settings.
        Disabling certain cookies may affect parts of BenchBuddy functionality.
      </p>
    </section>

    <section class="legal-section">
      <h2>Changes To This Policy</h2>
      <p>
        This Cookie Policy may be updated periodically to reflect platform updates,
        security improvements, or legal requirements.
      </p>
    </section>

    <section class="legal-section">
      <h2>Contact</h2>
      <p>
        Questions regarding this Cookie Policy can be sent to:
      </p>

      <a class="legal-contact" href="mailto:benchbuddy.devworks@gmail.com">
        benchbuddy.devworks@gmail.com
      </a>
    </section>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
