<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';

require_login();

$teamId = current_team_id();

if (
    function_exists('billing_enforcement_enabled') &&
    billing_enforcement_enabled()
) {
    require_team_feature(
        $teamId,
        'lineup_templates.manage',
        'Lineup templates require a paid plan.'
    );
}

$pageTitle = 'Lineup Templates';
$currentPage = 'templates';

$message = '';
$error = '';
$templates = [];
$editingTemplateId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingTemplate = null;
$templateLimit = null;
$templateCount = count_team_lineup_templates_for_limit($teamId);
$canCreateMoreTemplates = true;

if (
    function_exists('billing_enforcement_enabled') &&
    billing_enforcement_enabled()
) {
    $templateLimit = team_feature_limit($teamId, 'lineup_templates_per_team');
    $canCreateMoreTemplates = $templateLimit === null || $templateCount < $templateLimit;
}
try {
    if ($teamId <= 0) {
        throw new RuntimeException('No team selected for this account.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'rename_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $name = (string)($_POST['name'] ?? '');

            rename_lineup_template($teamId, $templateId, $name);

            header('Location: lineup_templates.php?msg=renamed');
            exit;
        }

        if ($action === 'delete_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);

            delete_lineup_template($teamId, $templateId);

            header('Location: lineup_templates.php?msg=deleted');
            exit;
        }

        if ($action === 'duplicate_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);

            assert_team_limit_available(
                $teamId,
                'lineup_templates_per_team',
                count_team_lineup_templates_for_limit($teamId),
                'Your current plan has reached its lineup template limit. Upgrade to save more templates.'
            );

            duplicate_lineup_template($teamId, $templateId);

            header('Location: lineup_templates.php?msg=duplicated');
            exit;
        }
    }

    $statusMessage = (string)($_GET['msg'] ?? '');
    if ($statusMessage === 'renamed') {
        flash_redirect('ok', 'Template renamed successfully.', 'lineup_templates.php');
    } elseif ($statusMessage === 'deleted') {
        flash_redirect('ok', 'Template deleted successfully.', 'lineup_templates.php');
    } elseif ($statusMessage === 'duplicated') {
        flash_redirect('ok', 'Template duplicated successfully.', 'lineup_templates.php');
    }

    if ($editingTemplateId > 0) {
        $editingTemplate = get_lineup_template_by_id($teamId, $editingTemplateId);
        if (!$editingTemplate) {
            $editingTemplateId = 0;
        }
    }

    $templates = get_lineup_templates($teamId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="page-title brand-title-font">Lineup Templates</h1>

<?php if ($message !== ''): ?>
  <div class="msg ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>

<div class="games-layout">
  <div class="card">
    <h2><?= $editingTemplate ? 'Rename Template' : 'How Templates Work' ?></h2>

    <?php if ($editingTemplate): ?>
      <form method="post" action="lineup_templates.php?edit=<?= (int)$editingTemplate['id'] ?>">
          <?= csrf_field() ?>
        <input type="hidden" name="action" value="rename_template">
        <input type="hidden" name="template_id" value="<?= (int)$editingTemplate['id'] ?>">

        <div class="form-field">
          <label for="name">Template Name</label>
          <input
            type="text"
            id="name"
            name="name"
            required
            maxlength="100"
            value="<?= h((string)$editingTemplate['name']) ?>"
          >
        </div>

        <div class="actions-row" style="margin-top:20px;">
          <button type="submit">Save Template Name</button>
          <a class="btn btn-secondary" href="lineup_templates.php">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <p class="muted">
        Lineup templates let you save reusable defensive patterns and apply them later when building a game lineup.
      </p>

      <p class="muted" style="margin-top:12px;">
        Use templates for common rotations, tournament patterns, 8-player alignments, or preferred inning setups.
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="actions-row" style="justify-content:space-between; align-items:flex-start;">
      <h2 style="margin:0;">Saved Templates</h2>
    </div>

    <?php if (empty($templates)): ?>
      <p class="muted">No lineup templates saved yet.</p>
    <?php else: ?>


      <div class="mobile-cards">
        <?php foreach ($templates as $template): ?>
          <div class="mobile-card">
            <div class="mobile-card-title"><?= h((string)$template['name']) ?></div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Roster Size</span>
              <?= (int)$template['roster_size'] ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Innings</span>
              <?= (int)$template['innings'] ?>
            </div>

            <div class="mobile-card-row">
              <span class="mobile-card-label">Created</span>
              <?= h((string)$template['created_at']) ?>
            </div>

            <div class="stack-actions" style="margin-top:12px;">
              <a class="btn" href="lineup_templates.php?edit=<?= (int)$template['id'] ?>">Rename</a>

              <?php if ($canCreateMoreTemplates): ?>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                  <input type="hidden" name="action" value="duplicate_template">
                  <input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>">
                  <button type="submit" class="btn-sm">Duplicate</button>
                </form>
              <?php else: ?>
                <a class="btn-sm btn-secondary" href="billing.php?upgrade_reason=lineup_templates_per_team">
                  Duplicate 🔒
                </a>
              <?php endif; ?>

              <form method="post" onsubmit="return confirm('Delete this template?');">
                  <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
