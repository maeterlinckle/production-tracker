<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * An order line and the distribution of its quantity across production stages.
 *
 * A line does not have a status. Its quantity is spread across stages -- "12
 * awaiting free issue, 5 ready for production, 3 in production" -- and the
 * status a person reads is written out from that spread. Nobody sets it, and
 * there is nowhere it could be set.
 *
 * The invariant everything here maintains is that the rows in
 * order_line_quantities for a line sum to order_lines.qty_ordered. Every part
 * ordered is somewhere: in the flow, scrapped, or cancelled off. Quantity is
 * only created by placing an order or increasing one, and only destroyed by
 * reducing one -- both of which move qty_ordered by the same amount in the same
 * transaction.
 *
 * order_lines.qty_completed and its siblings are still here, and are still what
 * the reports and the despatch screen read, but nothing writes to them by hand:
 * recalculateTotals() derives all five from the distribution after every move.
 */
final class OrderLine
{
    /** The stages quantity moves through, in order. */
    public const FLOW_STAGES = [
        'awaiting_free_issue',
        'ready_for_production',
        'in_production',
        'complete',
        'delivered',
        'invoiced',
    ];

    /** Where quantity ends up when it does not finish the flow. */
    public const TERMINAL_STAGES = ['failed', 'cancelled'];

    public const STAGES = [
        'awaiting_free_issue',
        'ready_for_production',
        'in_production',
        'complete',
        'delivered',
        'invoiced',
        'failed',
        'cancelled',
    ];

    public const STAGE_LABELS = [
        'awaiting_free_issue' => 'Awaiting free issue',
        'ready_for_production' => 'Ready for production',
        'in_production' => 'In production',
        'complete' => 'Complete',
        'delivered' => 'Delivered',
        'invoiced' => 'Invoiced',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    /** The same names mid-sentence, for "12 awaiting free issue, 8 in production". */
    public const STAGE_SENTENCE_LABELS = [
        'awaiting_free_issue' => 'awaiting free issue',
        'ready_for_production' => 'ready for production',
        'in_production' => 'in production',
        'complete' => 'complete',
        'delivered' => 'delivered',
        'invoiced' => 'invoiced',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ];

    /**
     * Where staff may move quantity to, by hand, from each stage.
     *
     * 'delivered' and 'invoiced' are not reachable from here on purpose. Parts
     * become delivered by appearing on a delivery note and invoiced by appearing
     * on an invoice; letting somebody type the quantity into a box as well would
     * be a second, quieter way of saying the same thing, and the two would
     * disagree within a week.
     */
    public const MANUAL_DESTINATIONS = [
        'awaiting_free_issue' => ['ready_for_production', 'failed', 'cancelled'],
        'ready_for_production' => ['in_production', 'awaiting_free_issue', 'failed', 'cancelled'],
        'in_production' => ['complete', 'ready_for_production', 'failed', 'cancelled'],
        'complete' => ['in_production', 'failed', 'cancelled'],
        'delivered' => [],
        'invoiced' => [],
        // Failed parts go back into the flow once there is material to remake
        // them from -- that is the whole reason failed quantity is parked rather
        // than deducted.
        'failed' => ['awaiting_free_issue', 'ready_for_production', 'cancelled'],
        'cancelled' => ['awaiting_free_issue', 'ready_for_production'],
    ];

    // -- Reading -------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $line = Database::one(
            'SELECT ol.*, p.cpn, p.name AS part_name, p.has_free_issue, o.order_number, o.client_id
               FROM order_lines ol
               JOIN parts p ON p.id = ol.part_id
               JOIN orders o ON o.id = ol.order_id
              WHERE ol.id = :id',
            ['id' => $id]
        );

        if ($line === null) {
            return null;
        }

        $line['quantities'] = self::distribution($id);

        return $line;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private static function withDistributions(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $distributions = self::distributionsFor(array_map(static fn (array $l): int => (int) $l['id'], $lines));

        return array_map(static function (array $line) use ($distributions): array {
            $line['quantities'] = $distributions[(int) $line['id']] ?? self::emptyDistribution();

            return $line;
        }, $lines);
    }

