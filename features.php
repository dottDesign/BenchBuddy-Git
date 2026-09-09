<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'What’s New';
$currentPage = 'features';

$error = '';
$featureUpdates = [];
$highlightedUpdates = [];
$regularUpdates = [];

function features_type_label(string $type): string
{
    if (function_exists('feature_type_label')) {
        return feature_type_label($type);
    }

    return match ($type) {
        'feature' => 'New Feature',
        'improvement' => 'Improvement',
        'fix' => 'Bug Fix',
        'beta' => 'Beta',
        'roadmap' => 'Coming Soon',
        'performance' => 'Performance',
        'ui' => 'UI Update',
        'security' => 'Security',
        default => 'Update',
    };
}

function features_type_class(string $type): string
{
    if (function_exists('feature_type_class')) {
        return feature_type_class($type);
    }

    return match ($type) {
        'feature' => 'feature-badge-feature',
        'improvement' => 'feature-badge-improvement',
        'fix' => 'feature-badge-fix',
        'beta' => 'feature-badge-beta',
        'roadmap' => 'feature-badge-roadmap',
        'performance' => 'feature-badge-performance',
        'ui' => 'feature-badge-ui',
        'security' => 'feature-badge-security',
        default => 'feature-badge-default',
    };
}

function features_release_date(array $feature): string
{
    $dateValue = !empty($feature['release_date'])
        ? (string)$feature['release_date']
        : (string)($feature['created_at'] ?? '');

    if ($dateValue === '') {
        return '';
    }

    $time = strtotime($dateValue);

    return $time !== false ? date('M j, Y', $time) : $dateValue;
}

function features_is_new(array $feature): bool
{
    $dateValue = !empty($feature['release_date'])
        ? (string)$feature['release_date']
        : (string)($feature['created_at'] ?? '');

    $time = strtotime($dateValue);

    return $time !== false && $time >= strtotime('-7 days');
}

