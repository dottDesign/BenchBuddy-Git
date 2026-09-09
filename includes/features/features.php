<?php
declare(strict_types=1);


function get_highlighted_feature_updates(int $limit = 5): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, title, summary, details, feature_type, status, is_highlighted, created_at
        FROM feature_updates
        WHERE is_highlighted = 1
        ORDER BY created_at DESC, id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function create_feature_update(
    string $title,
    string $summary,
    string $details,
    string $featureType,
    string $status,
    bool $isHighlighted,
    string $createdAt,
    string $releaseVersion,
): void {
    $pdo = db();

    if ($createdAt === "") {
        $createdAt = date("Y-m-d H:i:s");
    } else {
        $createdAt = date("Y-m-d H:i:s", strtotime($createdAt));
    }

    $stmt = $pdo->prepare("
        INSERT INTO feature_updates (
            title,
            summary,
            details,
            feature_type,
            status,
            is_highlighted,
            created_at,
            release_version
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $title,
        $summary,
        $details,
        $featureType,
        $status,
        $isHighlighted ? 1 : 0,
        $createdAt,
        $releaseVersion !== "" ? $releaseVersion : null,
    ]);
}

function update_feature_update(
    int $featureId,
    string $title,
    string $summary,
    string $details,
    string $featureType,
    string $status,
    bool $isHighlighted,
    string $createdAt,
    string $releaseVersion,
): void {
    $pdo = db();

    if ($featureId <= 0) {
        throw new RuntimeException("Invalid feature ID.");
    }

    if ($createdAt === "") {
        $createdAt = date("Y-m-d H:i:s");
    } else {
        $createdAt = date("Y-m-d H:i:s", strtotime($createdAt));
    }

    $stmt = $pdo->prepare("
        UPDATE feature_updates
        SET
            title = ?,
            summary = ?,
            details = ?,
            feature_type = ?,
            status = ?,
            is_highlighted = ?,
            created_at = ?,
            release_version = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $title,
        $summary,
        $details,
        $featureType,
        $status,
        $isHighlighted ? 1 : 0,
        $createdAt,
        $releaseVersion !== "" ? $releaseVersion : null,
        $featureId,
    ]);
}

function get_feature_updates(int $limit = 50): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT *
        FROM feature_updates
        ORDER BY is_highlighted DESC, created_at DESC, id DESC
        LIMIT ?
    ");

    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_feature_update_by_id(int $featureId): ?array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM feature_updates
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$featureId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ?: null;
}

function delete_feature_update(int $featureId): void
{
    $pdo = db();

    if ($featureId <= 0) {
        throw new RuntimeException("Invalid feature ID.");
    }

    $stmt = $pdo->prepare("
        DELETE FROM feature_updates
        WHERE id = ?
    ");

    $stmt->execute([$featureId]);
}

function toggle_feature_highlight(int $featureId, bool $isHighlighted): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE feature_updates
        SET is_highlighted = ?
        WHERE id = ?
    ");

    $stmt->execute([$isHighlighted ? 1 : 0, $featureId]);
}

function get_latest_feature_updates(int $limit = 3): array
{
    return get_feature_updates($limit);
}


function get_recent_feature_updates(int $limit = 3): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM feature_updates
        ORDER BY is_highlighted DESC, created_at DESC, id DESC
        LIMIT ?
    ");

    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function mark_features_page_viewed(int $userId): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO user_feature_views (user_id, last_viewed_at)
        VALUES (:user_id, NOW())
        ON DUPLICATE KEY UPDATE
            last_viewed_at = NOW(),
            updated_at = NOW()
    ");

    $stmt->execute([
        "user_id" => $userId,
    ]);
}
function get_user_last_feature_view(int $userId): ?string
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT last_viewed_at
        FROM user_feature_views
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        "user_id" => $userId,
    ]);

    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}
function get_unseen_feature_updates_count(int $userId): int
{
    $lastViewedAt = get_user_last_feature_view($userId);

    $pdo = db();

    if ($lastViewedAt === null) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM feature_updates");
        return (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM feature_updates
        WHERE created_at > :last_viewed_at
    ");
    $stmt->execute([
        "last_viewed_at" => $lastViewedAt,
    ]);

    return (int) $stmt->fetchColumn();
}
function get_latest_unseen_feature_for_user(int $userId): ?array
{
    $lastViewedAt = get_user_last_feature_view($userId);
    $pdo = db();

    if ($lastViewedAt === null) {
        $stmt = $pdo->query("
            SELECT *
            FROM feature_updates
            WHERE status IN ('live', 'beta')
            ORDER BY is_highlighted DESC, created_at DESC, id DESC
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM feature_updates
            WHERE created_at > :last_viewed_at
              AND status IN ('live', 'beta')
            ORDER BY is_highlighted DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([
            "last_viewed_at" => $lastViewedAt,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function feature_type_label(string $type): string
{
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

function feature_type_class(string $type): string
{
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
