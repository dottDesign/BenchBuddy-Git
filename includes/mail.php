<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_app_mail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody = ''
): void {
    $cfg = require __DIR__ . '/mail_config.php';

    /*
    |--------------------------------------------------------------------------
    | Validate recipient
    |--------------------------------------------------------------------------
    */

    $toEmail = trim($toEmail);
    $toName = trim($toName);

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email could not be sent because the recipient email address is invalid.');
    }

    /*
    |--------------------------------------------------------------------------
    | Staging Email Protection
    |--------------------------------------------------------------------------
    |
    | Emails sent from staging or local development are redirected to the
    | developer instead of being delivered to real account owners.
    |
    | The production hostname benchbuddy.devworks.space is intentionally
    | excluded so production emails go to the original recipient.
    |
    */

    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));

    // Remove the port when testing locally, for example localhost:8000.
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    $stagingHosts = [
        'benchbuddy-staging.devworks.space',
        'benchboss-staging.devworks.space',
        'localhost',
        '127.0.0.1',
    ];

    $isStaging = in_array($host, $stagingHosts, true);

    if ($isStaging) {
        $originalRecipientEmail = $toEmail;
        $originalRecipientName = $toName;

        $toEmail = 'dottenbreit@gmail.com';
        $toName = 'Derrick';

        if (!str_starts_with($subject, '[STAGING]')) {
            $subject = '[STAGING] ' . $subject;
        }

        $originalRecipientLabel = trim(
            $originalRecipientName . ' <' . $originalRecipientEmail . '>'
        );

        $htmlBody =
            "<div style=\"background:#fff3cd;color:#856404;padding:12px;border:1px solid #ffeeba;margin-bottom:20px;font-weight:bold;\">
                STAGING EMAIL<br>
                Original Recipient: "
                . htmlspecialchars(
                    $originalRecipientLabel,
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "
            </div>"
            . $htmlBody;

        if ($textBody !== '') {
            $textBody =
                "STAGING EMAIL\n"
                . "Original Recipient: "
                . $originalRecipientLabel
                . "\n\n"
                . $textBody;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create mailer
    |--------------------------------------------------------------------------
    */

    $mail = new PHPMailer(true);

    try {
        /*
        |--------------------------------------------------------------------------
        | SMTP configuration
        |--------------------------------------------------------------------------
        */

        $mail->isSMTP();
        $mail->Host = (string)($cfg['host'] ?? '');
        $mail->SMTPAuth = true;
        $mail->Username = (string)($cfg['username'] ?? '');
        $mail->Password = (string)($cfg['password'] ?? '');
        $mail->Port = (int)($cfg['port'] ?? 587);

        if (($cfg['encryption'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        /*
        |--------------------------------------------------------------------------
        | Reliability settings
        |--------------------------------------------------------------------------
        */

        $mail->Timeout = 30;
        $mail->SMTPAutoTLS = true;
        $mail->SMTPKeepAlive = false;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Debugging
        |--------------------------------------------------------------------------
        */

        // Enable temporarily when troubleshooting SMTP:
        // $mail->SMTPDebug = 2;

        /*
        |--------------------------------------------------------------------------
        | Email headers
        |--------------------------------------------------------------------------
        */

        $fromEmail = trim((string)($cfg['from_email'] ?? ''));
        $fromName = trim((string)($cfg['from_name'] ?? 'BenchBuddy'));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The configured sender email address is invalid.');
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        /*
        |--------------------------------------------------------------------------
        | Email content
        |--------------------------------------------------------------------------
        */

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->AltBody = $textBody !== ''
            ? $textBody
            : html_entity_decode(
                strip_tags(
                    preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?? $htmlBody
                ),
                ENT_QUOTES,
                'UTF-8'
            );

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException(
            'Email could not be sent: ' . $mail->ErrorInfo,
            0,
            $e
        );
    }
}
