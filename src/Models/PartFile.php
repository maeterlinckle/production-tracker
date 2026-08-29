<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * One revision of one drawing.
 *
 * Versioning is per drawing, not per part: a part with a general arrangement
 * and two detail drawings has three v1s, and numbering them 1, 2, 3 across the
 * part would make "v2" mean nothing at all. See PartDrawing for what a drawing
 * is and why the name lives there.
 *
 * Nothing is ever replaced. A superseded revision keeps its file and stays
 * viewable, because parts already made were made to it.
 */
final class PartFile
{
    /** @return array<int,array<string,mixed>> */
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT f.*, u.name AS uploaded_by_name
               FROM part_files f
          LEFT JOIN users u ON u.id = f.uploaded_by
              WHERE f.part_id = :part_id
              ORDER BY f.drawing_id, f.version_no DESC',
            ['part_id' => $partId]
        );
    }

    /**
     * Every revision of every drawing on a part, grouped by drawing id,
     * newest first within each.
     *
     * One query for the whole page rather than one per drawing.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function byDrawing(int $partId): array
    {
        $grouped = [];
        foreach (self::forPart($partId) as $file) {
            $grouped[(int) $file['drawing_id']][] = $file;
        }

        return $grouped;
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM part_files WHERE id = :id', ['id' => $id]);
    }

    /**
     * Add a revision to a drawing.
     *
     * The version number and the demotion of the previous current revision
     * happen together, so two uploads landing at once cannot both come out as
     * v3 or both end up marked current.
     */
    public static function create(array $data): int
    {
        $drawingId = (int) $data['drawing_id'];

        return Database::transaction(static function (PDO $pdo) use ($data, $drawingId): int {
            $statement = $pdo->prepare(
                'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version
                   FROM part_files WHERE drawing_id = :drawing_id FOR UPDATE'
            );
            $statement->execute(['drawing_id' => $drawingId]);
            $version = (int) ($statement->fetchColumn() ?: 1);

            $pdo->prepare('UPDATE part_files SET is_current = 0 WHERE drawing_id = :drawing_id')
                ->execute(['drawing_id' => $drawingId]);

            $insert = $pdo->prepare(
                'INSERT INTO part_files (
                    part_id, drawing_id, file_path, original_filename, mime_type, file_size,
                    version_no, is_current, uploaded_by
                 ) VALUES (
                    :part_id, :drawing_id, :file_path, :original_filename, :mime_type, :file_size,
                    :version_no, 1, :uploaded_by
                 )'
            );
            $insert->execute([
                'part_id' => $data['part_id'],
                'drawing_id' => $drawingId,
                'file_path' => $data['file_path'],
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'version_no' => $version,
                'uploaded_by' => $data['uploaded_by'],
            ]);

            return (int) $pdo->lastInsertId();
        });
    }

    /** The revision in force for a drawing, or null while it has none. */
    public static function current(int $drawingId): ?array
    {
        return Database::one(
            'SELECT * FROM part_files WHERE drawing_id = :drawing_id AND is_current = 1',
            ['drawing_id' => $drawingId]
        );
    }

    /** @return array<int,array<string,mixed>> every file belonging to a drawing */
    public static function forDrawing(int $drawingId): array
    {
        return Database::all(
            'SELECT * FROM part_files WHERE drawing_id = :drawing_id ORDER BY version_no DESC',
            ['drawing_id' => $drawingId]
        );
    }
}
