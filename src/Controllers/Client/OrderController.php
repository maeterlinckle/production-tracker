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
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineChangeRequest;
use App\Models\OrderLineDueDate;
use App\Models\OrderNote;
use App\Models\OrderPhoto;
use App\Models\OrderPoDocument;
use App\Models\OrderQuery;
use App\Models\Part;
use App\Services\Notifications;
use App\Services\OrderPlacement;
use App\Services\OrderView;
use App\Services\PartsReturnService;
use RuntimeException;

final class OrderController
{
    public function index(): void
    {
        Auth::authorize('view_orders');
        $orders = Order::withRollup(Order::forClient((int) Auth::clientId()));

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

        try {
            $orderId = OrderPlacement::placeFromRequest((int) Auth::clientId(), (int) Auth::id());
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/orders/new');

            return;
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

        // One payload, one template, both audiences — see App\Services\OrderView.
        View::render('orders/show', OrderView::payload($order));
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
    /**
     * When the client needs the parts on one line.
     *
     * A statement of need, not a change to the order: nothing about what is
     * owed moves, so this is not a quantity-change request and does not go
     * near approval. Junction reads it to decide what to set up next.
     */
    public function updateDueDates(string $id, string $lineId): void
    {
        Auth::authorize('set_due_dates');

        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        $line = OrderLine::find((int) $lineId);

        if ($line === null || (int) $line['order_id'] !== (int) $order['id']) {
            View::renderError(404, 'Line not found', 'That line is not on this order.');

            return;
        }

        if (!Client::isActive((int) $order['client_id'])) {
            Flash::error('This account is not active, so nothing on it can be changed.');
            Response::redirect('/orders/' . $id . '#line-' . $lineId);
        }

        $quantities = Request::post('due_qty', []);
        $dates = Request::post('due_date', []);
        $notes = Request::post('due_note', []);

        $rows = [];
        if (is_array($quantities)) {
            foreach (array_values($quantities) as $i => $qty) {
                $rows[] = [
                    'qty' => (string) $qty,
                    'due_date' => (string) (array_values((array) $dates)[$i] ?? ''),
                    'note' => (string) (array_values((array) $notes)[$i] ?? ''),
                ];
            }
        }

        OrderLineDueDate::replace((int) $lineId, $rows, (int) Auth::id());

        Flash::success('Required-by dates updated. Junction can see them on the order.');
        Response::redirect('/orders/' . $id . '#line-' . $lineId);
    }

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

    /**
     * Send finished parts back because they failed the client's own inspection.
     *
     * Raises the paperwork and nothing else. The parts are still in the
     * client's building at this point, so nothing on the order moves: what
     * moves quantity is Junction booking the parcel in at the other end, which
     * is the same shape as free-issue material arriving.
     */
    public function raisePartsReturn(string $id): void
    {
        Auth::authorize('return_rejected_parts');

        $order = $this->findVisibleOrder((int) $id);
        if ($order === null) {
            return;
        }

        // One control, one value: the select carries the despatch and the part
        // together, because "which part" only means anything once "off which
        // delivery" has been answered.
        [$noteId, $lineId] = array_pad(explode(':', (string) Request::post('return_target', '')), 2, '');

        try {
            $returnNoteId = PartsReturnService::raise(
                (int) $order['id'],
                (int) $noteId,
                (int) $lineId,
                (int) Request::post('qty', 0),
                (string) Request::post('problem', ''),
                (int) Auth::id()
            );
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/orders/' . $id . '#delivery-notes');

            return;
        }

        $note = DeliveryNote::find($returnNoteId);
        $lines = DeliveryNote::lines($returnNoteId);

        Notifications::partsReturned(
            $note,
            $lines[0],
            $order,
            Client::find((int) $order['client_id']),
            (string) $note['notes']
        );

        Flash::success(
            'Return note ' . $note['reference'] . ' raised. Print it and send it back with the parts — '
            . 'Junction will book them in when they arrive.'
        );
        Response::redirect('/orders/' . $id . '#delivery-notes');
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
