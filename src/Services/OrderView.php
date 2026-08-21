<?php

declare(strict_types=1);

namespace App\Services;

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

/**
 * Everything the order page needs, assembled once.
 *
 * As with the part page, there were two of these and they had already started
 * to disagree — the client's listed delivery notes one way and Junction's
 * another, and only one of them showed where a line's quantity had got to. An
 * order is one thing.
 *
 * The payload is the superset and the template decides what to show. That is
 * deliberate: gating in the template, next to the markup it hides, is where
 * somebody reading the page will look for it. The one thing not gated here is
 * pricing, which is refused at source by the capability check wherever a figure
 * is rendered.
 */
final class OrderView
{
    /**
     * @param array<string,mixed> $order a row from Order::find()
     * @return array<string,mixed>
     */
    public static function payload(array $order): array
    {
        $orderId = (int) $order['id'];
        $lines = OrderLine::forOrder($orderId);

        $lineDetail = [];
        $parts = [];
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $lineDetail[$lineId] = [
                'change_requests' => OrderLineChangeRequest::forLine($lineId),
                'rejections' => OrderLine::rejections($lineId),
                'failures' => OrderLine::failureHistory($lineId),
                'moves' => OrderLine::stageMoves($lineId),
                'open_discrepancy' => OrderLine::openDiscrepancy($lineId),
            ];
            $parts[$lineId] = Part::find((int) $line['part_id']);
        }

        $deliveryNotes = DeliveryNote::forOrder($orderId);
        $invoicesByDn = [];
        foreach ($deliveryNotes as $note) {
            $invoicesByDn[$note['id']] = Invoice::forDeliveryNote((int) $note['id']);
        }

        $queries = array_map(static function (array $query): array {
            $query['replies'] = OrderQuery::replies((int) $query['id']);

            return $query;
        }, OrderQuery::forOrder($orderId));

        return [
            'title' => $order['order_number'],
            'order' => $order,
            'client' => Client::find((int) $order['client_id']),
            'lines' => $lines,
            'lineDetail' => $lineDetail,
            'parts' => $parts,
            'deliveryNotes' => $deliveryNotes,
            'invoicesByDn' => $invoicesByDn,
            'freeIssueTotals' => DeliveryNote::freeIssueTotalsForOrder($orderId),
            // What the client could still send back, and off which despatch.
            // Assembled for both audiences: the form is theirs, but the figures
            // are the same ones Junction is looking at.
            'returnableLines' => DeliveryNote::returnableLinesForOrder($orderId),
            'poDocuments' => OrderPoDocument::forOrder($orderId),
            'photos' => OrderPhoto::forOrder($orderId),
            'notes' => OrderNote::forOrder($orderId),
            'queries' => $queries,
            'rollupStatus' => Order::rollupStatus($lines),
        ];
    }
}
