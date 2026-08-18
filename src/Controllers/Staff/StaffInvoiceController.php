<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Core\View;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\ClearBooksClient;
use App\Services\Notifications;

final class StaffInvoiceController
{
    public function raise(string $deliveryNoteId): void
    {
        Auth::authorize('push_invoices');
        $note = DeliveryNote::find((int) $deliveryNoteId);
        if ($note === null) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist.');

            return;
        }

        if ($note['type'] !== 'goods_out') {
            Flash::error('Only completed-goods delivery notes can be invoiced.');
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        if ((bool) $note['invoiced']) {
            Flash::error('This delivery note has already been invoiced.');
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        $client = Client::find((int) $note['client_id']);
        if ($client === null || empty($client['clearbooks_entity_id'])) {
            Flash::error('Set the Clear Books customer ID on this client before raising an invoice.');
            Response::redirect('/staff/clients/' . $note['client_id']);
        }

        $lines = DeliveryNote::lines((int) $deliveryNoteId);
        $invoiceLines = array_map(static fn (array $l) => [
            'description' => $l['cpn'] . ' — ' . $l['part_name'] . ' (' . $l['order_number'] . ')',
            'quantity' => (int) $l['qty'],
            'unit_price' => (float) $l['unit_price'],
        ], $lines);

        try {
            $result = ClearBooksClient::createSalesInvoice(
                $client['clearbooks_entity_id'],
                $invoiceLines,
                $this->invoiceReference($note, $lines)
            );
        } catch (\Throwable $e) {
            Flash::error('Could not raise the Clear Books invoice: ' . $e->getMessage());
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        $invoiceId = Invoice::raise((int) $deliveryNoteId, $result['id'], $result['number'], $result['amount'], (int) Auth::id());
        Notifications::invoiceRaised(Invoice::find($invoiceId), $note, (int) $note['client_id']);

        Flash::success('Invoice ' . $result['number'] . ' raised in Clear Books.');
        Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
    }

    /**
     * What goes in the Clear Books invoice's `reference` field (item 9).
     *
     * The client's PO number, because that is what they will match the invoice
     * against when it lands in their accounts payable — a Junction delivery note
     * number means nothing at their end.
     *
     * A note can cover lines from more than one order, so more than one PO can
     * apply; all of them go in rather than an arbitrary first. The delivery note
     * reference is the fallback for orders placed before PO numbers were
     * recorded, so an invoice never goes out with an empty reference.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function invoiceReference(array $note, array $lines): string
    {
        $poNumbers = array_values(array_unique(array_filter(
            array_map(static fn (array $line): string => trim((string) ($line['po_number'] ?? '')), $lines),
            static fn (string $poNumber): bool => $poNumber !== ''
        )));

        if ($poNumbers === []) {
            return (string) $note['reference'];
        }

        return implode(', ', $poNumbers);
    }
}
