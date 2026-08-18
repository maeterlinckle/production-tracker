<?php

declare(strict_types=1);

namespace App\Controllers\Client;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineChangeRequest;
use App\Models\OrderNote;
use App\Models\OrderPhoto;
use App\Models\OrderPoDocument;
use App\Models\OrderQuery;
use App\Models\Part;
use App\Models\User;
use App\Services\FreeIssueNoteService;
use App\Services\Notifications;

final class OrderController
{
    public function index(): void
    {
        Auth::authorize('view_orders');
        $orders = Order::forClient((int) Auth::clientId());

        $orders = array_map(static function (array $order): array {
            $lines = OrderLine::forOrder((int) $order['id']);
            $order['rollup_status'] = Order::rollupStatus($lines);
            $order['line_count'] = count($lines);

            return $order;
        }, $orders);

        View::render('orders/index', ['title' => 'Orders', 'orders' => $orders]);
    }

    public function create(): void
    {
        Auth::authorize('place_orders');

        $preselectId = (int) Request::query('part', 0);
        $preselectPart = null;
        if ($preselectId > 0) {
            $part = Part::find($preselectId);
            if ($part !== null && (int) $part['client_id'] === (int) Auth::clientId() && $part['status'] === 'quoted') {
                $preselectPart = Part::orderableJson($part);
            }
        }

        View::render('orders/create', [
            'title' => 'Place order',
            'preselectPart' => $preselectPart,
        ]);
    }

