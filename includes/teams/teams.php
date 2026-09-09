<?php
declare(strict_types=1);

function normalize_team_role(string $role): string
{
    $role = strtolower(trim($role));

    $map = [
        "owner" => "head_coach",
        "coach" => "head_coach",
        "head_coach" => "head_coach",
        "assistant" => "assistant_coach",
        "assistant_coach" => "assistant_coach",
    ];

    if (!isset($map[$role])) {
        throw new InvalidArgumentException("Invalid team role.");
    }

    return $map[$role];
}

function display_team_role(string $role): string
{
    return match (normalize_team_role($role)) {
        "head_coach" => "Head Coach",
        "assistant_coach" => "Assistant Coach",
        default => $role,
    };
}


function get_team_by_id(int $teamId): ?array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM teams
        WHERE id = :id
        LIMIT 1
        ");
    $stmt->execute(["id" => $teamId]);

    $team = $stmt->fetch();
    return $team ?: null;
}

function get_team_name(int $teamId): string
{
    $team = get_team_by_id($teamId);
    return $team["name"] ?? "";
}

function update_team_name(int $teamId, string $name): void
{
    validate_team_id($teamId);

    $name = trim($name);
    if ($name === "") {
        throw new InvalidArgumentException("Team name is required.");
    }

    if (strlen($name) > 150) {
        throw new InvalidArgumentException(
            "Team name must be 150 characters or fewer.",
        );
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE teams
            SET name = :name
            WHERE id = :id
            ");
    $stmt->execute([
        "id" => $teamId,
        "name" => $name,
    ]);
}
function create_team(
    string $name,
    ?string $seasonLabel = null,
    ?string $division = null,
): int {
    $name = trim($name);
    $seasonLabel = $seasonLabel !== null ? trim($seasonLabel) : null;
    $division = normalize_division($division);

    if ($name === "") {
        throw new InvalidArgumentException("Team name is required.");
    }

    if (strlen($name) > 150) {
        throw new InvalidArgumentException(
            "Team name must be 150 characters or fewer.",
        );
    }

    if (
        $seasonLabel !== null &&
        $seasonLabel !== "" &&
        strlen($seasonLabel) > 50
    ) {
        throw new InvalidArgumentException(
            "Season label must be 50 characters or fewer.",
        );
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO teams (
            name,
            season_label,
            division,
            plan_key,
            subscription_status,
            billing_override,
            is_trial,
            trial_ends_at
        )
        VALUES (
            :name,
            :season_label,
            :division,
            'coachplus',
            'trialing',
            0,
            1,
            DATE_ADD(NOW(), INTERVAL 30 DAY)
        )
    ");

    $stmt->execute([
        "name" => $name,
        "season_label" => $seasonLabel === "" ? null : $seasonLabel,
        "division" => $division,
    ]);

    return (int) $pdo->lastInsertId();
}


function normalize_division(?string $division): ?string
{
    if ($division === null) {
        return null;
    }

    $division = strtoupper(trim($division));

    if ($division === "") {
        return null;
    }

    if (!in_array($division, get_available_divisions(), true)) {
        throw new InvalidArgumentException("Invalid team division.");
    }

    return $division;
}

function get_team_division(int $teamId): ?string
{
    $team = get_team_by_id($teamId);
    if (!$team) {
        return null;
    }

    $division = trim((string) ($team["division"] ?? ""));
    return $division !== "" ? strtoupper($division) : null;
}

function update_team_division(int $teamId, ?string $division): void
{
    validate_team_id($teamId);

    $division = $division !== null ? strtoupper(trim($division)) : null;
    $allowedDivisions = ["5U", "7U", "9U", "11U", "13U", "15U", "18U", "26U"];

    if (
        $division !== null &&
        $division !== "" &&
        !in_array($division, $allowedDivisions, true)
    ) {
        throw new InvalidArgumentException("Invalid division selected.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE teams
            SET division = :division
            WHERE id = :id
            ");
    $stmt->execute([
        "id" => $teamId,
        "division" => $division === "" ? null : $division,
    ]);
}


function normalize_team_division(?string $division): string
{
    $division = strtoupper(trim((string) $division));

    if ($division === "") {
        throw new InvalidArgumentException("Team division is required.");
    }

    if (!in_array($division, get_available_divisions(), true)) {
        throw new InvalidArgumentException("Invalid team division.");
    }

    return $division;
}

function update_team_details(
    int $teamId,
    string $name,
    ?string $seasonLabel = null,
    ?string $division = null,
): void {
    validate_team_id($teamId);

    $name = trim($name);
    $seasonLabel = $seasonLabel !== null ? trim($seasonLabel) : null;
    $division = normalize_team_division($division);

    if ($name === "") {
        throw new InvalidArgumentException("Team name is required.");
    }

    if (strlen($name) > 150) {
        throw new InvalidArgumentException(
            "Team name must be 150 characters or fewer.",
        );
    }

    if (
        $seasonLabel !== null &&
        $seasonLabel !== "" &&
        strlen($seasonLabel) > 50
    ) {
        throw new InvalidArgumentException(
            "Season label must be 50 characters or fewer.",
        );
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE teams
            SET name = :name,
            season_label = :season_label,
            division = :division
            WHERE id = :id
            ");
    $stmt->execute([
        "id" => $teamId,
        "name" => $name,
        "season_label" => $seasonLabel === "" ? null : $seasonLabel,
        "division" => $division,
    ]);
}

function update_current_team_details(
    int $teamId,
    string $name,
    ?string $seasonLabel = null,
    ?string $division = null,
): void {
    update_team_details($teamId, $name, $seasonLabel, $division);
}


function archive_team(int $teamId): void
{
    validate_team_id($teamId);

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE teams
            SET is_archived = 1,
            archived_at = NOW()
            WHERE id = :id
            LIMIT 1
            ");
    $stmt->execute([
        "id" => $teamId,
    ]);
}

function restore_team(int $teamId): void
{
    validate_team_id($teamId);

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE teams
            SET is_archived = 0,
            archived_at = NULL
            WHERE id = :id
            LIMIT 1
            ");
    $stmt->execute([
        "id" => $teamId,
    ]);
}
function get_archived_user_teams(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT
            t.id,
            t.name,
            t.season_label,
            t.division,
            t.archived_at,
            t.created_at,
            tm.role
        FROM team_memberships tm
        INNER JOIN teams t
            ON t.id = tm.team_id
        WHERE tm.user_id = :user_id
          AND t.archived_at IS NOT NULL
        ORDER BY
            t.archived_at DESC,
            t.name ASC,
            t.id DESC
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
