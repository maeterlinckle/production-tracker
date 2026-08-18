<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Crypto;
use App\Models\EmailLog;
use App\Models\Setting;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Thin PHPMailer/SMTP wrapper. send() never throws -- a broken mail server
 * must not break the workflow action that triggered the notification (order
 * placed, delivery note issued, etc). Every attempt is logged either way.
 *
 * Configuration lives in the `settings` table (staff.admin editable from
 * Settings -> Email), with .env values as the fallback for a fresh install
 * that hasn't visited that screen yet.
 */
final class Mailer
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'];

    public static function setting(string $key, string $envKey, string $default = ''): string
    {
        $value = Setting::get('mail_' . $key);

        return $value !== null && $value !== '' ? $value : (string) Config::get('mail.' . $envKey, $default);
    }

    public static function isEnabled(): bool
    {
        $value = Setting::get('mail_enabled');

        return $value === null ? self::setting('host', 'host') !== '' : $value === '1';
    }

    /** @return string[] human-readable blockers, empty if email can actually be sent */
    public static function problems(): array
    {
        $problems = [];

        if (!class_exists(PHPMailer::class)) {
            $problems[] = 'The PHPMailer library is not installed (run composer install).';
        }
        if (self::setting('host', 'host') === '') {
            $problems[] = 'No SMTP host configured.';
        }
        if (self::setting('from_address', 'from_address') === '') {
            $problems[] = 'No "from" address configured.';
        }

        return $problems;
    }

    public static function isReady(): bool
    {
        return self::isEnabled() && self::problems() === [];
    }

    public static function passwordSource(): string
    {
        if (Setting::get('mail_password') !== null && Setting::get('mail_password') !== '') {
            return 'database';
        }

        return (string) Config::get('mail.password', '') !== '' ? 'env' : 'unset';
    }

    /** Returns false (and stores nothing) if it can't encrypt -- never stores plaintext. */
    public static function storePassword(string $password): bool
    {
        if ($password === '') {
            Setting::put('mail_password', null);

            return true;
        }

        $encrypted = Crypto::encrypt($password);
        if ($encrypted === null) {
            return false;
        }

        Setting::put('mail_password', $encrypted);

        return true;
    }

    private static function password(): string
    {
        $stored = Setting::get('mail_password');
        if ($stored !== null && $stored !== '') {
            return Crypto::decrypt($stored) ?? '';
        }

        return (string) Config::get('mail.password', '');
    }

    /**
     * Has an administrator switched this one message off?
     *
     * sendTemplate() returns false in that case *without* writing a log row — a
     * deliberately silenced message is not a failure, and logging one on every
     * cron run would be noise. Callers that report a result to a person need to
     * tell the two apart, or they end up saying "see the log" about a log entry
     * that was never written.
     */
    public static function isTemplateActive(string $templateKey): bool
    {
        $template = EmailTemplate::find($templateKey);

        return $template !== null && $template['is_active'] === true;
    }

    /**
     * The merge fields available to every template.
     *
     * @return array<string,string>
     */
    public static function commonFields(?string $recipientName = null): array
    {
        return [
            'app_name'       => (string) Config::get('app.product', 'Production Tracker'),
            'app_url'        => rtrim((string) Config::get('app.url', ''), '/'),
            'recipient_name' => $recipientName ?? 'there',
            'today'          => date('j M Y'),
        ];
    }

    /**
     * Send one of the application's templates.
     *
     * @param array<string,string> $fields Merge-field values
     */
    public static function sendTemplate(
        string $templateKey,
        string $toEmail,
        ?string $toName,
        array $fields = [],
        ?string $relatedType = null,
        ?int $relatedId = null
    ): bool {
        $template = EmailTemplate::find($templateKey);

        if ($template === null) {
            return false;
        }

        if ($template['is_active'] !== true) {
            return false;
        }

        $merged  = array_merge(self::commonFields($toName), $fields);
        $subject = Merge::render((string) $template['subject'], $merged);
        $isHtml  = (bool) $template['is_html'];
        $content = Merge::render((string) $template['body'], $merged, $isHtml);

        if (!$isHtml) {
            return self::send($toEmail, $toName, $subject, $content, $templateKey, $relatedType, $relatedId, false);
        }

        // The plain-text alternative comes from the *content*, before the shell
        // is wrapped round it: running htmlToText over the whole message would
        // put the masthead and footer chrome into the text part, which is the
        // one people fall back to when their client shows no pictures.
        return self::send(
            $toEmail,
            $toName,
            $subject,
            Layout::wrap($content, $subject),
            $templateKey,
            $relatedType,
            $relatedId,
            true,
            Merge::htmlToText($content)
        );
    }

    /** Send a message. Always logs; never throws. */
    public static function send(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        string $templateKey,
        ?string $relatedType = null,
        ?int $relatedId = null,
        bool $isHtml = true,
        ?string $altBody = null
    ): bool {
        $toEmail = trim($toEmail);

        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            EmailLog::record($toEmail === '' ? '(none)' : $toEmail, $subject, $templateKey, 'failed', 'Not a valid email address.', $relatedType, $relatedId);

            return false;
        }

        if (!self::isEnabled()) {
            EmailLog::record($toEmail, $subject, $templateKey, 'failed', 'Email sending is switched off in Settings.', $relatedType, $relatedId);

            return false;
        }

        $problems = self::problems();
        if ($problems !== []) {
            EmailLog::record($toEmail, $subject, $templateKey, 'failed', implode(' ', $problems), $relatedType, $relatedId);

            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = self::setting('host', 'host');
            $mail->Port = (int) self::setting('port', 'port', '587');
            $username = self::setting('username', 'username');
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = self::password();
            $mail->CharSet = 'UTF-8';

            $encryption = self::setting('encryption', 'encryption', 'tls');
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(self::setting('from_address', 'from_address'), self::setting('from_name', 'from_name', 'Production Tracker'));
            $mail->addAddress($toEmail, $toName ?? '');
            $mail->Subject = $subject;

            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $bodyHtml;

                // PHPMailer does not invent a text part, so without this the
                // message goes out as HTML only and a text-only client shows
                // nothing useful at all.
                $mail->AltBody = $altBody ?? Merge::htmlToText($bodyHtml);

                // Embedded rather than linked: the tracker may not be reachable
                // from wherever the message is read, so an <img src="https://…">
                // would be a broken image in somebody's inbox.
                $logo = Layout::logoPath();

                if ($logo !== null && str_contains($bodyHtml, 'cid:' . Layout::LOGO_CID)) {
                    $mail->addEmbeddedImage($logo, Layout::LOGO_CID, 'logo');
                }
            } else {
                $mail->isHTML(false);
                $mail->Body = $bodyHtml;
            }

            $mail->send();
            EmailLog::record($toEmail, $subject, $templateKey, 'sent', null, $relatedType, $relatedId);

            return true;
        } catch (\Throwable $e) {
            EmailLog::record($toEmail, $subject, $templateKey, 'failed', $e->getMessage(), $relatedType, $relatedId);

            return false;
        }
    }
}
