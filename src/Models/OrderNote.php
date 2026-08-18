<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Timestamped freeform log entries on an order -- not tied to a reply, just a record of who said what when. */
final class OrderNote
{
    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT n.*, u.name AS user_name FROM order_notes n
             JOIN users u ON u.id = n.user_id
             WHERE n.order_id = :order_id ORDER BY n.created_at',
            ['order_id' => $orderId]
        );
    }

    public static function create(int $orderId, int $userId, string $body): int
    {
        return Database::insert(
            'INSERT INTO order_notes (order_id, user_id, body) VALUES (:order_id, :user_id, :body)',
            ['order_id' => $orderId, 'user_id' => $userId, 'body' => $body]
        );
    }
}
