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
     * The note that currently speaks for a line asks for the line's whole
     * outstanding requirement, so that material rejected and sent back, or a
     * replacement for parts that failed, appears on the piece of paper the
     * client has in front of them rather than on a second one they have to
     * find. Superseded notes stay pegged to what they originally asked for, so
     * an old printout never quietly grows.
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

        $required = !empty($noteLine['is_standing_note'])
            ? $lineOutstanding
            : min($original, $lineOutstanding);

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
     * Reject material that arrived and cannot be used, hand it back, and ask
     * for it again.
     *
     * Takes every rejection recorded in one check-in together, because they are
     * one event: the material goes back in one parcel, so it goes back on one
     * note, with each reason listed on it. The rejections are separate rows —
     * "three cracked, two the wrong grade" is two different conversations to
     * have with the client — but one piece of paper.
     *
     * The replacement asks for exactly what was rejected, no more. That falls
     * out of the requirement being derived: rejected material counts as
     * received and is subtracted again by freeIssueOutstanding(), so rejecting
     * three puts three back on to what is owed and leaves the rest alone.
     *
     * A plain shortage does none of this. The material simply has not arrived,
     * and the note already out is the request for it.
     *
     * @param array<int,array{qty:int,reason:string}> $rejections
     * @return array{rejection_ids:array<int,int>,return_note_id:int,replacement_note_id:int,qty_rejected:int}
     */
    public static function rejectAndIssueNotes(int $orderLineId, array $rejections, int $userId): array
    {
        $rejectionIds = [];
        $summaries = [];
        $total = 0;

        foreach ($rejections as $rejection) {
            $qty = (int) $rejection['qty'];
            $reason = trim((string) $rejection['reason']);

            $rejectionIds[] = OrderLine::rejectFreeIssue($orderLineId, $qty, $reason, $userId);
            $summaries[] = $qty . ' × ' . $reason;
            $total += $qty;
        }

        $line = OrderLine::find($orderLineId);
        $clientId = self::clientIdForLine($line);

        $returnNoteId = DeliveryNote::createReturnNote(
            $clientId,
            [['order_line_id' => $orderLineId, 'qty' => $total]],
            $userId,
            'Rejected free-issue material returned. ' . implode('; ', $summaries)
        );

        self::buildStoredPdf($returnNoteId, '/staff/delivery-notes/' . $returnNoteId);

        $replacementNoteId = self::standingNoteFor($orderLineId, $userId);

        foreach ($rejectionIds as $rejectionId) {
            OrderLine::linkRejectionNotes($rejectionId, $returnNoteId, $replacementNoteId);
        }

        return [
            'rejection_ids' => $rejectionIds,
            'return_note_id' => $returnNoteId,
            'replacement_note_id' => $replacementNoteId,
            'qty_rejected' => $total,
        ];
    }

    /**
     * The one free-issue note that stands for a line, creating it if there is
     * none.
     *
     * Everything that needs more material — a rejection, a failure — points at
     * this rather than raising paperwork of its own, which is what keeps the
     * client looking at a single figure.
     */
    public static function standingNoteFor(int $orderLineId, int $userId): int
    {
        $existing = DeliveryNote::openFreeIssueNoteForLine($orderLineId);

        return $existing !== null
            ? (int) $existing['id']
            : self::generateForLine($orderLineId, $userId);
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
                'relatedNote' => $note['related_note_id'] !== null
                    ? DeliveryNote::find((int) $note['related_note_id'])
                    : null,
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
