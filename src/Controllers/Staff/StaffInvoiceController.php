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
use App\Models\Invoice;
use App\Services\ClearBooksClient;
use App\Services\ClearBooksPoAttachments;
use App\Services\ClearBooksPosting;
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
            Flash::error('Only Completed Parts Sent notes can be invoiced.');
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        if ((bool) $note['invoiced']) {
            Flash::error('This delivery note has already been invoiced.');
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        $client = Client::find((int) $note['client_id']);
        if ($client === null) {
            Flash::error('That delivery note has no client on it.');
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        // Nominal code, VAT, terms and the summary template are all this
        // client's own now rather than one set applied to everybody. Checked
        // here rather than left to the API call, so the answer is "set the VAT
        // treatment on this client" with a link to the page it is set on.
        $posting = ClearBooksPosting::fromRow($client);
        if ($posting->problems() !== []) {
            Flash::error(
                'Clear Books is not set up for ' . $client['name'] . ' yet: '
                . implode(' ', $posting->problems())
            );
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
                $posting,
                $invoiceLines,
                $this->invoiceReference($note, $lines),
                $posting->summaryFor($this->summaryValues($client, $note, $lines))
            );
        } catch (\Throwable $e) {
            Flash::error('Could not raise the Clear Books invoice: ' . $e->getMessage());
            Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
        }

        $invoiceId = Invoice::raise(
            (int) $deliveryNoteId,
            $result['id'],
            $result['number'],
            $result['amount'],
            (int) Auth::id(),
            null,
            Invoice::SOURCE_CLEARBOOKS
        );
        Notifications::invoiceRaised(Invoice::find($invoiceId), $note, (int) $note['client_id']);

        // Only now, with the invoice recorded: the PO goes up alongside it so
        // that whoever at their end has to match the bill against what they
        // authorised has both in one place. Deliberately after the record is
        // written, and deliberately incapable of throwing — an invoice that
        // exists in Clear Books but not here is a far worse outcome than one
        // with nothing attached.
        $attachments = ClearBooksPoAttachments::push($posting, (int) $result['id'], $lines);

        Flash::success('Invoice ' . $result['number'] . ' raised in Clear Books'
            . ($attachments['attached'] === []
                ? '.'
                : ', with ' . count($attachments['attached']) . ' purchase order document(s) attached.'));

        if ($attachments['problems'] !== []) {
            Flash::warning(
                'The invoice was raised, but not everything could be attached to it: '
                . implode(' ', $attachments['problems'])
                . ' Attach what is missing in Clear Books directly.'
            );
        }

        Response::redirect('/staff/delivery-notes/' . $deliveryNoteId);
    }

    /**
     * Record an invoice that was raised somewhere else.
     *
     * Clear Books being unreachable, unconfigured or simply not connected yet is
     * not a reason for a delivery note to sit forever in the "not yet invoiced"
     * list. The work has gone out and somebody has billed for it; this is how
     * they say so.
     *
     * The invoice number is required because it is the entire point — without
     * it this would be a flag saying "invoiced, somehow, somewhere", which is
     * worse than the note staying on the list. The amount is offered pre-filled
     * from the order's own prices and can be corrected, because whoever raised
     * the real invoice knows what it said and the tracker only knows what it
     * thinks the goods are worth.
     */
    public function raiseManual(string $deliveryNoteId): void
    {
        Auth::authorize('push_invoices');
        $note = DeliveryNote::find((int) $deliveryNoteId);
        $back = '/staff/delivery-notes/' . $deliveryNoteId;

        if ($note === null) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist.');

            return;
        }

        if ($note['type'] !== 'goods_out') {
            Flash::error('Only completed-parts delivery notes can be invoiced.');
            Response::redirect($back);
        }

        if ((bool) $note['invoiced']) {
            Flash::error('This delivery note has already been invoiced.');
            Response::redirect($back);
        }

        $number = trim((string) Request::post('invoice_number', ''));
        if ($number === '') {
            Flash::error('Enter the invoice number that was raised. Without it there is nothing to match this against.');
            Response::redirect($back);
        }

        $amount = trim((string) Request::post('amount', ''));
        $amount = $amount === ''
            ? Invoice::valueOfDeliveryNote((int) $deliveryNoteId)
            : (float) $amount;

        $invoiceId = Invoice::raise(
            (int) $deliveryNoteId,
            null,
            $number,
            $amount,
            (int) Auth::id(),
            trim((string) Request::post('notes', '')) ?: null,
            Invoice::SOURCE_MANUAL
        );

        Notifications::invoiceRaised(Invoice::find($invoiceId), $note, (int) $note['client_id']);

        Flash::success(
            'Recorded invoice ' . $number . ' against ' . $note['reference']
            . '. It is marked as raised outside Clear Books, so it is still obvious later which is which.'
        );
        Response::redirect($back);
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
        $poNumbers = $this->distinct($lines, 'po_number');

        if ($poNumbers === '') {
            return (string) $note['reference'];
        }

        return $poNumbers;
    }

    /**
     * What the placeholders in a client's invoice summary stand for.
     *
     * Everything here is already in hand at the moment the invoice is raised —
     * the delivery note, the lines and the client — so filling the template
     * costs no extra API call and cannot fail halfway through. The set is
     * declared once in ClearBooksPosting::PLACEHOLDERS, which is also what the
     * hint on the client page lists, so the two cannot drift apart.
     *
     * A placeholder with nothing behind it comes back as an empty string rather
     * than being left out: an order placed before PO numbers were recorded
     * should produce a summary missing its PO, not a summary with `{po_number}`
     * printed on the invoice.
     *
     * @param array<string,mixed>            $client
     * @param array<string,mixed>            $note
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,string>
     */
    private function summaryValues(array $client, array $note, array $lines): array
    {
        return [
            'po_number' => $this->distinct($lines, 'po_number'),
            'order_number' => $this->distinct($lines, 'order_number'),
            'delivery_note' => (string) $note['reference'],
            'client_name' => (string) $client['name'],
            'invoice_date' => date('d/m/Y'),
        ];
    }

    /**
     * One field off the note's lines, deduplicated and joined.
     *
     * A delivery note can cover lines from more than one order, so more than
     * one PO and more than one order number can apply. All of them go in rather
     * than an arbitrary first — an invoice naming one of two purchase orders is
     * worse than one naming both.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function distinct(array $lines, string $field): string
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn (array $line): string => trim((string) ($line[$field] ?? '')), $lines),
            static fn (string $value): bool => $value !== ''
        )));

        return implode(', ', $values);
    }
}