    public function store(): void
    {
        Auth::authorize('place_orders');
        $clientId = (int) Auth::clientId();

        $partIds = Request::post('part_id', []);
        $quantities = Request::post('qty', []);
        $freeIssueQtys = Request::post('free_issue_qty', []);

        if (!is_array($partIds) || $partIds === []) {
            Flash::error('Add at least one part to the order.');
            Response::redirect('/orders/new');
        }

        // Required from this release on (item 9): it is the reference the Clear
        // Books invoice is raised against, and chasing it afterwards means
        // chasing it by email.
        $poNumber = trim((string) Request::post('po_number', ''));
        if ($poNumber === '') {
            Flash::error('Enter your purchase order number.');
            Response::redirect('/orders/new');
        }

        $poFile = Upload::files('po')[0] ?? null;
        if ($poFile === null) {
            Flash::error('A purchase order document is required.');
            Response::redirect('/orders/new');
        }

        $error = Upload::validate($poFile, Config::get('uploads.po.extensions'), (int) Config::get('uploads.po.max_bytes'));
        if ($error !== null) {
            Flash::error($error);
            Response::redirect('/orders/new');
        }

        $lines = [];
        foreach ($partIds as $i => $partId) {
            $qty = (int) ($quantities[$i] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $part = Part::find((int) $partId);
            if ($part === null || (int) $part['client_id'] !== $clientId || $part['status'] !== 'quoted') {
                continue;
            }

            // Worked out here from the part's own ratio rather than trusted from
            // the form. The field in the browser is a convenience — it shows the
            // client what they will need to send — but it is an input on a page,
            // and a posted 0 would have created a line that needed no material
            // and so never waited for any, quietly stepping around the whole
            // free-issue check-in.
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

        if ($lines === []) {
            Flash::error('None of the selected parts could be added — make sure quantities are set and parts are quoted.');
            Response::redirect('/orders/new');
        }

        $poRelativePath = Upload::store($poFile, 'pos/' . $clientId);

        $orderId = Order::createWithLines(
            [
                'client_id' => $clientId,
                'po_number' => $poNumber,
                'po_file_path' => $poRelativePath,
                'po_original_filename' => Upload::displayName((string) $poFile['name']),
                'placed_by' => Auth::id(),
                'notes' => Request::post('notes', ''),
            ],
            $lines
        );

        $order = Order::find($orderId);
        $placedBy = User::find((int) Auth::id());
        if ($order !== null && $placedBy !== null) {
            Notifications::orderConfirmed($order, $placedBy);
        }

        // One free-issue delivery note per line that needs material sent in,
        // generated immediately so the client can print it straight away, each
        // with its own QR back to that line's check-in.
        foreach (OrderLine::forOrder($orderId) as $line) {
            if ((int) $line['qty_free_issue_required'] > 0) {
                $dnId = FreeIssueNoteService::generateForLine((int) $line['id'], (int) Auth::id());
                Notifications::freeIssueNoteIssued(DeliveryNote::find($dnId), $clientId);
            }
        }

        Flash::success('Order placed. Junction will confirm and begin processing it shortly.');
        Response::redirect('/orders/' . $orderId);
    }

    public function show(string $id): void
    {
        if (!Auth::isStaff()) {
            Auth::authorize('view_orders');
        }

        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        $lines = OrderLine::forOrder($order['id']);

        $changeRequests = [];
        foreach ($lines as $line) {
            $changeRequests[(int) $line['id']] = OrderLineChangeRequest::forLine((int) $line['id']);
        }

        $queries = array_map(static function (array $q) {
            $q['replies'] = OrderQuery::replies((int) $q['id']);

            return $q;
        }, OrderQuery::forOrder($order['id']));

        View::render('orders/show', [
            'title' => $order['order_number'],
            'order' => $order,
            'lines' => $lines,
            'changeRequests' => $changeRequests,
            'deliveryNotes' => DeliveryNote::forOrder($order['id']),
            'poDocuments' => OrderPoDocument::forOrder($order['id']),
            'photos' => OrderPhoto::forOrder($order['id']),
            'notes' => OrderNote::forOrder($order['id']),
            'queries' => $queries,
            'rollupStatus' => Order::rollupStatus($lines),
        ]);
    }

    /**
     * Ask for a different quantity on a line that is already running (item 8).
     *
     * This records the request and nothing else. Junction may have bought
     * material, set a machine up or made half of it already, so what happens to
     * the order is their decision, not an edit the client can make.
     *
     * An amended or additional purchase order can come with it -- that is
     * usually the paperwork that justifies the change -- and it is added to the
     * order's document history rather than replacing what is there.
     */
    public function requestQuantityChange(string $id, string $lineId): void
    {
        Auth::authorize('request_quantity_change');

        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        $line = OrderLine::find((int) $lineId);
        if ($line === null || (int) $line['order_id'] !== (int) $order['id']) {
            View::renderError(404, 'Line not found', 'That line is not part of this order.');

            return;
        }

        $requested = (int) Request::post('qty_requested', 0);
        $reason = trim((string) Request::post('reason', '')) ?: null;

        if ($requested <= 0) {
            Flash::error('Enter the quantity you would like on this line.');
            Response::redirect('/orders/' . $id);
        }

        if ($requested === (int) $line['qty_ordered']) {
            Flash::error('That is already the quantity on this line.');
            Response::redirect('/orders/' . $id);
        }

        if (OrderLineChangeRequest::pendingForLine((int) $lineId) !== null) {
            Flash::error('There is already a change request waiting on this line. Junction will come back to you on it.');
            Response::redirect('/orders/' . $id);
        }

        $requestId = OrderLineChangeRequest::create(
            (int) $lineId,
            (int) $line['qty_ordered'],
            $requested,
            $reason,
            (int) Auth::id()
        );

        if ((Upload::files('po')[0] ?? null) !== null) {
            $this->attachPoDocument($order, 'Attached to change request #' . $requestId);
        }

        Notifications::quantityChangeRequested(
            OrderLineChangeRequest::find($requestId),
            $line,
            $order,
            (string) Auth::name()
        );

        Flash::success('Change request sent to Junction. Nothing on the order has changed yet — they will confirm.');
        Response::redirect('/orders/' . $id);
    }

    /** Add a purchase order document to an order's history without a change request. */
    public function uploadPoDocument(string $id): void
    {
        Auth::authorize('place_orders');

        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        if ((Upload::files('po')[0] ?? null) === null) {
            Flash::error('Choose a purchase order document to add.');
            Response::redirect('/orders/' . $id);
        }

        $this->attachPoDocument($order, trim((string) Request::post('note', '')) ?: null);

        Flash::success('Purchase order added. The original is still on file.');
        Response::redirect('/orders/' . $id);
    }

    private function attachPoDocument(array $order, ?string $note): void
    {
        $file = Upload::files('po')[0] ?? null;
        if ($file === null) {
            return;
        }

        $error = Upload::validate($file, Config::get('uploads.po.extensions'), (int) Config::get('uploads.po.max_bytes'));
        if ($error !== null) {
            Flash::error($error);

            return;
        }

        $relativePath = Upload::store($file, 'pos/' . $order['client_id']);
        $absolutePath = Upload::absolutePath($relativePath);
        $poNumber = trim((string) Request::post('po_number', ''));

        OrderPoDocument::create([
            'order_id' => $order['id'],
            'po_number' => $poNumber,
            'file_path' => $relativePath,
            'original_filename' => Upload::displayName((string) $file['name']),
            'mime_type' => $absolutePath !== null ? Upload::detectMime($absolutePath) : null,
            'file_size' => (int) $file['size'],
            'is_original' => false,
            'note' => $note,
            'uploaded_by' => Auth::id(),
        ]);

        if ($poNumber !== '') {
            Order::setPoNumber((int) $order['id'], $poNumber);
        }
    }

    private function findVisibleOrder(int $id): ?array
    {
        $order = Order::find($id);
        if ($order === null || (!Auth::isStaff() && (int) $order['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Order not found', 'That order does not exist or is not available to you.');

            return null;
        }

        return $order;
    }
}
