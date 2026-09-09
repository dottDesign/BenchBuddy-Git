<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/session_config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';

if (!empty($_SESSION['user_id'])) {
    if (session_is_expired()) {
        mark_current_session_logged_out();
        session_unset();
        session_destroy();
        header('Location: /login.php?expired=1');
        exit;
    }

    session_touch();
}
function auth_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): array
{
    $user = get_current_user_record();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_name(): string
{
    return (string)($_SESSION['user_name'] ?? '');
}

function current_user_role(): string
{
    return (string)($_SESSION['user_role'] ?? 'user');
}

function is_admin_user(): bool
{
    $role = current_user_role();
    return in_array($role, ['owner', 'admin'], true);
}

function require_admin_user(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }

    if (is_admin_user()) {
        return;
    }

    if (is_impersonating()) {
        $impersonatorRole = (string)($_SESSION['impersonator_user_role'] ?? 'user');
        if (in_array($impersonatorRole, ['owner', 'admin'], true)) {
            return;
        }
    }

    header('Location: /forbidden.php');
    exit;
}
function is_impersonating(): bool
{
    return !empty($_SESSION['impersonator_user_id']);
}

function impersonator_user_id(): int
{
    return (int)($_SESSION['impersonator_user_id'] ?? 0);
}


function current_team_id(): int
{
    $teamId = (int)($_SESSION['current_team_id'] ?? 0);

    if ($teamId > 0) {
        return $teamId;
    }

    $userId = current_user_id();

    if ($userId <= 0) {
        return 0;
    }

    $user = get_user_by_id($userId);

    $defaultTeamId = (int)($user['default_team_id'] ?? 0);

    if (
        $defaultTeamId > 0 &&
        user_belongs_to_team($userId, $defaultTeamId)
    ) {
        $_SESSION['current_team_id'] = $defaultTeamId;
        return $defaultTeamId;
    }

    $teams = get_user_teams($userId);

    if (empty($teams)) {
        return 0;
    }

    $fallbackTeamId = (int)($teams[0]['id'] ?? 0);

    if ($fallbackTeamId > 0) {
        $_SESSION['current_team_id'] = $fallbackTeamId;
        return $fallbackTeamId;
    }

    return 0;
}
function set_current_team(int $teamId): void
{
    if ($teamId <= 0) {
        unset($_SESSION['current_team_id']);
        return;
    }

    $userId = current_user_id();

    if ($userId > 0 && user_belongs_to_team($userId, $teamId)) {
        $_SESSION['current_team_id'] = $teamId;

        $stmt = db()->prepare("
            UPDATE users
            SET default_team_id = :team_id
            WHERE id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'team_id' => $teamId,
            'user_id' => $userId,
        ]);
    }
}

function set_current_team_id(int $teamId): void
{
    set_current_team($teamId);
}
function mark_current_session_logged_out(): void
{
    $sessionId = session_id();

    if ($sessionId === '') {
        return;
    }

    $now = date('Y-m-d H:i:s');

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE user_sessions
        SET logged_out_at = :logged_out_at
        WHERE session_id = :session_id
        LIMIT 1
    ");
    $stmt->execute([
        'logged_out_at' => $now,
        'session_id' => $sessionId,
    ]);
}
function logout_user(): void
{
    mark_current_session_logged_out();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $sessionName = session_name();

        if ($sessionName !== false && $sessionName !== '') {
            setcookie(
                $sessionName,
                '',
                time() - 42000,
                (string)($params['path'] ?? '/'),
                (string)($params['domain'] ?? ''),
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }
    }

    session_destroy();
}

