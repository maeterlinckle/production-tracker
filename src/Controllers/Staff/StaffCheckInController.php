<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Database;
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
 * Booking free-issue material in, and rejecting what cannot be used.
 *
 * This is the only place either happens. The order page shows where a line's
 * quantity has got to and links here; it has no material inputs of its own,
 * because two ways of recording the same arrival is how two different answers
 * end up on the same line.
 *
 * One form, and one question at the middle of it: is what arrived correct? Yes
 * puts the whole delivery into production. No opens the rejection rows, and
 * what is left after the rejections goes into production instead — a lorry
 * bringing ten bars of which three are cracked is seven bars of work that can
 * start today, not a delivery to be argued about before anything moves.
 *
 * Deliberately a single focused screen: it is what gets opened by scanning a
 * printed delivery note on a phone in the goods-in bay.
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

        // Set by the redirect after a check-in that rejected something, so the
        // return note is one click away at the moment it is wanted.
        $returnNoteId = (int) Request::query('return_note', 0);
        $returnNote = $returnNoteId > 0 ? DeliveryNote::find($returnNoteId) : null;

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
            'returnNote' => $returnNote,
        ], 'layouts/app');
    }

    /**
     * The whole check-in, in one submission.
     *
     * Every rule the form enforces in the browser is enforced again here. The
     * disabled submit button is a courtesy to the person filling it in, not a
     * control: this method is reachable without it.
     */
    public function store(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $back = '/staff/lines/' . $id . '/check-in';
        $received = (int) Request::post('qty', 0);

        if ($received <= 0) {
            Flash::error('Enter how many were received.');
            Response::redirect($back);
        }

        $allCorrect = (string) Request::post('all_correct', '');
        if (!in_array($allCorrect, ['yes', 'no'], true)) {
            Flash::error('Say whether all the received parts are correct.');
            Response::redirect($back);
        }

        $rejections = [];
        if ($allCorrect === 'no') {
            try {
                $rejections = $this->collectRejections($received);
            } catch (RuntimeException $e) {
                Flash::error($e->getMessage());
                Response::redirect($back);
            }
        }

        $rejectedTotal = array_sum(array_column($rejections, 'qty'));
        $notes = trim((string) Request::post('notes', '')) ?: null;
        $result = null;

        // One transaction over the lot: the receipt, what was rejected, the
        // return note, and the parts moving into production. A half-recorded
        // check-in is worse than a failed one.
        try {
            $result = Database::transaction(function () use ($id, $received, $rejections, $rejectedTotal, $notes, $line) {
                OrderLine::recordFreeIssueReceipt(
                    (int) $id,
                    $received,
                    (int) Auth::id(),
                    $notes,
                    $rejections === [] ? 'none' : 'wrong_item',
                    $rejections === [] ? null : $this->rejectionSummary($rejections),
                    // Recorded already dealt with: the return note and the
                    // replacement request are the resolution, and leaving a
                    // flag open behind them would ask somebody to close
                    // something that is closed.
                    $rejections !== []
                );

                $outcome = $rejections === []
                    ? null
                    : FreeIssueNoteService::rejectAndIssueNotes((int) $id, $rejections, (int) Auth::id());

                // What is usable goes straight to ready for production. The
                // quantity typed in is material units; the ledger is in final
                // parts, so it converts on the way in.
                $usableUnits = $received - $rejectedTotal;
                if ($usableUnits > 0) {
                    OrderLine::move(
                        (int) $id,
                        'awaiting_free_issue',
                        'ready_for_production',
                        OrderLine::storedQtyFromEntered($line, 'awaiting_free_issue', $usableUnits),
                        (int) Auth::id(),
                        'Checked in: ' . $usableUnits . ' accepted'
                    );
                }

                return $outcome;
            });
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect($back);
        }

        $this->announce((int) $id, $line, $rejections, $rejectedTotal, $result);

        Response::redirect($result === null
            ? $back
            : $back . '?return_note=' . $result['return_note_id']);
    }

    /**
     * Read the rejection rows, refusing anything the form should not have let
     * through.
     *
     * @return array<int,array{qty:int,reason:string}>
     */
    private function collectRejections(int $received): array
    {
        $quantities = Request::post('reject_qty', []);
        $reasons = Request::post('reject_reason', []);

        if (!is_array($quantities) || !is_array($reasons)) {
            throw new RuntimeException('Add at least one rejected entry, or say that everything is correct.');
        }

        $rejections = [];
        $total = 0;

        foreach ($quantities as $i => $quantity) {
            $qty = (int) $quantity;
            $reason = trim((string) ($reasons[$i] ?? ''));

            // A wholly blank row is somebody who added one and changed their
            // mind; a half-filled one is a mistake worth stopping.
            if ($qty <= 0 && $reason === '') {
                continue;
            }

            if ($qty <= 0) {
                throw new RuntimeException('Every rejected entry needs a quantity.');
            }

            if ($reason === '') {
                throw new RuntimeException('Every rejected entry needs a reason — it goes on the return note.');
            }

            $rejections[] = ['qty' => $qty, 'reason' => $reason];
            $total += $qty;
        }

        if ($rejections === []) {
            throw new RuntimeException('Add at least one rejected entry, or say that everything is correct.');
        }

        if ($total > $received) {
            throw new RuntimeException(
                'You cannot reject ' . $total . ' out of ' . $received . ' received.'
            );
        }

        return $rejections;
    }

    /** @param array<int,array{qty:int,reason:string}> $rejections */
    private function rejectionSummary(array $rejections): string
    {
        return implode('; ', array_map(
            static fn (array $rejection): string => $rejection['qty'] . ' × ' . $rejection['reason'],
            $rejections
        ));
    }

    /**
     * Tell the client what happened, and the person at the screen what to do
     * next.
     *
     * @param array<int,array{qty:int,reason:string}> $rejections
     */
    private function announce(int $lineId, array $line, array $rejections, int $rejectedTotal, ?array $result): void
    {
        $updated = OrderLine::find($lineId);
        $order = Order::find((int) $line['order_id']);

        Notifications::freeIssueCheckedIn($updated, $order, (int) $order['client_id']);

        if ($result === null) {
            $outstanding = OrderLine::freeIssueOutstanding($updated);

            Flash::success($outstanding > 0
                ? 'Checked in and moved to ready for production. ' . $outstanding . ' still to come.'
                : 'Checked in and moved to ready for production. All the material for this line is now in.');

            return;
        }

        $returnNote = DeliveryNote::find($result['return_note_id']);

        Notifications::materialRejected(
            $updated,
            $order,
            $returnNote,
            $rejectedTotal,
            $this->rejectionSummary($rejections),
            (int) $order['client_id']
        );

        Flash::success(
            $rejectedTotal . ' rejected and returned on ' . $returnNote['reference'] . '. '
            . 'The same ' . $rejectedTotal . ' has been added back to what this line still needs, so the '
            . 'free-issue note asks for it again.'
        );
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
