<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A named drawing on a part, with its own revision history.
 *
 * A part used to have exactly one drawing lineage: upload a file and it became
 * the current revision of the only drawing there was. That is wrong for most
 * real parts. A fabrication has a general arrangement and a detail drawing per
 * sub-component; a machined part often has a separate drawing for a second
 * operation. Uploading the second one superseded the first, and the first
 * quietly became history — with nothing on the page to say the two were
 * different drawings rather than two revisions of one.
 *
 * So the drawing is the thing with a name, and `part_files` rows are its
 * revisions. The name lives here rather than on each file because it belongs
 * to the drawing and not to any one revision of it: renaming a drawing should
 * not mean rewriting its history.
 */
final class PartDrawing
{
    public const NAME_MAX = 120;

    /** @return array<int,array<string,mixed>> */
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT d.*, u.name AS created_by_name
               FROM part_drawings d
          LEFT JOIN users u ON u.id = d.created_by
              WHERE d.part_id = :part_id
              ORDER BY d.position, d.id',
            ['part_id' => $partId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM part_drawings WHERE id = :id', ['id' => $id]);
    }

    /** The drawing with this name on this part, if there is one. */
    public static function findByName(int $partId, string $name): ?array
    {
        return Database::one(
            'SELECT * FROM part_drawings WHERE part_id = :part_id AND name = :name',
            ['part_id' => $partId, 'name' => trim($name)]
        );
    }

    /**
     * Start a new drawing on a part.
     *
     * Returns the existing one if the name is already taken rather than
     * failing on the unique key. Two people uploading "Op 20 detail" minutes
     * apart mean the same drawing, and the second upload becoming its next
     * revision is what they both intended; a duplicate-key error at that
     * moment loses the file they just chose.
     */
    public static function create(int $partId, string $name, int $userId): int
    {
        $name = mb_substr(trim($name), 0, self::NAME_MAX);

        $existing = self::findByName($partId, $name);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $position = (int) Database::scalar(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM part_drawings WHERE part_id = :part_id',
            ['part_id' => $partId]
        );

        return Database::insert(
            'INSERT INTO part_drawings (part_id, name, position, created_by)
             VALUES (:part_id, :name, :position, :created_by)',
            ['part_id' => $partId, 'name' => $name, 'position' => $position, 'created_by' => $userId]
        );
    }

    /**
     * Rename a drawing.
     *
     * Refuses a name another drawing on the same part already holds, and says
     * so, rather than letting the unique key throw.
     *
     * @return string|null the reason it was refused, or null if it worked
     */
    public static function rename(int $id, string $name): ?string
    {
        $name = mb_substr(trim($name), 0, self::NAME_MAX);
        if ($name === '') {
            return 'A drawing needs a name — it is how it is told apart from the others.';
        }

        $drawing = self::find($id);
        if ($drawing === null) {
            return 'That drawing no longer exists.';
        }

        $clash = self::findByName((int) $drawing['part_id'], $name);
        if ($clash !== null && (int) $clash['id'] !== $id) {
            return 'This part already has a drawing called "' . $name . '".';
        }

        Database::query('UPDATE part_drawings SET name = :name WHERE id = :id', ['name' => $name, 'id' => $id]);

        return null;
    }

    /**
     * Delete a drawing and every revision of it.
     *
     * The rows go by cascade; the files on disk are the caller's to remove,
     * because this model has no business knowing where uploads live.
     */
    public static function delete(int $id): void
    {
        Database::query('DELETE FROM part_drawings WHERE id = :id', ['id' => $id]);
    }
}
