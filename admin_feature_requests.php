<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers/general.php';
require_once __DIR__ . '/includes/features/feature_requests.php';
require_once __DIR__ . '/includes/functions.php';

require_admin_user();

$pageTitle = 'Admin Feature Request';
$currentPage = 'admin_feature_request';


$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $action = (string)($_POST['action'] ?? '');
        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($requestId <= 0) {
            throw new RuntimeException('Invalid feature request.');
        }

        if ($action === 'update_status') {
            $status = strtolower(trim((string)($_POST['status'] ?? 'new')));

            update_feature_request_status(
                $requestId,
                $status,
                trim((string)($_POST['admin_notes'] ?? ''))
            );

            flash_redirect('ok', 'Feature request updated.', 'admin_feature_requests.php');
        }

        if ($action === 'convert') {
            convert_feature_request_to_update(
                $requestId,
                (string)($_POST['feature_type'] ?? 'feature'),
                trim((string)($_POST['release_version'] ?? ''))
            );

            flash_redirect('ok', 'Feature request converted to a feature update.', 'admin_feature_requests.php');
        }

        if ($action === 'delete') {
            delete_feature_request($requestId);

            flash_redirect('ok', 'Feature request deleted.', 'admin_feature_requests.php');
        }

        throw new RuntimeException('Invalid request action.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT
        fr.*,
        u.full_name AS submitted_by_name,
        u.email AS submitted_by_email
    FROM feature_requests fr
    LEFT JOIN users u
        ON u.id = fr.user_id
    ORDER BY fr.created_at DESC, fr.id DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Feature Requests';
require_once __DIR__ . '/includes/header.php';
?>
<div class="page-shell">
    <section class="page-header">
        <div>
            <p class="eyebrow">Admin</p>
            <h1>Feature Requests</h1>
            <p class="muted">
                Review user-submitted ideas, update their status, and convert strong requests into public feature updates.
            </p>
        </div>
    </section>



    <?php if ($error !== ''): ?>
        <div class="msg err"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <?php if (empty($requests)): ?>
            <p class="muted">No feature requests yet.</p>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $submittedBy = trim((string)($request['submitted_by_name'] ?? ''));

                    if ($submittedBy === '') {
                        $submittedBy = trim((string)($request['submitted_by_email'] ?? ''));
                    }

                    if ($submittedBy === '') {
                        $submittedBy = 'Guest';
                    }

                    $submittedEmail = trim((string)($request['submitted_by_email'] ?? ''));
                    $status = (string)($request['status'] ?? 'new');

                    $statusLabels = [
                        'new' => 'New',
                        'reviewing' => 'Reviewing',
                        'planned' => 'Planned',
                        'declined' => 'Declined',
                        'converted' => 'Converted',

                    ];

                    $statusLabel = $statusLabels[$status] ?? ucfirst($status);?>

                    <article class="request-card">
                        <div class="request-top">
                            <div>
                                <span class="status-pill status-<?= e($status) ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                                </span>

                                <h2><?= e((string)$request['title']) ?></h2>

                                <p class="muted small">
                                    Submitted
                                    <?= e(date('M j, Y g:i A', strtotime((string)$request['created_at']))) ?>
                                    by
                                    <strong><?= e($submittedBy) ?></strong>

                                    <?php if ($submittedEmail !== ''): ?>
                                        &lt;<?= e($submittedEmail) ?>&gt;
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="request-body">
                            <?= nl2br(e((string)$request['description'])) ?>
                        </div>

                        <?php if (!empty($request['converted_feature_update_id'])): ?>
                            <p class="muted small">
                                Converted to feature update #<?= (int)$request['converted_feature_update_id'] ?>
                            </p>
                        <?php endif; ?>

                        <form method="post" class="admin-request-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <input type="hidden" name="action" value="update_status">

                            <label>
                                Status
                                <select name="status">
                                    <?php foreach ($statusLabels as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Admin notes
                                <textarea name="admin_notes" rows="3"><?= e((string)($request['admin_notes'] ?? '')) ?></textarea>
                            </label>

                            <button type="submit" class="button button-secondary">
                                Save Status
                            </button>
                        </form>

                        <?php if ($status !== 'converted'): ?>
                            <form method="post" class="admin-request-form convert-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                <input type="hidden" name="action" value="convert">

                                <label>
                                    Feature type
                                    <select name="feature_type">
                                        <option value="feature">Feature</option>
                                        <option value="improvement">Improvement</option>
                                        <option value="fix">Fix</option>
                                        <option value="beta">Beta</option>
                                    </select>
                                </label>

                                <label>
                                    Release version
                                    <input type="text" name="release_version" placeholder="Optional, e.g. v1.4">
                                </label>

                                <button type="submit" class="button">
                                    Convert to Feature Update
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" onsubmit="return confirm('Delete this request?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <input type="hidden" name="action" value="delete">

                            <button type="submit" class="button button-danger">
                                Delete
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
