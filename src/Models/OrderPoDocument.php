<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The purchase order paperwork attached to an order, as a running history.
 *
 * A quantity change usually arrives with an amended PO, or a second one
 * covering the extra parts. Overwriting the first would lose the document the
 * original price was agreed against, so nothing is ever replaced -- the list
 * grows, oldest first, and the original stays flagged as such.
 */
final class OrderPoDocument
{
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT d.*, o.client_id FROM order_po_documents d
               JOIN orders o ON o.id = d.order_id
              WHERE d.id = :id',
            ['id' => $id]
        );
    }

    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT d.*, u.name AS uploaded_by_name FROM order_po_documents d
               JOIN users u ON u.id = d.uploaded_by
              WHERE d.order_id = :id
              ORDER BY d.uploaded_at, d.id',
            ['id' => $orderId]
        );
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO order_po_documents (
                order_id, po_number, file_path, original_filename, mime_type, file_size,
                is_original, note, uploaded_by
             ) VALUES (
                :order_id, :po_number, :file_path, :original_filename, :mime_type, :file_size,
                :is_original, :note, :uploaded_by
             )',
            [
                'order_id' => $data['order_id'],
                'po_number' => $data['po_number'] ?? '',
                'file_path' => $data['file_path'],
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'is_original' => !empty($data['is_original']) ? 1 : 0,
                'note' => $data['note'] ?? null,
                'uploaded_by' => $data['uploaded_by'],
            ]
        );
    }
}
