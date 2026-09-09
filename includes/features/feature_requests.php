<?php
declare(strict_types=1);

function create_feature_request(int $userId, string $title, string $description): int
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user.');
    }

    $title = trim($title);
    $description = trim($description);

    if ($title === '') {
        throw new InvalidArgumentException('Title is required.');
    }

    if ($description === '') {
        throw new InvalidArgumentException('Description is required.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("

        INSERT INTO feature_requests (user_id, title, description, status)

        VALUES (:user_id, :title, :description, 'new')

    ");

    $stmt->execute([
        'user_id' => $userId,
        'title' => $title,
        'description' => $description,
    ]);

    return (int)$pdo->lastInsertId();
}

function get_feature_requests_admin(): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM feature_requests
        ORDER BY
            CASE status
                WHEN 'new' THEN 1
                WHEN 'reviewing' THEN 2
                WHEN 'planned' THEN 3
                WHEN 'declined' THEN 4
                WHEN 'converted' THEN 5
                ELSE 6
            END,
            created_at DESC,
            id DESC
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_feature_request_by_id(int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM feature_requests
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "id" => $requestId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function update_feature_request_status(
    int $requestId,
    string $status,
    ?string $adminNotes = null
): void {
    $allowed = ['new', 'reviewing', 'planned', 'declined', 'converted'];

    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid request status: ' . $status);
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE feature_requests
        SET status = ?,
            admin_notes = ?
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $status,
        $adminNotes,
        $requestId,
    ]);
}

function delete_feature_request(int $requestId): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        DELETE FROM feature_requests
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
}
function convert_feature_request_to_update(
    int $requestId,
    string $featureType = 'feature',
    string $releaseVersion = ''
): int {
    $request = get_feature_request_by_id($requestId);

    if (!$request) {
        throw new RuntimeException('Feature request not found.');
    }

    $title = (string)$request['title'];
    $summary = mb_substr(trim((string)$request['description']), 0, 240);
    $details = trim((string)$request['description']);

    create_feature_update(
        $title,
        $summary,
        $details,
        $featureType,
        'planned',
        false,
        date('Y-m-d H:i:s'),
        $releaseVersion
    );

    $featureUpdateId = (int)db()->lastInsertId();

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE feature_requests
        SET status = 'converted',
            converted_feature_update_id = ?
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $featureUpdateId,
        $requestId,
    ]);

    return $featureUpdateId;
}
