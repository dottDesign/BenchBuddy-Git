<?php
declare(strict_types=1);

function save_lineup_template(
    int $teamId,
    string $name,
    array $lineupResult,
    int $rosterSize,
): void {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO lineup_templates (team_id, name, roster_size, innings, lineup_json)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $teamId,
        $name,
        $rosterSize,
        (int) $lineupResult["innings"],
        json_encode($lineupResult),
    ]);
}

function get_lineup_templates(int $teamId): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            team_id,
            name,
            roster_size,
            innings,
            lineup_json,
            created_at
        FROM lineup_templates
        WHERE team_id = :team_id
        ORDER BY created_at DESC, id DESC
    ");

    $stmt->execute([
        "team_id" => $teamId,
    ]);

    return $stmt->fetchAll();
}

function get_lineup_template_by_id(int $teamId, int $templateId): ?array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            team_id,
            name,
            roster_size,
            innings,
            lineup_json,
            created_at
        FROM lineup_templates
        WHERE team_id = :team_id
          AND id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "id" => $templateId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function rename_lineup_template(
    int $teamId,
    int $templateId,
    string $name,
): void {
    $name = trim($name);

    if ($templateId <= 0) {
        throw new RuntimeException("Invalid template selected.");
    }

    if ($name === "") {
        throw new RuntimeException("Template name is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE lineup_templates
        SET name = :name
        WHERE team_id = :team_id
          AND id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "name" => $name,
        "team_id" => $teamId,
        "id" => $templateId,
    ]);
}

function delete_lineup_template(int $teamId, int $templateId): void
{
    if ($templateId <= 0) {
        throw new RuntimeException("Invalid template selected.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        DELETE FROM lineup_templates
        WHERE team_id = :team_id
          AND id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "id" => $templateId,
    ]);
}

function duplicate_lineup_template(int $teamId, int $templateId): int
{
    $template = get_lineup_template_by_id($teamId, $templateId);
    if (!$template) {
        throw new RuntimeException("Template not found.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO lineup_templates (
            team_id,
            name,
            roster_size,
            innings,
            lineup_json,
            created_at
        ) VALUES (
            :team_id,
            :name,
            :roster_size,
            :innings,
            :lineup_json,
            NOW()
        )
    ");

    $stmt->execute([
        "team_id" => $teamId,
        "name" => (string) $template["name"] . " Copy",
        "roster_size" => (int) $template["roster_size"],
        "innings" => (int) $template["innings"],
        "lineup_json" => (string) $template["lineup_json"],
    ]);

    return (int) $pdo->lastInsertId();
}
