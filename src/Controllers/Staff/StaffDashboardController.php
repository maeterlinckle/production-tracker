<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\View;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Part;

final class StaffDashboardController
{
    public function index(): void
    {
        $uninvoiced = DeliveryNote::uninvoiced();
        $unquoted = Part::unquoted();
        $awaitingFreeIssue = OrderLine::awaitingFreeIssue();
        $orders = Order::all();

        View::render('staff/dashboard', [
            'title' => 'Staff dashboard',
            'uninvoiced' => $uninvoiced,
            'unquoted' => $unquoted,
            'awaitingFreeIssue' => $awaitingFreeIssue,
            'recentOrders' => array_slice($orders, 0, 8),
            'totalOrders' => count($orders),
        ]);
    }
}
