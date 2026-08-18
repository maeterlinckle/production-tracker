<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A single-use invitation to set up an account.
 *
 * The token itself is never stored — only its SHA-256 — so a copy of the
 * database is not a set of working invitation links. Lookup hashes the incoming
 * token and matches on that, which is the same trick a password hash plays and
 * costs nothing here because the token is long and random rather than guessable.
 *
 * Rows survive acceptance. "Who invited this person, and when" is the audit
 * trail for how an account came to exist, and deleting the row would throw it
 * away to save one row per user.
 */
final class Invite
{
    /** How long a link stays usable. Long enough to survive a holiday, short enough to expire. */
    public const LIFETIME_DAYS = 7;

    /**
     * Issue a fresh invitation, superseding any outstanding one for that user.
     *
     * @return string the plaintext token — the only time it exists
     */
    public static function issue(int $userId, int $invitedBy): string
    {
        // Anything still outstanding for this user is replaced rather than left
        // alongside: two live links for one account means the older one keeps
        // working after somebody deliberately re-sent a newer one.
        self::revokeOutstanding($userId);

        $token = bin2hex(random_bytes(32));

        Database::insert(
            'INSERT INTO user_invites (user_id, token_hash, invited_by, expires_at)
             VALUES (:user_id, :token_hash, :invited_by, :expires_at)',
            [
                'user_id' => $userId,
                'token_hash' => self::hash($token),
                'invited_by' => $invitedBy,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::LIFETIME_DAYS . ' days')),
            ]
        );

        return $token;
    }

    /**
     * The invitation a token refers to, if it is still usable.
     *
     * Unaccepted, unexpired, and belonging to an account that still exists and
     * has not been deactivated in the meantime.
     */
    public static function findUsable(string $token): ?array
    {
        return Database::one(
            'SELECT i.*, u.name, u.email, u.side, u.client_id, u.is_active, c.name AS client_name
               FROM user_invites i
               JOIN users u ON u.id = i.user_id
          LEFT JOIN clients c ON c.id = u.client_id
              WHERE i.token_hash = :token_hash
                AND i.accepted_at IS NULL
                AND i.expires_at > NOW()',
            ['token_hash' => self::hash($token)]
        );
    }

    /** The outstanding invitation for a user, if there is one. */
    public static function outstandingFor(int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM user_invites
              WHERE user_id = :user_id AND accepted_at IS NULL AND expires_at > NOW()
           ORDER BY created_at DESC LIMIT 1',
            ['user_id' => $userId]
        );
    }

    public static function markAccepted(int $inviteId): void
    {
        Database::run('UPDATE user_invites SET accepted_at = NOW() WHERE id = :id', ['id' => $inviteId]);
    }

    /** Expire anything outstanding, without deleting the record that it happened. */
    public static function revokeOutstanding(int $userId): void
    {
        Database::run(
            'UPDATE user_invites SET expires_at = NOW()
              WHERE user_id = :user_id AND accepted_at IS NULL AND expires_at > NOW()',
            ['user_id' => $userId]
        );
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
