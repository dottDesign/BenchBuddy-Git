<?php
declare(strict_types=1);


function user_allows_email_category(string $email, string $category): bool
{
    $allowedColumns = [
        'product_updates' => 'email_product_updates',
        'coaching_tips' => 'email_coaching_tips',
        'feature_announcements' => 'email_feature_announcements',
        'marketing' => 'email_marketing',
    ];

    if (!isset($allowedColumns[$category])) {
        return true;
    }

    $column = $allowedColumns[$category];

    $stmt = db()->prepare("
        SELECT {$column}
        FROM users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $value = $stmt->fetchColumn();

    if ($value === false) {
        return true;
    }

    return (int)$value === 1;
}


function get_email_preferences_url(string $email): string
{
    $stmt = db()->prepare("
        SELECT email_preferences_token
        FROM users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $token = (string)$stmt->fetchColumn();

    if ($token === '') {
        return 'https://benchbuddy.devworks.space/email_preferences.php';
    }

    return
        'https://benchbuddy.devworks.space/email_preferences.php?token=' .
        urlencode($token);
}

function send_welcome_email(string $email, string $fullName): void
{
    require_once __DIR__ . "/../mail.php";

    $scheme =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
            ? "https"
            : "http";

    $host = $_SERVER["HTTP_HOST"] ?? "benchbuddy.ca";
    $dashboardUrl = $scheme . "://" . $host . "/index.php";
    $name = trim($fullName) !== "" ? $fullName : "there";
    $subject = "Welcome to BenchBuddy";
    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Welcome to BenchBuddy</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;' />
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hi {$name},</p>
                                    <p>Welcome to BenchBuddy.</p>
                                    <p>
                                        BenchBuddy was built to help coaches spend less time managing lineups and more time coaching baseball.
                                    </p>
                                    <p style='margin-bottom:10px;'><strong>To get started:</strong></p>
                                    <table width='100%' cellpadding='0' cellspacing='0' style='margin:10px 0 24px;'>
                                        <tr>
                                            <td style='padding:8px 0; font-size:16px;'>⚾ Create your team</td>
                                        </tr>
                                        <tr>
                                            <td style='padding:8px 0; font-size:16px;'>⚾ Add your players</td>
                                        </tr>
                                        <tr>
                                            <td style='padding:8px 0; font-size:16px;'>⚾ Set your lineup templates</td>
                                        </tr>
                                        <tr>
                                            <td style='padding:8px 0; font-size:16px;'>⚾ Build your first game lineup</td>
                                        </tr>
                                        <tr>
                                            <td style='padding:8px 0; font-size:16px;'>⚾ Track pitch counts and innings automatically</td>
                                        </tr>
                                    </table>
                                    <p>
                                        Most coaches are up and running in less than 15 minutes.
                                    </p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='{$dashboardUrl}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                            Open Your BenchBuddy Dashboard
                                        </a>
                                    </p>
                                    <p>
                                        Need help getting started?
                                    </p>
                                    <p>
                                        Visit the Help Center or reply to this email and we'll point you in the right direction.
                                    </p>
                                    <p>
                                        Thanks for joining BenchBuddy.
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
                                    <p style='font-size:12px; color:#fff; text-align:center;'>
                                        If the button doesn't work, copy and paste this link into your browser:<br>
                                        <span style='word-break:break-all;'>{$dashboardUrl}</span>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
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




function send_team_invitation_email(
    string $email,
    string $teamName,
    string $inviterName,
    string $inviteLink
): void {
    require_once __DIR__ . "/../mail.php";

    $name = "there";
    $subject = "You've been invited to join a BenchBuddy team";

    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>BenchBuddy Invitation</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px; '>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;padding-top:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;' />
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hey Coach!</p>
                                    <p><strong>{$inviterName}</strong> invited you to join <strong>{$teamName}</strong> on BenchBuddy.</p>
                                    <p>BenchBuddy helps coaches manage lineups, player usage, pitch counts, and game history in one place.</p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='{$inviteLink}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                            Accept Invitation
                                        </a>
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
                                    <p style='font-size:12px; color:#fff; text-align:center;'>
                                        If the button doesn't work, copy and paste this link into your browser:<br>
                                        <span style='word-break:break-all;'>{$inviteLink}</span>
                                    </p>
                                </td>
                            </tr>
                            </table>
                                <table width='100%' cellpadding='0' cellspacing='0'>
                                <tr>
                                    <td align='center'>
                                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                            <tr>
                                                <td style='padding: 0 8px; text-align: center;'>
                                                    <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                                        <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                                    </a>
                                                </td>
                                                <td style='padding: 0 8px; text-align: center;'>
                                                    <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                                        <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                                    </a>
                                                </td>
                                                <td style='padding: 0 8px; text-align: center;'>
                                                    <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                                        <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                                    </a>
                                                </td>
                                                <td style='padding: 0 8px; text-align: center;'>
                                                    <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                                        <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
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



function send_trial_started_email(
    string $email,
    string $fullName,
    string $teamName,
    string $trialEndDate
): void {
    require_once __DIR__ . "/../mail.php";

    $scheme =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
            ? "https"
            : "http";

    $host = $_SERVER["HTTP_HOST"] ?? "benchbuddy.ca";

    $billingUrl = $scheme . "://" . $host . "/billing.php";

    $name = trim($fullName) !== "" ? $fullName : "Coach";

    $subject = "Your BenchBuddy Coach Plus trial has started";

    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Coach Plus Trial Started</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hi {$name},</p>
                                    <p>
                                        Your <strong>BenchBuddy Coach Plus Trial</strong> is now active for
                                        <strong>{$teamName}</strong>.
                                    </p>
                                    <p>
                                        Your trial will end on <strong>{$trialEndDate}</strong>.
                                    </p>
                                    <p>
                                        During your trial you'll have access to premium BenchBuddy features and future Coach Plus enhancements.
                                    </p>
                                    <ul>
                                        <li>Advanced team management</li>
                                        <li>Custom team branding</li>
                                        <li>Expanded coaching tools</li>
                                        <li>Future premium features</li>
                                    </ul>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='{$billingUrl}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                            Explore Coach Plus
                                        </a>
                                    </p>
                                    <p>
                                        We recommend spending a few minutes exploring everything available while your trial is active.
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
                                    <p style='font-size:12px; color:#fff; text-align:center;'>
                                        If the button doesn't work, copy and paste this link into your browser:<br>
                                        <span style='word-break:break-all;'>{$billingUrl}</span>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
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



function send_trial_expiring_email(
    string $email,
    string $fullName,
    string $teamName,
    int $daysRemaining,
    string $trialEndDate
): void
{
    require_once __DIR__ . "/../mail.php";

    $name = trim($fullName) !== '' ? $fullName : 'Coach';

    $subject = match ($daysRemaining) {
        1 => 'Your BenchBuddy trial ends in {$daysRemaining} days',
        default => "Your BenchBuddy trial ends in {$daysRemaining} days",
    };

    $billingUrl = 'https://benchbuddy.devworks.space/billing.php';

    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Your BenchBuddy trial ends tomorrow</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hi {$name},</p>
                                    <p>
                                        Your BenchBuddy Coach Plus trial for
                                        <strong>{$teamName}</strong>
                                        ends on <strong>{$trialEndDate}</strong>.
                                    </p>
                                    <p>
                                        You have <strong>{$daysRemaining} day(s)</strong> remaining.
                                    </p>
                                    <p>
                                        Upgrade now to keep access to premium BenchBuddy features.
                                    </p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='{$billingUrl}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                            Upgrade to Coach Plus
                                        </a>
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
                                    <p style='font-size:12px; color:#fff; text-align:center;'>
                                        If the button doesn't work, copy and paste this link into your browser:<br>
                                        <span style='word-break:break-all;'>{$billingUrl}</span>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
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


function get_completed_game_count(int $teamId): int
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM games
        WHERE team_id = :team_id
          AND status = 'locked'
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}

function send_first_game_ready_email(
    string $email,
    string $fullName,
    string $teamName
): void
{
    require_once __DIR__ . "/../mail.php";
    if (!user_allows_email_category($email, 'product_updates')) {

        return;

    }
    $subject = "Your first BenchBuddy lineup is ready ⚾";
    $preferencesUrl = get_email_preferences_url($email);
    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Your First BenchBuddy Lineup Is Ready</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hi {$fullName},</p>
                                    <p>
                                        Congratulations! You've set up your first game using BenchBuddy.
                                    </p>
                                    <p>
                                        Your lineup has been built and is ready to print.
                                    </p>
                                    <p>
                                        Don't forget to return after the game to record pitch counts and innings pitched. The more games you track, the more valuable your season data becomes.
                                    </p>
                                    <p>
                                        Most coaches see the biggest benefit after a few games when player usage, position history, and pitching trends start to emerge.
                                    </p>
                                    <p>
                                        You're officially ready for game day.
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <p style='font-size:12px; color:#fff; text-align:center; margin-top:18px;'>
                                        You can update your email preferences anytime.<br>
                                        <a href='{$preferencesUrl}' style='color:#ffffff; text-decoration:underline;'>
                                            Manage email preferences
                                        </a>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                    </td>
                    </tr>
                    </table>
                </body>

                </html>
    ";

    send_app_mail($email, $fullName, $subject, $html);
}


function send_pitch_count_reminder_email(
    string $email,
    string $fullName,
    string $gameName
): void {
    require_once __DIR__ . "/../mail.php";
    if (!user_allows_email_category($email, 'product_updates')) {
        return;
    }
    $subject = "Don't forget to record pitch counts";
    $preferencesUrl = get_email_preferences_url($email);
    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Don't forget to record pitch counts</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p>Hi {$fullName},</p>
                                    <p>
                                        Your lineup for <strong>{$gameName}</strong> was finalized, but pitch counts haven't been recorded yet.
                                    </p>
                                    <p>
                                        Recording pitch counts helps BenchBuddy:
                                    </p>
                                    <ul>
                                        <li>Track pitcher workload</li>
                                        <li>Monitor innings pitched</li>
                                        <li>Build season statistics</li>
                                        <li>Support future lineup decisions</li>
                                    </ul>
                                    <p>
                                        Take a minute to update your game records while they're still fresh.
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <p style='font-size:12px; color:#fff; text-align:center; margin-top:18px;'>
                                        You can update your email preferences anytime.<br>
                                        <a href='{$preferencesUrl}' style='color:#ffffff; text-decoration:underline;'>
                                            Manage email preferences
                                        </a>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                    </td>
                    </tr>
                    </table>
                </body>

                </html>
    ";

    send_app_mail($email, $fullName, $subject, $html);
}



function send_games_tracked_milestone_email(
    string $email,
    string $fullName,
    string $teamName,
    int $gamesCompleted
): void {
    require_once __DIR__ . "/../mail.php";
    if (!user_allows_email_category($email, 'product_updates')) {
        return;
    }
    $name = trim($fullName) !== '' ? $fullName : 'Coach';
    $subject = "{$gamesCompleted} games tracked with BenchBuddy";
    $preferencesUrl = get_email_preferences_url($email);
    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>{$gamesCompleted} games tracked with BenchBuddy</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p>Hey Coach!</p>
                                    <p>
                                        Big milestone. <strong>{$teamName}</strong> has now tracked
                                        <strong>{$gamesCompleted} games</strong> with BenchBuddy.
                                    </p>
                                    <p>
                                        Every game you track makes your player usage, pitching workload,
                                        and lineup history more valuable.
                                    </p>
                                    <p>
                                        Keep going. Your season data is starting to tell a real story.
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <p style='font-size:12px; color:#fff; text-align:center; margin-top:18px;'>
                                        You can update your email preferences anytime.<br>
                                        <a href='{$preferencesUrl}' style='color:#ffffff; text-decoration:underline;'>
                                            Manage email preferences
                                        </a>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
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



function send_admin_new_signup_email(
    string $fullName,
    string $email,
    string $teamName,
    string $referralSource = '',
    string $planStatus = 'Free signup'
): void {
    require_once __DIR__ . "/../mail.php";

    $adminEmail = 'benchbuddy.devworks@gmail.com';
    $adminName = 'Derrick';

    $subject = 'New BenchBuddy Signup: ' . $fullName;

    $referralText = trim($referralSource) !== ''
        ? $referralSource
        : 'Direct / no referral';

    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>New BenchBuddy Signup</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <h2 style='margin-top:0;'>New BenchBuddy Signup</h2>
                                    <p>A new coach just signed up for BenchBuddy.</p>
                                    <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse:collapse;'>
                                        <tr>
                                            <td style='font-weight:bold; border-bottom:1px solid #eee;'>Name</td>
                                            <td style='border-bottom:1px solid #eee;'>{$fullName}</td>
                                        </tr>
                                        <tr>
                                            <td style='font-weight:bold; border-bottom:1px solid #eee;'>Email</td>
                                            <td style='border-bottom:1px solid #eee;'>{$email}</td>
                                        </tr>
                                        <tr>
                                            <td style='font-weight:bold; border-bottom:1px solid #eee;'>Team Created</td>
                                            <td style='border-bottom:1px solid #eee;'>{$teamName}</td>
                                        </tr>
                                        <tr>
                                            <td style='font-weight:bold; border-bottom:1px solid #eee;'>Referral Source</td>
                                            <td style='border-bottom:1px solid #eee;'>{$referralText}</td>
                                        </tr>
                                        <tr>
                                            <td style='font-weight:bold; border-bottom:1px solid #eee;'>Plan / Trial</td>
                                            <td style='border-bottom:1px solid #eee;'>{$planStatus}</td>
                                        </tr>
                                    </table>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>

                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                    </td>
                    </tr>
                    </table>
                </body>

                </html>
    ";

    send_app_mail($adminEmail, $adminName, $subject, $html);
}

function send_payment_failed_email(
    string $email,
    string $fullName,
    string $teamName
): void {
    require_once __DIR__ . "/../mail.php";

    $billingUrl = 'https://benchbuddy.devworks.space/billing.php';

    $name = trim($fullName) !== '' ? $fullName : 'Coach';

    $subject = 'Action required: update your BenchBuddy payment method';

    $html = "
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset='UTF-8'>
        <title>New BenchBuddy Signup</title>
    </head>

    <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
            <tr>
                <td align='center'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                        <tr>
                            <td style='text-align:center; padding-bottom:20px;'>
                                <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                            </td>
                        </tr>
                        <tr>
                            <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                <p style='margin-top:0;'>Hi {$name},</p>
                                <p> We were unable to process your most recent BenchBuddy subscription payment for <strong>{$teamName}</strong>. </p>
                                <p> This usually happens because: </p>
                                <table width='100%' cellpadding='0' cellspacing='0' style='margin:10px 0 24px;'>
                                    <tr>
                                        <td style='padding:8px 0;'>⚾ Card expired</td>
                                    </tr>
                                    <tr>
                                        <td style='padding:8px 0;'>⚾ Card replaced</td>
                                    </tr>
                                    <tr>
                                        <td style='padding:8px 0;'>⚾ Insufficient funds</td>
                                    </tr>
                                    <tr>
                                        <td style='padding:8px 0;'>⚾ Bank declined the charge</td>
                                    </tr>
                                </table>
                                <p> Please update your payment method to avoid interruption of your Coach Plus subscription. </p>
                                <p> See you at the ballpark,<br> <strong>Derrick Ottenbreit</strong><br> Founder, BenchBuddy </p>
                        </tr>
                        <tr>
                            <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                <h2>Coach smarter. Build better lineups.</h2>
                                <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                    The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                </p>
                            </td>
                        </tr>
                </td>
            </tr>
            </table>
                <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                            <tr>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                    </a>
                                </td>
                            </tr>
                        </table>
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

function send_subscription_activated_email(
    string $email,
    string $fullName,
    string $teamName,
    string $planName
): void {
    require_once __DIR__ . "/../mail.php";

    $dashboardUrl = 'https://benchbuddy.devworks.space/index.php';

    $name = trim($fullName) !== '' ? $fullName : 'Coach';

    $subject = 'Welcome to BenchBuddy ' . $planName;

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Subscription Activated</title>
    </head>
    <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
            <tr>
                <td align='center'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                        <tr>
                            <td style='text-align:center; padding:20px 0;'>
                                <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                            </td>
                        </tr>

                        <tr>
                            <td style='color:#444; font-size:16px; line-height:1.6; padding:30px;'>
                                <p style='margin-top:0;'>Hi {$name},</p>

                                <p>
                                    Your <strong>BenchBuddy {$planName}</strong> subscription is now active for
                                    <strong>{$teamName}</strong>.
                                </p>

                                <p>
                                    Thank you for supporting BenchBuddy. Your team now has access to the features included with your plan.
                                </p>

                                <p>
                                    Keep building lineups, tracking pitch counts, managing player usage, and organizing your season from one place.
                                </p>

                                <p style='text-align:center; margin:30px 0;'>
                                    <a href='{$dashboardUrl}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                        Open BenchBuddy
                                    </a>
                                </p>

                                <p>
                                    See you at the ballpark,<br>
                                    <strong>Derrick Ottenbreit</strong><br>
                                    Founder, BenchBuddy
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6; padding:30px; text-align:center;'>
                                <h2>Coach smarter. Build better lineups.</h2>
                                <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                    The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                </p>

                                <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>

                                <p style='font-size:12px; color:#fff; text-align:center;'>
                                    If the button doesn't work, copy and paste this link into your browser:<br>
                                    <span style='word-break:break-all;'>{$dashboardUrl}</span>
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </table>
                <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                            <tr>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                    </a>
                                </td>
                                <td style='padding: 0 8px; text-align: center;'>
                                    <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                        <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                    </a>
                                </td>
                            </tr>
                        </table>
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



function send_subscription_cancelled_email(
    string $email,
    string $fullName,
    string $teamName
): void {
    require_once __DIR__ . "/../mail.php";

    $billingUrl = 'https://benchbuddy.devworks.space/billing.php';

    $name = trim($fullName) !== '' ? $fullName : 'Coach';

    $subject = 'Your BenchBuddy subscription has been cancelled';

    $html = "
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset='UTF-8'>
            <title>Your BenchBuddy subscription has been cancelled</title>
        </head>

        <body style='margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='padding:20px 0;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background:#ffffff; border-radius:8px;'>
                            <tr>
                                <td style='text-align:center; padding-bottom:20px;'>
                                    <img src='https://benchbuddy.devworks.space/assets/logo.svg' alt='BenchBuddy' style='max-width:160px; height:auto; display:block; margin:0 auto;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='color:#444; font-size:16px; line-height:1.6;padding:30px;'>
                                    <p style='margin-top:0;'>Hi {$name},</p>
                                    <p>
                                        Your BenchBuddy subscription for <strong>{$teamName}</strong> has been cancelled.
                                    </p>
                                    <p>
                                        Your team has been moved back to the Free plan. You can still access BenchBuddy, but paid features may no longer be available.
                                    </p>
                                    <p>
                                        You can reactivate your subscription anytime from Billing.
                                    </p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='{$billingUrl}' style='background:#2563eb; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; display:inline-block; font-weight:bold;'>
                                            Manage Billing
                                        </a>
                                    </p>
                                    <p>
                                        See you at the ballpark,<br>
                                        <strong>Derrick Ottenbreit</strong><br>
                                        Founder, BenchBuddy
                                    </p>
                            </tr>
                            <tr>
                                <td style='background:#111827; color:#fff; font-size:16px; line-height:1.6;padding:30px;text-align:center;'>
                                    <h2>Coach smarter. Build better lineups.</h2>
                                    <p style='font-size:13px; color:#fff; text-align:center; margin-bottom:0;'>
                                        The Dugout’s Smartest Clipboard. Manage teams, build lineups, track pitch counts, organize game history, and print clean game day sheets.
                                    </p>
                                    <hr style='border:none; border-top:1px solid #eee; margin:30px 0;'>
                                    <p style='font-size:12px; color:#fff; text-align:center;'>
                                        If the button doesn't work, copy and paste this link into your browser:<br>
                                        <span style='word-break:break-all;'>{$billingUrl}</span>
                                    </p>
                                </td>
                            </tr>
                    </td>
                </tr>
                </table>
                    <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td align='center'>
                            <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; padding:20px;'>
                                <tr>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='mailto:benchbuddy.devworks@gmail.com' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Send-Email-Fly.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.facebook.com/benchbuddy.devworks/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Facebook-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://www.instagram.com/devworks.space/' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Instagram-Logo.png'>
                                        </a>
                                    </td>
                                    <td style='padding: 0 8px; text-align: center;'>
                                        <a href='https://wa.me/17788781422' target='_blank' style='text-decoration: none;'>
                                            <img src='https://benchbuddy.devworks.space/assets/images/Whatsapp-Logo.png'>
                                        </a>
                                    </td>
                                </tr>
                            </table>
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
