<?php
declare(strict_types=1);


function add_user_to_team_with_role(
    int $userId,
    int $teamId,
    string $role = "assistant_coach",
): void {
    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    validate_team_id($teamId);
    $role = normalize_team_role($role);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM team_memberships
        WHERE user_id = :user_id
        AND team_id = :team_id
        LIMIT 1
        ");
    $stmt->execute([
        "user_id" => $userId,
        "team_id" => $teamId,
    ]);

    if ($stmt->fetch()) {
        throw new RuntimeException("That user is already part of this team.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO team_memberships (user_id, team_id, role)
        VALUES (:user_id, :team_id, :role)
        ");
    $stmt->execute([
        "user_id" => $userId,
        "team_id" => $teamId,
        "role" => $role,
    ]);
}

function add_user_to_team(
    int $userId,
    int $teamId,
    string $role = "assistant_coach",
): void {
    add_user_to_team_with_role($userId, $teamId, $role);
}
function create_team_for_current_user(
    string $name,
    ?string $seasonLabel = null,
    ?string $division = null,
): int {
    if (!function_exists("current_user_id")) {
        throw new RuntimeException("Authentication helpers are not loaded.");
    }

    $userId = current_user_id();
    if ($userId <= 0) {
        throw new RuntimeException("No logged in user.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $teamId = create_team($name, $seasonLabel, $division);
        add_user_to_team_with_role($userId, $teamId, "head_coach");
        $pdo->commit();
        return $teamId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_team_for_user_id(
    int $userId,
    string $name,
    ?string $seasonLabel = null,
    ?string $division = null,
): int {
    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $teamId = create_team($name, $seasonLabel, $division);
        add_user_to_team_with_role($userId, $teamId, "head_coach");
        $pdo->commit();
        return $teamId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function current_user_team_role(int $teamId): ?string
{
    validate_team_id($teamId);

    if (!function_exists("current_user_id")) {
        return null;
    }

    $userId = current_user_id();
    if ($userId <= 0) {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT role
        FROM team_memberships
        WHERE team_id = :team_id
        AND user_id = :user_id
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "user_id" => $userId,
    ]);

    $role = $stmt->fetchColumn();
    return $role ? normalize_team_role((string) $role) : null;
}

function current_user_is_head_coach(int $teamId): bool
{
    return current_user_team_role($teamId) === "head_coach";
}

function get_team_members(int $teamId): array
{
    validate_team_id($teamId);

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
        tm.user_id,
        tm.team_id,
        tm.role,
        u.full_name,
        u.email,
        u.is_active
        FROM team_memberships tm
        INNER JOIN users u
        ON u.id = tm.user_id
        WHERE tm.team_id = :team_id
        ORDER BY
        CASE tm.role
        WHEN 'head_coach' THEN 1
        WHEN 'assistant_coach' THEN 2
        ELSE 3
    END,
    u.full_name ASC,
    u.email ASC
    ");
    $stmt->execute([
        "team_id" => $teamId,
    ]);

    return $stmt->fetchAll();
}

function update_team_member_role(int $teamId, int $userId, string $role): void
{
    validate_team_id($teamId);

    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    $role = normalize_team_role($role);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT user_id, role
        FROM team_memberships
        WHERE team_id = :team_id
        AND user_id = :user_id
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "user_id" => $userId,
    ]);

    $membership = $stmt->fetch();
    if (!$membership) {
        throw new RuntimeException("Team membership not found.");
    }

    $stmt = $pdo->prepare("
        UPDATE team_memberships
            SET role = :role
            WHERE team_id = :team_id
            AND user_id = :user_id
            ");
    $stmt->execute([
        "team_id" => $teamId,
        "user_id" => $userId,
        "role" => $role,
    ]);
}

function remove_team_member(int $teamId, int $userId): void
{
    validate_team_id($teamId);

    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        DELETE FROM team_memberships
            WHERE team_id = :team_id
            AND user_id = :user_id
            ");
    $stmt->execute([
        "team_id" => $teamId,
        "user_id" => $userId,
    ]);
}

function add_user_to_team_by_email_with_role(
    int $teamId,
    string $email,
    string $role = "assistant_coach",
): void {
    validate_team_id($teamId);
    $role = normalize_team_role($role);

    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("A valid email is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE email = :email
        LIMIT 1
        ");
    $stmt->execute(["email" => $email]);

    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException("User with that email does not exist.");
    }

    add_user_to_team_with_role((int) $user["id"], $teamId, $role);
}
