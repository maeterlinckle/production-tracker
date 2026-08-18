<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * What is still to be made, and how much of it.
 *
 * The question this answers is not "show me the open orders" — the orders list
 * already does that. It is "how many of *this part* do we owe, across
 * everything": the same part often appears on several purchase orders at once,
 * and setting up twice for two lines of the same component is the waste this
 * report exists to prevent.
 *
 * So the rows are per part, with the orders that make up each total underneath.
 *
 * "Outstanding" is `qty_ordered - qty_completed`: parts that still have to come
 * off a machine. Completed-but-undelivered is a despatch problem rather than a
 * production one, and it is shown separately so the two are not confused.
 */
final class PartsOnOrder
{
    /**
     * One row per outstanding order line.
     *
     * The grouping happens in PHP rather than with a GROUP BY plus a second
     * query: the whole result is a few hundred rows at the very most, and doing
     * it in one pass means the totals and the breakdown cannot disagree.
     *
     * @param int|null $clientId Restrict to one client, or null for all
     * @return array<int,array<string,mixed>>
     */
    public static function lines(?int $clientId = null): array
    {
        // Two conditions, not one. The counter is the real measure, but a line
        // whose stage says complete or closed is finished as far as the workshop
        // is concerned whatever the counter says — and historic rows exist where
        // the two disagree, from before setStage() started keeping them in step.
        $where = ["ol.stage NOT IN ('complete', 'closed')", 'ol.qty_completed < ol.qty_ordered'];
        $params = [];

        if ($clientId !== null) {
            $where[] = 'o.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        return Database::all(
            'SELECT ol.id, ol.order_id, ol.part_id, ol.stage, ol.qty_ordered, ol.qty_completed,
                    ol.qty_delivered, ol.qty_free_issue_required, ol.qty_free_issue_received,
                    ol.qty_ordered - ol.qty_completed AS qty_outstanding,
                    o.order_number, o.placed_at, o.po_original_filename,
                    DATEDIFF(NOW(), o.placed_at) AS days_open,
                    p.cpn, p.name AS part_name, p.base_material,
                    c.id AS client_id, c.name AS client_name,
                    (SELECT COUNT(*) FROM free_issue_receipts fir
                      WHERE fir.order_line_id = ol.id
                        AND fir.discrepancy_type <> \'none\'
                        AND fir.resolved_at IS NULL) AS open_discrepancies
               FROM order_lines ol
               JOIN orders o ON o.id = ol.order_id
               JOIN parts p ON p.id = ol.part_id
               JOIN clients c ON c.id = o.client_id
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY p.cpn, o.placed_at',
            $params
        );
    }

    /**
     * The same lines, grouped by part.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function groupByPart(array $lines): array
    {
        $parts = [];

        foreach ($lines as $line) {
            $key = (int) $line['part_id'];

            if (!isset($parts[$key])) {
                $parts[$key] = [
                    'part_id' => $key,
                    'cpn' => $line['cpn'],
                    'part_name' => $line['part_name'],
                    'base_material' => $line['base_material'],
                    'client_id' => (int) $line['client_id'],
                    'client_name' => $line['client_name'],
                    'qty_ordered' => 0,
                    'qty_completed' => 0,
                    'qty_outstanding' => 0,
                    'qty_awaiting_despatch' => 0,
                    'order_count' => 0,
                    'oldest_days' => 0,
                    'blocked' => false,
                    'lines' => [],
                ];
            }

            $parts[$key]['qty_ordered'] += (int) $line['qty_ordered'];
            $parts[$key]['qty_completed'] += (int) $line['qty_completed'];
            $parts[$key]['qty_outstanding'] += (int) $line['qty_outstanding'];
            $parts[$key]['qty_awaiting_despatch'] += (int) $line['qty_completed'] - (int) $line['qty_delivered'];
            $parts[$key]['order_count']++;
            $parts[$key]['oldest_days'] = max($parts[$key]['oldest_days'], (int) $line['days_open']);
            $parts[$key]['blocked'] = $parts[$key]['blocked'] || self::isBlocked($line);
            $parts[$key]['lines'][] = $line;
        }

        // Most outstanding first: the report is read to decide what to set up
        // next, and the biggest number is the most likely answer.
        usort($parts, static fn (array $a, array $b): int => $b['qty_outstanding'] <=> $a['qty_outstanding']);

        return $parts;
    }

    /** Waiting on the client rather than on the workshop. */
    public static function isBlocked(array $line): bool
    {
        if ((int) $line['open_discrepancies'] > 0) {
            return true;
        }

        return (int) $line['qty_free_issue_required'] > (int) $line['qty_free_issue_received'];
    }

    /** A one-line description of why a line has not moved, for the report and the digest. */
    public static function holdReason(array $line): string
    {
        if ((int) $line['open_discrepancies'] > 0) {
            return 'Free-issue discrepancy unresolved';
        }

        if ((int) $line['qty_free_issue_required'] > (int) $line['qty_free_issue_received']) {
            $short = (int) $line['qty_free_issue_required'] - (int) $line['qty_free_issue_received'];

            return 'Awaiting free issue (' . $short . ' short)';
        }

        return '';
    }

    /**
     * Totals across everything on the report.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,int>
     */
    public static function totals(array $lines, int $ageingDays): array
    {
        $totals = [
            'lines' => count($lines),
            'parts' => 0,
            'orders' => 0,
            'clients' => 0,
            'qty_outstanding' => 0,
            'blocked' => 0,
            'ageing' => 0,
        ];

        $parts = $orders = $clients = [];

        foreach ($lines as $line) {
            $parts[(int) $line['part_id']] = true;
            $orders[(int) $line['order_id']] = true;
            $clients[(int) $line['client_id']] = true;

            $totals['qty_outstanding'] += (int) $line['qty_outstanding'];

            if (self::isBlocked($line)) {
                $totals['blocked']++;
            }

            if ((int) $line['days_open'] > $ageingDays) {
                $totals['ageing']++;
            }
        }

        $totals['parts'] = count($parts);
        $totals['orders'] = count($orders);
        $totals['clients'] = count($clients);

        return $totals;
    }
}
