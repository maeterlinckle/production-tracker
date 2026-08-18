<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Symmetric "usually ordered with" links between parts of the same client. */
final class PartLink
{
    /** Parts linked to $partId, from either side of the stored pair. */
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT p.* FROM part_links pl
             JOIN parts p ON p.id = IF(pl.part_id = :id1, pl.linked_part_id, pl.part_id)
             WHERE (pl.part_id = :id2 OR pl.linked_part_id = :id3) AND p.is_archived = 0
             ORDER BY p.cpn',
            ['id1' => $partId, 'id2' => $partId, 'id3' => $partId]
        );
    }

    public static function link(int $partId, int $otherPartId, int $createdBy): void
    {
        if ($partId === $otherPartId) {
            return;
        }

        [$low, $high] = $partId < $otherPartId ? [$partId, $otherPartId] : [$otherPartId, $partId];

        Database::query(
            'INSERT IGNORE INTO part_links (part_id, linked_part_id, created_by) VALUES (:low, :high, :created_by)',
            ['low' => $low, 'high' => $high, 'created_by' => $createdBy]
        );
    }

    public static function unlink(int $partId, int $otherPartId): void
    {
        [$low, $high] = $partId < $otherPartId ? [$partId, $otherPartId] : [$otherPartId, $partId];

        Database::query('DELETE FROM part_links WHERE part_id = :low AND linked_part_id = :high', ['low' => $low, 'high' => $high]);
    }
}
