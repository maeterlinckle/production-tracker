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
use App\Models\OrderNote;
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
                'qty_free_issue_required' => Part::freeIssueMaterials((int) $part['id']) === []
                    ? 0
                    : max($required, $posted),
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

        // One free-issue delivery note per line that needs material sent in
        // (item 6) -- generated immediately so the client can print it
        // straight away, each with its own QR back to that line's check-in.
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
        $order = Order::find((int) $id);
        if ($order === null || (!Auth::isStaff() && (int) $order['client_id'] !== Auth::clientId())) {
            View::renderError(404, 'Order not found', 'That order does not exist or is not available to you.');

            return;
        }

        $lines = OrderLine::forOrder($order['id']);

        $queries = array_map(static function (array $q) {
            $q['replies'] = OrderQuery::replies((int) $q['id']);

            return $q;
        }, OrderQuery::forOrder($order['id']));

        View::render('orders/show', [
            'title' => $order['order_number'],
            'order' => $order,
            'lines' => $lines,
            'deliveryNotes' => DeliveryNote::forOrder($order['id']),
            'notes' => OrderNote::forOrder($order['id']),
            'queries' => $queries,
            'rollupStatus' => Order::rollupStatus($lines),
        ]);
    }
}