    public static function forOrder(int $orderId): array
    {
        return self::withDistributions(Database::all(
            'SELECT ol.*, p.cpn, p.name AS part_name, p.has_free_issue
               FROM order_lines ol
               JOIN parts p ON p.id = ol.part_id
              WHERE ol.order_id = :order_id
              ORDER BY ol.line_no',
            ['order_id' => $orderId]
        ));
    }

    public static function forPart(int $partId): array
    {
        return self::withDistributions(Database::all(
            'SELECT ol.*, o.order_number, o.po_number
               FROM order_lines ol
               JOIN orders o ON o.id = ol.order_id
              WHERE ol.part_id = :part_id
              ORDER BY ol.created_at DESC',
            ['part_id' => $partId]
        ));
    }

    /** Lines with quantity still waiting on free-issue material, across all clients. */
    public static function awaitingFreeIssue(): array
    {
        return self::withDistributions(Database::all(
            "SELECT ol.*, o.order_number, o.client_id, c.name AS client_name,
                    p.cpn, p.name AS part_name, p.has_free_issue
               FROM order_lines ol
               JOIN orders o ON o.id = ol.order_id
               JOIN clients c ON c.id = o.client_id
               JOIN parts p ON p.id = ol.part_id
               JOIN order_line_quantities q
                 ON q.order_line_id = ol.id AND q.stage = 'awaiting_free_issue' AND q.qty > 0
              ORDER BY o.placed_at"
        ));
    }

    /**
     * Quantity that has been made and not yet despatched, for the goods-out
     * delivery note screen. That is precisely the 'complete' bucket: parts
     * further on have already gone, parts behind it have not been made.
     */
    public static function shippableForClient(int $clientId): array
    {
        return Database::all(
            "SELECT ol.*, o.order_number, o.po_number, p.cpn, p.name AS part_name,
                    q.qty AS qty_shippable
               FROM order_lines ol
               JOIN orders o ON o.id = ol.order_id
               JOIN parts p ON p.id = ol.part_id
               JOIN order_line_quantities q
                 ON q.order_line_id = ol.id AND q.stage = 'complete' AND q.qty > 0
              WHERE o.client_id = :client_id
              ORDER BY o.order_number, ol.line_no",
            ['client_id' => $clientId]
        );
    }

    /** @return array<string,int> every stage, including the empty ones */
    public static function emptyDistribution(): array
    {
        return array_fill_keys(self::STAGES, 0);
    }

    /** @return array<string,int> */
    public static function distribution(int $lineId): array
    {
        return self::distributionsFor([$lineId])[$lineId] ?? self::emptyDistribution();
    }

    /**
     * @param array<int,int> $lineIds
     * @return array<int,array<string,int>>
     */
    public static function distributionsFor(array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            return [];
        }

        // The ids are cast to int immediately above, so interpolating them is
        // the same string a placeholder list would have produced -- and this
        // way the query is one statement whatever the number of lines.
        $rows = Database::all(
            'SELECT order_line_id, stage, qty FROM order_line_quantities
              WHERE order_line_id IN (' . implode(',', $lineIds) . ')'
        );

        $out = array_fill_keys($lineIds, self::emptyDistribution());
        foreach ($rows as $row) {
            $out[(int) $row['order_line_id']][$row['stage']] = (int) $row['qty'];
        }

