<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use PDO;
use RuntimeException;

/**
 * Finished parts coming back because the client rejected them.
 *
 * The one movement in the system that starts at the client's end and finishes
 * at Junction's. It is deliberately two steps and not one:
 *
 *   - the client raises the note, which is a declaration of intent and a piece
 *     of paper to put in the box. Nothing on the order moves;
 *   - staff book the parts in when they arrive, and *that* is what moves
 *     quantity — into Failed, the bucket that already means "made, not
 *     acceptable, still owed".
 *
 * The split matters because the two facts are genuinely different. Parts
 * declared as coming back are not the same as parts on the bench, and an order
 * that quietly wrote off a dozen finished parts the moment somebody filled in a
 * form would be lying about what Junction is holding.
 *
 * Everything here is in final parts. There is no unit conversion to do: the
 * stages this touches are all on the finished side of the machine.
 */
final class PartsReturnService
{
    /**
     * Raise the note.
     *
     * The quantity is bounded by what went out on the despatch being named,
     * less anything already raised against that same despatch and part. That
     * bound is per-note on purpose — the same part on two deliveries is two
     * allowances, because the client is telling us which parcel the bad parts
     * came out of.
     */
    public static function raise(
        int $orderId,
        int $goodsOutNoteId,
        int $orderLineId,
        int $qty,
        string $problem,
        int $userId
    ): int {
        $problem = trim($problem);

        if ($problem === '') {
            throw new RuntimeException('Describe what is wrong with the parts — it goes on the note Junction reads.');
        }

        if ($qty <= 0) {
            throw new RuntimeException('Enter how many parts are coming back.');
        }

        $candidate = self::returnableLine($orderId, $goodsOutNoteId, $orderLineId);
        if ($candidate === null) {
            throw new RuntimeException('That part is not on that delivery note.');
        }

        $allowance = (int) $candidate['qty_sent'] - (int) $candidate['qty_already_returned'];
        if ($qty > $allowance) {
            throw new RuntimeException(
                $allowance === 0
                    ? 'Everything sent on ' . $candidate['reference'] . ' for that part has already been raised as a return.'
                    : 'Only ' . $allowance . ' of that part can still be returned against ' . $candidate['reference'] . '.'
            );
        }

        $order = Order::find($orderId);

        $noteId = DeliveryNote::createPartsReturnNote(
            (int) $order['client_id'],
            $goodsOutNoteId,
            $orderLineId,
            $qty,
            $userId,
            $problem
        );

        // A record of a movement, so the PDF is written once and kept — unlike
        // a free-issue note, what this asks for will never change.
        FreeIssueNoteService::buildStoredPdf($noteId, '/delivery-notes/' . $noteId . '/pdf');

        return $noteId;
    }

    /**
     * Book returned parts in.
     *
     * One transaction over the receipt and the quantity moving, for the same
     * reason as the free-issue check-in: a receipt recorded against parts that
     * did not move is worse than no receipt at all.
     *
     * @return array{qty:int,taken:array<string,int>,outstanding:int}
     */
    public static function checkIn(int $noteId, int $qty, ?string $notes, int $userId): array
    {
        $note = DeliveryNote::find($noteId);
        if ($note === null || $note['type'] !== 'parts_return') {
            throw new RuntimeException('That is not a rejected-parts return note.');
        }

        if ($qty <= 0) {
            throw new RuntimeException('Enter how many parts have arrived.');
        }

        $lines = DeliveryNote::lines($noteId);
        if ($lines === []) {
            throw new RuntimeException('That return note has nothing on it.');
        }

        $line = $lines[0];
        $declared = (int) $line['qty'];
        $alreadyIn = DeliveryNote::qtyCheckedIn($noteId);
        $outstanding = $declared - $alreadyIn;

        if ($outstanding <= 0) {
            throw new RuntimeException('Everything on ' . $note['reference'] . ' has already been booked in.');
        }

        if ($qty > $outstanding) {
            throw new RuntimeException(
                'Only ' . $outstanding . ' of the ' . $declared . ' on ' . $note['reference'] . ' are still to arrive.'
            );
        }

        $orderLineId = (int) $line['order_line_id'];
        $reason = 'Rejected by the client and returned on ' . $note['reference']
            . ($note['notes'] ? ' — ' . $note['notes'] : '');

        $taken = Database::transaction(static function (PDO $pdo) use ($noteId, $orderLineId, $qty, $notes, $userId, $reason): array {
            $pdo->prepare(
                'INSERT INTO parts_return_receipts (delivery_note_id, order_line_id, qty_received, notes, received_by)
                 VALUES (:note_id, :line_id, :qty, :notes, :user_id)'
            )->execute([
                'note_id' => $noteId,
                'line_id' => $orderLineId,
                'qty' => $qty,
                'notes' => $notes,
                'user_id' => $userId,
            ]);

            return OrderLine::recordPartsReturn($pdo, $orderLineId, $qty, $userId, $reason);
        });

        return ['qty' => $qty, 'taken' => $taken, 'outstanding' => $outstanding - $qty];
    }

    /**
     * What is still to arrive on a return note.
     *
     * @param array<string,mixed> $note
     */
    public static function outstanding(array $note): int
    {
        $lines = DeliveryNote::lines((int) $note['id']);
        $declared = $lines === [] ? 0 : (int) $lines[0]['qty'];

        return max(0, $declared - DeliveryNote::qtyCheckedIn((int) $note['id']));
    }

    /**
     * One candidate (despatch, part) pair, checked against the order it is
     * claimed to belong to.
     *
     * Looked up rather than trusted: the note id and the line id both arrive
     * from a form, and a client who edits them must not be able to raise a
     * return against somebody else's despatch.
     *
     * @return array<string,mixed>|null
     */
    private static function returnableLine(int $orderId, int $goodsOutNoteId, int $orderLineId): ?array
    {
        foreach (DeliveryNote::returnableLinesForOrder($orderId) as $candidate) {
            if ((int) $candidate['note_id'] === $goodsOutNoteId && (int) $candidate['order_line_id'] === $orderLineId) {
                return $candidate;
            }
        }

        return null;
    }
}
