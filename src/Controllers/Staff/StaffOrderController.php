<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\View;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineChangeRequest;
use App\Models\OrderNote;
use App\Models\OrderPhoto;
use App\Models\OrderPoDocument;
use App\Models\OrderQuery;
use App\Models\Part;
use App\Services\FreeIssueNoteService;
use App\Services\Notifications;
use App\Services\RouteCardService;
use RuntimeException;

final class StaffOrderController
{
    public function index(): void
    {
        View::render('staff/orders/index', ['title' => 'Orders', 'orders' => Order::all()]);
    }

    public function show(string $id): void
    {
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $client = Client::find((int) $order['client_id']);
        $lines = OrderLine::forOrder($order['id']);

        // Everything the per-line panel needs, gathered once rather than from
        // inside the template's loop.
        $lineDetail = [];
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $lineDetail[$lineId] = [
                'change_requests' => OrderLineChangeRequest::forLine($lineId),
                'rejections' => OrderLine::rejections($lineId),
                'failures' => OrderLine::failureHistory($lineId),
                'moves' => OrderLine::stageMoves($lineId),
                'open_discrepancy' => OrderLine::openDiscrepancy($lineId),
            ];
        }

        $deliveryNotes = DeliveryNote::forOrder($order['id']);
        $invoicesByDn = [];
        foreach ($deliveryNotes as $dn) {
            $invoicesByDn[$dn['id']] = Invoice::forDeliveryNote((int) $dn['id']);
        }

        $queries = array_map(static function (array $q) {
            $q['replies'] = OrderQuery::replies((int) $q['id']);

            return $q;
        }, OrderQuery::forOrder($order['id']));

