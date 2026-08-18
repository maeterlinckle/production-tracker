<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\OrderLine;

/**
 * Free-issue material paperwork: the request that goes out with an order, the
 * return note when material has to go back, and the replacement request that
 * follows it.
 *
 * A free-issue note is a standing request rather than a record of a shipment,
 * so it is a living document (item 7). What it asks for is worked out when it
 * is rendered, from what the line still needs: check something in and the note
 * asks for less, reject something and it asks for it again. Its PDF is
 * therefore never stored — the copy on disk could only be the version that was
 * true on the day it was written.
 *
 * Goods-out and return notes are the opposite: they record a movement that
 * happened, so their quantities are frozen and their PDFs are kept.
 *
 * One outstanding request per line, reissued rather than duplicated. Two notes
 * asking for overlapping material is the state in which somebody sends twice,
 * or sends nothing because they assume the other note covers it.
 */
final class FreeIssueNoteService
{
    /** Issue the note that goes out with a new order line. */
    public static function generateForLine(int $orderLineId, int $issuedBy): int
    {
        $line = OrderLine::find($orderLineId);

        return DeliveryNote::createFreeIssueNote(
            self::clientIdForLine($line),
            [['order_line_id' => $orderLineId, 'qty' => $line['qty_free_issue_required']]],
            $issuedBy
        );
    }

    /**
     * What a free-issue note line should print today.
     *
     * The quantity asked for is capped at what this note originally covered:
     * with the usual one note per line the cap never bites, and where staff have
     * built a note covering part of a line it stops that note quietly growing
     * into a request for the whole thing.
     *
     * @param array<string,mixed> $noteLine a row from DeliveryNote::lines()
     * @return array{required:int,original:int,received:int,rejected:int,outstanding_sentence:string}
     */
    public static function noteLineFigures(array $noteLine): array
    {
        $original = (int) $noteLine['qty'];
        $received = (int) $noteLine['qty_free_issue_received'];
        $rejected = (int) $noteLine['qty_free_issue_rejected'];

        $lineOutstanding = OrderLine::freeIssueOutstanding([
            'qty_free_issue_required' => (int) $noteLine['qty_free_issue_required'],
            'qty_free_issue_received' => $received,
            'qty_free_issue_rejected' => $rejected,
        ]);

        $required = min($original, $lineOutstanding);

        return [
            'required' => $required,
            'original' => $original,
            'received' => $received,
            'rejected' => $rejected,
            'outstanding_sentence' => self::outstandingSentence(
                (int) $noteLine['qty_free_issue_required'],
                $received,
                $rejected,
                $lineOutstanding
            ),
        ];
    }

    /** "8 of 20 already received — 12 still required." */
    public static function outstandingSentence(int $required, int $received, int $rejected, int $outstanding): string
    {
        if ($received === 0 && $rejected === 0) {
            return 'Nothing received against this line yet.';
        }

        $sentence = $received . ' of ' . $required . ' already received';

        if ($rejected > 0) {
            $sentence .= ', ' . $rejected . ' of it rejected and to be replaced';
        }

        return $sentence . ' — ' . $outstanding . ' still required.';
    }

    /**
     * Reject received material, hand it back, and ask for it again (item 6).
     *
     * Three things happen and they belong together: the material is recorded as
     * received-wrong, a return note is raised for what is going back, and the
     * line's requirement goes up by the same amount so the standing free-issue
     * request asks for a replacement. A plain shortage does none of this — the
     * material simply has not arrived yet, and the request already covers it.
     *
     * @return array{rejection_id:int,return_note_id:int,replacement_note_id:int}
     */
    public static function rejectAndIssueNotes(int $orderLineId, int $qty, string $reason, int $userId): array
    {
        $rejectionId = OrderLine::rejectFreeIssue($orderLineId, $qty, $reason, $userId);

        $line = OrderLine::find($orderLineId);
        $clientId = self::clientIdForLine($line);

        $returnNoteId = DeliveryNote::createReturnNote(
            $clientId,
            [['order_line_id' => $orderLineId, 'qty' => $qty]],
            $userId,
            'Rejected free-issue material returned. Reason: ' . trim($reason)
        );

        self::buildStoredPdf($returnNoteId, '/staff/delivery-notes/' . $returnNoteId);

        // The replacement is the same request, asking again. Raising the
        // requirement is what makes it reappear on the note that is already out.
        OrderLine::addFreeIssueRequirement($orderLineId, $qty);

        $replacement = DeliveryNote::openFreeIssueNoteForLine($orderLineId);
        $replacementNoteId = $replacement !== null
            ? (int) $replacement['id']
            : self::generateForLine($orderLineId, $userId);

        OrderLine::linkRejectionNotes($rejectionId, $returnNoteId, $replacementNoteId);

        return [
            'rejection_id' => $rejectionId,
            'return_note_id' => $returnNoteId,
            'replacement_note_id' => $replacementNoteId,
        ];
    }

    /**
     * Ask for replacement material for parts that failed in production.
     *
     * The other half of the rule that only failed or rejected quantity triggers
     * a new request: a shortage is already covered by the outstanding one.
     *
     * @return int the delivery note the request now sits on
     */
    public static function requestReplacementForFailures(int $orderLineId, int $materialQty, int $userId): int
    {
        OrderLine::addFreeIssueRequirement($orderLineId, $materialQty);

        $existing = DeliveryNote::openFreeIssueNoteForLine($orderLineId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return self::generateForLine($orderLineId, $userId);
    }

    private static function clientIdForLine(array $line): int
    {
        if (isset($line['client_id'])) {
            return (int) $line['client_id'];
        }

        return (int) Database::one(
            'SELECT client_id FROM orders WHERE id = :order_id',
            ['order_id' => $line['order_id']]
        )['client_id'];
    }

    /** Render and keep a PDF, for the notes that record something rather than ask for it. */
    public static function buildStoredPdf(int $deliveryNoteId, string $qrPath): void
    {
        $note = DeliveryNote::find($deliveryNoteId);
        $client = Client::find((int) $note['client_id']);

        $relativePath = PdfService::renderAndStore(
            'pdf/delivery-note',
            [
                'deliveryNote' => $note,
                'client' => $client,
                'lines' => DeliveryNote::lines($deliveryNoteId),
                'qrDataUri' => QrCodeService::pngDataUri(QrCodeService::jobUrl($qrPath)),
            ],
            'delivery-notes/' . $note['client_id'],
            $note['reference'] . '.pdf'
        );

        DeliveryNote::setPdfPath($deliveryNoteId, $relativePath);
    }

    /**
     * Render a free-issue note now, without keeping it.
     *
     * @return array{bytes:string,filename:string}
     */
    public static function renderLive(int $deliveryNoteId): array
    {
        $note = DeliveryNote::find($deliveryNoteId);
        $client = Client::find((int) $note['client_id']);
        $lines = DeliveryNote::lines($deliveryNoteId);

        // The QR goes to the line's own check-in screen when the note covers a
        // single line, which is what a phone in the goods-in bay wants. A note
        // spanning several lines has no single check-in to point at.
        $qrPath = count($lines) === 1
            ? '/staff/lines/' . $lines[0]['order_line_id'] . '/check-in'
            : '/staff/delivery-notes/' . $deliveryNoteId;

        $bytes = PdfService::render('pdf/delivery-note', [
            'deliveryNote' => $note,
            'client' => $client,
            'lines' => $lines,
            'qrDataUri' => QrCodeService::pngDataUri(QrCodeService::jobUrl($qrPath)),
        ]);

        return ['bytes' => $bytes, 'filename' => $note['reference'] . '.pdf'];
    }
}
