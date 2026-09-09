<?php
declare(strict_types=1);


function generate_invite_token(): string
{
    return bin2hex(random_bytes(32));
}

function find_user_id_by_email(string $email): int
{
    $email = strtolower(trim($email));
    if ($email === "") {
        return 0;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE email = :email
        LIMIT 1
        ");
    $stmt->execute(["email" => $email]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

function get_pending_invitation_for_email_and_team(
    int $teamId,
    string $email,
): ?array {
    validate_team_id($teamId);

    $email = strtolower(trim($email));
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM team_invitations
        WHERE team_id = :team_id
        AND email = :email
        AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "email" => $email,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function create_team_invitation(int $teamId, string $email, string $role): array
{
    validate_team_id($teamId);
    $role = normalize_team_role($role);

    $email = strtolower(trim($email));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("A valid email is required.");
    }

    $existingUserId = find_user_id_by_email($email);
    if ($existingUserId > 0) {
        throw new RuntimeException(
            "That user already exists. Add them directly instead.",
        );
    }

    $existingInvite = get_pending_invitation_for_email_and_team(
        $teamId,
        $email,
    );
    if ($existingInvite) {
        return $existingInvite;
    }

    $token = generate_invite_token();
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO team_invitations (team_id, email, role, invite_token, status)
        VALUES (:team_id, :email, :role, :invite_token, 'pending')
        ");
    $stmt->execute([
        "team_id" => $teamId,
        "email" => $email,
        "role" => $role,
        "invite_token" => $token,
    ]);

    return [
        "id" => (int) $pdo->lastInsertId(),
        "team_id" => $teamId,
        "email" => $email,
        "role" => $role,
        "invite_token" => $token,
        "status" => "pending",
    ];
}

function get_invitation_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === "") {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT ti.*, t.name AS team_name
        FROM team_invitations ti
        INNER JOIN teams t
        ON t.id = ti.team_id
        WHERE ti.invite_token = :invite_token
        LIMIT 1
        ");
    $stmt->execute([
        "invite_token" => $token,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function accept_team_invitation(int $userId, string $token): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    $invite = get_invitation_by_token($token);
    if (!$invite) {
        throw new RuntimeException("Invitation not found.");
    }

    if ((string) $invite["status"] !== "pending") {
        throw new RuntimeException("This invitation is no longer active.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT email
        FROM users
        WHERE id = :id
        LIMIT 1
        ");
    $stmt->execute(["id" => $userId]);

    $userEmail = strtolower(trim((string) $stmt->fetchColumn()));
    if (
        $userEmail === "" ||
        $userEmail !== strtolower(trim((string) $invite["email"]))
    ) {
        throw new RuntimeException(
            "This invitation email does not match the signed in account.",
        );
    }

    $pdo->beginTransaction();

    try {
        $check = $pdo->prepare("
            SELECT id
            FROM team_memberships
            WHERE user_id = :user_id
            AND team_id = :team_id
            LIMIT 1
            ");
        $check->execute([
            "user_id" => $userId,
            "team_id" => (int) $invite["team_id"],
        ]);

        if (!$check->fetch()) {
            $insert = $pdo->prepare("
                INSERT INTO team_memberships (user_id, team_id, role)
                VALUES (:user_id, :team_id, :role)
                ");
            $insert->execute([
                "user_id" => $userId,
                "team_id" => (int) $invite["team_id"],
                "role" => normalize_team_role((string) $invite["role"]),
            ]);
        }

        $update = $pdo->prepare("
            UPDATE team_invitations
                SET status = 'accepted',
                accepted_at = NOW()
                WHERE id = :id
                ");
        $update->execute([
            "id" => (int) $invite["id"],
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function accept_pending_invitations_for_user(int $userId): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user ID is required.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT email
        FROM users
        WHERE id = :id
        LIMIT 1
        ");
    $stmt->execute(["id" => $userId]);

    $email = strtolower(trim((string) $stmt->fetchColumn()));
    if ($email === "") {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT invite_token
        FROM team_invitations
        WHERE email = :email
        AND status = 'pending'
        ORDER BY id ASC
        ");
    $stmt->execute(["email" => $email]);

    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tokens as $token) {
        accept_team_invitation($userId, (string) $token);
    }
}
