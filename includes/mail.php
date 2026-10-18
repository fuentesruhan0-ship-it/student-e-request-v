<?php
/**
 * Email sending.
 *
 * Configuration is set in config/database.php via env vars / .env.local.
 *
 * MAIL_MODE:
 *  - 'log'  : no real email is sent. Each message is appended to mail_outbox/mail.log
 *             (viewable from the admin bell menu link). Ideal for XAMPP + demos.
 *  - 'smtp' : sends through the SMTP server configured via env vars via raw sockets.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!defined('MAIL_MODE'))       define('MAIL_MODE',       'log');
if (!defined('MAIL_FROM'))       define('MAIL_FROM',       'no-reply@srvp.test');
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME',  'SRVP Student Portal');
if (!defined('MAIL_HOST'))       define('MAIL_HOST',       'smtp.example.com');
if (!defined('MAIL_PORT'))       define('MAIL_PORT',       587);
if (!defined('MAIL_USER'))       define('MAIL_USER',       '');
if (!defined('MAIL_PASS'))       define('MAIL_PASS',       '');
define('MAIL_OUTBOX', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail_outbox');

/**
 * Send (or log) an email. Returns true when the message was accepted/logged.
 */
function send_mail(string $to, string $subject, string $htmlBody): bool
{
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n";

    if (MAIL_MODE === 'smtp') {
        try {
            return smtp_send($to, $subject, $htmlBody, $headers);
        } catch (Throwable $e) {
            error_log('send_mail smtp failed: ' . $e->getMessage());
            mail_log($to, $subject, $htmlBody . "\n\n[SMTP FAILED: " . $e->getMessage() . "]");
            return false;
        }
    }
    mail_log($to, $subject, $htmlBody);
    return true;
}

/** Append a message to the outbox log so it can be reviewed without a mail server. */
function mail_log(string $to, string $subject, string $body): void
{
    if (!is_dir(MAIL_OUTBOX)) {
        @mkdir(MAIL_OUTBOX, 0775, true);
    }
    $entry = "=========================================================\n"
           . 'Date:    ' . date('Y-m-d H:i:s') . "\n"
           . 'To:      ' . $to . "\n"
           . 'Subject: ' . $subject . "\n"
           . "---------------------------------------------------------\n"
           . strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $body)) . "\n\n";
    @file_put_contents(MAIL_OUTBOX . DIRECTORY_SEPARATOR . 'mail.log', $entry, FILE_APPEND | LOCK_EX);
}

/** URL of the outbox log, or null when nothing was logged yet. */
function mail_outbox_url(): ?string
{
    return is_file(MAIL_OUTBOX . DIRECTORY_SEPARATOR . 'mail.log') ? url('mail_outbox/mail.log') : null;
}

/** Minimal SMTP client (STARTTLS-less plain submission; fine for local relays). */
function smtp_send(string $to, string $subject, string $htmlBody, string $headers): bool
{
    $socket = @fsockopen(MAIL_HOST, (int) MAIL_PORT, $errno, $errstr, 10);
    if (!$socket) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    $read = function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c) use ($socket, $read): string {
        fwrite($socket, $c . "\r\n");
        return $read();
    };

    $read();                                  // banner
    $cmd('EHLO localhost');
    if (MAIL_USER !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode(MAIL_USER));
        $cmd(base64_encode(MAIL_PASS));
    }
    $cmd('MAIL FROM:<' . MAIL_FROM . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $cmd('DATA');
    $payload = "Subject: $subject\r\n$headers\r\n" . $htmlBody . "\r\n.";
    $resp = $cmd($payload);
    $cmd('QUIT');
    fclose($socket);
    return str_starts_with($resp, '250');
}
