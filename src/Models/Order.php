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

    public static function all(): array
    {
        return Database::all(
            'SELECT o.*, c.name AS client_name FROM orders o JOIN clients c ON c.id = o.client_id ORDER BY o.placed_at DESC'
        );
    }

    /**
     * Creates an order with its lines in one transaction. $lines is a list of
     * ['part_id', 'qty_ordered', 'unit_price', 'qty_free_issue_required', 'notes'].
     */
    public static function createWithLines(array $order, array $lines): int
    {
        return Database::transaction(static function (PDO $pdo) use ($order, $lines): int {
            $orderNumber = ReferenceNumber::next('ORD', $pdo);

            $statement = $pdo->prepare(
                'INSERT INTO orders (client_id, order_number, po_file_path, po_original_filename, placed_by, notes)
                 VALUES (:client_id, :order_number, :po_file_path, :po_original_filename, :placed_by, :notes)'
            );
            $statement->execute([
                'client_id' => $order['client_id'],
                'order_number' => $orderNumber,
                'po_file_path' => $order['po_file_path'],
                'po_original_filename' => $order['po_original_filename'],
                'placed_by' => $order['placed_by'],
                'notes' => $order['notes'] ?? null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $lineStatement = $pdo->prepare(
                'INSERT INTO order_lines (
                    order_id, part_id, line_no, qty_ordered, unit_price, stage, qty_free_issue_required
                ) VALUES (
                    :order_id, :part_id, :line_no, :qty_ordered, :unit_price, :stage, :qty_free_issue_required
                )'
            );

            $lineNo = 1;
            foreach ($lines as $line) {
                $freeIssueRequired = (int) ($line['qty_free_issue_required'] ?? 0);
                $stage = $freeIssueRequired > 0 ? 'awaiting_free_issue' : 'ready_for_production';

                $lineStatement->execute([
                    'order_id' => $orderId,
                    'part_id' => $line['part_id'],
                    'line_no' => $lineNo,
                    'qty_ordered' => $line['qty_ordered'],
                    'unit_price' => $line['unit_price'],
                    'stage' => $stage,
                    'qty_free_issue_required' => $freeIssueRequired,
                ]);
                $lineNo++;
            }

            return $orderId;
        });
    }

    /** Coarse rollup status for the client-facing simplified view. */
    public static function rollupStatus(array $lines): string
    {
        if ($lines === []) {
            return 'ordered';
        }

        $stages = array_column($lines, 'stage');

        if (count(array_unique($stages)) === 1 && $stages[0] === 'closed') {
            return 'closed';
        }
        if (in_array('awaiting_free_issue', $stages, true)) {
            return 'awaiting_free_issue';
        }

        $anyDelivered = false;
        $allFullyDelivered = true;
        foreach ($lines as $line) {
            if ((int) $line['qty_delivered'] > 0) {
                $anyDelivered = true;
            }
            if ((int) $line['qty_delivered'] < (int) $line['qty_ordered']) {
                $allFullyDelivered = false;
            }
        }

        if ($anyDelivered && !$allFullyDelivered) {
            return 'partially_delivered';
        }
        if ($allFullyDelivered) {
            return 'delivered';
        }
        if (in_array('in_production', $stages, true)) {
            return 'in_production';
        }
        if (in_array('complete', $stages, true)) {
            return 'complete';
        }

        return 'ready_for_production';
    }
}
