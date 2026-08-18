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
use App\Services\Notifications;
use App\Services\PdfService;
use App\Services\QrCodeService;

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

        $lines = array_values(array_filter(
            \App\Core\Database::all(
                "SELECT ol.*, o.order_number, p.cpn, p.name AS part_name FROM order_lines ol
                 JOIN orders o ON o.id = ol.order_id JOIN parts p ON p.id = ol.part_id
                 WHERE o.client_id = :client_id AND ol.qty_free_issue_required > ol.qty_free_issue_received
                 ORDER BY o.order_number",
                ['client_id' => $client['id']]
            )
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
        $this->buildPdf($id);
        Notifications::freeIssueNoteIssued(DeliveryNote::find($id), (int) $clientId);

        Flash::success('Free-issue delivery note generated.');
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

        View::render('staff/delivery-notes/create-goods-out', ['title' => 'New delivery note', 'client' => $client, 'lines' => $lines]);
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

    public function downloadPdf(string $id): void
    {
        $note = DeliveryNote::find((int) $id);
        if ($note === null) {
            View::renderError(404, 'Delivery note not found', 'That delivery note does not exist.');

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
        $note = DeliveryNote::find($deliveryNoteId);
        $client = Client::find((int) $note['client_id']);
        $lines = DeliveryNote::lines($deliveryNoteId);

        $qr = QrCodeService::pngDataUri(QrCodeService::jobUrl('/staff/delivery-notes/' . $deliveryNoteId));

        $relativePath = PdfService::renderAndStore(
            'pdf/delivery-note',
            ['deliveryNote' => $note, 'client' => $client, 'lines' => $lines, 'qrDataUri' => $qr],
            'delivery-notes/' . $note['client_id'],
            $note['reference'] . '.pdf'
        );

        DeliveryNote::setPdfPath($deliveryNoteId, $relativePath);
    }
}
