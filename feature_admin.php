<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_admin_user();

$pageTitle = 'Feature Updates Admin';
$currentPage = 'features_admin';

$message = '';
$error = '';
$featureUpdates = [];
$editingFeatureId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingFeature = null;

function admin_feature_type_options(): array
{
    return [
        'feature' => 'New Feature',
        'improvement' => 'Improvement',
        'fix' => 'Bug Fix',
        'beta' => 'Beta',
        'roadmap' => 'Coming Soon',
        'performance' => 'Performance',
        'ui' => 'UI Update',
        'security' => 'Security',
    ];
}

function admin_feature_status_options(): array
{
    return [
        'live' => 'Live',
        'beta' => 'Beta',
        'planned' => 'Planned',
    ];
}

function admin_feature_visibility_options(): array
{
    return [
        'public' => 'Public',
        'beta' => 'Beta',
        'internal' => 'Internal',
    ];
}

function admin_get_feature_update_by_id(int $featureId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM feature_updates
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $featureId,
    ]);

    $feature = $stmt->fetch(PDO::FETCH_ASSOC);

    return $feature ?: null;
}

function admin_get_feature_updates(int $limit = 100): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM feature_updates
        ORDER BY
            COALESCE(release_date, DATE(created_at)) DESC,
            id DESC
        LIMIT :limit_count
    ");

    $stmt->bindValue('limit_count', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_save_feature_update(?int $featureId, array $data): void
{
    if ($featureId && $featureId > 0) {
        $stmt = db()->prepare("
            UPDATE feature_updates
            SET
                title = :title,
                summary = :summary,
                details = :details,
                feature_type = :feature_type,
                status = :status,
                release_date = :release_date,
                visibility = :visibility,
                release_version = :release_version,
                is_highlighted = :is_highlighted,
                created_at = :created_at
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'title' => $data['title'],
            'summary' => $data['summary'],
            'details' => $data['details'],
            'feature_type' => $data['feature_type'],
            'status' => $data['status'],
            'release_date' => $data['release_date'],
            'visibility' => $data['visibility'],
            'release_version' => $data['release_version'],
            'is_highlighted' => $data['is_highlighted'],
            'created_at' => $data['created_at'],
            'id' => $featureId,
        ]);

        return;
    }

    $stmt = db()->prepare("
        INSERT INTO feature_updates (
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
        )
        VALUES (
            :title,
            :summary,
            :details,
            :feature_type,
            :status,
            :release_date,
            :visibility,
            :release_version,
            :is_highlighted,
            :created_at
        )
    ");

    $stmt->execute([
        'title' => $data['title'],
        'summary' => $data['summary'],
        'details' => $data['details'],
        'feature_type' => $data['feature_type'],
        'status' => $data['status'],
        'release_date' => $data['release_date'],
        'visibility' => $data['visibility'],
        'release_version' => $data['release_version'],
        'is_highlighted' => $data['is_highlighted'],
        'created_at' => $data['created_at'],
    ]);
}

function admin_delete_feature_update(int $featureId): void
{
    $stmt = db()->prepare("
        DELETE FROM feature_updates
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $featureId,
    ]);
}

