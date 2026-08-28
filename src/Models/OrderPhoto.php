<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * What got attached to an order: photos, and the odd document.
 *
 * The table is still called `order_photos` because that is what it started as
 * and what everything referring to it calls it; what it holds is any file
 * attached to an order, and `mime_type` has always been the thing that decides
 * whether it renders as a picture or as a link.
 *
 * An attachment may say which part or parts it shows — see migration 012 and
 * `setParts()`. That is a different fact from `order_line_id`, which says which
 * line it was filed against.
 */
final class OrderPhoto
{
    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT p.*, u.name AS uploaded_by_name
               FROM order_photos p
          LEFT JOIN users u ON u.id = p.uploaded_by
              WHERE p.order_id = :order_id
              ORDER BY p.uploaded_at DESC',
            ['order_id' => $orderId]
        );
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

    /**
     * Change what an attachment is described as.
     *
     * An empty box clears the caption rather than storing a blank one, so a
     * description somebody has thought better of goes back to the filename
     * instead of leaving an empty line where a description used to be.
     */
    public static function updateCaption(int $id, ?string $caption): void
    {
        $caption = $caption === null ? null : (trim($caption) ?: null);

        Database::query(
            'UPDATE order_photos SET caption = :caption WHERE id = :id',
            ['caption' => $caption, 'id' => $id]
        );
    }

    /**
     * Say which parts an attachment shows.
     *
     * Written as a set: what is passed in becomes the whole of it. Ticking a
     * box and unticking another are the same operation from the form's point of
     * view, and reconciling them one at a time is how the two halves get out of
     * step.
     *
     * @param array<int,int> $partIds
     */
    public static function setParts(int $photoId, array $partIds): void
    {
        $partIds = array_values(array_unique(array_map('intval', $partIds)));

        Database::transaction(static function (PDO $pdo) use ($photoId, $partIds): void {
            $pdo->prepare('DELETE FROM order_photo_parts WHERE order_photo_id = :id')
                ->execute(['id' => $photoId]);

            if ($partIds === []) {
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO order_photo_parts (order_photo_id, part_id) VALUES (:photo_id, :part_id)'
            );
            foreach ($partIds as $partId) {
                $statement->execute(['photo_id' => $photoId, 'part_id' => $partId]);
            }
        });
    }

    /**
     * The parts each of these attachments shows, keyed by attachment id.
     *
     * One query for a whole page rather than one per tile.
     *
     * @param array<int,int> $photoIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function partsFor(array $photoIds): array
    {
        $photoIds = array_values(array_unique(array_map('intval', $photoIds)));
        if ($photoIds === []) {
            return [];
        }

        // Cast to int immediately above, so interpolating is the same string a
        // placeholder list would have produced, and this stays one query.
        $rows = Database::all(
            'SELECT op.order_photo_id, p.id, p.cpn, p.name
               FROM order_photo_parts op
               JOIN parts p ON p.id = op.part_id
              WHERE op.order_photo_id IN (' . implode(',', $photoIds) . ')
              ORDER BY p.cpn'
        );

        $byPhoto = [];
        foreach ($rows as $row) {
            $byPhoto[(int) $row['order_photo_id']][] = $row;
        }

        return $byPhoto;
    }

    /**
     * Everything attached to an order that says it shows this part.
     *
     * This is the part page's side of the link: the reason for tagging in the
     * first place is that the photo is wanted from the part, by somebody who
     * has no idea which order it was taken on. The order comes back with it so
     * the tile can say where it is from.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT ph.*, o.id AS order_id, o.order_number, u.name AS uploaded_by_name
               FROM order_photo_parts op
               JOIN order_photos ph ON ph.id = op.order_photo_id
               JOIN orders o        ON o.id  = ph.order_id
          LEFT JOIN users u         ON u.id  = ph.uploaded_by
              WHERE op.part_id = :part_id
              ORDER BY ph.uploaded_at DESC',
            ['part_id' => $partId]
        );
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM order_photos WHERE id = :id', ['id' => $id]);
    }

    /** Pictures render inline; everything else is a link with its filename. */
    public static function isImage(array $item): bool
    {
        return str_starts_with((string) ($item['mime_type'] ?? ''), 'image/');
    }
}
