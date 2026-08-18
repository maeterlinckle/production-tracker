<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ClearBooksToken
{
    public static function get(): ?array
    {
        return Database::one('SELECT * FROM clearbooks_tokens WHERE id = 1');
    }

    public static function save(string $accessToken, string $refreshToken, \DateTimeImmutable $expiresAt): void
    {
        Database::query(
            'INSERT INTO clearbooks_tokens (id, access_token, refresh_token, expires_at)
             VALUES (1, :access_token, :refresh_token, :expires_at)
             ON DUPLICATE KEY UPDATE access_token = :access_token2, refresh_token = :refresh_token2, expires_at = :expires_at2',
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'access_token2' => $accessToken,
                'refresh_token2' => $refreshToken,
                'expires_at2' => $expiresAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    /** Forget the stored pair. There is no revoke endpoint in the v1 spec, so this is the local half of a disconnect. */
    public static function clear(): void
    {
        Database::query("DELETE FROM clearbooks_tokens WHERE id = 1");
    }
}
