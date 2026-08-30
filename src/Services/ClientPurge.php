<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Upload;
use App\Models\Client;
use PDO;
use RuntimeException;

/**
 * Permanently deleting a client, and everything that was ever theirs.
 *
 * This is the one place in the application that really deletes. Everywhere
 * else archives or deactivates, for good reasons — history stays attributable
 * and mistakes stay recoverable — and none of those reasons apply to a company
 * Junction has finished with and decided to remove. So this exists, and it is
 * deliberately hard to reach: staff.admin only, only for an account that has
 * already been switched off, and only after the client's name has been typed
 * out in full.
 *
 * Two things make it more than a `DELETE FROM clients`.
 *
 * The foreign keys are mostly RESTRICT rather than CASCADE, which is the right
 * default — it is what stops an ordinary mistake taking an order's history with
 * it — but it means the rows have to be removed in dependency order by hand.
 * The order below is that dependency graph read backwards, and it is not
 * arbitrary: invoices hold delivery notes, delivery note lines hold order
 * lines, order lines hold parts, and everything holds users.
 *
 * And the files have to be collected *before* the rows go. A path only exists
 * in the row that points at it, so deleting the rows first leaves the drawings,
 * photos and delivery notes on disk with nothing left to say whose they were.
 */
final class ClientPurge
{
    /**
     * What deleting this client would take with it.
     *
     * Shown on the confirmation, because "this cannot be undone" means very
     * little next to "this deletes 9 orders, 6 parts and 41 files".
     *
     * @return array<string,int>
     */
    public static function summary(int $clientId): array
    {
        $count = static fn (string $sql): int => (int) Database::scalar($sql, ['id' => $clientId]);

        return [
            'orders' => $count('SELECT COUNT(*) FROM orders WHERE client_id = :id'),
            'order lines' => $count(
                'SELECT COUNT(*) FROM order_lines ol JOIN orders o ON o.id = ol.order_id WHERE o.client_id = :id'
            ),
            'parts' => $count('SELECT COUNT(*) FROM parts WHERE client_id = :id'),
            'delivery notes' => $count('SELECT COUNT(*) FROM delivery_notes WHERE client_id = :id'),
            'invoices' => $count(
                'SELECT COUNT(*) FROM invoices i JOIN delivery_notes d ON d.id = i.delivery_note_id WHERE d.client_id = :id'
            ),
            'user accounts' => $count('SELECT COUNT(*) FROM users WHERE client_id = :id'),
            'files' => count(self::filePaths($clientId)),
        ];
    }

