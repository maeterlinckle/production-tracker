<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PartPhoto
{
    public static function forPart(int $partId): array
    {
        return Database::all('SELECT * FROM part_photos WHERE part_id = :part_id ORDER BY uploaded_at DESC', ['part_id' => $partId]);
    }

    /** First photo, for use as a thumbnail wherever the part is listed. */
    public static function primaryForPart(int $partId): ?array
    {
        return Database::one('SELECT * FROM part_photos WHERE part_id = :part_id ORDER BY uploaded_at LIMIT 1', ['part_id' => $partId]);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM part_photos WHERE id = :id', ['id' => $id]);
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO part_photos (part_id, file_path, original_filename, mime_type, file_size, uploaded_by)
             VALUES (:part_id, :file_path, :original_filename, :mime_type, :file_size, :uploaded_by)',
            [
                'part_id' => $data['part_id'],
                'file_path' => $data['file_path'],
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'uploaded_by' => $data['uploaded_by'],
            ]
        );
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM part_photos WHERE id = :id', ['id' => $id]);
    }
}
