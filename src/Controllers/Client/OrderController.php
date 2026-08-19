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
use App\Services\Notifications;
use App\Services\OrderPlacement;
use RuntimeException;

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
