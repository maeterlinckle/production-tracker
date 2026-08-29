<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ReferenceNumber;
use PDO;

final class Order
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM orders WHERE id = :id', ['id' => $id]);
    }

    public static function forClient(int $clientId): array
    {
        return Database::all('SELECT * FROM orders WHERE client_id = :client_id ORDER BY placed_at DESC', ['client_id' => $clientId]);
    }

    /**
     * Every order, or only those belonging to clients still trading.
     *
     * A deactivated client's orders are hidden rather than deleted: they are
     * still what happened, still attributable, and still there the moment the
     * account is switched back on. Junction's list can ask for them with
     * `$includeInactiveClients`, which is what the "show closed accounts"
     * toggle does.
     */
    public static function all(bool $includeInactiveClients = false): array
    {
        $sql = 'SELECT o.*, c.name AS client_name, c.is_active AS client_is_active
                  FROM orders o JOIN clients c ON c.id = o.client_id';

        if (!$includeInactiveClients) {
            $sql .= ' WHERE c.is_active = 1';
        }

        return Database::all($sql . ' ORDER BY o.placed_at DESC');
    }

    /** How many orders belong to clients that are switched off. */
    public static function countOnInactiveClients(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM orders o JOIN clients c ON c.id = o.client_id WHERE c.is_active = 0'
        );
    }

    /**
     * Attach each order's line count and rolled-up status.
     *
     * Both order lists want these and only one of them used to have them, so
     * Junction's list of every order in the shop was the one place you could
     * not see what state an order was in. The derivation lives here rather than
     * in either controller, so the two lists cannot come to different answers
     * about the same order.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array<string,mixed>>
     */
    public static function withRollup(array $orders): array
    {
        $byOrder = OrderLine::forOrders(array_map(static fn (array $o): int => (int) $o['id'], $orders));

        return array_map(static function (array $order) use ($byOrder): array {
            $lines = $byOrder[(int) $order['id']] ?? [];
            $order['line_count'] = count($lines);
            $order['rollup_status'] = self::rollupStatus($lines);

            return $order;
        }, $orders);
    }

    /**
     * Creates an order with its lines in one transaction. $lines is a list of
     * ['part_id', 'qty_ordered', 'unit_price', 'qty_free_issue_required', 'needs_free_issue'].
     *
     * Each line's whole quantity is seeded into the distribution at its entry
     * stage -- 'awaiting free issue' for a part built from client material,
     * 'ready for production' for one that is not. That single choice is the
     * only place the two paths differ; from then on they are the same code.
     */
    public static function createWithLines(array $order, array $lines): int
    {
        return Database::transaction(static function (PDO $pdo) use ($order, $lines): int {
            $orderNumber = ReferenceNumber::next('ORD', $pdo);

            $statement = $pdo->prepare(
                'INSERT INTO orders (client_id, order_number, po_number, po_file_path, po_original_filename, placed_by, notes)
                 VALUES (:client_id, :order_number, :po_number, :po_file_path, :po_original_filename, :placed_by, :notes)'
            );
            $statement->execute([
                'client_id' => $order['client_id'],
                'order_number' => $orderNumber,
                'po_number' => $order['po_number'],
                'po_file_path' => $order['po_file_path'],
                'po_original_filename' => $order['po_original_filename'],
                'placed_by' => $order['placed_by'],
                'notes' => $order['notes'] ?? null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO order_po_documents (order_id, po_number, file_path, original_filename, is_original, uploaded_by)
                 VALUES (:order_id, :po_number, :file_path, :original_filename, 1, :uploaded_by)'
            )->execute([
                'order_id' => $orderId,
                'po_number' => $order['po_number'],
                'file_path' => $order['po_file_path'],
                'original_filename' => $order['po_original_filename'],
                'uploaded_by' => $order['placed_by'],
            ]);

            $lineStatement = $pdo->prepare(
                'INSERT INTO order_lines (
                    order_id, part_id, line_no, qty_ordered, unit_price, qty_free_issue_required
                ) VALUES (
                    :order_id, :part_id, :line_no, :qty_ordered, :unit_price, :qty_free_issue_required
                )'
            );

            $lineNo = 1;
            foreach ($lines as $line) {
                $freeIssueRequired = (int) ($line['qty_free_issue_required'] ?? 0);

                $lineStatement->execute([
                    'order_id' => $orderId,
                    'part_id' => $line['part_id'],
                    'line_no' => $lineNo,
                    'qty_ordered' => $line['qty_ordered'],
                    'unit_price' => $line['unit_price'],
                    'qty_free_issue_required' => $freeIssueRequired,
                ]);

                OrderLine::seedDistribution(
                    $pdo,
                    (int) $pdo->lastInsertId(),
                    (int) $line['qty_ordered'],
                    !empty($line['needs_free_issue']) ? 'awaiting_free_issue' : 'ready_for_production',
                    (int) $order['placed_by']
                );

                $lineNo++;
            }

            return $orderId;
        });
    }

    public static function setPoNumber(int $id, string $poNumber): void
    {
        Database::query('UPDATE orders SET po_number = :po_number WHERE id = :id', ['po_number' => $poNumber, 'id' => $id]);
    }

    /**
     * Close the order down: every line's outstanding quantity is cancelled off.
     *
     * @return int how much quantity was cancelled across the order
     */
    public static function closeDown(int $id, int $userId, string $reason): int
    {
        $cancelled = 0;

        foreach (OrderLine::forOrder($id) as $line) {
            $cancelled += OrderLine::closeDown((int) $line['id'], $userId, $reason);
        }

        Database::query(
            'UPDATE orders SET closed_at = NOW(), closed_by = :user, close_reason = :reason WHERE id = :id',
            ['user' => $userId, 'reason' => $reason, 'id' => $id]
        );

        return $cancelled;
    }

    public static function isClosed(array $order): bool
    {
        return !empty($order['closed_at']);
    }

    /**
     * A single status for an order made of many lines, for listings and the
     * page heading.
     *
     * The rule is "report the least advanced thing still outstanding", because
     * that is what somebody scanning a list of orders wants to know: an order is
     * not delivered while any of it is still on a machine. Cancelled and failed
     * quantity is ignored unless it is all there is.
     *
     * @param array<int,array<string,mixed>> $lines lines with distributions attached
     */
    public static function rollupStatus(array $lines): string
    {
        if ($lines === []) {
            return 'ordered';
        }

        $totals = array_fill_keys(OrderLine::STAGES, 0);

        foreach ($lines as $line) {
            foreach (OrderLine::STAGES as $stage) {
                $totals[$stage] += OrderLine::qtyAt($line, $stage);
            }
        }

        $inFlow = 0;
        foreach (OrderLine::FLOW_STAGES as $stage) {
            $inFlow += $totals[$stage];
        }

        if ($inFlow === 0) {
            return $totals['cancelled'] > 0 ? 'cancelled' : 'closed';
        }

        foreach (['awaiting_free_issue', 'ready_for_production', 'in_production', 'complete'] as $stage) {
            if ($totals[$stage] > 0) {
                // Something has gone out already, but not all of it.
                if ($totals['delivered'] + $totals['invoiced'] > 0) {
                    return 'partially_delivered';
                }

                return $stage;
            }
        }

        if ($totals['delivered'] > 0) {
            return 'delivered';
        }

        return 'closed';
    }
}
