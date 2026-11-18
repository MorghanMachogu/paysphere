<?php
/**
 * Email delivery through PHPMailer (installed with `composer install`).
 *
 * If MAIL_HOST is not set, or PHPMailer is not installed, emails are written to
 * backend/logs/email.log instead of being sent, so development and testing work
 * without an SMTP account.
 */
class Mailer
{
    /** @return bool true if sent (or logged in development mode) */
    public static function send(string $to, string $subject, string $html, array $attachments = []): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!env('MAIL_HOST') || !is_readable($autoload)) {
            return self::logOnly($to, $subject, $html, $attachments);
        }
        require_once $autoload;
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST');
            $mail->Port       = (int)env('MAIL_PORT', 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME', '');
            $mail->Password   = env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@paysphere.co.ke'), env('MAIL_FROM_NAME', 'PaySphere'));
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
            foreach ($attachments as $name => $content) {
                $mail->addStringAttachment($content, $name);
            }
            return $mail->send();
        } catch (Throwable $e) {
            error_log('[PaySphere mail] ' . $e->getMessage());
            return false;
        }
    }

    private static function logOnly(string $to, string $subject, string $html, array $attachments): bool
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = sprintf("[%s] TO: %s | SUBJECT: %s | ATTACHMENTS: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'), $to, $subject, implode(', ', array_keys($attachments)),
            trim(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html))), str_repeat('-', 60));
        return @file_put_contents("$dir/email.log", $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    /** Wraps content in the standard PaySphere email layout. */
    public static function layout(string $title, string $bodyHtml): string
    {
        return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:auto;color:#1f2937">'
            . '<div style="background:#0f3d5e;color:#fff;padding:18px 24px;border-radius:8px 8px 0 0">'
            . '<strong style="font-size:18px">PaySphere</strong></div>'
            . '<div style="border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 8px 8px">'
            . '<h2 style="margin-top:0;font-size:18px">' . htmlspecialchars($title) . '</h2>' . $bodyHtml
            . '<p style="color:#6b7280;font-size:12px;margin-top:24px">This is an automated message from PaySphere.</p>'
            . '</div></div>';
    }
}