function admin_toggle_feature_highlight(int $featureId, bool $isHighlighted): void
{
    $stmt = db()->prepare("
        UPDATE feature_updates
        SET is_highlighted = :is_highlighted
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'is_highlighted' => $isHighlighted ? 1 : 0,
        'id' => $featureId,
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_feature_update' || $action === 'update_feature_update') {
            $featureId = $action === 'update_feature_update'
                ? (int)($_POST['feature_id'] ?? 0)
                : null;

            $title = trim((string)($_POST['title'] ?? ''));
            $summary = trim((string)($_POST['summary'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $featureType = trim((string)($_POST['feature_type'] ?? 'feature'));
            $status = trim((string)($_POST['status'] ?? 'live'));
            $releaseDate = trim((string)($_POST['release_date'] ?? ''));
            $visibility = trim((string)($_POST['visibility'] ?? 'public'));
            $releaseVersion = trim((string)($_POST['release_version'] ?? ''));
            $isHighlighted = !empty($_POST['is_highlighted']) ? 1 : 0;
            $createdAtInput = trim((string)($_POST['created_at'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Title is required.');
            }

            if ($summary === '') {
                throw new RuntimeException('Summary is required.');
            }

            if (!array_key_exists($featureType, admin_feature_type_options())) {
                throw new RuntimeException('Invalid feature type.');
            }

            if (!array_key_exists($status, admin_feature_status_options())) {
                throw new RuntimeException('Invalid status.');
            }

            if (!array_key_exists($visibility, admin_feature_visibility_options())) {
                throw new RuntimeException('Invalid visibility.');
            }

            $createdAt = $createdAtInput !== ''
                ? date('Y-m-d H:i:s', strtotime($createdAtInput))
                : date('Y-m-d H:i:s');

            admin_save_feature_update($featureId, [
                'title' => $title,
                'summary' => $summary,
                'details' => $details !== '' ? $details : null,
                'feature_type' => $featureType,
                'status' => $status,
                'release_date' => $releaseDate !== '' ? $releaseDate : null,
                'visibility' => $visibility,
                'release_version' => $releaseVersion !== '' ? $releaseVersion : null,
                'is_highlighted' => $isHighlighted,
                'created_at' => $createdAt,
            ]);

            header('Location: feature_admin.php?msg=' . ($featureId ? 'updated' : 'created'));
            exit;
        }

        if ($action === 'delete_feature_update') {
            admin_delete_feature_update((int)($_POST['feature_id'] ?? 0));

            header('Location: feature_admin.php?msg=deleted');
            exit;
        }

        if ($action === 'toggle_highlight') {
            $featureId = (int)($_POST['feature_id'] ?? 0);
            $isHighlighted = (int)($_POST['is_highlighted'] ?? 0) === 1;

            admin_toggle_feature_highlight($featureId, !$isHighlighted);

            header('Location: feature_admin.php');
            exit;
        }
    }

    $statusMessage = (string)($_GET['msg'] ?? '');

    if ($statusMessage === 'created') {
        $message = 'Feature update added successfully.';
    } elseif ($statusMessage === 'updated') {
        $message = 'Feature update updated successfully.';
    } elseif ($statusMessage === 'deleted') {
        $message = 'Feature update deleted successfully.';
    }

    if ($editingFeatureId > 0) {
        $editingFeature = admin_get_feature_update_by_id($editingFeatureId);

        if (!$editingFeature) {
            $editingFeatureId = 0;
        }
    }

    $featureUpdates = admin_get_feature_updates(100);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';

$createdAtValue = '';
if (!empty($editingFeature['created_at'])) {
    $createdAtValue = date('Y-m-d\TH:i', strtotime((string)$editingFeature['created_at']));
}

$releaseDateValue = !empty($editingFeature['release_date'])
    ? date('Y-m-d', strtotime((string)$editingFeature['release_date']))
    : date('Y-m-d');

$featureTypeValue = (string)($editingFeature['feature_type'] ?? 'feature');
$statusValue = (string)($editingFeature['status'] ?? 'live');
$visibilityValue = (string)($editingFeature['visibility'] ?? 'public');
?>

<h1 class="page-title brand-title-font">Feature Updates Admin</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="games-layout">
  <div class="card">
    <h2><?= $editingFeature ? 'Edit Feature Update' : 'Add Feature Update' ?></h2>

    <form method="post" action="feature_admin.php<?= $editingFeature ? '?edit=' . (int)$editingFeature['id'] : '' ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editingFeature ? 'update_feature_update' : 'create_feature_update' ?>">

      <?php if ($editingFeature): ?>
        <input type="hidden" name="feature_id" value="<?= (int)$editingFeature['id'] ?>">
      <?php endif; ?>

      <div class="form-field">
        <label for="title">Title</label>
        <input
          type="text"
          id="title"
          name="title"
          required
          maxlength="150"
          value="<?= h((string)($editingFeature['title'] ?? '')) ?>"
          placeholder="Manual Lineup Autosave"
        >
      </div>

      <div class="form-field">
        <label for="summary">Summary</label>
        <input
          type="text"
          id="summary"
          name="summary"
          maxlength="255"
          required
          value="<?= h((string)($editingFeature['summary'] ?? '')) ?>"
          placeholder="Manual lineup edits now autosave while coaches work."
        >
      </div>

      <div class="form-field">
        <label for="details">Details</label>
        <textarea
          id="details"
          name="details"
          rows="6"
          placeholder="Explain what changed, why it matters, and where coaches can use it."
        ><?= h((string)($editingFeature['details'] ?? '')) ?></textarea>
      </div>

      <div class="game-meta-grid">
        <div class="form-field">
          <label for="feature_type">Feature Type</label>
          <select id="feature_type" name="feature_type">
            <?php foreach (admin_feature_type_options() as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $featureTypeValue === $value ? 'selected' : '' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-field">
          <label for="status">Status</label>
          <select id="status" name="status">
            <?php foreach (admin_feature_status_options() as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusValue === $value ? 'selected' : '' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="game-meta-grid">
        <div class="form-field">
          <label for="release_date">Release Date</label>
          <input
            type="date"
            id="release_date"
            name="release_date"
            value="<?= h($releaseDateValue) ?>"
          >
        </div>

        <div class="form-field">
          <label for="release_version">Release Version</label>
          <input
            type="text"
            id="release_version"
            name="release_version"
            maxlength="30"
            value="<?= h((string)($editingFeature['release_version'] ?? '')) ?>"
            placeholder="Beta 1.6"
          >
        </div>
      </div>

      <div class="game-meta-grid">
        <div class="form-field">
          <label for="visibility">Visibility</label>
          <select id="visibility" name="visibility">
            <?php foreach (admin_feature_visibility_options() as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $visibilityValue === $value ? 'selected' : '' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-field">
          <label for="created_at">Admin Created Date</label>
          <input
            type="datetime-local"
            id="created_at"
            name="created_at"
            value="<?= h($createdAtValue) ?>"
          >
        </div>
      </div>

      <div class="form-field checkbox-field" style="margin-top:16px;">
        <label class="ios-toggle-wrap" for="is_highlighted">
          <span class="ios-toggle-label">Highlight this update</span>
          <span class="ios-toggle">
            <input
              type="checkbox"
              id="is_highlighted"
              name="is_highlighted"
              value="1"
              <?= !empty($editingFeature['is_highlighted']) ? 'checked' : '' ?>
            >
            <span class="ios-toggle-slider"></span>
          </span>
        </label>

        <div class="muted" style="margin-top:6px;">
          Highlighted updates can be shown more prominently on What’s New or inside Coach Helper nudges.
        </div>
      </div>

      <div class="actions-row" style="margin-top:20px;">
        <button type="submit">
          <?= $editingFeature ? 'Update Feature Update' : 'Add Feature Update' ?>
        </button>

        <?php if ($editingFeature): ?>
          <a class="btn btn-secondary" href="feature_admin.php">Cancel Edit</a>
        <?php endif; ?>

        <a class="btn btn-secondary" href="features.php">View What’s New</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Recent Feature History</h2>

    <?php if (empty($featureUpdates)): ?>
      <p class="muted">No feature updates added yet.</p>
    <?php else: ?>
      <div class="table-wrap desktop-table">
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Summary</th>
              <th>Version</th>
              <th>Type</th>
              <th>Status</th>
              <th>Visibility</th>
              <th>Release Date</th>
              <th>Highlighted</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($featureUpdates as $feature): ?>
              <tr>
                <td><?= h((string)$feature['title']) ?></td>
                <td><?= h((string)($feature['summary'] ?? '')) ?></td>
                <td><?= h((string)($feature['release_version'] ?? '')) ?></td>
                <td><?= h(admin_feature_type_options()[(string)($feature['feature_type'] ?? 'feature')] ?? 'Update') ?></td>
                <td><?= h(ucwords((string)($feature['status'] ?? ''))) ?></td>
                <td><?= h(ucwords((string)($feature['visibility'] ?? 'public'))) ?></td>
                <td>
                  <?= !empty($feature['release_date'])
                      ? h(date('M j, Y', strtotime((string)$feature['release_date'])))
                      : h(date('M j, Y', strtotime((string)$feature['created_at'])))
                  ?>
                </td>
                <td>
                  <form method="post" style="display:inline;">
                      <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_highlight">
                    <input type="hidden" name="feature_id" value="<?= (int)$feature['id'] ?>">
                    <input type="hidden" name="is_highlighted" value="<?= (int)$feature['is_highlighted'] ?>">

                    <button type="submit" class="btn btn-secondary" style="padding:4px 8px;">
                      <?= !empty($feature['is_highlighted']) ? 'Unhighlight' : 'Highlight' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="inline-actions">
                    <a class="btn-sm" href="feature_admin.php?edit=<?= (int)$feature['id'] ?>">Edit</a>

                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this feature update?');">
                        <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_feature_update">
                      <input type="hidden" name="feature_id" value="<?= (int)$feature['id'] ?>">
                      <button type="submit" class="btn-sm btn-danger">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-cards">
        <?php foreach ($featureUpdates as $feature): ?>
          <div class="mobile-card">
            <div class="mobile-card-title"><?= h((string)$feature['title']) ?></div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Summary</span>
              <?= h((string)($feature['summary'] ?? '')) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Version</span>
              <?= h((string)($feature['release_version'] ?? '')) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Type</span>
              <?= h(admin_feature_type_options()[(string)($feature['feature_type'] ?? 'feature')] ?? 'Update') ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Status</span>
              <?= h(ucwords((string)($feature['status'] ?? ''))) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Visibility</span>
              <?= h(ucwords((string)($feature['visibility'] ?? 'public'))) ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Release</span>
              <?= !empty($feature['release_date'])
                  ? h(date('M j, Y', strtotime((string)$feature['release_date'])))
                  : h(date('M j, Y', strtotime((string)$feature['created_at'])))
              ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Highlighted</span>
              <?= !empty($feature['is_highlighted']) ? 'Yes' : 'No' ?>
            </div>

            <div class="stack-actions" style="margin-top:12px;">
              <a class="btn" href="feature_admin.php?edit=<?= (int)$feature['id'] ?>">Edit</a>

              <form method="post">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_highlight">
                <input type="hidden" name="feature_id" value="<?= (int)$feature['id'] ?>">
                <input type="hidden" name="is_highlighted" value="<?= (int)$feature['is_highlighted'] ?>">

                <button type="submit" class="btn btn-secondary">
                  <?= !empty($feature['is_highlighted']) ? 'Unhighlight' : 'Highlight' ?>
                </button>
              </form>

              <form method="post" onsubmit="return confirm('Delete this feature update?');">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_feature_update">
                <input type="hidden" name="feature_id" value="<?= (int)$feature['id'] ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<button
  type="button"
  class="btn btn-secondary"
  style="margin-top:16px;"
  onclick="localStorage.removeItem('coach_helper_dismissed_feature_id'); location.reload();"
>
  Reset Feature Nudge
</button>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
