<?php
declare(strict_types=1);


function create_password_reset(string $email): ?array
{
    $pdo = db();

    $requestIp = $_SERVER["REMOTE_ADDR"] ?? "";

    $stmt = $pdo->prepare("
        SELECT id, email, full_name
        FROM users
        WHERE email = :email
        AND is_active = 1
        LIMIT 1
        ");
    $stmt->execute([
        "email" => strtolower(trim($email)),
    ]);

    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $userId = (int) $user["id"];

    $cleanup = $pdo->prepare("
        DELETE FROM password_resets
            WHERE expires_at < NOW()
            OR used_at IS NOT NULL
            ");
    $cleanup->execute();

    $check = $pdo->prepare("
        SELECT created_at
        FROM password_resets
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 1
        ");
    $check->execute([
        "user_id" => $userId,
    ]);

    $last = $check->fetch();

    if ($last) {
        $cooldown = 300; // 5 minutes
        $createdAt = strtotime((string) $last["created_at"]);
        $remaining = ($createdAt + $cooldown) - time();

        if ($remaining > 0) {
            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;

            if ($minutes > 0) {
                $message = "Please wait {$minutes} minute(s) and {$seconds} second(s) before trying again.";
            } else {
                $message = "Please wait {$seconds} second(s) before trying again.";
            }

            throw new RuntimeException($message);
        }
    }

    $ipCheck = $pdo->prepare("
        SELECT COUNT(*)
        FROM password_resets
        WHERE request_ip = :request_ip
        AND created_at > (NOW() - INTERVAL 10 MINUTE)
        ");
    $ipCheck->execute([
        "request_ip" => $requestIp,
    ]);

    if ((int) $ipCheck->fetchColumn() > 5) {
        throw new RuntimeException(
            "Too many reset attempts. Please try again later.",
        );
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date("Y-m-d H:i:s", time() + 3600);

    $insert = $pdo->prepare("
        INSERT INTO password_resets (
            user_id,
            email,
            reset_token,
            expires_at,
            request_ip
            ) VALUES (
            :user_id,
            :email,
            :reset_token,
            :expires_at,
            :request_ip
            )
            ");

    $insert->execute([
        "user_id" => $userId,
        "email" => (string) $user["email"],
        "reset_token" => $token,
        "expires_at" => $expiresAt,
        "request_ip" => $requestIp,
    ]);

    return [
        "user_id" => $userId,
        "email" => (string) $user["email"],
        "full_name" => (string) ($user["full_name"] ?? ""),
        "reset_token" => $token,
        "expires_at" => $expiresAt,
    ];
}

function get_valid_password_reset(string $token): ?array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT pr.*, u.id AS user_id, u.email
        FROM password_resets pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.reset_token = :reset_token
        AND pr.used_at IS NULL
        AND pr.expires_at >= NOW()
        LIMIT 1
        ");
    $stmt->execute([
        "reset_token" => trim($token),
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function mark_password_reset_used(int $resetId): void
{
    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE password_resets
            SET used_at = NOW()
            WHERE id = :id
            ");
    $stmt->execute([
        "id" => $resetId,
    ]);
}


function send_password_reset_email(
    string $email,
    string $fullName,
    string $token,
): void {
    require_once __DIR__ . "/../mail.php";

    $scheme =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
            ? "https"
            : "http";
    $host = $_SERVER["HTTP_HOST"];

    $resetLink =
        $scheme .
        "://" .
        $host .
        "/reset_password.php?token=" .
        urlencode($token);

    $name = trim($fullName) !== "" ? $fullName : "there";

    $subject = "Password Reset Request";

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset='UTF-8'>
    <title>Password Reset</title>
    </head>
    <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
    <tr>
    <td align='center'>
    <table width='100%' max-width='600' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:8px; padding:30px;'>
    <tr>
    <td style='text-align:center; padding-bottom:20px;'>
    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;' />
    </td>
    </tr>
    <tr>
    <td style='color:#444; font-size:16px; line-height:1.6;'>
    <p style='margin-top:0;'>Hi {$name},</p>
    <p>You requested a password reset. Click the button below to set a new password.</p>
    <p style='text-align:center; margin:30px 0;'>
        <a href='{$resetLink}'
           style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
            Reset Password
        </a>
    </p>
    <p style='font-size:14px; color:#666;'>
    This link will expire in 1 hour.
    </p>
    <p style='font-size:14px; color:#666;'>
    If you didn’t request this, you can safely ignore this email.
    </p>
    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
    <p style='font-size:12px; color:#999; text-align:center;'>
    If the button doesn’t work, copy and paste this link into your browser:<br>
    <span style='word-break:break-all;'>{$resetLink}</span>
    </p>
    </td>
    </tr>
    </table>
    </td>
    </tr>
    </table>
    </body>
    </html>
    ";

    send_app_mail($email, $name, $subject, $html);
}