function get_user_by_email(string $email): ?array
{
    $email = auth_normalize_email($email);
    if ($email === '') {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function get_user_by_id(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $userId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function get_current_user_record(): ?array
{
    return get_user_by_id(current_user_id());
}
function get_user_teams(int $userId): array
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
          AND t.archived_at IS NULL
        ORDER BY
            t.name ASC,
            t.id ASC
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function user_belongs_to_team(int $userId, int $teamId): bool
{
    if ($userId <= 0 || $teamId <= 0) {
        return false;
    }

    $stmt = db()->prepare("
        SELECT 1
        FROM team_memberships
        WHERE user_id = :user_id
          AND team_id = :team_id
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $userId,
        'team_id' => $teamId,
    ]);

    return $stmt->fetchColumn() !== false;
}
function complete_user_login(array $user): void
{
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = (string)$user['full_name'];
    $_SESSION['user_role'] = (string)($user['role'] ?? 'user');

    unset($_SESSION['pending_2fa_user_id']);

    $teams = get_user_teams((int)$user['id']);

    if (!empty($teams)) {

        $defaultTeamId = (int)($user['default_team_id'] ?? 0);

        if (
            $defaultTeamId > 0 &&
            user_belongs_to_team((int)$user['id'], $defaultTeamId)
        ) {
            $_SESSION['current_team_id'] = $defaultTeamId;
        } else {
            $_SESSION['current_team_id'] = (int)$teams[0]['id'];
        }

    } else {
        $_SESSION['current_team_id'] = 0;
    }

    touch_user_last_active();
    touch_user_session();
}
function login_user(string $email, string $password): bool
{
    $user = get_user_by_email($email);

    if (!$user) {
        return false;
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        return false;
    }

    $passwordHash = (string)($user['password_hash'] ?? '');

    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        return false;
    }

    if ((int)($user['two_factor_enabled'] ?? 0) === 1) {
        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
        $_SESSION['pending_2fa_started_at'] = time();

        return true;
    }

    complete_user_login($user);

    return true;
}
function start_user_impersonation(int $targetUserId): void
{
    if (!is_admin_user()) {
        throw new RuntimeException('Admin access required.');
    }

    if ($targetUserId <= 0) {
        throw new RuntimeException('Invalid target user.');
    }

    $targetUser = get_user_by_id($targetUserId);
    if (!$targetUser) {
        throw new RuntimeException('Target user not found.');
    }

    if ((int)($targetUser['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Cannot impersonate an inactive user.');
    }

    if (!is_impersonating()) {
        $_SESSION['impersonator_user_id'] = current_user_id();
        $_SESSION['impersonator_user_name'] = current_user_name();
        $_SESSION['impersonator_user_role'] = current_user_role();
    }

    $_SESSION['user_id'] = (int)$targetUser['id'];
    $_SESSION['user_name'] = (string)$targetUser['full_name'];
    $_SESSION['user_role'] = (string)($targetUser['role'] ?? 'user');

    $teams = get_user_teams((int)$targetUser['id']);
    $_SESSION['current_team_id'] = !empty($teams) ? (int)$teams[0]['id'] : 0;

    log_admin_action(
        impersonator_user_id(),
        (int)$targetUser['id'],
        'start_impersonation',
        'Started impersonation'
    );
}

function stop_user_impersonation(): void
{
    if (!is_impersonating()) {
        return;
    }

    $adminUserId = (int)($_SESSION['impersonator_user_id'] ?? 0);
    $adminUser = get_user_by_id($adminUserId);
    if (!$adminUser) {
        throw new RuntimeException('Original admin account not found.');
    }

    $targetUserId = current_user_id();

    $_SESSION['user_id'] = (int)$adminUser['id'];
    $_SESSION['user_name'] = (string)$adminUser['full_name'];
    $_SESSION['user_role'] = (string)($adminUser['role'] ?? 'user');

    unset(
        $_SESSION['impersonator_user_id'],
        $_SESSION['impersonator_user_name'],
        $_SESSION['impersonator_user_role']
    );

    $teams = get_user_teams((int)$adminUser['id']);
    $_SESSION['current_team_id'] = !empty($teams) ? (int)$teams[0]['id'] : 0;

    log_admin_action(
        (int)$adminUser['id'],
        $targetUserId,
        'stop_impersonation',
        'Stopped impersonation'
    );
}

function register_user_only(string $fullName, string $email, string $password): int
{
    $fullName = trim($fullName);
    $email = auth_normalize_email($email);

    if ($fullName === '') {
        throw new InvalidArgumentException('Full name is required.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email is required.');
    }

    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }

    $pdo = db();

    $check = $pdo->prepare("
        SELECT id
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $check->execute([
        'email' => $email,
    ]);

    if ($check->fetch()) {
        throw new RuntimeException('An account with that email already exists.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, password_hash, role, is_active)
        VALUES (:full_name, :email, :password_hash, 'user', 1)
    ");
    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
    ]);

    return (int)$pdo->lastInsertId();
}

function register_user_with_team(string $fullName, string $email, string $password, string $teamName): int
{
    $fullName = trim($fullName);
    $email = auth_normalize_email($email);
    $teamName = trim($teamName);

    if ($teamName === '') {
        throw new InvalidArgumentException('Team name is required.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userId = register_user_only($fullName, $email, $password);

        if (!function_exists('create_team') || !function_exists('add_user_to_team_with_role')) {
            throw new RuntimeException('Team helper functions are not loaded.');
        }

        $teamId = create_team($teamName, null);
        add_user_to_team_with_role($userId, $teamId, 'head_coach');

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_current_user_profile(string $fullName, string $email): void
{
    $userId = current_user_id();
    if ($userId <= 0) {
        throw new RuntimeException('No logged in user.');
    }

    $fullName = trim($fullName);
    $email = auth_normalize_email($email);

    if ($fullName === '') {
        throw new InvalidArgumentException('Full name is required.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email is required.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE email = :email
          AND id <> :id
        LIMIT 1
    ");
    $stmt->execute([
        'email' => $email,
        'id' => $userId,
    ]);

    if ($stmt->fetch()) {
        throw new RuntimeException('That email address is already in use.');
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET full_name = :full_name,
            email = :email
        WHERE id = :id
    ");
    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'id' => $userId,
    ]);

    $_SESSION['user_name'] = $fullName;
}

function update_current_user_password(string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $user = get_current_user_record();
    if (!$user) {
        throw new RuntimeException('User account not found.');
    }

    $passwordHash = (string)($user['password_hash'] ?? '');
    if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
        throw new RuntimeException('Current password is incorrect.');
    }

    if (strlen($newPassword) < 8) {
        throw new RuntimeException('New password must be at least 8 characters.');
    }

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE users
        SET password_hash = :password_hash
        WHERE id = :id
    ");
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => (int)$user['id'],
    ]);
}
function touch_user_last_active(): void
{
    if (!is_logged_in()) {
        return;
    }

    $userId = current_user_id();

    if ($userId <= 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE users
        SET last_active_at = :last_active_at
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        'last_active_at' => $now,
        'id' => $userId,
    ]);
}

function is_user_online(?string $lastActiveAt, int $minutes = 5): bool
{
    if (empty($lastActiveAt)) {
        return false;
    }

    $last = strtotime($lastActiveAt);
    if ($last === false) {
        return false;
    }

    return (time() - $last) <= ($minutes * 60);
}function touch_user_session(): void
{
    if (!is_logged_in()) {
        return;
    }

    $userId = current_user_id();

    if ($userId <= 0) {
        return;
    }

    $sessionId = session_id();

    if ($sessionId === '') {
        return;
    }

    try {
        $pdo = db();

        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (
                user_id,
                session_id,
                ip_address,
                user_agent,
                started_at,
                last_seen_at
            )
            VALUES (
                :user_id,
                :session_id,
                :ip_address,
                :user_agent,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                last_seen_at = NOW(),
                logged_out_at = NULL
        ");

        $stmt->execute([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
        ]);
    } catch (Throwable $e) {
        error_log('Session tracking failed: ' . $e->getMessage());
    }
}
touch_user_last_active();
touch_user_session();