        return $out;
    }

    public static function qtyAt(array $line, string $stage): int
    {
        return (int) ($line['quantities'][$stage] ?? 0);
    }

    /**
     * Where quantity enters this line.
     *
     * The one place the free-issue and no-free-issue paths differ. Everything
     * downstream is the same code: a line with no material to wait for simply
     * never has anything in the first bucket.
     */
    public static function entryStage(array $line): string
    {
        return self::needsFreeIssue($line) ? 'awaiting_free_issue' : 'ready_for_production';
    }

    public static function needsFreeIssue(array $line): bool
    {
        if (isset($line['has_free_issue'])) {
            return (bool) $line['has_free_issue'];
        }

        return (int) ($line['qty_free_issue_required'] ?? 0) > 0;
    }

    /** The stage after this one, or null at the end of the flow. */
    public static function nextStage(string $stage): ?string
    {
        $index = array_search($stage, self::FLOW_STAGES, true);
        if ($index === false || $index === count(self::FLOW_STAGES) - 1) {
            return null;
        }

        return self::FLOW_STAGES[$index + 1];
    }

    /**
     * Destinations offered for a given stage on this line, with the ones that
     * make no sense here removed -- a line needing no material should not offer
     * to send failed parts back to a stage it never uses.
     *
     * @return array<int,string>
     */
    public static function manualDestinations(array $line, string $stage): array
    {
        $destinations = self::MANUAL_DESTINATIONS[$stage] ?? [];

        if (!self::needsFreeIssue($line)) {
            $destinations = array_values(array_filter(
                $destinations,
                static fn (string $s): bool => $s !== 'awaiting_free_issue'
            ));
        }

        return $destinations;
    }

    // -- Derived status ------------------------------------------------------

    /**
     * The line's production status, written out from the distribution.
     *
     * One occupied stage reads as that stage; several read as the breakdown,
     * which is the whole point of the model -- "In production" alone cannot say
     * that twelve of the twenty are still waiting for material.
     */
    public static function statusLabel(array $line): string
    {
        $occupied = self::occupiedStages($line);

        if ($occupied === []) {
            return 'Nothing outstanding';
        }

        if (count($occupied) === 1) {
            return self::STAGE_LABELS[array_key_first($occupied)];
        }

        $phrases = [];
        foreach ($occupied as $stage => $qty) {
            $phrases[] = $qty . ' ' . self::STAGE_SENTENCE_LABELS[$stage];
        }

        return ucfirst(implode(', ', $phrases));
    }

    /** @return array<string,int> stage => qty, in flow order, empties removed */
    public static function occupiedStages(array $line): array
    {
        $distribution = $line['quantities'] ?? self::emptyDistribution();
        $occupied = [];

        foreach (self::STAGES as $stage) {
            $qty = (int) ($distribution[$stage] ?? 0);
            if ($qty > 0) {
                $occupied[$stage] = $qty;
            }
        }

        return $occupied;
    }

    /**
     * The single stage a badge should show: the least advanced place the line
     * still has work sitting at.
     *
     * Failed and cancelled quantity is skipped unless there is nothing else --
     * a line with 19 delivered and 1 failed is not a failed line, but a line
     * that is entirely cancelled is a cancelled line.
     */
    public static function headlineStage(array $line): string
    {
        $occupied = self::occupiedStages($line);
        if ($occupied === []) {
            return 'complete';
        }

        foreach (self::FLOW_STAGES as $stage) {
            if (isset($occupied[$stage])) {
                return $stage;
            }
        }

        return array_key_first($occupied);
    }

    // -- Moving quantity -----------------------------------------------------

    /**
     * Move quantity between stages on a line.
     *
     * $from null adds quantity to the line, $to null takes it away; both of
     * those also move qty_ordered, and both are only reached from the quantity
     * change-request flow. Everything else is a redistribution of what is
     * already there.
     */
    public static function move(
        int $lineId,
        ?string $from,
        ?string $to,
        int $qty,
        int $userId,
        ?string $reason = null
    ): void {
        if ($qty <= 0) {
            throw new RuntimeException('Enter a quantity greater than zero.');
        }

        if ($from !== null && !in_array($from, self::STAGES, true)) {
            throw new RuntimeException('Unknown stage: ' . $from);
        }

        if ($to !== null && !in_array($to, self::STAGES, true)) {
            throw new RuntimeException('Unknown stage: ' . $to);
        }

        if ($from === $to) {
            throw new RuntimeException('That quantity is already there.');
        }

        Database::transaction(static function (PDO $pdo) use ($lineId, $from, $to, $qty, $userId, $reason): void {
            self::moveWithin($pdo, $lineId, $from, $to, $qty, $userId, $reason);
        });
    }

    /**
     * The body of move(), for callers already inside a transaction -- issuing a
     * delivery note moves several lines and writes the note itself, and either
     * all of it happens or none of it does.
     */
    public static function moveWithin(
        PDO $pdo,
        int $lineId,
        ?string $from,
        ?string $to,
        int $qty,
        int $userId,
        ?string $reason = null
    ): void {
        $lock = $pdo->prepare('SELECT qty_ordered FROM order_lines WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $lineId]);
        $line = $lock->fetch();

        if ($line === false) {
            throw new RuntimeException('That order line no longer exists.');
        }

        if ($from !== null) {
            $available = self::lockedQtyAt($pdo, $lineId, $from);
            if ($available < $qty) {
                throw new RuntimeException(
                    'There are only ' . $available . ' ' . self::STAGE_SENTENCE_LABELS[$from] . ' to move.'
                );
            }

            $pdo->prepare(
                'UPDATE order_line_quantities SET qty = qty - :qty
                  WHERE order_line_id = :id AND stage = :stage'
            )->execute(['qty' => $qty, 'id' => $lineId, 'stage' => $from]);
        } else {
            $pdo->prepare('UPDATE order_lines SET qty_ordered = qty_ordered + :qty WHERE id = :id')
                ->execute(['qty' => $qty, 'id' => $lineId]);
        }

        if ($to !== null) {
            $pdo->prepare(
                'INSERT INTO order_line_quantities (order_line_id, stage, qty) VALUES (:id, :stage, :qty)
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
            )->execute(['id' => $lineId, 'stage' => $to, 'qty' => $qty]);
        } else {
            $pdo->prepare('UPDATE order_lines SET qty_ordered = qty_ordered - :qty WHERE id = :id')
                ->execute(['qty' => $qty, 'id' => $lineId]);
        }

        $pdo->prepare(
            'INSERT INTO order_line_stage_moves (order_line_id, from_stage, to_stage, qty, reason, moved_by)
             VALUES (:id, :from_stage, :to_stage, :qty, :reason, :moved_by)'
        )->execute([
            'id' => $lineId,
            'from_stage' => $from,
            'to_stage' => $to,
            'qty' => $qty,
            'reason' => $reason,
            'moved_by' => $userId,
        ]);

        self::recalculateTotals($pdo, $lineId);
    }

    private static function lockedQtyAt(PDO $pdo, int $lineId, string $stage): int
    {
        $statement = $pdo->prepare(
            'SELECT qty FROM order_line_quantities WHERE order_line_id = :id AND stage = :stage FOR UPDATE'
        );
        $statement->execute(['id' => $lineId, 'stage' => $stage]);
        $row = $statement->fetch();

        return $row === false ? 0 : (int) $row['qty'];
    }

    /**
     * Rebuild the cached totals on order_lines from the distribution.
     *
     * qty_completed counts everything at or past 'complete', because a part that
     * has been delivered was certainly made -- the columns are cumulative
     * progress markers, not bucket sizes.
     */
    public static function recalculateTotals(PDO $pdo, int $lineId): void
    {
        $pdo->prepare(
            "UPDATE order_lines ol SET
                qty_completed = (SELECT COALESCE(SUM(q.qty), 0) FROM order_line_quantities q
                                  WHERE q.order_line_id = ol.id AND q.stage IN ('complete','delivered','invoiced')),
                qty_delivered = (SELECT COALESCE(SUM(q.qty), 0) FROM order_line_quantities q
                                  WHERE q.order_line_id = ol.id AND q.stage IN ('delivered','invoiced')),
                qty_invoiced  = (SELECT COALESCE(SUM(q.qty), 0) FROM order_line_quantities q
                                  WHERE q.order_line_id = ol.id AND q.stage = 'invoiced'),
                qty_failed    = (SELECT COALESCE(SUM(q.qty), 0) FROM order_line_quantities q
                                  WHERE q.order_line_id = ol.id AND q.stage = 'failed'),
                qty_cancelled = (SELECT COALESCE(SUM(q.qty), 0) FROM order_line_quantities q
                                  WHERE q.order_line_id = ol.id AND q.stage = 'cancelled')
             WHERE ol.id = :id"
        )->execute(['id' => $lineId]);
    }

    /** Puts the whole ordered quantity at the entry stage. Called when a line is created. */
    public static function seedDistribution(PDO $pdo, int $lineId, int $qty, string $entryStage, int $userId): void
    {
        $pdo->prepare(
            'INSERT INTO order_line_quantities (order_line_id, stage, qty) VALUES (:id, :stage, :qty)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
        )->execute(['id' => $lineId, 'stage' => $entryStage, 'qty' => $qty]);

        $pdo->prepare(
            'INSERT INTO order_line_stage_moves (order_line_id, from_stage, to_stage, qty, reason, moved_by)
             VALUES (:id, NULL, :stage, :qty, :reason, :moved_by)'
        )->execute([
            'id' => $lineId,
            'stage' => $entryStage,
            'qty' => $qty,
            'reason' => 'Order placed',
            'moved_by' => $userId,
        ]);
    }

    /**
     * Cancel off everything that will not now be made (item 6).
     *
     * Quantity that has already been made is left alone: closing a line down is
     * a statement about the future, and parts sitting in the despatch bay still
     * have to go out and still have to be invoiced.
     *
     * @return int how much was cancelled
     */
    public static function closeDown(int $lineId, int $userId, string $reason): int
    {
        return Database::transaction(static function (PDO $pdo) use ($lineId, $userId, $reason): int {
            $cancelled = 0;

            foreach (['awaiting_free_issue', 'ready_for_production', 'in_production', 'failed'] as $stage) {
                $qty = self::lockedQtyAt($pdo, $lineId, $stage);
                if ($qty > 0) {
                    self::moveWithin($pdo, $lineId, $stage, 'cancelled', $qty, $userId, $reason);
                    $cancelled += $qty;
                }
            }

            $pdo->prepare(
                'UPDATE order_lines SET closed_at = NOW(), closed_by = :user, close_reason = :reason WHERE id = :id'
            )->execute(['user' => $userId, 'reason' => $reason, 'id' => $lineId]);

            // Nothing is waiting for material any more, so nothing should still
            // be asking the client for any. Only ever lowered to what has
            // already arrived: material that is here is here.
            $pdo->prepare(
                'UPDATE order_lines
                    SET qty_free_issue_required = GREATEST(qty_free_issue_received - qty_free_issue_rejected, 0)
                  WHERE id = :id
                    AND qty_free_issue_required > GREATEST(qty_free_issue_received - qty_free_issue_rejected, 0)'
            )->execute(['id' => $lineId]);

            return $cancelled;
        });
    }

    public static function reopen(int $lineId): void
    {
        Database::query(
            'UPDATE order_lines SET closed_at = NULL, closed_by = NULL, close_reason = NULL WHERE id = :id',
            ['id' => $lineId]
        );
    }

    public static function isClosed(array $line): bool
    {
        return !empty($line['closed_at']);
    }

    public static function stageMoves(int $lineId): array
    {
        return Database::all(
            'SELECT m.*, u.name AS moved_by_name
               FROM order_line_stage_moves m
               JOIN users u ON u.id = m.moved_by
              WHERE m.order_line_id = :id
              ORDER BY m.moved_at, m.id',
            ['id' => $lineId]
        );
    }

    /** How much has been failed at each stage over the line's life, for the record. */
    public static function failureHistory(int $lineId): array
    {
        return Database::all(
            "SELECT m.from_stage, m.qty, m.reason, m.moved_at, u.name AS moved_by_name
               FROM order_line_stage_moves m
               JOIN users u ON u.id = m.moved_by
              WHERE m.order_line_id = :id AND m.to_stage = 'failed'
              ORDER BY m.moved_at DESC",
            ['id' => $lineId]
        );
    }

    // -- Free-issue material -------------------------------------------------

    public const DISCREPANCY_TYPES = ['none', 'shortfall', 'excess', 'wrong_item'];

    public const DISCREPANCY_LABELS = [
        'none' => 'None',
        'shortfall' => 'Shortfall',
        'excess' => 'Excess',
        'wrong_item' => 'Wrong item',
    ];

    /**
     * Material still to be sent for this line.
     *
     * Rejected material is added back on: it arrived, it could not be used, and
     * it has to come again. That is the difference between a rejection and a
     * shortage, expressed as arithmetic.
     */
    public static function freeIssueOutstanding(array $line): int
    {
        $required = (int) $line['qty_free_issue_required'];
        $usable = (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'];

        return max(0, $required - $usable);
    }

    /**
     * Records a check-in of free-issue material.
     *
     * It deliberately does not move any part quantity. Material arriving is not
     * the same event as deciding to start on it -- staff advance the parts they
     * are ready to advance, when they are ready, from the order page. Automatic
     * advancement was the old behaviour and it made partial receipts unusable:
     * everything moved or nothing did.
     */
    public static function recordFreeIssueReceipt(
        int $lineId,
        int $qty,
        int $receivedBy,
        ?string $notes = null,
        string $discrepancyType = 'none',
        ?string $discrepancyNotes = null
    ): void {
        if (!in_array($discrepancyType, self::DISCREPANCY_TYPES, true)) {
            $discrepancyType = 'none';
        }

        Database::transaction(static function (PDO $pdo) use ($lineId, $qty, $receivedBy, $notes, $discrepancyType, $discrepancyNotes): void {
            $pdo->prepare(
                'INSERT INTO free_issue_receipts (order_line_id, qty_received, received_by, notes, discrepancy_type, discrepancy_notes)
                 VALUES (:line_id, :qty, :received_by, :notes, :discrepancy_type, :discrepancy_notes)'
            )->execute([
                'line_id' => $lineId, 'qty' => $qty, 'received_by' => $receivedBy, 'notes' => $notes,
                'discrepancy_type' => $discrepancyType, 'discrepancy_notes' => $discrepancyNotes,
            ]);

            $pdo->prepare(
                'UPDATE order_lines SET qty_free_issue_received = qty_free_issue_received + :qty WHERE id = :id'
            )->execute(['qty' => $qty, 'id' => $lineId]);
        });
    }

    /**
     * Reject received material as wrong, faulty or damaged (item 6).
     *
     * Quantities here are material units. The rejection raises the line's
     * outstanding requirement by exactly what was rejected, which is what makes
     * the free-issue delivery note ask for it again -- see FreeIssueNoteService
     * for the paperwork that follows.
     *
     * @return int the id of the rejection record
     */
    public static function rejectFreeIssue(int $lineId, int $qty, string $reason, int $userId): int
    {
        if ($qty <= 0) {
            throw new RuntimeException('Enter a quantity to reject.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A rejection needs a reason -- it goes on the return note the client will read.');
        }

        return Database::transaction(static function (PDO $pdo) use ($lineId, $qty, $reason, $userId): int {
            $lock = $pdo->prepare(
                'SELECT qty_free_issue_received, qty_free_issue_rejected FROM order_lines WHERE id = :id FOR UPDATE'
            );
            $lock->execute(['id' => $lineId]);
            $line = $lock->fetch();

            if ($line === false) {
                throw new RuntimeException('That order line no longer exists.');
            }

            $usable = (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'];
            if ($qty > $usable) {
                throw new RuntimeException(
                    'Only ' . $usable . ' of the material received is still on the shelf to reject.'
                );
            }

            $pdo->prepare(
                'UPDATE order_lines SET qty_free_issue_rejected = qty_free_issue_rejected + :qty WHERE id = :id'
            )->execute(['qty' => $qty, 'id' => $lineId]);

            $pdo->prepare(
                'INSERT INTO free_issue_rejections (order_line_id, qty_rejected, reason, rejected_by)
                 VALUES (:line_id, :qty, :reason, :user)'
            )->execute(['line_id' => $lineId, 'qty' => $qty, 'reason' => trim($reason), 'user' => $userId]);

            return (int) $pdo->lastInsertId();
        });
    }

    public static function rejections(int $lineId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS rejected_by_name,
                    rn.reference AS return_reference, pn.reference AS replacement_reference
               FROM free_issue_rejections r
               JOIN users u ON u.id = r.rejected_by
          LEFT JOIN delivery_notes rn ON rn.id = r.return_note_id
          LEFT JOIN delivery_notes pn ON pn.id = r.replacement_note_id
              WHERE r.order_line_id = :id
              ORDER BY r.rejected_at DESC',
            ['id' => $lineId]
        );
    }

    public static function linkRejectionNotes(int $rejectionId, ?int $returnNoteId, ?int $replacementNoteId): void
    {
        Database::query(
            'UPDATE free_issue_rejections SET return_note_id = :return_id, replacement_note_id = :replacement_id
              WHERE id = :id',
            ['return_id' => $returnNoteId, 'replacement_id' => $replacementNoteId, 'id' => $rejectionId]
        );
    }

    /**
     * Where the free issue for this line stands, as a sentence.
     *
     * Written out rather than left to the reader because it goes into an email:
     * "10 of 10" on its own does not say whether the line can start, and the
     * thing that stops it starting is usually an unresolved discrepancy rather
     * than a shortfall.
     */
    public static function freeIssueStatusSentence(array $line): string
    {
        if (!self::needsFreeIssue($line) && (int) $line['qty_free_issue_required'] === 0) {
            return 'This line does not need free-issue material.';
        }

        if (self::openDiscrepancy((int) $line['id']) !== null) {
            return 'Held -- there is an unresolved discrepancy on this line. Junction will be in touch.';
        }

        $outstanding = self::freeIssueOutstanding($line);
        if ($outstanding > 0) {
            $rejected = (int) $line['qty_free_issue_rejected'];
            if ($rejected > 0) {
                return 'Still short by ' . $outstanding . ', including ' . $rejected
                     . ' rejected and awaiting replacement.';
            }

            return 'Still short by ' . $outstanding . '. Production can start on whatever material is here.';
        }

        return 'Complete -- all the material for this line is in.';
    }

    /** Latest receipt with an unresolved discrepancy for this line, or null. */
    public static function openDiscrepancy(int $lineId): ?array
    {
        return Database::one(
            "SELECT * FROM free_issue_receipts
              WHERE order_line_id = :line_id AND discrepancy_type != 'none' AND resolved_at IS NULL
              ORDER BY received_at DESC LIMIT 1",
            ['line_id' => $lineId]
        );
    }

    public static function resolveDiscrepancy(int $receiptId, int $resolvedBy): void
    {
        Database::query(
            'UPDATE free_issue_receipts SET resolved_at = NOW(), resolved_by = :resolved_by WHERE id = :id',
            ['resolved_by' => $resolvedBy, 'id' => $receiptId]
        );
    }

    public static function freeIssueReceipts(int $lineId): array
    {
        return Database::all(
            'SELECT fir.*, u.name AS received_by_name FROM free_issue_receipts fir
               JOIN users u ON u.id = fir.received_by
              WHERE fir.order_line_id = :line_id ORDER BY fir.received_at',
            ['line_id' => $lineId]
        );
    }

    /** Raise the material requirement, e.g. to replace rejected or failed quantity. */
    public static function addFreeIssueRequirement(int $lineId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        Database::query(
            'UPDATE order_lines SET qty_free_issue_required = qty_free_issue_required + :qty WHERE id = :id',
            ['qty' => $qty, 'id' => $lineId]
        );
    }

    // -- Despatch and invoicing ---------------------------------------------

    /** Called when a goods-out delivery note line is created. */
    public static function recordDelivery(PDO $pdo, int $lineId, int $qty, int $userId, string $reference): void
    {
        self::moveWithin($pdo, $lineId, 'complete', 'delivered', $qty, $userId, 'Delivery note ' . $reference);
    }

    /** Called when an invoice is raised against a delivery note. */
    public static function recordInvoice(PDO $pdo, int $lineId, int $qty, int $userId, string $reference): void
    {
        self::moveWithin($pdo, $lineId, 'delivered', 'invoiced', $qty, $userId, 'Invoice ' . $reference);
    }
}
