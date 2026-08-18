<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\NotificationTypes;

final class NotificationPreference
{
    public static function subscribedTypes(int $userId): array
    {
        return array_column(
            Database::all('SELECT notification_type FROM notification_preferences WHERE user_id = :user_id', ['user_id' => $userId]),
            'notification_type'
        );
    }

    public static function isSubscribed(int $userId, string $type): bool
    {
        return Database::one(
            'SELECT 1 FROM notification_preferences WHERE user_id = :user_id AND notification_type = :type',
            ['user_id' => $userId, 'type' => $type]
        ) !== null;
    }

    public static function setForUser(int $userId, array $types): void
    {
        $types = array_values(array_intersect($types, array_keys(NotificationTypes::all())));

        Database::transaction(static function (\PDO $pdo) use ($userId, $types): void {
            $delete = $pdo->prepare('DELETE FROM notification_preferences WHERE user_id = :user_id');
            $delete->execute(['user_id' => $userId]);

            $insert = $pdo->prepare('INSERT INTO notification_preferences (user_id, notification_type) VALUES (:user_id, :type)');
            foreach ($types as $type) {
                $insert->execute(['user_id' => $userId, 'type' => $type]);
            }
        });
    }
}