try {
    $stmt = db()->prepare("
        SELECT
            id,
            title,
            summary,
            details,
            feature_type,
            status,
            release_date,
            visibility,
            release_version,
            is_highlighted,
            created_at
        FROM feature_updates
        WHERE visibility IN ('public', 'beta')
        ORDER BY
            is_highlighted DESC,
            COALESCE(release_date, DATE(created_at)) DESC,
            id DESC
        LIMIT 100
    ");

    $stmt->execute();
    $featureUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($featureUpdates as $feature) {
        if (!empty($feature['is_highlighted'])) {
            $highlightedUpdates[] = $feature;
        } else {
            $regularUpdates[] = $feature;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .features-hero {
    padding: 30px;
    border-radius: 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    margin-bottom: 22px;
  }

  .features-hero h1 {
    margin: 0 0 10px;
    color: #fff;
  }

  .features-hero p {
    margin: 0;
    max-width: 720px;
    color: rgba(255,255,255,.85);
    line-height: 1.6;
  }

  .features-section-header {
    margin: 26px 0 14px;
  }

  .features-section-header h2 {
    margin-bottom: 4px;
  }

  .feature-card {
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    background: #fff;
    padding: 22px;
    margin-bottom: 16px;
  }

  .feature-card-highlighted {
    border-color: rgba(37, 99, 235, .35);
    box-shadow: 0 16px 34px rgba(15, 23, 42, .08);
  }

  .feature-card-top {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 12px;
  }

  .feature-badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .feature-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
  }

  .feature-badge-feature {
    background: #dbeafe;
    color: #1d4ed8;
  }

  .feature-badge-improvement {
    background: #e0f2fe;
    color: #0369a1;
  }

  .feature-badge-fix {
    background: #dcfce7;
    color: #166534;
  }

  .feature-badge-beta {
    background: #fef3c7;
    color: #92400e;
  }

  .feature-badge-roadmap {
    background: #f3e8ff;
    color: #7e22ce;
  }

  .feature-badge-performance {
    background: #ccfbf1;
    color: #0f766e;
  }

  .feature-badge-ui {
    background: #ffe4e6;
    color: #be123c;
  }

  .feature-badge-security {
    background: #fee2e2;
    color: #b91c1c;
  }

  .feature-badge-default,
  .feature-badge-new,
  .feature-badge-highlighted {
    background: #f1f5f9;
    color: #334155;
  }

  .feature-badge-new {
    background: #dcfce7;
    color: #166534;
  }

  .feature-badge-highlighted {
    background: #2563eb;
    color: #fff;
  }

  .feature-date {
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
  }

  .feature-card h2 {
    margin: 0 0 8px;
  }

  .feature-meta {
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 12px;
  }

  .feature-summary {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 12px;
    color: #111827;
  }

  .feature-details {
    color: #475569;
    line-height: 1.65;
  }

  @media (max-width: 700px) {
    .features-hero {
      padding: 24px;
    }

    .feature-card-top {
      display: block;
    }

    .feature-date {
      display: block;
      margin-top: 10px;
    }
  }
</style>

<div class="features-hero">
  <h1>What’s New in BenchBuddy</h1>
  <p>
    Follow recent releases, improvements, bug fixes, beta tools, and upcoming features built to make game day easier for coaches.
  </p>
</div>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<?php if (empty($featureUpdates)): ?>
  <div class="card">
    <p class="muted">No feature updates have been added yet.</p>
  </div>
<?php else: ?>

  <?php if (!empty($highlightedUpdates)): ?>
    <div class="features-section-header">
      <h2>Featured Updates</h2>
      <p class="muted">Key BenchBuddy improvements worth checking out first.</p>
    </div>

    <?php foreach ($highlightedUpdates as $feature): ?>
      <?php
        $type = (string)($feature['feature_type'] ?? 'feature');
        $releaseDate = features_release_date($feature);
        $isNew = features_is_new($feature);
      ?>

      <article class="feature-card feature-card-highlighted">
        <div class="feature-card-top">
          <div class="feature-badge-row">
            <span class="feature-badge feature-badge-highlighted">Featured</span>

            <?php if ($isNew): ?>
              <span class="feature-badge feature-badge-new">New</span>
            <?php endif; ?>

            <span class="feature-badge <?= h(features_type_class($type)) ?>">
              <?= h(features_type_label($type)) ?>
            </span>
          </div>

          <?php if ($releaseDate !== ''): ?>
            <span class="feature-date"><?= h($releaseDate) ?></span>
          <?php endif; ?>
        </div>

        <h2><?= h((string)$feature['title']) ?></h2>

        <div class="feature-meta">
          <?= h(ucwords((string)($feature['status'] ?? 'live'))) ?>
          <?php if (!empty($feature['release_version'])): ?>
            · <?= h((string)$feature['release_version']) ?>
          <?php endif; ?>
        </div>

        <p class="feature-summary"><?= h((string)($feature['summary'] ?? '')) ?></p>

        <?php if (!empty($feature['details'])): ?>
          <div class="feature-details">
            <?= nl2br(h((string)$feature['details'])) ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($regularUpdates)): ?>
    <div class="features-section-header">
      <h2>Release Timeline</h2>
      <p class="muted">Recent improvements and fixes, newest first.</p>
    </div>

    <?php foreach ($regularUpdates as $feature): ?>
      <?php
        $type = (string)($feature['feature_type'] ?? 'feature');
        $releaseDate = features_release_date($feature);
        $isNew = features_is_new($feature);
      ?>

      <article class="feature-card">
        <div class="feature-card-top">
          <div class="feature-badge-row">
            <?php if ($isNew): ?>
              <span class="feature-badge feature-badge-new">New</span>
            <?php endif; ?>

            <span class="feature-badge <?= h(features_type_class($type)) ?>">
              <?= h(features_type_label($type)) ?>
            </span>
          </div>

          <?php if ($releaseDate !== ''): ?>
            <span class="feature-date"><?= h($releaseDate) ?></span>
          <?php endif; ?>
        </div>

        <h2><?= h((string)$feature['title']) ?></h2>

        <div class="feature-meta">
          <?= h(ucwords((string)($feature['status'] ?? 'live'))) ?>
          <?php if (!empty($feature['release_version'])): ?>
            · <?= h((string)$feature['release_version']) ?>
          <?php endif; ?>
        </div>

        <p class="feature-summary"><?= h((string)($feature['summary'] ?? '')) ?></p>

        <?php if (!empty($feature['details'])): ?>
          <div class="feature-details">
            <?= nl2br(h((string)$feature['details'])) ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
