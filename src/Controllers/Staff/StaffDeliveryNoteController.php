<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\OrderLine;
use App\Services\FreeIssueNoteService;
use App\Services\Notifications;

final class StaffDeliveryNoteController
{
    public function index(): void
    {
        $filter = Request::query('filter');
        $notes = $filter === 'uninvoiced' ? DeliveryNote::uninvoiced() : $this->allNotes();

        View::render('staff/delivery-notes/index', ['title' => 'Delivery notes', 'notes' => $notes, 'filter' => $filter]);
    }

    private function allNotes(): array
    {
        return \App\Core\Database::all(
            'SELECT dn.*, c.name AS client_name FROM delivery_notes dn JOIN clients c ON c.id = dn.client_id ORDER BY dn.issued_at DESC'
        );
    }

    public function createFreeIssue(string $clientId): void
    {
        $client = Client::find((int) $clientId);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        // Outstanding rather than "required > received": rejected material has
        // arrived and been sent back, so it is owed again even though the
        // received counter says it came.
        $lines = array_values(array_filter(
            \App\Core\Database::all(
                "SELECT ol.*, o.order_number, o.po_number, p.cpn, p.name AS part_name, p.has_free_issue
                   FROM order_lines ol
                   JOIN orders o ON o.id = ol.order_id
                   JOIN parts p ON p.id = ol.part_id
                  WHERE o.client_id = :client_id
                    AND p.has_free_issue = 1
                    AND ol.closed_at IS NULL
                  ORDER BY o.order_number",
                ['client_id' => $client['id']]
            ),
            static fn (array $line): bool => OrderLine::freeIssueOutstanding($line) > 0
        ));

        View::render('staff/delivery-notes/create-free-issue', ['title' => 'New free-issue note', 'client' => $client, 'lines' => $lines]);
    }

    public function storeFreeIssue(string $clientId): void
    {
        Auth::authorize('issue_delivery_notes');
        $client = Client::find((int) $clientId);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $lines = $this->collectLineQuantities();
        if ($lines === []) {
            Flash::error('Enter a quantity for at least one line.');
            Response::redirect('/staff/clients/' . $clientId . '/free-issue-note/new');
        }

        $id = DeliveryNote::createFreeIssueNote((int) $clientId, $lines, (int) Auth::id(), Request::post('notes') ?: null);
        Notifications::freeIssueNoteIssued(DeliveryNote::find($id), (int) $clientId);

        Flash::success('Free-Issue Sent note generated.');
        Response::redirect('/staff/delivery-notes/' . $id);
    }

    public function createGoodsOut(string $clientId): void
    {
        $client = Client::find((int) $clientId);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $lines = OrderLine::shippableForClient((int) $clientId);

        // Reached from an order's own page nine times out of ten, and that
        // order is what the person is here to despatch. Everything else the
        // client has ready still gets listed — putting two orders in one parcel
        // is normal — but underneath, and clearly second.
        $focusOrderId = (int) Request::query('order', 0);
        $focusOrder = null;
        $focusLines = [];
        $otherLines = [];

        foreach ($lines as $line) {
            if ($focusOrderId > 0 && (int) $line['order_id'] === $focusOrderId) {
                $focusLines[] = $line;
                $focusOrder ??= ['id' => $focusOrderId, 'order_number' => $line['order_number'], 'po_number' => $line['po_number']];
            } else {
                $otherLines[] = $line;
            }
        }

        View::render('staff/delivery-notes/create-goods-out', [
            'title' => 'New delivery note',
            'client' => $client,
            'focusOrder' => $focusOrder,
            'focusRequested' => $focusOrderId > 0,
            'focusLines' => $focusLines,
            'otherLines' => $otherLines,
            'lines' => $lines,
        ]);
    }

    public function storeGoodsOut(string $clientId): void
    {
        Auth::authorize('issue_delivery_notes');
        $client = Client::find((int) $clientId);
        if ($client === null) {
            View::renderError(404, 'Client not found', 'That client does not exist.');

            return;
        }

        $lines = $this->collectLineQuantities();
        if ($lines === []) {
            Flash::error('Enter a quantity for at least one line.');
            Response::redirect('/staff/clients/' . $clientId . '/delivery-note/new');
        }

        $id = DeliveryNote::createGoodsOutNote((int) $clientId, $lines, (int) Auth::id(), Request::post('notes') ?: null);
        $this->buildPdf($id);
        Notifications::deliveryNoteIssued(DeliveryNote::find($id), (int) $clientId);

        Flash::success('Delivery note generated. Remember to raise the invoice once ready.');
        Response::redirect('/staff/delivery-notes/' . $id);
    }

    private function collectLineQuantities(): array
    {
        $lineIds = Request::post('line_id', []);
        $quantities = Request::post('qty', []);

        $lines = [];
        if (is_array($lineIds)) {
            foreach ($lineIds as $i => $lineId) {
                $qty = (int) ($quantities[$i] ?? 0);
                if ($qty > 0) {
                    $lines[] = ['order_line_id' => (int) $lineId, 'qty' => $qty];
                }
            }
        }

        return $lines;
    }

    public function show(string $id): void
    {
        $note = DeliveryNote::find((int) $id);
        if ($note === null) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist.');

            return;
        }

        $client = Client::find((int) $note['client_id']);

        View::render('staff/delivery-notes/show', [
            'title' => $note['reference'],
            'note' => $note,
            'client' => $client,
            'lines' => DeliveryNote::lines($note['id']),
            'invoice' => \App\Models\Invoice::forDeliveryNote($note['id']),
        ]);
    }

    /**
     * A free-issue note is a standing request, so it is rendered fresh every
     * time: what it asks for is whatever the line still needs today. The notes
     * that record a movement -- goods out, material returned -- keep the copy
     * that was made when the movement happened.
     */
    public function downloadPdf(string $id): void
    {
        $note = DeliveryNote::find((int) $id);
        if ($note === null) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist.');

            return;
        }

        if ($note['type'] === 'free_issue_in') {
            $rendered = FreeIssueNoteService::renderLive((int) $id);
            Response::inlineBytes($rendered['bytes'], $rendered['filename'], 'application/pdf');

            return;
        }

        if ($note['pdf_file_path'] === null) {
            $this->buildPdf((int) $id);
            $note = DeliveryNote::find((int) $id);
        }

        $absolute = Upload::absolutePath($note['pdf_file_path']);
        if ($absolute === null || !is_file($absolute)) {
            View::renderError(404, 'File not found', 'The delivery note PDF is missing from storage.');

            return;
        }

        Response::file($absolute, $note['reference'] . '.pdf', 'application/pdf');
    }

    private function buildPdf(int $deliveryNoteId): void
    {
        FreeIssueNoteService::buildStoredPdf($deliveryNoteId, '/staff/delivery-notes/' . $deliveryNoteId);
    }
}
