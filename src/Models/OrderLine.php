<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class OrderLine
{
    public const STAGES = ['awaiting_free_issue', 'ready_for_production', 'in_production', 'complete', 'closed'];

    public const STAGE_LABELS = [
        'awaiting_free_issue' => 'Awaiting free issue',
        'ready_for_production' => 'Ready for production',
        'in_production' => 'In production',
        'complete' => 'Complete',
        'closed' => 'Closed',
    ];

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT ol.*, p.cpn, p.name AS part_name FROM order_lines ol
             JOIN parts p ON p.id = ol.part_id
             WHERE ol.id = :id',
            ['id' => $id]
        );
    }

    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT ol.*, p.cpn, p.name AS part_name FROM order_lines ol
             JOIN parts p ON p.id = ol.part_id
             WHERE ol.order_id = :order_id ORDER BY ol.line_no',
            ['order_id' => $orderId]
        );
    }

    public static function forPart(int $partId): array
    {
        return Database::all(
            'SELECT ol.*, o.order_number FROM order_lines ol
             JOIN orders o ON o.id = ol.order_id
             WHERE ol.part_id = :part_id ORDER BY ol.created_at DESC',
            ['part_id' => $partId]
        );
    }

    /** Lines with outstanding free issue, across all clients (staff view). */
    public static function awaitingFreeIssue(): array
    {
        return Database::all(
            "SELECT ol.*, o.order_number, o.client_id, c.name AS client_name, p.cpn, p.name AS part_name
             FROM order_lines ol
             JOIN orders o ON o.id = ol.order_id
             JOIN clients c ON c.id = o.client_id
             JOIN parts p ON p.id = ol.part_id
             WHERE ol.stage = 'awaiting_free_issue'
             ORDER BY o.placed_at"
        );
    }

    /**
     * Lines with completed-but-undelivered quantity for a given client, used
     * when building a delivery note. A line doesn't need to be fully
     * complete (item 7) -- whatever's been completed and not yet shipped is
     * shippable, tracked via qty_completed - qty_delivered rather than stage
     * alone.
     */
    public static function shippableForClient(int $clientId): array
    {
        return Database::all(
            "SELECT ol.*, o.order_number, p.cpn, p.name AS part_name,
                    (ol.qty_completed - ol.qty_delivered) AS qty_shippable
             FROM order_lines ol
             JOIN orders o ON o.id = ol.order_id
             JOIN parts p ON p.id = ol.part_id
             WHERE o.client_id = :client_id
               AND ol.stage IN ('in_production', 'complete', 'closed')
               AND ol.qty_completed > ol.qty_delivered
             ORDER BY o.order_number, ol.line_no",
            ['client_id' => $clientId]
        );
    }

    /** "In production" with a completed-of-ordered breakdown reads differently from a flat "In production" (item 7). */
    public static function statusLabel(array $line): string
    {
        if ($line['stage'] === 'in_production' && (int) $line['qty_completed'] > 0 && (int) $line['qty_completed'] < (int) $line['qty_ordered']) {
            return 'In production — part complete (' . (int) $line['qty_completed'] . ' of ' . (int) $line['qty_ordered'] . ')';
        }

        return self::STAGE_LABELS[$line['stage']] ?? $line['stage'];
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
        $required = (int) $line['qty_free_issue_required'];
        $received = (int) $line['qty_free_issue_received'];

        if ($required === 0) {
            return 'This line does not need free-issue material.';
        }

        if (self::openDiscrepancy((int) $line['id']) !== null) {
            return 'Held — there is an unresolved discrepancy on this line. Junction will be in touch.';
        }

        if ($received < $required) {
            return 'Still short by ' . ($required - $received) . '. The line starts once the balance arrives.';
        }

        return 'Complete — this line is ready for production.';
    }

    public static function setStage(int $id, string $stage, int $changedBy, ?string $notes = null): void
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException("Unknown stage: {$stage}");
        }

        Database::transaction(static function (PDO $pdo) use ($id, $stage, $changedBy, $notes): void {
            $current = $pdo->prepare('SELECT stage FROM order_lines WHERE id = :id FOR UPDATE');
            $current->execute(['id' => $id]);
            $row = $current->fetch();
            $fromStage = $row['stage'] ?? null;

            $update = $pdo->prepare('UPDATE order_lines SET stage = :stage WHERE id = :id');
            $update->execute(['stage' => $stage, 'id' => $id]);

            // Marking a line complete by hand is a statement that the parts
            // exist, so the counter has to agree. Without this a line could sit
            // at "complete" with qty_completed still 0 — which read as the whole
            // quantity being outstanding on the parts-on-order report, and left
            // the delivery-note screen refusing to ship parts that were made.
            // Only ever raised, never lowered: reopening a line does not unmake
            // anything.
            if (in_array($stage, ['complete', 'closed'], true)) {
                $pdo->prepare('UPDATE order_lines SET qty_completed = qty_ordered WHERE id = :id AND qty_completed < qty_ordered')
                    ->execute(['id' => $id]);
            }

            $log = $pdo->prepare(
                'INSERT INTO production_status_log (order_line_id, from_stage, to_stage, changed_by, notes)
                 VALUES (:line_id, :from_stage, :to_stage, :changed_by, :notes)'
            );
            $log->execute([
                'line_id' => $id,
                'from_stage' => $fromStage,
                'to_stage' => $stage,
                'changed_by' => $changedBy,
                'notes' => $notes,
            ]);
        });
    }

    public const DISCREPANCY_TYPES = ['none', 'shortfall', 'excess', 'wrong_item'];

    public const DISCREPANCY_LABELS = [
        'none' => 'None',
        'shortfall' => 'Shortfall',
        'excess' => 'Excess',
        'wrong_item' => 'Wrong item',
    ];

    /**
     * Records a staff check-in event (item 6). Only auto-advances the line
     * to ready_for_production when the full required quantity is in AND
     * there's no shortfall/wrong-item discrepancy -- a flagged discrepancy
     * blocks production until staff resolve it, even if the quantity nets
     * out, because "the numbers match" isn't the same as "the material is
     * right".
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
            $insert = $pdo->prepare(
                'INSERT INTO free_issue_receipts (order_line_id, qty_received, received_by, notes, discrepancy_type, discrepancy_notes)
                 VALUES (:line_id, :qty, :received_by, :notes, :discrepancy_type, :discrepancy_notes)'
            );
            $insert->execute([
                'line_id' => $lineId, 'qty' => $qty, 'received_by' => $receivedBy, 'notes' => $notes,
                'discrepancy_type' => $discrepancyType, 'discrepancy_notes' => $discrepancyNotes,
            ]);

            $update = $pdo->prepare(
                'UPDATE order_lines SET qty_free_issue_received = qty_free_issue_received + :qty WHERE id = :id'
            );
            $update->execute(['qty' => $qty, 'id' => $lineId]);

            self::maybeAdvanceAfterFreeIssue($pdo, $lineId, $receivedBy);
        });
    }

    /**
     * Moves a line from awaiting_free_issue to ready_for_production once the
     * full quantity is in AND no unresolved discrepancy remains. Called both
     * after a receipt is recorded and after a discrepancy is resolved --
     * either can be the event that finally clears the line.
     */
    private static function maybeAdvanceAfterFreeIssue(PDO $pdo, int $lineId, int $changedBy): void
    {
        $line = $pdo->prepare('SELECT stage, qty_free_issue_required, qty_free_issue_received FROM order_lines WHERE id = :id');
        $line->execute(['id' => $lineId]);
        $row = $line->fetch();

        if ($row === false || $row['stage'] !== 'awaiting_free_issue') {
            return;
        }

        if ((int) $row['qty_free_issue_received'] < (int) $row['qty_free_issue_required']) {
            return;
        }

        $openCount = $pdo->prepare(
            "SELECT COUNT(*) AS n FROM free_issue_receipts
             WHERE order_line_id = :line_id AND discrepancy_type != 'none' AND resolved_at IS NULL"
        );
        $openCount->execute(['line_id' => $lineId]);
        if ((int) $openCount->fetch()['n'] > 0) {
            return;
        }

        $moveStage = $pdo->prepare("UPDATE order_lines SET stage = 'ready_for_production' WHERE id = :id");
        $moveStage->execute(['id' => $lineId]);

        $log = $pdo->prepare(
            'INSERT INTO production_status_log (order_line_id, from_stage, to_stage, changed_by, notes)
             VALUES (:line_id, :from_stage, :to_stage, :changed_by, :notes)'
        );
        $log->execute([
            'line_id' => $lineId,
            'from_stage' => 'awaiting_free_issue',
            'to_stage' => 'ready_for_production',
            'changed_by' => $changedBy,
            'notes' => 'Auto: all free issue material checked in',
        ]);
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
        Database::transaction(static function (PDO $pdo) use ($receiptId, $resolvedBy): void {
            $update = $pdo->prepare('UPDATE free_issue_receipts SET resolved_at = NOW(), resolved_by = :resolved_by WHERE id = :id');
            $update->execute(['resolved_by' => $resolvedBy, 'id' => $receiptId]);

            $lineId = $pdo->prepare('SELECT order_line_id FROM free_issue_receipts WHERE id = :id');
            $lineId->execute(['id' => $receiptId]);
            $row = $lineId->fetch();

            if ($row !== false) {
                self::maybeAdvanceAfterFreeIssue($pdo, (int) $row['order_line_id'], $resolvedBy);
            }
        });
    }

    /**
     * Cumulative build count staff record against a line (item 7). Caps at
     * qty_ordered and auto-advances to 'complete' once it's fully built.
     */
    public static function recordCompletion(int $lineId, int $qty, int $changedBy): void
    {
        Database::transaction(static function (PDO $pdo) use ($lineId, $qty, $changedBy): void {
            $current = $pdo->prepare('SELECT stage, qty_ordered, qty_completed FROM order_lines WHERE id = :id FOR UPDATE');
            $current->execute(['id' => $lineId]);
            $row = $current->fetch();
            if ($row === false) {
                return;
            }

            $newCompleted = min((int) $row['qty_ordered'], (int) $row['qty_completed'] + $qty);

            $update = $pdo->prepare('UPDATE order_lines SET qty_completed = :qty WHERE id = :id');
            $update->execute(['qty' => $newCompleted, 'id' => $lineId]);

            if ($newCompleted >= (int) $row['qty_ordered'] && $row['stage'] === 'in_production') {
                $moveStage = $pdo->prepare("UPDATE order_lines SET stage = 'complete' WHERE id = :id");
                $moveStage->execute(['id' => $lineId]);

                $log = $pdo->prepare(
                    'INSERT INTO production_status_log (order_line_id, from_stage, to_stage, changed_by, notes)
                     VALUES (:line_id, :from_stage, :to_stage, :changed_by, :notes)'
                );
                $log->execute([
                    'line_id' => $lineId, 'from_stage' => 'in_production', 'to_stage' => 'complete',
                    'changed_by' => $changedBy, 'notes' => 'Auto: full ordered quantity completed',
                ]);
            }
        });
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

    public static function statusLog(int $lineId): array
    {
        return Database::all(
            'SELECT l.*, u.name AS changed_by_name FROM production_status_log l
             JOIN users u ON u.id = l.changed_by
             WHERE l.order_line_id = :line_id ORDER BY l.changed_at',
            ['line_id' => $lineId]
        );
    }

    /** Called when a delivery note line is created — increments qty_delivered. */
    public static function recordDelivery(PDO $pdo, int $lineId, int $qty): void
    {
        $statement = $pdo->prepare('UPDATE order_lines SET qty_delivered = qty_delivered + :qty WHERE id = :id');
        $statement->execute(['qty' => $qty, 'id' => $lineId]);
    }

    /** Called when an invoice is raised against a delivery note — increments qty_invoiced and may close the line. */
    public static function recordInvoice(PDO $pdo, int $lineId, int $qty): void
    {
        $statement = $pdo->prepare('UPDATE order_lines SET qty_invoiced = qty_invoiced + :qty WHERE id = :id');
        $statement->execute(['qty' => $qty, 'id' => $lineId]);

        $row = $pdo->prepare('SELECT qty_ordered, qty_delivered, qty_invoiced FROM order_lines WHERE id = :id');
        $row->execute(['id' => $lineId]);
        $line = $row->fetch();

        if ($line !== false
            && (int) $line['qty_delivered'] >= (int) $line['qty_ordered']
            && (int) $line['qty_invoiced'] >= (int) $line['qty_delivered']
        ) {
            $close = $pdo->prepare("UPDATE order_lines SET stage = 'closed' WHERE id = :id");
            $close->execute(['id' => $lineId]);
        }
    }
}
