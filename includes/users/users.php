<?php
declare(strict_types=1);


function register_user(string $fullName, string $email, string $password): int
{
    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    if ($fullName === "") {
        throw new InvalidArgumentException("Full name is required.");
    }

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("A valid email is required.");
    }

    if (strlen($password) < 8) {
        throw new InvalidArgumentException(
            "Password must be at least 8 characters.",
        );
    }

    $pdo = db();

    $existing = find_user_id_by_email($email);
    if ($existing > 0) {
        throw new RuntimeException(
            "An account with this email already exists.",
        );
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users
        (
            full_name,
            email,
            password_hash,
            is_active,
            created_at
            )
        VALUES
        (
            :full_name,
            :email,
            :password_hash,
            1,
            NOW()
            )
        ");

    $stmt->execute([
        "full_name" => $fullName,
        "email" => $email,
        "password_hash" => $passwordHash,
    ]);

    return (int) $pdo->lastInsertId();
}


function login_user_by_id(int $userId): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException("Invalid user ID.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, is_active
        FROM users
        WHERE id = :id
        LIMIT 1
        ");
    $stmt->execute([
        "id" => $userId,
    ]);

    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException("User account not found.");
    }

    if ((int) $user["is_active"] !== 1) {
        throw new RuntimeException("User account is inactive.");
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION["user_id"] = (int) $user["id"];
}


function update_user_password_by_id(int $userId, string $newPassword): void
{
    if (strlen($newPassword) < 8) {
        throw new RuntimeException("Password must be at least 8 characters.");
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException("Password hashing failed.");
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE users
            SET password_hash = :password_hash
            WHERE id = :id
            LIMIT 1
            ");
    $stmt->execute([
        "password_hash" => $hash,
        "id" => $userId,
    ]);
}

function ensure_user_referral_code(int $userId): string
{
    $stmt = db()->prepare("
        SELECT referral_code, full_name
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    $existing = trim((string)($user['referral_code'] ?? ''));

    if ($existing !== '') {
        return $existing;
    }

    $name = strtolower((string)($user['full_name'] ?? 'coach'));

    $name = preg_replace('/[^a-z0-9]/', '', $name);

    if ($name === '') {
        $name = 'coach';
    }

    $prefixes = [
        'benchbuddy',
        'boss',
        'bench',
        'dugout',
        'clipboard'
    ];

    $prefix = $prefixes[array_rand($prefixes)];

    $code =
        strtoupper($prefix) .
        '-' .
        strtoupper(substr($name, 0, 10));

    $checkStmt = db()->prepare("
        SELECT COUNT(*)
        FROM users
        WHERE referral_code = :code
    ");

    $checkStmt->execute([
        'code' => $code
    ]);

    $exists = (int)$checkStmt->fetchColumn();

    if ($exists > 0) {
        $code .= rand(10, 99);
    }

    $updateStmt = db()->prepare("
        UPDATE users
        SET referral_code = :code
        WHERE id = :id
        LIMIT 1
    ");

    $updateStmt->execute([
        'code' => $code,
        'id' => $userId
    ]);

    return $code;
}


function find_user_id_by_referral_code(string $code): int
{
    $stmt = db()->prepare("
        SELECT id
        FROM users
        WHERE referral_code = :referral_code
        LIMIT 1
    ");

    $stmt->execute([
        'referral_code' => trim($code),
    ]);

    return (int)$stmt->fetchColumn();
}


function create_referral_rewards_for_paid_user(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $stmt = db()->prepare("
        SELECT referred_by_user_id
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);

    $referrerId = (int)$stmt->fetchColumn();

    if ($referrerId <= 0) {
        return;
    }

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM referral_rewards
        WHERE referred_user_id = :referred_user_id
    ");

    $stmt->execute([
        'referred_user_id' => $userId,
    ]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO referral_rewards (
            referrer_user_id,
            referred_user_id,
            reward_type,
            reward_value,
            notes
        ) VALUES (
            :referrer_user_id,
            :referred_user_id,
            'subscription_upgrade',
            '1_free_month',
            'Referral converted into paid subscription'
        )
    ");

    $stmt->execute([
        'referrer_user_id' => $referrerId,
        'referred_user_id' => $userId,
    ]);
}