        View::render('staff/orders/show', [
            'title' => $order['order_number'],
            'order' => $order,
            'client' => $client,
            'lines' => $lines,
            'lineDetail' => $lineDetail,
            'deliveryNotes' => $deliveryNotes,
            'invoicesByDn' => $invoicesByDn,
            'poDocuments' => OrderPoDocument::forOrder($order['id']),
            'photos' => OrderPhoto::forOrder($order['id']),
            'notes' => OrderNote::forOrder($order['id']),
            'queries' => $queries,
            'rollupStatus' => Order::rollupStatus($lines),
        ]);
    }

    // -- The quantity workflow (item 6) --------------------------------------

    /**
     * Move a staff-entered number of parts between stages.
     *
     * One action covers advancing, moving back, and failing, because they are
     * the same operation with a different destination -- and having one of them
     * take a reason but not the others is how a reason ends up optional in
     * practice.
     */
    public function moveQuantity(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $from = (string) Request::post('from_stage', '');
        $to = (string) Request::post('to_stage', '');
        $qty = (int) Request::post('qty', 0);
        $reason = trim((string) Request::post('reason', '')) ?: null;

        $allowed = OrderLine::manualDestinations($line, $from);
        if (!in_array($to, $allowed, true)) {
            Flash::error('Parts cannot be moved from ' . ($from ?: 'nowhere') . ' to ' . ($to ?: 'nowhere') . ' by hand.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        // Material arrives and is booked in on the check-in screen, which is
        // also where anything wrong with it is dealt with. Letting the order
        // page push parts out of the first stage as well would be a second way
        // of recording the same arrival.
        if ($from === 'awaiting_free_issue' && $to === 'ready_for_production') {
            Flash::error('Material is booked in from the check-in screen, which is where rejections are handled too.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        if ($to === 'failed' && $reason === null) {
            Flash::error('Say why the parts failed — that reason is the only record of it.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        // Before completion a person is counting pieces of material; after it
        // they are counting parts. The number typed in is in the unit of the
        // stage it is being taken from, and converts on the way to the ledger.
        $stored = OrderLine::storedQtyFromEntered($line, $from, $qty);

        try {
            OrderLine::move((int) $id, $from, $to, $stored, (int) Auth::id(), $reason);
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        if ($to === 'in_production' && OrderLine::qtyAt($line, 'in_production') === 0) {
            $order = Order::find((int) $line['order_id']);
            if ($order !== null) {
                Notifications::orderLineInProduction($line, $order, (int) $order['client_id']);
            }
        }

        Flash::success($this->moveMessage($from, $to, $qty));
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    private function moveMessage(string $from, string $to, int $qty): string
    {
        if ($to === 'failed') {
            return $qty . ' marked as failed at ' . OrderLine::STAGE_SENTENCE_LABELS[$from]
                 . '. They are still owed on this line — ask for replacement material if the part needs it.';
        }

        if ($to === 'cancelled') {
            return $qty . ' cancelled off this line.';
        }

        return $qty . ' moved to ' . OrderLine::STAGE_SENTENCE_LABELS[$to] . '.';
    }

    /**
     * Put the request for replacement material in front of the client.
     *
     * The quantity is not entered and not stored. It is the shortfall in final
     * parts — everything currently failed on this line — turned back into
     * material units by the part's own ratio, rounded up, and it is worked out
     * again from scratch every time anything moves. Two parts failing tomorrow
     * raises the same one figure rather than adding a second request beside it.
     *
     * All this action does is make sure there is a note carrying that figure
     * and tell the client it has changed. An ordinary shortage never comes
     * through here: the material has not arrived yet, and the note already out
     * is the request for it.
     */
    public function requestReplacementMaterial(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $part = Part::find((int) $line['part_id']);
        if ($part === null || !Part::hasFreeIssue($part)) {
            Flash::error('This part is not made from free-issue material, so there is nothing to ask for.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        $failedParts = (int) $line['qty_failed'];
        if ($failedParts <= 0) {
            Flash::error('Nothing has failed on this line, so there is no shortfall to make up.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        $units = OrderLine::replacementUnitsForFailures($line);
        $noteId = FreeIssueNoteService::standingNoteFor((int) $id, (int) Auth::id());
        $note = DeliveryNote::find($noteId);

        Notifications::freeIssueNoteIssued($note, (int) $line['client_id']);

        $spare = Part::finalPartsFor($part, $units) - $failedParts;

        Flash::success(
            'Free-issue note ' . $note['reference'] . ' now asks for ' . $units
            . ' more to remake ' . $failedParts . ' failed part(s)'
            . ($spare > 0 ? ', which will leave ' . $spare . ' spare — material comes in whole pieces' : '')
            . '. The figure follows the shortfall, so it moves again if anything else fails.'
        );
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    /** Close a line down: outstanding quantity is cancelled off, not deleted. */
    public function closeLine(string $id): void
    {
        Auth::authorize('close_orders');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $reason = trim((string) Request::post('reason', ''));
        if ($reason === '') {
            Flash::error('Say why the line is being closed down.');
            Response::redirect('/staff/orders/' . $line['order_id']);
        }

        $cancelled = OrderLine::closeDown((int) $id, (int) Auth::id(), $reason);

        Flash::success($cancelled > 0
            ? $cancelled . ' cancelled off this line. It no longer counts as outstanding anywhere.'
            : 'Line closed down. There was nothing outstanding to cancel.');
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    public function reopenLine(string $id): void
    {
        Auth::authorize('close_orders');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        OrderLine::reopen((int) $id);
        Flash::success('Line reopened. Cancelled quantity stays cancelled until it is moved back by hand.');
        Response::redirect('/staff/orders/' . $line['order_id']);
    }

    public function closeOrder(string $id): void
    {
        Auth::authorize('close_orders');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $reason = trim((string) Request::post('reason', ''));
        if ($reason === '') {
            Flash::error('Say why the order is being closed down.');
            Response::redirect('/staff/orders/' . $id);
        }

        $cancelled = Order::closeDown((int) $id, (int) Auth::id(), $reason);

        Flash::success($cancelled . ' cancelled off across the order. Parts already made still have to go out.');
        Response::redirect('/staff/orders/' . $id);
    }

    // -- Quantity change requests (item 8) -----------------------------------

    public function applyChangeRequest(string $id, string $requestId): void
    {
        Auth::authorize('approve_quantity_changes');
        $request = OrderLineChangeRequest::find((int) $requestId);
        if ($request === null || (int) $request['order_id'] !== (int) $id) {
            View::renderError(404, 'Request not found', 'That change request does not exist on this order.');

            return;
        }

        $part = Part::find((int) $request['part_id']);
        $notes = trim((string) Request::post('review_notes', '')) ?: null;

        try {
            OrderLineChangeRequest::apply(
                (int) $requestId,
                (int) Auth::id(),
                $notes,
                static fn (int $qty): int => Part::freeIssueQtyFor($part ?? [], $qty)
            );
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/staff/orders/' . $id);
        }

        $this->announceDecision((int) $requestId, 'applied');

        Flash::success('Quantity change applied.');
        Response::redirect('/staff/orders/' . $id);
    }

    public function declineChangeRequest(string $id, string $requestId): void
    {
        Auth::authorize('approve_quantity_changes');
        $request = OrderLineChangeRequest::find((int) $requestId);
        if ($request === null || (int) $request['order_id'] !== (int) $id) {
            View::renderError(404, 'Request not found', 'That change request does not exist on this order.');

            return;
        }

        OrderLineChangeRequest::decline(
            (int) $requestId,
            (int) Auth::id(),
            trim((string) Request::post('review_notes', '')) ?: null
        );

        $this->announceDecision((int) $requestId, 'declined');

        Flash::success('Quantity change declined.');
        Response::redirect('/staff/orders/' . $id);
    }

    private function announceDecision(int $requestId, string $outcome): void
    {
        $request = OrderLineChangeRequest::find($requestId);
        if ($request === null) {
            return;
        }

        $line = OrderLine::find((int) $request['order_line_id']);
        $order = Order::find((int) $request['order_id']);

        if ($line !== null && $order !== null) {
            Notifications::quantityChangeDecided(
                $request,
                $line,
                $order,
                $outcome,
                (string) Auth::name(),
                (int) $order['client_id']
            );
        }
    }

    // -- Purchase order paperwork (items 8 and 9) ----------------------------

    public function uploadPoDocument(string $id): void
    {
        Auth::authorize('approve_quantity_changes');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $this->storePoDocument($order, '/staff/orders/' . $id);
    }

    /** Shared with the client-side upload: the same document history, either way in. */
    public function storePoDocument(array $order, string $redirectTo): void
    {
        $file = Upload::files('po')[0] ?? null;
        if ($file === null) {
            Flash::error('Choose a purchase order document to add.');
            Response::redirect($redirectTo);
        }

        $error = Upload::validate($file, Config::get('uploads.po.extensions'), (int) Config::get('uploads.po.max_bytes'));
        if ($error !== null) {
            Flash::error($error);
            Response::redirect($redirectTo);
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
            'note' => trim((string) Request::post('note', '')) ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        // An amended PO usually carries a new number, and the order should quote
        // whichever one the next invoice will be raised against.
        if ($poNumber !== '') {
            Order::setPoNumber((int) $order['id'], $poNumber);
        }

        Flash::success('Purchase order document added. The original is still on file.');
        Response::redirect($redirectTo);
    }

    public function updatePoNumber(string $id): void
    {
        Auth::authorize('approve_quantity_changes');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $poNumber = trim((string) Request::post('po_number', ''));
        if ($poNumber === '') {
            Flash::error('Enter a PO number.');
            Response::redirect('/staff/orders/' . $id);
        }

        Order::setPoNumber((int) $id, $poNumber);
        Flash::success('PO number updated. It is what the next Clear Books invoice for this order will quote.');
        Response::redirect('/staff/orders/' . $id);
    }

    // -- Route cards (item 3) ------------------------------------------------

    /**
     * View or print the route card. Built now, from the line as it stands --
     * there is nothing stored, so there is nothing to regenerate.
     */
    public function routeCard(string $id): void
    {
        Auth::authorize('production_control');
        $line = $this->findLine((int) $id);
        if ($line === null) {
            return;
        }

        $card = RouteCardService::render((int) $id);
        Response::inlineBytes($card['bytes'], $card['filename'], 'application/pdf');
    }

    /**
     * Every line's route card in one document, for the person who is about to
     * walk the whole order out to the machines.
     *
     * One card per page, in line order, built now like the single one — going
     * to eight lines individually and printing each is the same paper and eight
     * more chances to miss one.
     */
    public function allRouteCards(string $id): void
    {
        Auth::authorize('production_control');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $lines = OrderLine::forOrder((int) $id);
        if ($lines === []) {
            Flash::error('That order has no lines to print.');
            Response::redirect('/staff/orders/' . $id);
        }

        $cards = RouteCardService::renderForOrder((int) $id);
        Response::inlineBytes($cards['bytes'], $cards['filename'], 'application/pdf');
    }

    // -- Photos ---------------------------------------------------------------

    public function uploadPhoto(string $id): void
    {
        Auth::authorize('production_control');
        $order = Order::find((int) $id);
        if ($order === null) {
            View::renderError(404, 'Order not found', 'That order does not exist.');

            return;
        }

        $allowed = Config::get('uploads.photo.extensions');
        $maxBytes = (int) Config::get('uploads.photo.max_bytes');
        $lineId = (int) Request::post('order_line_id', 0) ?: null;
        $caption = trim((string) Request::post('caption', '')) ?: null;

        foreach (Upload::files('photos') as $file) {
            $error = Upload::validate($file, $allowed, $maxBytes);
            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $relativePath = Upload::store($file, 'order-photos/' . $order['id']);
            $absolutePath = Upload::absolutePath($relativePath);

            OrderPhoto::create([
                'order_id' => $order['id'],
                'order_line_id' => $lineId,
                'file_path' => $relativePath,
                'original_filename' => Upload::displayName((string) $file['name']),
                'mime_type' => $absolutePath !== null ? Upload::detectMime($absolutePath) : null,
                'file_size' => (int) $file['size'],
                'caption' => $caption,
                'uploaded_by' => Auth::id(),
            ]);
        }

        Flash::success('Photo uploaded.');
        Response::redirect('/staff/orders/' . $id);
    }

    public function deletePhoto(string $id, string $photoId): void
    {
        Auth::authorize('production_control');
        $photo = OrderPhoto::find((int) $photoId);
        if ($photo !== null && (int) $photo['order_id'] === (int) $id) {
            Upload::delete($photo['file_path']);
            OrderPhoto::delete($photo['id']);
            Flash::success('Photo removed.');
        }

        Response::redirect('/staff/orders/' . $id);
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
