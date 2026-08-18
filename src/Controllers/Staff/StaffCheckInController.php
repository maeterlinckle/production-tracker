<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Notifications;

/**
 * Staff-only, QR-reachable page for confirming free-issue material on
 * arrival (item 6). Deliberately a single focused screen -- this is what
 * gets opened by scanning a printed delivery note on a phone in the
 * workshop, not the full order page.
 */
final class StaffCheckInController
{
    public function show(string $id): void
    {
        Auth::authorize('production_control');
        $line = OrderLine::find((int) $id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

            return;
        }

        $order = Order::find((int) $line['order_id']);
        $client = Client::find((int) $order['client_id']);

        View::render('staff/check-in', [
            'title' => 'Check in ' . $line['cpn'],
            'line' => $line,
            'order' => $order,
            'client' => $client,
            'receipts' => OrderLine::freeIssueReceipts($line['id']),
            'openDiscrepancy' => OrderLine::openDiscrepancy($line['id']),
        ], 'layouts/app');
    }

    public function store(string $id): void
    {
        Auth::authorize('production_control');
        $line = OrderLine::find((int) $id);
        if ($line === null) {
            View::renderError(404, 'Line not found', 'That order line does not exist.');

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

        $updatedLine = OrderLine::find((int) $id);
        $order = Order::find((int) $line['order_id']);

        if ($updatedLine['stage'] === 'ready_for_production' && $line['stage'] === 'awaiting_free_issue') {
            Notifications::freeIssueCheckedIn($updatedLine, $order, (int) $order['client_id']);
            Flash::success('Checked in. All free-issue material is now in and the line is ready for production.');
        } elseif ($discrepancyType !== 'none') {
            Flash::error('Recorded with a ' . OrderLine::DISCREPANCY_LABELS[$discrepancyType] . ' flag — this line will not move to production until it is resolved.');
        } else {
            Flash::success('Receipt recorded. ' . $updatedLine['qty_free_issue_received'] . ' of ' . $updatedLine['qty_free_issue_required'] . ' received so far.');
        }

        Response::redirect('/staff/lines/' . $id . '/check-in');
    }

    public function resolveDiscrepancy(string $id, string $receiptId): void
    {
        Auth::authorize('production_control');
        OrderLine::resolveDiscrepancy((int) $receiptId, (int) Auth::id());
        Flash::success('Discrepancy marked resolved.');
        Response::redirect('/staff/lines/' . $id . '/check-in');
    }
}
