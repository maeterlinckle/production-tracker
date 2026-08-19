<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;
use RuntimeException;

/**
 * Route cards, built on request.
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
            throw new RuntimeException('That order line does not exist.');
        }

        $order = Order::find((int) $line['order_id']);
        $card = self::card($line, $order);

        return [
            'bytes' => PdfService::render('pdf/route-card', ['cards' => [$card]]),
            'filename' => $card['routeCard']['reference'] . '.pdf',
        ];
    }

    /**
     * Every line on an order, one card to a page.
     *
     * For the person about to walk the whole job out to the machines: opening
     * eight lines and printing each is the same paper and eight more chances to
     * miss one.
     *
     * @return array{bytes:string,filename:string}
     */
    public static function renderForOrder(int $orderId): array
    {
        $order = Order::find($orderId);
        if ($order === null) {
            throw new RuntimeException('That order does not exist.');
        }

        $cards = [];
        foreach (OrderLine::forOrder($orderId) as $line) {
            $cards[] = self::card($line, $order);
        }

        if ($cards === []) {
            throw new RuntimeException('That order has no lines to print.');
        }

        return [
            'bytes' => PdfService::render('pdf/route-card', ['cards' => $cards]),
            'filename' => 'RC-' . $order['order_number'] . '-all.pdf',
        ];
    }

    /**
     * @return array{routeCard:array,line:array,order:array,part:array,client:array,qrDataUri:string}
     */
    private static function card(array $line, array $order): array
    {
        return [
            'routeCard' => [
                'reference' => self::reference($order, $line),
                'generated_at' => date('Y-m-d H:i:s'),
            ],
            'line' => $line,
            'order' => $order,
            'part' => Part::find((int) $line['part_id']),
            'client' => Client::find((int) $order['client_id']),
            // Straight to the line's own place on the order page: a card in a
            // hand is about one line, whatever else is on the order.
            'qrDataUri' => QrCodeService::pngDataUri(
                QrCodeService::jobUrl('/staff/orders/' . $order['id'] . '#line-' . $line['id'])
            ),
        ];
    }
}
