<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Models\User;

/**
 * Managing the people on a client account.
 *
 * Two places do it: the client's own admin on their team page, and Junction
 * from the client's page. The rules are the same either way — a name and an
 * email that is not already somebody else's, and never leaving a company with
 * nobody who can manage it — so they live here rather than being written twice
 * and drifting.
 *
 * Deactivating rather than deleting, throughout. A user who has raised orders,
 * asked questions and written notes is attached to all of it; removing the row
 * would either take that history with it or leave it pointing at nothing. An
 * account that is switched off keeps its name on everything it did.
 */
final class ClientUsers
{
    /**
     * Correct somebody's name or email address.
     *
     * @return string|null the reason it was refused, or null if it saved
     */
    public static function updateDetails(int $userId, string $name, string $email): ?string
    {
        $name = trim($name);
        $email = trim($email);

        $validator = new Validator(['name' => $name, 'email' => $email]);
        $validator->required('name', 'Name')->required('email', 'Email')->email('email', 'Email');

        if ($validator->fails()) {
            return implode(' ', $validator->errors());
        }

        // Changing an email changes what somebody signs in with, so it has to
        // stay unique across everybody — not just within this client.
        $clash = User::findByEmail($email);
        if ($clash !== null && (int) $clash['id'] !== $userId) {
            return 'Another account already uses ' . $email . '.';
        }

        Database::query(
            'UPDATE users SET name = :name, email = :email WHERE id = :id',
            ['name' => $name, 'email' => $email, 'id' => $userId]
        );

        return null;
    }

    /**
     * Would switching this person off leave their company with nobody who can
     * manage it?
     *
     * A client whose only administrator is deactivated cannot invite anybody,
     * cannot fix their own roles, and cannot reactivate the person who could —
     * they have to ring Junction. Cheap to prevent, tedious to undo.
     *
     * Staff are never counted: they have no client, and this is a question
     * about one company's own people.
     */
    public static function isLastActiveAdmin(int $userId): bool
    {
        $user = User::find($userId);

        if ($user === null || $user['client_id'] === null || !(bool) $user['is_active']) {
            return false;
        }

        $isAdmin = (int) Database::scalar(
            'SELECT COUNT(*) FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :id AND r.slug = :slug',
            ['id' => $userId, 'slug' => 'client.admin']
        ) > 0;

        if (!$isAdmin) {
            return false;
        }

        $others = (int) Database::scalar(
            'SELECT COUNT(*) FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE u.client_id = :client_id
                AND u.id <> :id
                AND u.is_active = 1
                AND r.slug = :slug',
            ['client_id' => $user['client_id'], 'id' => $userId, 'slug' => 'client.admin']
        );

        return $others === 0;
    }
}
