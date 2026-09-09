<?php
declare(strict_types=1);

$pageTitle = 'Terms of Service';
$currentPage = 'terms';

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
    <h1>Terms of Service</h1>
    <p>Last Updated: May 15, 2026</p>
  </div>

  <div class="card legal-card">
    <section class="legal-section">
      <h2>Acceptance of Terms</h2>
      <p>
        By accessing or using BenchBuddy, you agree to these Terms of Service.
        If you do not agree, you should not use the platform.
      </p>
    </section>

    <section class="legal-section">
      <h2>Use of BenchBuddy</h2>
      <p>
        BenchBuddy is designed for baseball and softball team management,
        lineup planning, player tracking, pitch tracking, and coaching operations.
      </p>
    </section>

    <section class="legal-section">
      <h2>User Accounts</h2>
      <ul>
        <li>Users are responsible for maintaining account security.</li>
        <li>Users must provide accurate account information.</li>
        <li>Users are responsible for activity that occurs under their account.</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Subscriptions & Billing</h2>
      <p>
        Certain BenchBuddy features may require a paid subscription. BenchBuddy may offer
        monthly plans, yearly plans, free trials, promotional access, or admin-granted access.
      </p>
      <p>
        Pricing, plan limits, and feature availability may change over time.
      </p>
    </section>

    <section class="legal-section">
      <h2>Trials</h2>
      <p>
        Trial access may expire automatically after the stated trial period.
        After a trial ends, access may move to a free plan unless upgraded.
      </p>
    </section>

    <section class="legal-section">
      <h2>Acceptable Use</h2>
      <p>You agree not to:</p>
      <ul>
        <li>Attempt unauthorized access to BenchBuddy or other accounts.</li>
        <li>Interfere with platform functionality or security.</li>
        <li>Upload malicious, unlawful, or harmful content.</li>
        <li>Use BenchBuddy for unlawful activity.</li>
        <li>Abuse, harass, or interfere with other users.</li>
      </ul>
    </section>

    <section class="legal-section">
      <h2>Team Collaboration</h2>
      <p>
        Team administrators and Head Coaches may manage team members, roles,
        player data, lineups, team settings, and related team content.
      </p>
    </section>

    <section class="legal-section">
      <h2>Intellectual Property</h2>
      <p>
        BenchBuddy retains ownership of its platform software, design, systems, and branding.
        Users retain ownership of their uploaded team content, including team logos and team data.
      </p>
    </section>

    <section class="legal-section">
      <h2>Availability</h2>
      <p>
        BenchBuddy is provided on an “as available” basis. We do not guarantee uninterrupted
        access, error-free operation, or permanent availability of every feature.
      </p>
    </section>

    <section class="legal-section">
      <h2>Limitation of Liability</h2>
      <p>
        BenchBuddy shall not be liable for indirect damages, lost data, lost profits,
        business interruption, or coaching decisions and outcomes resulting from platform use.
      </p>
    </section>

    <section class="legal-section">
      <h2>Account Termination</h2>
      <p>
        Accounts may be suspended, restricted, or terminated for abuse, fraud,
        unlawful use, or violations of these Terms.
      </p>
    </section>

    <section class="legal-section">
      <h2>Changes To These Terms</h2>
      <p>
        These Terms may be updated periodically. Continued use of BenchBuddy after updates
        means you accept the revised Terms.
      </p>
    </section>

    <section class="legal-section">
      <h2>Contact</h2>
      <p>
        Questions about these Terms can be sent to:
      </p>
      <a class="legal-contact" href="mailto:benchbuddy.devworks@gmail.com">
        benchbuddy.devworks@gmail.com
      </a>
    </section>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
