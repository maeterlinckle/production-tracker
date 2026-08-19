<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class OrderPhoto
{
    public static function forOrder(int $orderId): array
    {
        return Database::all('SELECT * FROM order_photos WHERE order_id = :order_id ORDER BY uploaded_at DESC', ['order_id' => $orderId]);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM order_photos WHERE id = :id', ['id' => $id]);
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO order_photos (order_id, order_line_id, file_path, thumb_path, original_filename, mime_type, file_size, caption, uploaded_by)
             VALUES (:order_id, :order_line_id, :file_path, :thumb_path, :original_filename, :mime_type, :file_size, :caption, :uploaded_by)',
            [
                'order_id' => $data['order_id'],
                'order_line_id' => $data['order_line_id'] ?? null,
                'file_path' => $data['file_path'],
                'thumb_path' => $data['thumb_path'] ?? null,
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'caption' => $data['caption'] ?? null,
                'uploaded_by' => $data['uploaded_by'],
            ]
        );
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM order_photos WHERE id = :id', ['id' => $id]);
    }
}
