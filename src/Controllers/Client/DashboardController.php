<?php

declare(strict_types=1);

namespace App\Controllers\Client;

use App\Core\Auth;
use App\Core\Response;
use App\Core\View;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;

final class DashboardController
{
    public function index(): void
    {
        if (Auth::isStaff()) {
            Response::redirect('/staff');
        }

        $clientId = Auth::clientId();
        $parts = Part::forClient($clientId);
        $orders = Order::forClient($clientId);

        $partsByStatus = ['draft' => 0, 'quoted' => 0];
        foreach ($parts as $part) {
            $partsByStatus[$part['status']] = ($partsByStatus[$part['status']] ?? 0) + 1;
        }

        $ordersWithStatus = array_map(static function (array $order): array {
            $lines = OrderLine::forOrder((int) $order['id']);
            $order['rollup_status'] = Order::rollupStatus($lines);

            return $order;
        }, array_slice($orders, 0, 8));

        View::render('dashboard/client', [
            'title' => 'Dashboard',
            'parts' => $parts,
            'partsByStatus' => $partsByStatus,
            'orders' => $ordersWithStatus,
            'totalOrders' => count($orders),
        ]);
    }
}
