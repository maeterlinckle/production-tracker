<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Core\Upload;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;
use App\Models\User;
use RuntimeException;

/**
 * Placing an order, wherever it is placed from.
 *
 * Clients place their own; Junction places them on the phone. It is the same
 * order either way — the same lines, the same purchase order, the same
 * free-issue notes going out — so it is the same code, and the only difference
 * is who is signed in and which client the order is for.
 *
 * Keeping the two apart would have meant two places for the free-issue
 * calculation to live, and the one that is used less is the one that goes
 * wrong quietly.
 */
final class OrderPlacement
{
    /**
     * Read an order out of the posted form, place it, and send the paperwork.
     *
     * @param int $clientId whose order this is
     * @param int $placedBy the signed-in user, client or staff
     * @return int the new order's id
     * @throws RuntimeException with a message fit to show the person who submitted it
     */
    public static function placeFromRequest(int $clientId, int $placedBy): int
    {
        $partIds = Request::post('part_id', []);
        $quantities = Request::post('qty', []);
        $freeIssueQtys = Request::post('free_issue_qty', []);

        if (!is_array($partIds) || $partIds === []) {
            throw new RuntimeException('Add at least one part to the order.');
        }

        // Required since the PO number became the Clear Books invoice
        // reference: chasing it afterwards means chasing it by email.
        $poNumber = trim((string) Request::post('po_number', ''));
        if ($poNumber === '') {
            throw new RuntimeException('Enter the purchase order number.');
        }

        $poFile = Upload::files('po')[0] ?? null;
        if ($poFile === null) {
            throw new RuntimeException('A purchase order document is required.');
        }

        $error = Upload::validate($poFile, Config::get('uploads.po.extensions'), (int) Config::get('uploads.po.max_bytes'));
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $lines = self::readLines($clientId, $partIds, $quantities, $freeIssueQtys);
        if ($lines === []) {
            throw new RuntimeException(
                'None of the selected parts could be added — make sure quantities are set and the parts are quoted.'
            );
        }

        $orderId = Order::createWithLines(
            [
                'client_id' => $clientId,
                'po_number' => $poNumber,
                'po_file_path' => Upload::store($poFile, 'pos/' . $clientId),
                'po_original_filename' => Upload::displayName((string) $poFile['name']),
                'placed_by' => $placedBy,
                'notes' => Request::post('notes', ''),
            ],
            $lines
        );

        self::sendPaperwork($orderId, $clientId, $placedBy);

        return $orderId;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function readLines(int $clientId, array $partIds, array $quantities, array $freeIssueQtys): array
    {
        $lines = [];

        foreach ($partIds as $i => $partId) {
            $qty = (int) ($quantities[$i] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $part = Part::find((int) $partId);

            // The client id is checked here and not only in the controller: a
            // staff order is placed against a client chosen in a select, and
            // nothing else stops a part from another client's account being
            // posted alongside it.
            if ($part === null || (int) $part['client_id'] !== $clientId || $part['status'] !== 'quoted') {
                continue;
            }

            // Worked out from the part's own ratio rather than trusted from the
            // form. The field in the browser is a convenience — it shows what
            // will need to be sent — but it is an input on a page, and a posted
            // 0 would create a line that needed no material and so never waited
            // for any, stepping around the whole free-issue check-in.
            //
            // A *higher* figure is honoured: sending more material than the
            // ratio calls for is a real thing to do, and the line simply books
            // in what arrives. Anything lower falls back to the calculation.
            $required = Part::freeIssueQtyFor($part, $qty);
            $posted = max(0, (int) ($freeIssueQtys[$i] ?? 0));

            $lines[] = [
                'part_id' => $part['id'],
                'qty_ordered' => $qty,
                'unit_price' => $part['quoted_price'],
                'needs_free_issue' => Part::hasFreeIssue($part),
                'qty_free_issue_required' => Part::hasFreeIssue($part) ? max($required, $posted) : 0,
            ];
        }

        return $lines;
    }

    /**
     * The confirmation, and one free-issue note per line that needs material —
     * generated immediately so the client can print it straight away, each with
     * its own QR back to that line's check-in.
     */
    private static function sendPaperwork(int $orderId, int $clientId, int $placedBy): void
    {
        $order = Order::find($orderId);
        $placedByUser = User::find($placedBy);

        if ($order !== null && $placedByUser !== null) {
            Notifications::orderConfirmed($order, $placedByUser);
        }

        foreach (OrderLine::forOrder($orderId) as $line) {
            if ((int) $line['qty_free_issue_required'] > 0) {
                $noteId = FreeIssueNoteService::generateForLine((int) $line['id'], $placedBy);
                Notifications::freeIssueNoteIssued(DeliveryNote::find($noteId), $clientId);
            }
        }
    }
}
