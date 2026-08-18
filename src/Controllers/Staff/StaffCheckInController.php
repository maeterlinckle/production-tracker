<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;
use App\Services\FreeIssueNoteService;
use App\Services\Notifications;
use RuntimeException;

/**
 * Staff-only, QR-reachable page for handling free-issue material on arrival.
 *
 * Deliberately a single focused screen -- this is what gets opened by scanning
 * a printed delivery note on a phone in the goods-in bay, not the full order
 * page. Two things happen here and they are different: booking material in, and
 * rejecting material that arrived but cannot be used.
 *
 * Booking material in no longer moves any parts. What arrived and what the
 * workshop is ready to start on are separate decisions, and the second one is
 * made on the order page, by somebody choosing how many to advance.
 */
final class StaffCheckInController
{
    public function show(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $order = Order::find((int) $line['order_id']);
        $client = Client::find((int) $order['client_id']);

        View::render('staff/check-in', [
            'title' => 'Check in ' . $line['cpn'],
            'line' => $line,
            'order' => $order,
            'client' => $client,
            'part' => Part::find((int) $line['part_id']),
            'receipts' => OrderLine::freeIssueReceipts($line['id']),
            'rejections' => OrderLine::rejections($line['id']),
            'openDiscrepancy' => OrderLine::openDiscrepancy($line['id']),
            'outstanding' => OrderLine::freeIssueOutstanding($line),
        ], 'layouts/app');
    }

    public function store(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $qty = (int) Request::post('qty', 0);
        if ($qty <= 0) {
            Flash::error('Enter a quantity received.');
            Response::redirect('/staff/lines/' . $id . '/check-in');
        }

        $discrepancyType = (string) Request::post('discrepancy_type', 'none');
        $discrepancyNotes = trim((string) Request::post('discrepancy_notes', '')) ?: null;

        OrderLine::recordFreeIssueReceipt(
            (int) $id,
            $qty,
            (int) Auth::id(),
            Request::post('notes') ?: null,
            $discrepancyType,
            $discrepancyNotes
        );

        $updated = OrderLine::find((int) $id);
        $order = Order::find((int) $line['order_id']);

        Notifications::freeIssueCheckedIn($updated, $order, (int) $order['client_id']);

        if ($discrepancyType !== 'none') {
            Flash::error(
                'Recorded with a ' . OrderLine::DISCREPANCY_LABELS[$discrepancyType] . ' flag. '
                . 'If the material is wrong rather than simply short, reject it below so it goes back and a replacement is asked for.'
            );
        } else {
            $outstanding = OrderLine::freeIssueOutstanding($updated);
            Flash::success($outstanding > 0
                ? 'Receipt recorded — ' . $outstanding . ' still to come. Advance whatever parts you can start on from the order page.'
                : 'Receipt recorded. All the material for this line is now in.');
        }

        Response::redirect('/staff/lines/' . $id . '/check-in');
    }

    /**
     * Reject material that arrived and cannot be used.
     *
     * Not the same thing as a shortage, and handled differently on purpose: a
     * shortage is material that has not turned up, already covered by the note
     * that is out. This is material that turned up wrong, so it goes back on a
     * return note and the same quantity is asked for again.
     */
    public function reject(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $qty = (int) Request::post('qty', 0);
        $reason = trim((string) Request::post('reason', ''));

        try {
            $result = FreeIssueNoteService::rejectAndIssueNotes((int) $id, $qty, $reason, (int) Auth::id());
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/staff/lines/' . $id . '/check-in');
        }

        $returnNote = DeliveryNote::find($result['return_note_id']);
        $order = Order::find((int) $line['order_id']);
        $updated = OrderLine::find((int) $id);

        Notifications::materialRejected($updated, $order, $returnNote, $qty, $reason, (int) $order['client_id']);

        Flash::success(
            'Return note ' . $returnNote['reference'] . ' raised for ' . $qty . '. '
            . 'The same quantity has been added back to what this line still needs, so the free-issue note asks for it again.'
        );
        Response::redirect('/staff/lines/' . $id . '/check-in');
    }

    public function resolveDiscrepancy(string $id, string $receiptId): void
    {
        Auth::authorize('production_control');
        OrderLine::resolveDiscrepancy((int) $receiptId, (int) Auth::id());
        Flash::success('Discrepancy marked resolved.');
        Response::redirect('/staff/lines/' . $id . '/check-in');
    }

    private function findLine(int $id): ?array
    {
        $line = OrderLine::find($id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

            return null;
        }

        return $line;
    }
}
