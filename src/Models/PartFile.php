<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PartFile
{
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT * FROM part_files WHERE part_id = :part_id ORDER BY version_no DESC, uploaded_at DESC',
            ['part_id' => $partId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM part_files WHERE id = :id', ['id' => $id]);
    }

    public static function create(array $data): int
    {
        $row = Database::one(
            'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version FROM part_files WHERE part_id = :part_id',
            ['part_id' => $data['part_id']]
        );
        $version = (int) ($row['next_version'] ?? 1);

        Database::query('UPDATE part_files SET is_current = 0 WHERE part_id = :part_id', ['part_id' => $data['part_id']]);

        return Database::insert(
            'INSERT INTO part_files (
                part_id, file_path, original_filename, mime_type, file_size, version_no, is_current, uploaded_by
            ) VALUES (
                :part_id, :file_path, :original_filename, :mime_type, :file_size, :version_no, 1, :uploaded_by
            )',
            [
                'part_id' => $data['part_id'],
                'file_path' => $data['file_path'],
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'version_no' => $version,
                'uploaded_by' => $data['uploaded_by'],
            ]
        );
    }
}
