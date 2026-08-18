<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findActive(int $id): ?array
    {
        $user = Database::one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $id]);

        return $user === null ? null : self::withRoles($user);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
    }

    public static function forClient(int $clientId): array
    {
        $users = Database::all('SELECT * FROM users WHERE client_id = :client_id ORDER BY name', ['client_id' => $clientId]);

        return array_map(static fn (array $u) => self::withRoles($u), $users);
    }

    public static function allStaff(): array
    {
        $users = Database::all("SELECT * FROM users WHERE side = 'staff' ORDER BY name");

        return array_map(static fn (array $u) => self::withRoles($u), $users);
    }

    private static function withRoles(array $user): array
    {
        $user['roles'] = Role::slugsForUser((int) $user['id']);

        return $user;
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO users (client_id, side, name, email, password_hash, is_active)
             VALUES (:client_id, :side, :name, :email, :password_hash, :is_active)',
            [
                'client_id' => $data['client_id'] ?? null,
                'side' => $data['side'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => $data['password_hash'],
                'is_active' => $data['is_active'] ?? 1,
            ]
        );
    }

    /**
     * Create an account nobody can sign in to yet.
     *
     * The password column is NOT NULL, so an invited account still needs
     * *something* in it. A fresh random string is hashed rather than a fixed
     * placeholder: a shared sentinel value across every pending account is one
     * lucky guess away from being a master password, whereas this is a hash of
     * 32 bytes nobody has ever seen. `password_set_at` is the column that
     * actually answers "has this person set a password".
     */
    public static function createInvited(array $data): int
    {
        return self::create($data + [
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'is_active' => 0,
        ]);
    }

    public static function updatePasswordHash(int $id, string $hash): void
    {
        Database::query(
            'UPDATE users SET password_hash = :hash, password_set_at = NOW() WHERE id = :id',
            ['hash' => $hash, 'id' => $id]
        );
    }

    /** Has this account ever had a password set on it? */
    public static function hasPassword(int $id): bool
    {
        return Database::scalar('SELECT password_set_at FROM users WHERE id = :id', ['id' => $id]) !== null;
    }

    public static function touchLogin(int $id): void
    {
        Database::query('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::query('UPDATE users SET is_active = :active WHERE id = :id', ['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public static function emailExists(string $email): bool
    {
        return self::findByEmail($email) !== null;
    }
}
