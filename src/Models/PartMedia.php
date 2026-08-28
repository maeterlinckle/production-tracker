<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * The reference material that belongs to a part rather than to one order.
 *
 * A photo of the finished part, the setup on the machine, the settings sheet,
 * the tooling files for the job: all of it describes the part, and all of it is
 * wanted again the next time that part comes round. It used to live on the
 * order, where it was invisible to whoever set the same part up six months
 * later.
 *
 * One table with a `kind` rather than a table per sort of file. A tooling file
 * is a file attached to a part in exactly the way a PDF of the machine settings
 * is; giving it its own system would mean two upload paths, two listings, and
 * two places somebody has to think to look.
 */
final class PartMedia
{
    public const KINDS = ['photo', 'document', 'tooling'];

    public const KIND_LABELS = [
        'photo' => 'Photo',
        'document' => 'Document',
        'tooling' => 'Tooling / setup file',
    ];

    /** What each kind is for, on the upload form. */
    public const KIND_HINTS = [
        'photo' => 'The finished part, the setup on the machine, a fixture in place.',
        'document' => 'Machine settings, a setup sheet, an inspection report — PDFs and images.',
        'tooling' => 'CNC programs, tool lists, CAM files. Kept for the next time this part runs.',
    ];

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM part_media WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT m.*, u.name AS uploaded_by_name FROM part_media m
               JOIN users u ON u.id = m.uploaded_by
              WHERE m.part_id = :part_id
              ORDER BY m.is_main IS NULL, m.kind, m.uploaded_at DESC',
            ['part_id' => $partId]
        );
    }

    /**
     * The part's representative image, for the part page and any listing that
     * wants a thumbnail.
     */
    /**
     * The main photo for each of several parts, keyed by part id.
     *
     * One query for a whole listing. The alternative is a lookup per row, and
     * the parts list is the longest table in the application.
     *
     * Only parts that have one appear in the result, so a caller can treat a
     * missing key as "no photo" without a second check.
     *
     * @param array<int,int> $partIds
     * @return array<int,array<string,mixed>>
     */
    public static function mainPhotosFor(array $partIds): array
    {
        $partIds = array_values(array_unique(array_map('intval', $partIds)));
        if ($partIds === []) {
            return [];
        }

        // Cast to int immediately above, so interpolating is the same string a
        // placeholder list would have produced, and this stays one query.
        $rows = Database::all(
            'SELECT id, part_id, caption, thumb_path FROM part_media
              WHERE is_main = 1 AND part_id IN (' . implode(',', $partIds) . ')'
        );

        $byPart = [];
        foreach ($rows as $row) {
            $byPart[(int) $row['part_id']] = $row;
        }

        return $byPart;
    }

    public static function mainPhoto(int $partId): ?array
    {
        return Database::one(
            'SELECT * FROM part_media WHERE part_id = :part_id AND is_main = 1',
            ['part_id' => $partId]
        );
    }

    /**
     * Everything that is not the main photo, grouped by kind so the page can
     * show them under headings.
     *
     * @param array<int,array<string,mixed>> $media
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function groupAttachments(array $media): array
    {
        $grouped = array_fill_keys(self::KINDS, []);

        foreach ($media as $item) {
            if (!empty($item['is_main'])) {
                continue;
            }
            $grouped[$item['kind']][] = $item;
        }

        return array_filter($grouped, static fn (array $items): bool => $items !== []);
    }

    /**
     * Store a file against a part.
     *
     * Making something the main photo demotes whatever held that place: the
     * unique key on (part_id, is_main) would otherwise refuse the insert, and a
     * failed upload is a poor way to say "there is already one of those".
     */
    public static function create(array $data): int
    {
        $kind = in_array($data['kind'] ?? 'photo', self::KINDS, true) ? $data['kind'] : 'photo';
        $isMain = !empty($data['is_main']) && $kind === 'photo';

        return Database::transaction(static function (PDO $pdo) use ($data, $kind, $isMain): int {
            if ($isMain) {
                $pdo->prepare('UPDATE part_media SET is_main = NULL WHERE part_id = :part_id AND is_main = 1')
                    ->execute(['part_id' => $data['part_id']]);
            }

            $statement = $pdo->prepare(
                'INSERT INTO part_media (
                    part_id, kind, is_main, caption, file_path, thumb_path, original_filename, mime_type, file_size, uploaded_by
                 ) VALUES (
                    :part_id, :kind, :is_main, :caption, :file_path, :thumb_path, :original_filename, :mime_type, :file_size, :uploaded_by
                 )'
            );
            $statement->execute([
                'part_id' => $data['part_id'],
                'kind' => $kind,
                'is_main' => $isMain ? 1 : null,
                'caption' => $data['caption'] ?? null,
                'file_path' => $data['file_path'],
                'thumb_path' => $data['thumb_path'] ?? null,
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'uploaded_by' => $data['uploaded_by'],
            ]);

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Change what a file is described as.
     *
     * A caption is written when the file is uploaded, which is the worst
     * moment to write one: the person doing it is mid-upload and has not yet
     * seen the thing in context. Being able to change it afterwards is what
     * turns "IMG_4471.jpg" into something the next person can use.
     *
     * An empty box clears the caption rather than storing a blank one, so the
     * tile falls back to the filename instead of showing an empty line.
     */
    public static function updateCaption(int $id, ?string $caption): void
    {
        $caption = $caption === null ? null : (trim($caption) ?: null);

        Database::query(
            'UPDATE part_media SET caption = :caption WHERE id = :id',
            ['caption' => $caption, 'id' => $id]
        );
    }

    /** Promote an existing photo to be the part's main one. */
    public static function setMain(int $id): void
    {
        $item = self::find($id);
        if ($item === null || $item['kind'] !== 'photo') {
            return;
        }

        Database::transaction(static function (PDO $pdo) use ($item, $id): void {
            $pdo->prepare('UPDATE part_media SET is_main = NULL WHERE part_id = :part_id AND is_main = 1')
                ->execute(['part_id' => $item['part_id']]);
            $pdo->prepare('UPDATE part_media SET is_main = 1 WHERE id = :id')->execute(['id' => $id]);
        });
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM part_media WHERE id = :id', ['id' => $id]);
    }

    /** Photos render inline; everything else is a link with its filename. */
    public static function isImage(array $item): bool
    {
        return str_starts_with((string) ($item['mime_type'] ?? ''), 'image/');
    }
}
