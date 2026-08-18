<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\OrderLine;

/**
 * Generates a free-issue delivery note for a single order line, with a QR
 * code that deep-links straight to that line's staff check-in page (item 6)
 * -- one note per line, so the QR is always unambiguous about which line
 * it's confirming.
 */
final class FreeIssueNoteService
{
    public static function generateForLine(int $orderLineId, int $issuedBy): int
    {
        $line = OrderLine::find($orderLineId);

        $id = DeliveryNote::createFreeIssueNote(
            self::clientIdForLine($line),
            [['order_line_id' => $orderLineId, 'qty' => $line['qty_free_issue_required']]],
            $issuedBy
        );

        self::buildPdf($id, '/staff/lines/' . $orderLineId . '/check-in');

        return $id;
    }

    private static function clientIdForLine(array $line): int
    {
        return (int) \App\Core\Database::one(
            'SELECT client_id FROM orders WHERE id = :order_id',
            ['order_id' => $line['order_id']]
        )['client_id'];
    }

    private static function buildPdf(int $deliveryNoteId, string $qrPath): void
    {
        $note = DeliveryNote::find($deliveryNoteId);
        $client = Client::find((int) $note['client_id']);
        $lines = DeliveryNote::lines($deliveryNoteId);

        $qr = QrCodeService::pngDataUri(QrCodeService::jobUrl($qrPath));

        $relativePath = PdfService::renderAndStore(
            'pdf/delivery-note',
            ['deliveryNote' => $note, 'client' => $client, 'lines' => $lines, 'qrDataUri' => $qr],
            'delivery-notes/' . $note['client_id'],
            $note['reference'] . '.pdf'
        );

        DeliveryNote::setPdfPath($deliveryNoteId, $relativePath);
    }
}
