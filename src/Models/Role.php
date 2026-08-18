<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Role
{
    public static function all(): array
    {
        return Database::all('SELECT * FROM roles ORDER BY side, slug');
    }

    public static function forSide(string $side): array
    {
        return Database::all('SELECT * FROM roles WHERE side = :side ORDER BY slug', ['side' => $side]);
    }

    public static function slugsForUser(int $userId): array
    {
        return array_column(
            Database::all(
                'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id',
                ['user_id' => $userId]
            ),
            'slug'
        );
    }

    /**
     * Replaces every role a user holds with exactly $slugs. $allowedSlugs
     * restricts what the caller is permitted to grant (e.g. a client.admin
     * may only ever grant client.* roles) -- any requested slug outside that
     * set is silently dropped rather than trusted from the request.
     */
    public static function setForUser(int $userId, array $slugs, array $allowedSlugs): void
    {
        $slugs = array_values(array_intersect($slugs, $allowedSlugs));

        Database::transaction(static function (PDO $pdo) use ($userId, $slugs): void {
            $delete = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            $delete->execute(['user_id' => $userId]);

            if ($slugs === []) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($slugs), '?'));
            $roleIds = $pdo->prepare("SELECT id FROM roles WHERE slug IN ({$placeholders})");
            $roleIds->execute($slugs);

            $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            foreach ($roleIds->fetchAll(PDO::FETCH_COLUMN) as $roleId) {
                $insert->execute(['user_id' => $userId, 'role_id' => $roleId]);
            }
        });
    }
}
