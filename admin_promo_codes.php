<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'Admin Promo Codes';
$currentPage = 'admin_promo_codes';

$user = get_current_user_record();

$isAdmin = !empty($user['is_admin']) || (($user['role'] ?? '') === 'admin');

if (!$user || !$isAdmin) {
    http_response_code(403);
    exit('Access denied.');
}

$message = '';
$error = '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingPromo = null;

$form = [
    'code' => '',
    'trial_days' => 30,
    'description' => '',
    'active' => 1,
    'starts_at' => '',
    'ends_at' => '',
    'max_redemptions' => '',
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_promo' || $action === 'update_promo') {
            $promoId = (int)($_POST['promo_id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $trialDays = max(1, (int)($_POST['trial_days'] ?? 30));
            $description = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;
            $startsAt = trim((string)($_POST['starts_at'] ?? ''));
            $endsAt = trim((string)($_POST['ends_at'] ?? ''));
            $maxRedemptionsRaw = trim((string)($_POST['max_redemptions'] ?? ''));

            if ($code === '') {
                throw new RuntimeException('Promo code is required.');
            }

            if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
                throw new RuntimeException('Use only letters, numbers, dashes, and underscores.');
            }

            if ($trialDays > 730) {
                throw new RuntimeException('Trial days cannot exceed 730.');
            }

            $startsAtValue = $startsAt !== '' ? str_replace('T', ' ', $startsAt) . ':00' : null;
            $endsAtValue = $endsAt !== '' ? str_replace('T', ' ', $endsAt) . ':00' : null;
            $maxRedemptions = $maxRedemptionsRaw !== '' ? max(1, (int)$maxRedemptionsRaw) : null;

            if ($action === 'create_promo') {
                $stmt = db()->prepare("
                    INSERT INTO promo_codes (
                        code,
                        trial_days,
                        description,
                        active,
                        starts_at,
                        ends_at,
                        max_redemptions,
                        redemption_count,
                        created_at,
                        updated_at
                    ) VALUES (
                        :code,
                        :trial_days,
                        :description,
                        :active,
                        :starts_at,
                        :ends_at,
                        :max_redemptions,
                        0,
                        NOW(),
                        NOW()
                    )
                ");

                $stmt->execute([
                    'code' => $code,
                    'trial_days' => $trialDays,
                    'description' => $description !== '' ? $description : null,
                    'active' => $active,
                    'starts_at' => $startsAtValue,
                    'ends_at' => $endsAtValue,
                    'max_redemptions' => $maxRedemptions,
                ]);

                flash_redirect('ok', 'Promo code created.', 'admin_promo_codes.php');
            }

            if ($action === 'update_promo') {
                if ($promoId <= 0) {
                    throw new RuntimeException('Invalid promo selected.');
                }

                $stmt = db()->prepare("
                    UPDATE promo_codes
                    SET
                        code = :code,
                        trial_days = :trial_days,
                        description = :description,
                        active = :active,
                        starts_at = :starts_at,
                        ends_at = :ends_at,
                        max_redemptions = :max_redemptions,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    'code' => $code,
                    'trial_days' => $trialDays,
                    'description' => $description !== '' ? $description : null,
                    'active' => $active,
                    'starts_at' => $startsAtValue,
                    'ends_at' => $endsAtValue,
                    'max_redemptions' => $maxRedemptions,
                    'id' => $promoId,
                ]);

                flash_redirect('ok', 'Promo code updated.', 'admin_promo_codes.php');
            }
        }

        if ($action === 'toggle_promo') {
            $promoId = (int)($_POST['promo_id'] ?? 0);

            if ($promoId <= 0) {
                throw new RuntimeException('Invalid promo selected.');
            }

            db()->prepare("
                UPDATE promo_codes
                SET
                    active = CASE WHEN active = 1 THEN 0 ELSE 1 END,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([
                'id' => $promoId,
            ]);

            flash_redirect('ok', 'Promo status updated.', 'admin_promo_codes.php');
        }

        if ($action === 'delete_promo') {
            $promoId = (int)($_POST['promo_id'] ?? 0);

            if ($promoId <= 0) {
                throw new RuntimeException('Invalid promo selected.');
            }

            db()->prepare("
                DELETE FROM promo_codes
                WHERE id = :id
                LIMIT 1
            ")->execute([
                'id' => $promoId,
            ]);

            flash_redirect('ok', 'Promo code deleted.', 'admin_promo_codes.php');
        }
    }

    if ($editId > 0) {
        $stmt = db()->prepare("
            SELECT *
            FROM promo_codes
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $editId,
        ]);

        $editingPromo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editingPromo) {
            throw new RuntimeException('Promo code not found.');
        }

        $form = [
            'code' => (string)($editingPromo['code'] ?? ''),
            'trial_days' => (int)($editingPromo['trial_days'] ?? 30),
            'description' => (string)($editingPromo['description'] ?? ''),
            'active' => (int)($editingPromo['active'] ?? 1),
            'starts_at' => !empty($editingPromo['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$editingPromo['starts_at'])) : '',
            'ends_at' => !empty($editingPromo['ends_at']) ? date('Y-m-d\TH:i', strtotime((string)$editingPromo['ends_at'])) : '',
            'max_redemptions' => $editingPromo['max_redemptions'] !== null ? (string)$editingPromo['max_redemptions'] : '',
        ];
    }

    $stmt = db()->query("
        SELECT *
        FROM promo_codes
        ORDER BY created_at DESC, id DESC
    ");

    $promoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
    $promoCodes = [];

    try {
        $stmt = db()->query("
            SELECT *
            FROM promo_codes
            ORDER BY created_at DESC, id DESC
        ");

        $promoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
        $promoCodes = [];
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Admin Promo Codes</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="layout-grid">
  <div class="card">
    <h2><?= $editingPromo ? 'Edit Promo Code' : 'Create Promo Code' ?></h2>

    <form method="post" action="admin_promo_codes.php<?= $editingPromo ? '?edit=' . (int)$editingPromo['id'] : '' ?>">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editingPromo ? 'update_promo' : 'create_promo' ?>">

      <?php if ($editingPromo): ?>
        <input type="hidden" name="promo_id" value="<?= (int)$editingPromo['id'] ?>">
      <?php endif; ?>

      <div class="promo-form-grid">
        <div class="form-field">
          <label for="code">Promo Code</label>
          <input
            type="text"
            id="code"
            name="code"
            value="<?= h((string)$form['code']) ?>"
            placeholder="CLOVERDALECOACH"
            maxlength="50"
            required
          >
        </div>

        <div class="form-field">
          <label for="trial_days">Trial Days</label>
          <input
            type="number"
            id="trial_days"
            name="trial_days"
            min="1"
            max="730"
            value="<?= (int)$form['trial_days'] ?>"
            required
          >
        </div>

        <div class="form-field">
          <label for="max_redemptions">Max Redemptions</label>
          <input
            type="number"
            id="max_redemptions"
            name="max_redemptions"
            min="1"
            value="<?= h((string)$form['max_redemptions']) ?>"
            placeholder="Unlimited"
          >
        </div>

        <div class="form-field toggle-field">
          <label class="toggle-row" for="active_toggle">
            <span class="toggle-label-text">Active</span>
            <span class="toggle-switch">
              <input
                type="checkbox"
                id="active_toggle"
                name="active"
                value="1"
                <?= !empty($form['active']) ? 'checked' : '' ?>
              >
              <span class="toggle-slider"></span>
            </span>
          </label>
        </div>

        <div class="form-field">
          <label for="starts_at">Starts At</label>
          <input
            type="datetime-local"
            id="starts_at"
            name="starts_at"
            value="<?= h((string)$form['starts_at']) ?>"
          >
        </div>

        <div class="form-field">
          <label for="ends_at">Ends At</label>
          <input
            type="datetime-local"
            id="ends_at"
            name="ends_at"
            value="<?= h((string)$form['ends_at']) ?>"
          >
        </div>

        <div class="form-field span-2">
          <label for="description">Description</label>
          <input
            type="text"
            id="description"
            name="description"
            value="<?= h((string)$form['description']) ?>"
            placeholder="Clinic, partner, team, or campaign name"
            maxlength="255"
          >
        </div>
      </div>

      <div class="actions-row" style="margin-top:18px;">
        <button type="submit"><?= $editingPromo ? 'Save Promo Code' : 'Create Promo Code' ?></button>

        <?php if ($editingPromo): ?>
          <a class="btn btn-secondary" href="admin_promo_codes.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Promo Codes</h2>

    <?php if (empty($promoCodes)): ?>
      <p class="muted">No promo codes found.</p>
    <?php else: ?>
      <div class="table-wrap desktop-table">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Trial</th>
              <th>Status</th>
              <th>Redemptions</th>
              <th>Window</th>
              <th>Description</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
              <?php foreach ($promoCodes as $promo): ?>
                <?php
                  $max = $promo['max_redemptions'] !== null
                      ? (int)$promo['max_redemptions']
                      : null;

                  $used = (int)($promo['redemption_count'] ?? 0);

                  $signupLink = 'https://benchbuddy.devworks.space/signup.php?promo='
                      . rawurlencode((string)$promo['code']);
                ?>
              <tr>
                <td><strong><?= h((string)$promo['code']) ?></strong></td>
                <td><?= (int)$promo['trial_days'] ?> days</td>
                <td>
                  <span class="pill <?= !empty($promo['active']) ? 'generated' : 'default' ?>">
                    <?= !empty($promo['active']) ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td>
                  <?= $used ?>
                  /
                  <?= $max !== null ? $max : 'Unlimited' ?>
                </td>
                <td>
                  <div class="muted">
                    Starts:
                    <?= !empty($promo['starts_at']) ? h((string)$promo['starts_at']) : 'Anytime' ?>
                  </div>
                  <div class="muted">
                    Ends:
                    <?= !empty($promo['ends_at']) ? h((string)$promo['ends_at']) : 'Never' ?>
                  </div>
                </td>
                <td><?= h((string)($promo['description'] ?? '')) ?></td>
                <td>
                  <div class="stack-actions">
                    <a class="btn btn-secondary" href="admin_promo_codes.php?edit=<?= (int)$promo['id'] ?>">Edit</a>

                    <form method="post" action="admin_promo_codes.php">
                        <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_promo">
                      <input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>">
                      <button type="submit" class="btn btn-secondary">
                        <?= !empty($promo['active']) ? 'Deactivate' : 'Activate' ?>
                      </button>
                      <button
                        type="button"
                        class="btn btn-secondary copy-promo-link"
                        data-copy-link="<?= h($signupLink) ?>"
                      >
                        Copy Link
                      </button>
                    </form>

                    <form method="post" action="admin_promo_codes.php" onsubmit="return confirm('Delete this promo code?');">
                        <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_promo">
                      <input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>">
                      <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-cards">
          <?php foreach ($promoCodes as $promo): ?>
            <?php
              $max = $promo['max_redemptions'] !== null
                  ? (int)$promo['max_redemptions']
                  : null;

              $used = (int)($promo['redemption_count'] ?? 0);

              $signupLink = 'https://benchbuddy.devworks.space/signup.php?promo='
                  . rawurlencode((string)$promo['code']);
            ?>

          <div class="mobile-card">
            <div class="mobile-card-title"><?= h((string)$promo['code']) ?></div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Trial</span>
              <?= (int)$promo['trial_days'] ?> days
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Status</span>
              <?= !empty($promo['active']) ? 'Active' : 'Inactive' ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Used</span>
              <?= $used ?> / <?= $max !== null ? $max : 'Unlimited' ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Starts</span>
              <?= !empty($promo['starts_at']) ? h((string)$promo['starts_at']) : 'Anytime' ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Ends</span>
              <?= !empty($promo['ends_at']) ? h((string)$promo['ends_at']) : 'Never' ?>
            </div>

            <?php if (!empty($promo['description'])): ?>
              <div class="mobile-card-row">
                <span class="mobile-card-label">Description</span>
                <?= h((string)$promo['description']) ?>
              </div>
            <?php endif; ?>

            <div class="stack-actions" style="margin-top:12px;">
              <a class="btn btn-secondary" href="admin_promo_codes.php?edit=<?= (int)$promo['id'] ?>">Edit</a>

              <form method="post" action="admin_promo_codes.php">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_promo">
                <input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>">
                <button type="submit" class="btn btn-secondary">
                  <?= !empty($promo['active']) ? 'Deactivate' : 'Activate' ?>
                </button>
                <button
                  type="button"
                  class="btn btn-secondary copy-promo-link"
                  data-copy-link="<?= h($signupLink) ?>"
                >
                  Copy Link
                </button>
              </form>

              <form method="post" action="admin_promo_codes.php" onsubmit="return confirm('Delete this promo code?');">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_promo">
                <input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.promo-form-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  align-items: start;
}

.promo-form-grid .form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.promo-form-grid .span-2 {
  grid-column: span 2;
}

.toggle-field {
  justify-content: end;
}

@media (max-width: 900px) {
  .promo-form-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .promo-form-grid .span-2 {
    grid-column: span 2;
  }
}

@media (max-width: 560px) {
  .promo-form-grid {
    grid-template-columns: 1fr;
  }

  .promo-form-grid .span-2 {
    grid-column: span 1;
  }
}
</style>
<script>
document.addEventListener('click', async function (event) {
  const button = event.target.closest('.copy-promo-link');

  if (!button) {
    return;
  }

  const signupLink = button.dataset.copyLink || '';

  if (!signupLink) {
    return;
  }

  const originalText = button.textContent.trim();

  try {
    await navigator.clipboard.writeText(signupLink);

    button.textContent = 'Copied!';
    button.disabled = true;

    window.setTimeout(function () {
      button.textContent = originalText;
      button.disabled = false;
    }, 1800);
  } catch (error) {
    const temporaryInput = document.createElement('textarea');

    temporaryInput.value = signupLink;
    temporaryInput.setAttribute('readonly', '');
    temporaryInput.style.position = 'fixed';
    temporaryInput.style.opacity = '0';

    document.body.appendChild(temporaryInput);
    temporaryInput.select();

    const copied = document.execCommand('copy');

    document.body.removeChild(temporaryInput);

    if (copied) {
      button.textContent = 'Copied!';

      window.setTimeout(function () {
        button.textContent = originalText;
      }, 1800);
    } else {
      window.prompt('Copy this signup link:', signupLink);
    }
  }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
