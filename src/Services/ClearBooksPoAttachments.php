<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Upload;
use App\Models\OrderPoDocument;
use RuntimeException;
use Throwable;

/**
 * Putting the client's own purchase order on the Clear Books invoice.
 *
 * The first question anybody in accounts payable asks about an invoice is
 * "what did we order?", and the answer is a PDF sitting in this tracker that
 * nobody at their end can see. Attaching it to the invoice puts the
 * authorisation and the bill in the same place, which is where the two get
 * matched.
 *
 * The whole PO history goes up, not just the first one. Junction never replaces
 * a purchase order — an amended quantity arrives as a second document and both
 * are kept, because the original is what the original price was agreed against
 * (see App\Models\OrderPoDocument). An invoice raised after an amendment is
 * authorised by both pieces of paper, so both are attached.
 *
 * Nothing in here is allowed to throw. By the time it runs the invoice exists
 * in Clear Books and has been recorded here; losing that over a missing file on
 * disk would be a far worse outcome than an invoice with no attachment. Every
 * failure comes back as a sentence for the person who pressed the button.
 */
final class ClearBooksPoAttachments
{
    /**
     * Attach every purchase order behind a delivery note to its invoice.
     *
     * @param array<int,array<string,mixed>> $lines the delivery note's lines, as DeliveryNote::lines() returns them
     * @return array{attached:array<int,string>,problems:array<int,string>}
     */
    public static function push(ClearBooksPosting $posting, int $invoiceId, array $lines): array
    {
        $attached = [];
        $problems = [];
        $used = [];

        foreach (self::documents($lines) as $document) {
            $name = self::fileName($document, $used);

            try {
                $contents = self::read($document);
            } catch (Throwable $e) {
                $problems[] = $e->getMessage();
                continue;
            }

            try {
                ClearBooksClient::attachToSalesInvoice($posting->businessId, $invoiceId, $name, $contents);
                $attached[] = $name;
            } catch (Throwable $e) {
                $problems[] = $name . ': ' . $e->getMessage();
            }
        }

        return ['attached' => $attached, 'problems' => $problems];
    }

    /**
     * Every PO document behind the orders a delivery note covers, oldest first.
     *
     * A note can carry lines from more than one order, so the orders are taken
     * from the lines rather than assumed to be one. Deduplicated by document id
     * because two lines on the same order would otherwise bring the same PDF
     * back twice.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private static function documents(array $lines): array
    {
        $orderIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['order_id'],
            $lines
        )));

        $orderNumbers = [];
        foreach ($lines as $line) {
            $orderNumbers[(int) $line['order_id']] = (string) ($line['order_number'] ?? '');
        }

        $documents = [];

        foreach ($orderIds as $orderId) {
            foreach (OrderPoDocument::forOrder($orderId) as $document) {
                $document['order_number'] = $orderNumbers[$orderId] ?? '';
                $documents[(int) $document['id']] = $document;
            }
        }

        return array_values($documents);
    }

    /**
     * The file on disk, or an explanation of why it is not there.
     *
     * @param array<string,mixed> $document
     */
    private static function read(array $document): string
    {
        $absolute = Upload::absolutePath((string) $document['file_path']);

        if ($absolute === null || !is_file($absolute)) {
            throw new RuntimeException(
                (string) $document['original_filename'] . ' is recorded against the order but missing from storage, '
                . 'so it could not be attached.'
            );
        }

        $contents = @file_get_contents($absolute);

        if ($contents === false) {
            throw new RuntimeException(
                (string) $document['original_filename'] . ' could not be read from storage, so it could not be attached.'
            );
        }

        return $contents;
    }

    /**
     * What the attachment is called in Clear Books.
     *
     * The name is a path segment in their URL rather than a field in a body, so
     * it is sanitised down to something that survives being one: no separators,
     * no control characters, no runs of whitespace. The order number goes in
     * front because an invoice covering two orders otherwise arrives as two
     * files called "purchase order.pdf" and nobody can tell which is which.
     *
     * @param array<string,mixed> $document
     * @param array<string,true>  $used names already claimed on this invoice, by reference
     */
    private static function fileName(array $document, array &$used): string
    {
        $original = (string) $document['original_filename'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($original, PATHINFO_FILENAME);

        $prefix = trim((string) ($document['order_number'] ?? ''));
        $stem = trim(($prefix !== '' ? $prefix . ' ' : '') . $stem);

        $stem = (string) preg_replace('~[\\\\/:*?"<>|%#]+~', '-', $stem);
        $stem = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $stem);
        $stem = trim((string) preg_replace('/\s+/', ' ', $stem));
        $stem = $stem === '' ? 'purchase-order' : mb_substr($stem, 0, 100);

        $name = $extension === '' ? $stem : $stem . '.' . $extension;

        // Two amendments uploaded under the same filename on the same order.
        $suffix = 2;
        while (isset($used[strtolower($name)])) {
            $name = $stem . ' (' . $suffix++ . ')' . ($extension === '' ? '' : '.' . $extension);
        }

        $used[strtolower($name)] = true;

        return $name;
    }
}
