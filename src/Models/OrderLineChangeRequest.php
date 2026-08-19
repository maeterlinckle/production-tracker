<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * A client asking for a different quantity on a line that is already running.
 *
 * The request changes nothing on its own. Junction has usually already bought
 * material, set up a machine or made half of it by the time one of these
 * arrives, so what the client can do is ask; what staff do is decide, and it is
 * the deciding that moves the quantity.
 *
 * Applying an increase puts the extra parts into the distribution at whichever
 * stage new quantity enters the line at. Applying a decrease takes parts out of
 * the least advanced stages first, and refuses outright to eat into anything
 * already made -- see reducibleQty().
 */
final class OrderLineChangeRequest
{
    /**
     * Stages a reduction may take quantity from, least advanced first.
     *
     * Made, despatched and invoiced parts are absent on purpose: none of those
     * can be un-happened by editing a number. Cancelled quantity is last because
     * taking it away rewrites a decision somebody already recorded, which is
     * worth doing only when there is nothing else left to take.
     */
    private const DRAIN_ORDER = [
        'awaiting_free_issue',
        'ready_for_production',
        'in_production',
        'failed',
        'cancelled',
    ];

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT r.*, u.name AS requested_by_name, rv.name AS reviewed_by_name,
                    ol.order_id, ol.part_id, p.cpn, p.name AS part_name, o.order_number, o.client_id
               FROM order_line_change_requests r
               JOIN users u ON u.id = r.requested_by
          LEFT JOIN users rv ON rv.id = r.reviewed_by
               JOIN order_lines ol ON ol.id = r.order_line_id
               JOIN parts p ON p.id = ol.part_id
               JOIN orders o ON o.id = ol.order_id
              WHERE r.id = :id',
            ['id' => $id]
        );
    }

    public static function forLine(int $lineId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS requested_by_name, rv.name AS reviewed_by_name
               FROM order_line_change_requests r
               JOIN users u ON u.id = r.requested_by
          LEFT JOIN users rv ON rv.id = r.reviewed_by
              WHERE r.order_line_id = :id
              ORDER BY r.requested_at DESC',
            ['id' => $lineId]
        );
    }

    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS requested_by_name, rv.name AS reviewed_by_name,
                    ol.line_no, p.cpn, p.name AS part_name
               FROM order_line_change_requests r
               JOIN order_lines ol ON ol.id = r.order_line_id
               JOIN parts p ON p.id = ol.part_id
               JOIN users u ON u.id = r.requested_by
          LEFT JOIN users rv ON rv.id = r.reviewed_by
              WHERE ol.order_id = :id
              ORDER BY r.requested_at DESC',
            ['id' => $orderId]
        );
    }

    /** Every request still waiting on a decision, across all clients. */
    public static function pending(): array
    {
        return Database::all(
            "SELECT r.*, u.name AS requested_by_name, ol.line_no, ol.order_id,
                    p.cpn, p.name AS part_name, o.order_number, c.name AS client_name
               FROM order_line_change_requests r
               JOIN order_lines ol ON ol.id = r.order_line_id
               JOIN parts p ON p.id = ol.part_id
               JOIN orders o ON o.id = ol.order_id
               JOIN clients c ON c.id = o.client_id
               JOIN users u ON u.id = r.requested_by
              WHERE r.status = 'pending'
              ORDER BY r.requested_at"
        );
    }

    public static function pendingForLine(int $lineId): ?array
    {
        return Database::one(
            "SELECT * FROM order_line_change_requests
              WHERE order_line_id = :id AND status = 'pending'
              ORDER BY requested_at DESC LIMIT 1",
            ['id' => $lineId]
        );
    }

    public static function create(
        int $lineId,
        int $qtyAtRequest,
        int $qtyRequested,
        ?string $reason,
        int $userId,
        string $initiatedBy = 'client'
    ): int {
        return Database::insert(
            'INSERT INTO order_line_change_requests (
                order_line_id, initiated_by, qty_at_request, qty_requested, reason, requested_by
             ) VALUES (
                :line_id, :initiated_by, :qty_at, :qty_requested, :reason, :user
             )',
            [
                'line_id' => $lineId,
                'initiated_by' => $initiatedBy === 'staff' ? 'staff' : 'client',
                'qty_at' => $qtyAtRequest,
                'qty_requested' => $qtyRequested,
                'reason' => $reason,
                'user' => $userId,
            ]
        );
    }

    /**
     * A change Junction makes itself, usually because an amended purchase order
     * has arrived (item 7).
     *
     * Recorded in this table and applied in the same breath, because there is
     * nobody left to ask: the person deciding and the person applying are the
     * same, and a row that sat "pending" for the length of one function call
     * would be a fiction. It goes here rather than somewhere of its own so that
     * "how did this line come to be 32" has one answer whoever asked for it.
     *
     * @throws RuntimeException if the reduction would eat into finished work
     */
    public static function applyStaffChange(int $lineId, int $qtyRequested, ?string $reason, int $userId): int
    {
        $line = OrderLine::find($lineId);
        if ($line === null) {
            throw new RuntimeException('That order line no longer exists.');
        }

        $requestId = self::create(
            $lineId,
            (int) $line['qty_ordered'],
            $qtyRequested,
            $reason,
            $userId,
            'staff'
        );

        // apply() does the checking, so a reduction below what is already made
        // is refused here on exactly the terms it is refused a client. If it
        // refuses, the row goes with it: a staff change is one action, and
        // leaving a request behind that nobody made and nobody can approve
        // would put a permanent "pending" on the line.
        try {
            self::apply($requestId, $userId, $reason);
        } catch (RuntimeException $e) {
            Database::query('DELETE FROM order_line_change_requests WHERE id = :id', ['id' => $requestId]);

            throw $e;
        }

        return $requestId;
    }

    public static function decline(int $id, int $userId, ?string $notes): void
    {
        Database::query(
            "UPDATE order_line_change_requests
                SET status = 'declined', reviewed_by = :user, reviewed_at = NOW(), review_notes = :notes
              WHERE id = :id AND status = 'pending'",
            ['user' => $userId, 'notes' => $notes, 'id' => $id]
        );
    }

    /**
     * How much of a line's quantity a reduction could still take, and where
     * from.
     *
     * This is what the review screen shows staff before they commit: a line of
     * 20 with 14 already delivered can only come down to 14, and saying so up
     * front is better than a validation error after the decision is made.
     *
     * @return array{reducible:int,floor:int,breakdown:array<string,int>}
     */
    public static function reducibleQty(array $line): array
    {
        $breakdown = [];
        $reducible = 0;

        foreach (self::DRAIN_ORDER as $stage) {
            $qty = OrderLine::qtyAt($line, $stage);
            if ($qty > 0) {
                $breakdown[$stage] = $qty;
                $reducible += $qty;
            }
        }

        return [
            'reducible' => $reducible,
            'floor' => (int) $line['qty_ordered'] - $reducible,
            'breakdown' => $breakdown,
        ];
    }

    /** Apply the request: this is the only thing that changes qty_ordered. */
    public static function apply(int $id, int $userId, ?string $notes): void
    {
        $request = self::find($id);
        if ($request === null || $request['status'] !== 'pending') {
            throw new RuntimeException('That change request has already been dealt with.');
        }

        $line = OrderLine::find((int) $request['order_line_id']);
        if ($line === null) {
            throw new RuntimeException('That order line no longer exists.');
        }

        $current = (int) $line['qty_ordered'];
        $requested = (int) $request['qty_requested'];
        $delta = $requested - $current;

        if ($delta === 0) {
            throw new RuntimeException('The line is already at ' . $requested . '.');
        }

        if ($delta < 0) {
            $limits = self::reducibleQty($line);
            if (-$delta > $limits['reducible']) {
                throw new RuntimeException(
                    'This line cannot go below ' . $limits['floor'] . ': that much has already been made, '
                    . 'delivered or invoiced and cannot be taken back off the order.'
                );
            }
        }

        $reference = 'Change request #' . $id;

        Database::transaction(static function (PDO $pdo) use ($id, $userId, $notes, $line, $delta, $reference): void {
            $lineId = (int) $line['id'];

            if ($delta > 0) {
                OrderLine::moveWithin($pdo, $lineId, null, OrderLine::entryStage($line), $delta, $userId, $reference);
            } else {
                $remaining = -$delta;

                foreach (self::DRAIN_ORDER as $stage) {
                    if ($remaining === 0) {
                        break;
                    }

                    $available = OrderLine::qtyAt($line, $stage);
                    if ($available <= 0) {
                        continue;
                    }

                    $take = min($available, $remaining);
                    OrderLine::moveWithin($pdo, $lineId, $stage, null, $take, $userId, $reference);
                    $remaining -= $take;
                }
            }

            // The material requirement needs no adjustment here. It is derived
            // from the ordered, cancelled and failed quantities, all of which
            // the moves above have just changed, and OrderLine::moveWithin()
            // recomputes it as part of every move.

            $pdo->prepare(
                "UPDATE order_line_change_requests
                    SET status = 'applied', reviewed_by = :user, reviewed_at = NOW(), review_notes = :notes
                  WHERE id = :id"
            )->execute(['user' => $userId, 'notes' => $notes, 'id' => $id]);
        });
    }
}
