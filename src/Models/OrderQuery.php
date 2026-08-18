<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Threaded query/reply exchange on an order (item 8) -- every query expects a reply, tracked open/answered. */
final class OrderQuery
{
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT q.*, u.name AS raised_by_name, u.side AS raised_by_side FROM order_queries q
             JOIN users u ON u.id = q.raised_by WHERE q.id = :id',
            ['id' => $id]
        );
    }

    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT q.*, u.name AS raised_by_name, u.side AS raised_by_side FROM order_queries q
             JOIN users u ON u.id = q.raised_by
             WHERE q.order_id = :order_id ORDER BY q.created_at DESC',
            ['order_id' => $orderId]
        );
    }

    public static function create(int $orderId, int $raisedBy, string $subject, string $body): int
    {
        return Database::insert(
            'INSERT INTO order_queries (order_id, raised_by, subject, body) VALUES (:order_id, :raised_by, :subject, :body)',
            ['order_id' => $orderId, 'raised_by' => $raisedBy, 'subject' => $subject, 'body' => $body]
        );
    }

    public static function replies(int $queryId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS user_name, u.side FROM order_query_replies r
             JOIN users u ON u.id = r.user_id
             WHERE r.order_query_id = :query_id ORDER BY r.created_at',
            ['query_id' => $queryId]
        );
    }

    /**
     * A reply from anyone on the "other side" from whoever raised the query
     * marks it answered; a further reply from the original raiser (asking
     * again) reopens it.
     */
    public static function reply(int $queryId, int $userId, string $body): void
    {
        $query = self::find($queryId);
        if ($query === null) {
            return;
        }

        Database::insert(
            'INSERT INTO order_query_replies (order_query_id, user_id, body) VALUES (:query_id, :user_id, :body)',
            ['query_id' => $queryId, 'user_id' => $userId, 'body' => $body]
        );

        $status = $userId === (int) $query['raised_by'] ? 'open' : 'answered';
        Database::query('UPDATE order_queries SET status = :status WHERE id = :id', ['status' => $status, 'id' => $queryId]);
    }
}
