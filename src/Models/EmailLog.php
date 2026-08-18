<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class EmailLog
{
    public static function record(
        string $toEmail,
        string $subject,
        string $templateKey,
        string $status,
        ?string $error = null,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        Database::insert(
            'INSERT INTO email_log (to_email, subject, template_key, related_type, related_id, status, error)
             VALUES (:to_email, :subject, :template_key, :related_type, :related_id, :status, :error)',
            [
                'to_email' => $toEmail,
                'subject' => $subject,
                'template_key' => $templateKey,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'status' => $status,
                'error' => $error,
            ]
        );
    }

    public static function recent(int $limit = 20): array
    {
        return Database::all('SELECT * FROM email_log ORDER BY sent_at DESC LIMIT ' . $limit);
    }
}