    /**
     * Every file on disk belonging to this client.
     *
     * Drawings and their revisions, the part media library, order photos and
     * documents, purchase orders, and the delivery note PDFs. Thumbnails
     * count separately because they are separate files.
     *
     * @return array<int,string> relative paths, as stored
     */
    public static function filePaths(int $clientId): array
    {
        $sql = [
            // Drawings: every revision of every named drawing.
            'SELECT f.file_path AS p, NULL AS t FROM part_files f
               JOIN parts pt ON pt.id = f.part_id WHERE pt.client_id = :id',
            // The part media library, which keeps a thumbnail beside each image.
            'SELECT m.file_path AS p, m.thumb_path AS t FROM part_media m
               JOIN parts pt ON pt.id = m.part_id WHERE pt.client_id = :id',
            // Anything attached to one of their orders.
            'SELECT ph.file_path AS p, ph.thumb_path AS t FROM order_photos ph
               JOIN orders o ON o.id = ph.order_id WHERE o.client_id = :id',
            'SELECT d.file_path AS p, NULL AS t FROM order_po_documents d
               JOIN orders o ON o.id = d.order_id WHERE o.client_id = :id',
            // The purchase order the order was placed with.
            'SELECT o.po_file_path AS p, NULL AS t FROM orders o
              WHERE o.client_id = :id AND o.po_file_path IS NOT NULL',
            // Delivery notes that keep their PDF. Free-issue notes and route
            // cards are rendered live and never stored, so there is nothing of
            // theirs to remove.
            'SELECT dn.pdf_file_path AS p, NULL AS t FROM delivery_notes dn
              WHERE dn.client_id = :id AND dn.pdf_file_path IS NOT NULL',
        ];

        $paths = [];
        foreach ($sql as $query) {
            foreach (Database::all($query, ['id' => $clientId]) as $row) {
                foreach ([$row['p'], $row['t']] as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $paths[$path] = true;
                    }
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Delete the client, their data and their files.
     *
     * The rows go inside one transaction, so a failure part-way leaves the
     * client whole rather than half-deleted. The files are removed afterwards,
     * deliberately: a rolled-back transaction can put rows back and nothing can
     * put a file back, so the irreversible half only runs once the reversible
     * half has committed.
     *
     * @return array{rows:array<string,int>,files_deleted:int,files_missing:int}
     */
    public static function purge(int $clientId): array
    {
        $client = Client::find($clientId);

        if ($client === null) {
            throw new RuntimeException('That client does not exist.');
        }

        if ((bool) $client['is_active']) {
            throw new RuntimeException(
                'This account is still active. Switch it off first — deleting is only ever done to an '
                . 'account somebody has already decided to stop using.'
            );
        }

        $summary = self::summary($clientId);
        $paths = self::filePaths($clientId);

        Database::transaction(static function (PDO $pdo) use ($clientId): void {
            $run = static function (string $sql) use ($pdo, $clientId): void {
                $pdo->prepare($sql)->execute(['id' => $clientId]);
            };

            // Dependency order. Each step removes what the next step is
            // blocked by; the CASCADEs below each one do the rest.
            $run('DELETE i FROM invoices i
                    JOIN delivery_notes d ON d.id = i.delivery_note_id
                   WHERE d.client_id = :id');

            // Rejections point at notes with SET NULL, so they survive this and
            // go with their order line below.
            $run('DELETE FROM delivery_notes WHERE client_id = :id');

            // Takes order lines and everything hanging off them: quantities,
            // stage moves, due dates, change requests, receipts, rejections,
            // photos and their part tags, notes, queries and replies, PO
            // documents.
            $run('DELETE FROM orders WHERE client_id = :id');

            // Now nothing references the parts. Takes drawings and their
            // revisions, the media library, price breaks, quote drafts and
            // lines, time entries, alternate numbers and links.
            $run('DELETE FROM parts WHERE client_id = :id');

            // And now nothing references their people. Takes roles, invites
            // and notification preferences.
            $run('DELETE FROM users WHERE client_id = :id');

            $run('DELETE FROM clients WHERE id = :id');
        });

        $deleted = 0;
        $missing = 0;
        foreach ($paths as $path) {
            $absolute = Upload::absolutePath($path);
            if ($absolute !== null && is_file($absolute)) {
                Upload::delete($path);
                $deleted++;
            } else {
                $missing++;
            }
        }

        self::removeEmptyDirectories();

        return ['rows' => $summary, 'files_deleted' => $deleted, 'files_missing' => $missing];
    }

    /**
     * Tidy up the per-part and per-order folders left behind.
     *
     * Uploads are filed under `drawings/{part}` and `order-photos/{order}`, so
     * deleting a client's files empties those directories without removing
     * them. Only empty ones go, so nothing belonging to anybody else can be
     * caught by this.
     */
    private static function removeEmptyDirectories(): void
    {
        $root = rtrim((string) \App\Core\Config::get('storage.uploads'), '/\\');

        foreach (['drawings', 'order-photos', 'part-media', 'pos', 'delivery-notes', 'route-cards'] as $area) {
            foreach (glob($root . '/' . $area . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                // Images keep their thumbnails in a `thumbs` subdirectory, so
                // the parent is not empty until that has gone first. Innermost
                // out, one level at a time.
                foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
                    if ((glob($child . '/*') ?: []) === []) {
                        @rmdir($child);
                    }
                }

                if ((glob($dir . '/*') ?: []) === []) {
                    @rmdir($dir);
                }
            }
        }
    }
}
