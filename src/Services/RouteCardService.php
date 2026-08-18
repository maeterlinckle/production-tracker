<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;

/**
 * Route cards, built on request (item 3).
 *
 * Nothing is stored. A route card is a printout of what an order line says
 * right now — the quantity, the material, where the parts have got to — and a
 * saved copy is out of date the moment anybody moves anything. The old
 * "regenerate" button existed entirely to paper over that; with the card built
 * from live data there is nothing to regenerate, so there is one action rather
 * than two.
 *
 * The reference is derived rather than allocated for the same reason: an
 * order number and a line number already identify the card uniquely, and a
 * sequence would have needed somewhere to live.
 */
final class RouteCardService
{
    public static function reference(array $order, array $line): string
    {
        return 'RC-' . $order['order_number'] . '-' . str_pad((string) $line['line_no'], 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{bytes:string,filename:string}
     */
    public static function render(int $orderLineId): array
    {
        $line = OrderLine::find($orderLineId);
        if ($line === null) {
            throw new \RuntimeException('That order line does not exist.');
        }

        $order = Order::find((int) $line['order_id']);
        $part = Part::find((int) $line['part_id']);
        $client = Client::find((int) $order['client_id']);
        $reference = self::reference($order, $line);

        $bytes = PdfService::render('pdf/route-card', [
            'routeCard' => ['reference' => $reference, 'generated_at' => date('Y-m-d H:i:s')],
            'line' => $line,
            'order' => $order,
            'part' => $part,
            'client' => $client,
            'qrDataUri' => QrCodeService::pngDataUri(QrCodeService::jobUrl('/staff/orders/' . $order['id'])),
        ]);

        return ['bytes' => $bytes, 'filename' => $reference . '.pdf'];
    }
}
