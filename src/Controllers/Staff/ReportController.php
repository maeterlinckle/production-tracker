<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Client;
use App\Models\OrderLine;
use App\Services\PartsOnOrder;
use App\Services\Reminders;

final class ReportController
{
    public function index(): void
    {
        Auth::authorize('view_orders');

        View::render('staff/reports/index', ['title' => 'Reports']);
    }

    public function partsOnOrder(): void
    {
        Auth::authorize('view_orders');

        $clientId = Request::query('client_id');
        $clientId = $clientId === null || $clientId === '' ? null : (int) $clientId;

        $lines = PartsOnOrder::lines($clientId);
        $ageingDays = Reminders::ageingDays();

        // CSV is a query-string variant of the same page rather than a separate
        // route, so whatever you have filtered to is what you get in the file.
        if (Request::query('format') === 'csv') {
            $this->streamCsv($lines);

            return;
        }

        View::render('staff/reports/parts-on-order', [
            'title' => 'Parts on order',
            'parts' => PartsOnOrder::groupByPart($lines),
            'totals' => PartsOnOrder::totals($lines, $ageingDays),
            'ageingDays' => $ageingDays,
            'clients' => Client::all(),
            'clientId' => $clientId,
        ]);
    }

    /**
     * One row per order line, not per part.
     *
     * A spreadsheet can group for itself; it cannot ungroup. The per-part totals
     * on the screen are a reading aid, and exporting them instead would throw
     * away which order each quantity belongs to.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function streamCsv(array $lines): void
    {
        $filename = 'parts-on-order-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'wb');

        // A BOM, so Excel opens it as UTF-8 rather than mangling the £ and the
        // em dashes in part names.
        fwrite($out, "\xEF\xBB\xBF");

        // The escape character is passed explicitly: PHP 8.4 deprecates relying
        // on the default, and an empty string is the correct value for real CSV
        // — the backslash escaping PHP has always done by default is not part of
        // the format and confuses every spreadsheet that reads it.
        $row = static fn (array $values): bool|int => fputcsv($out, $values, ',', '"', '');

        $row([
            'Client', 'Part number', 'Part name', 'Material', 'Order', 'Purchase order',
            'Placed', 'Days open', 'Ordered', 'Completed', 'Outstanding', 'Delivered',
            'Failed', 'Status', 'Held up by',
        ]);

        foreach ($lines as $line) {
            $row([
                $line['client_name'],
                $line['cpn'],
                $line['part_name'],
                $line['base_material'] ?? '',
                $line['order_number'],
                $line['po_number'] !== '' ? $line['po_number'] : $line['po_original_filename'],
                date('Y-m-d', strtotime((string) $line['placed_at'])),
                (int) $line['days_open'],
                (int) $line['qty_ordered'],
                (int) $line['qty_completed'],
                (int) $line['qty_outstanding'],
                (int) $line['qty_delivered'],
                (int) $line['qty_failed'],
                OrderLine::statusLabel($line),
                PartsOnOrder::holdReason($line),
            ]);
        }

        fclose($out);
    }
}
