<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim((string)($_GET['token'] ?? ''));

if ($token !== '') {

    $stmt = db()->prepare("
        SELECT id
        FROM users
        WHERE email_preferences_token = :token
        LIMIT 1
    ");

    $stmt->execute([
        'token' => $token,
    ]);

    $userId = (int)$stmt->fetchColumn();

    if ($userId <= 0) {
        http_response_code(404);
        exit('Invalid email preferences link.');
    }

} else {

    require_login();

    $userId = current_user_id();
}
$pageTitle = 'Email Preferences';
$currentPage = 'email_preferences';

$stmt = db()->prepare("
    SELECT
        email_product_updates,
        email_coaching_tips,
        email_feature_announcements,
        email_marketing
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $userId]);
$prefs = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prefs) {
    http_response_code(404);
    exit('User not found.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $productUpdates = isset($_POST['email_product_updates']) ? 1 : 0;
    $coachingTips = isset($_POST['email_coaching_tips']) ? 1 : 0;
    $featureAnnouncements = isset($_POST['email_feature_announcements']) ? 1 : 0;
    $marketing = isset($_POST['email_marketing']) ? 1 : 0;

    $unsubscribedAt = (
        $productUpdates === 0 &&
        $coachingTips === 0 &&
        $featureAnnouncements === 0 &&
        $marketing === 0
    ) ? date('Y-m-d H:i:s') : null;

    $stmt = db()->prepare("
        UPDATE users
        SET
            email_product_updates = :product_updates,
            email_coaching_tips = :coaching_tips,
            email_feature_announcements = :feature_announcements,
            email_marketing = :marketing,
            email_unsubscribed_at = :unsubscribed_at
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'product_updates' => $productUpdates,
        'coaching_tips' => $coachingTips,
        'feature_announcements' => $featureAnnouncements,
        'marketing' => $marketing,
        'unsubscribed_at' => $unsubscribedAt,
        'id' => $userId,
    ]);

    $message = 'Email preferences updated.';

    $prefs = [
        'email_product_updates' => $productUpdates,
        'email_coaching_tips' => $coachingTips,
        'email_feature_announcements' => $featureAnnouncements,
        'email_marketing' => $marketing,
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="auth-wrap">
    <section class="card" style="max-width:720px;margin:0 auto;">
        <h2>Email Preferences</h2>

        <p class="muted">
            Choose which BenchBuddy emails you want to receive.
            Account, billing, security, password reset, and team invitation emails will still be sent when required.
        </p>

        <?php if ($message !== ''): ?>
            <div class="msg"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <div class="preference-row">
                <div>
                    <div class="preference-label">Product Updates</div>
                    <small>Updates about new improvements and enhancements to BenchBuddy.</small>
                </div>

                <label class="switch">
                    <input
                        type="checkbox"
                        name="email_product_updates"
                        value="1"
                        <?= (int)$prefs['email_product_updates'] === 1 ? 'checked' : '' ?>
                    >
                    <span class="slider"></span>
                </label>
            </div>

            <div class="preference-row">
                <div>
                    <div class="preference-label">Coaching Tips</div>
                    <small>Baseball coaching insights, lineup strategies, and best practices.</small>
                </div>

                <label class="switch">
                    <input
                        type="checkbox"
                        name="email_coaching_tips"
                        value="1"
                        <?= (int)$prefs['email_coaching_tips'] === 1 ? 'checked' : '' ?>
                    >
                    <span class="slider"></span>
                </label>
            </div>

            <div class="preference-row">
                <div>
                    <div class="preference-label">Feature Announcements</div>
                    <small>Be the first to hear about new BenchBuddy features.</small>
                </div>

                <label class="switch">
                    <input
                        type="checkbox"
                        name="email_feature_announcements"
                        value="1"
                        <?= (int)$prefs['email_feature_announcements'] === 1 ? 'checked' : '' ?>
                    >
                    <span class="slider"></span>
                </label>
            </div>

            <div class="preference-row">
                <div>
                    <div class="preference-label">Promotions & Special Offers</div>
                    <small>Occasional discounts, offers, and promotional announcements.</small>
                </div>

                <label class="switch">
                    <input
                        type="checkbox"
                        name="email_marketing"
                        value="1"
                        <?= (int)$prefs['email_marketing'] === 1 ? 'checked' : '' ?>
                    >
                    <span class="slider"></span>
                </label>
            </div>

            <div style="margin-top:24px;">
                <button type="submit" class="btn btn-primary">
                    Save Preferences
                </button>
            </div>

        </form>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
