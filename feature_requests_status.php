<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers/general.php';

$userId = current_user_id();
$pdo = db();

$stmt = $pdo->prepare("
    SELECT *
    FROM feature_requests
    WHERE user_id = :user_id
    ORDER BY created_at DESC, id DESC
");
$stmt->execute([
    'user_id' => $userId,
]);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Feature Requests';
require_once __DIR__ . '/includes/header.php';
?>

<main class="page-shell">
    <section class="page-header">
        <div>
            <p class="eyebrow">Requests</p>
            <h1>My Feature Requests</h1>
            <p class="muted">
                Track the status of ideas and improvements you have submitted.
            </p>
        </div>

        <a href="feature_request.php" class="button">
            Submit New Request
        </a>
    </section>

    <section class="card">
        <?php if (empty($requests)): ?>
            <p class="muted">You have not submitted any feature requests yet.</p>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $status = (string)($request['status'] ?? 'new');

                    $statusLabel = match ($status) {
                        'new' => 'Submitted',
                        'reviewing' => 'Under Review',
                        'planned' => 'Planned',
                        'declined' => 'Not Planned',
                        'converted' => 'Added to Updates',
                        default => ucwords(str_replace('_', ' ', $status)),
                    };

                    $progressText = match ($status) {
                        'new' => 'Your request has been received.',
                        'reviewing' => 'This request is being reviewed.',
                        'planned' => 'This request is planned for a future update.',
                        'declined' => 'This request is not planned right now.',
                        'converted' => 'This request has been converted into a feature update.',
                        default => 'Status updated.',
                    };
                    ?>

                    <article class="request-card">
                        <div class="request-top">
                            <div>
                                <span class="status-pill status-<?= e($status) ?>">
                                    <?= e($statusLabel) ?>
                                </span>

                                <h2><?= e((string)$request['title']) ?></h2>

                                <p class="muted small">
                                    Submitted <?= e(date('M j, Y g:i A', strtotime((string)$request['created_at']))) ?>
                                </p>
                            </div>
                        </div>

                        <div class="request-body">
                            <?= nl2br(e((string)$request['description'])) ?>
                        </div>

                        <div class="request-progress">
                            <strong><?= e($progressText) ?></strong>

                            <?php if (!empty($request['admin_notes'])): ?>
                                <p class="muted">
                                    <?= nl2br(e((string)$request['admin_notes'])) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
