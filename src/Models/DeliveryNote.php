<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ReferenceNumber;
use PDO;

final class DeliveryNote
{
    public const TYPES = ['free_issue_in', 'goods_out', 'material_return'];

    public const TYPE_LABELS = [
        'free_issue_in' => 'Free issue in',
        'goods_out' => 'Goods out',
        'material_return' => 'Material return',
    ];

    /** How each type reads to the client, whose side of the transaction is the other one. */
    public const CLIENT_TYPE_LABELS = [
        'free_issue_in' => 'Free issue — please send with material',
        'goods_out' => 'Goods received',
        'material_return' => 'Material returned to you',
    ];

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM delivery_notes WHERE id = :id', ['id' => $id]);
    }

    public static function forClient(int $clientId, ?string $type = null): array
    {
        $sql = 'SELECT * FROM delivery_notes WHERE client_id = :client_id';
        $params = ['client_id' => $clientId];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY issued_at DESC';

        return Database::all($sql, $params);
    }

    /** Every goods-out delivery note not yet invoiced, across all clients. */
    public static function uninvoiced(): array
    {
        return Database::all(
            "SELECT dn.*, c.name AS client_name FROM delivery_notes dn
               JOIN clients c ON c.id = dn.client_id
              WHERE dn.type = 'goods_out' AND dn.invoiced = 0
              ORDER BY dn.issued_at"
        );
    }

    /**
     * Every delivery note covering at least one line of this order, with the
     * CPNs it relates to.
     *
     * The CPN is what the order page lists these by (item 4): "free issue in"
     * on four rows says nothing about which of them is the one holding the job
     * up, and the part number does.
     */
    public static function forOrder(int $orderId): array
    {
        return Database::all(
            "SELECT dn.*,
                    GROUP_CONCAT(DISTINCT p.cpn ORDER BY p.cpn SEPARATOR ', ') AS cpns,
                    SUM(dnl.qty) AS qty_total
               FROM delivery_notes dn
               JOIN delivery_note_lines dnl ON dnl.delivery_note_id = dn.id
               JOIN order_lines ol ON ol.id = dnl.order_line_id
               JOIN parts p ON p.id = ol.part_id
              WHERE ol.order_id = :order_id
              GROUP BY dn.id
              ORDER BY dn.issued_at",
            ['order_id' => $orderId]
        );
    }

    public static function lines(int $deliveryNoteId): array
    {
        // `is_standing_note` marks the one free-issue note that currently
        // speaks for a line — the newest. It is the note that shows the line's
        // full outstanding requirement, so that anything raising that figure
        // (material rejected, parts failed) appears on the paper the client is
        // actually looking at. Older notes for the same line stay pegged to
        // what they originally asked for.
        return Database::all(
            "SELECT dnl.*, ol.qty_ordered, ol.unit_price, ol.part_id,
                    ol.qty_free_issue_required, ol.qty_free_issue_received, ol.qty_free_issue_rejected,
                    p.cpn, p.name AS part_name, p.has_free_issue,
                    p.free_issue_relationship, p.free_issue_factor,
                    o.order_number, o.po_number,
                    (dnl.delivery_note_id = (
                        SELECT MAX(dn2.id) FROM delivery_notes dn2
                          JOIN delivery_note_lines dnl2 ON dnl2.delivery_note_id = dn2.id
                         WHERE dnl2.order_line_id = dnl.order_line_id AND dn2.type = 'free_issue_in'
                    )) AS is_standing_note
               FROM delivery_note_lines dnl
               JOIN order_lines ol ON ol.id = dnl.order_line_id
               JOIN parts p ON p.id = ol.part_id
               JOIN orders o ON o.id = ol.order_id
              WHERE dnl.delivery_note_id = :id
              ORDER BY dnl.id",
            ['id' => $deliveryNoteId]
        );
    }

    /**
     * The free-issue note still standing for a line, if there is one.
     *
     * There is at most one outstanding request per line by design: a rejection
     * reissues the note that is already out rather than adding a second piece of
     * paper asking for overlapping material, so the client never has to work out
     * which of two notes supersedes the other.
     */
    public static function openFreeIssueNoteForLine(int $orderLineId): ?array
    {
        return Database::one(
            "SELECT dn.* FROM delivery_notes dn
               JOIN delivery_note_lines dnl ON dnl.delivery_note_id = dn.id
              WHERE dnl.order_line_id = :id AND dn.type = 'free_issue_in'
              ORDER BY dn.id DESC LIMIT 1",
            ['id' => $orderLineId]
        );
    }

    /**
     * Free-issue delivery note: a request for material, not a record of a
     * movement. It does not touch any order-line quantity.
     *
     * $lines: [['order_line_id','qty']], quantities in material units.
     */
    public static function createFreeIssueNote(int $clientId, array $lines, int $issuedBy, ?string $notes = null): int
    {
        return self::createNote('free_issue_in', 'FIDN', $clientId, $lines, $issuedBy, $notes);
    }

    /**
     * Return note: material going back to the client because it cannot be used.
     *
     * The same record with a third type rather than a table of its own -- it
     * needs the same numbering, the same PDF and the same place in the client's
     * list of paperwork.
     */
    public static function createReturnNote(int $clientId, array $lines, int $issuedBy, ?string $notes = null): int
    {
        return self::createNote('material_return', 'RTN', $clientId, $lines, $issuedBy, $notes);
    }

    private static function createNote(
        string $type,
        string $prefix,
        int $clientId,
        array $lines,
        int $issuedBy,
        ?string $notes
    ): int {
        return Database::transaction(static function (PDO $pdo) use ($type, $prefix, $clientId, $lines, $issuedBy, $notes): int {
            $reference = ReferenceNumber::next($prefix, $pdo);

            $statement = $pdo->prepare(
                'INSERT INTO delivery_notes (type, client_id, reference, issued_by, notes)
                 VALUES (:type, :client_id, :reference, :issued_by, :notes)'
            );
            $statement->execute([
                'type' => $type,
                'client_id' => $clientId,
                'reference' => $reference,
                'issued_by' => $issuedBy,
                'notes' => $notes,
            ]);
            $id = (int) $pdo->lastInsertId();

            $lineStatement = $pdo->prepare(
                'INSERT INTO delivery_note_lines (delivery_note_id, order_line_id, qty) VALUES (:dn_id, :line_id, :qty)'
            );
            foreach ($lines as $line) {
                $lineStatement->execute(['dn_id' => $id, 'line_id' => $line['order_line_id'], 'qty' => $line['qty']]);
            }

            return $id;
        });
    }

    /**
     * Completed-goods delivery note: moves quantity from 'complete' to
     * 'delivered' on every line it covers. $lines: [['order_line_id','qty']].
     */
    public static function createGoodsOutNote(int $clientId, array $lines, int $issuedBy, ?string $notes = null): int
    {
        return Database::transaction(static function (PDO $pdo) use ($clientId, $lines, $issuedBy, $notes): int {
            $reference = ReferenceNumber::next('DN', $pdo);

            $statement = $pdo->prepare(
                "INSERT INTO delivery_notes (type, client_id, reference, issued_by, notes)
                 VALUES ('goods_out', :client_id, :reference, :issued_by, :notes)"
            );
            $statement->execute(['client_id' => $clientId, 'reference' => $reference, 'issued_by' => $issuedBy, 'notes' => $notes]);
            $id = (int) $pdo->lastInsertId();

            $lineStatement = $pdo->prepare(
                'INSERT INTO delivery_note_lines (delivery_note_id, order_line_id, qty) VALUES (:dn_id, :line_id, :qty)'
            );
            foreach ($lines as $line) {
                $lineStatement->execute(['dn_id' => $id, 'line_id' => $line['order_line_id'], 'qty' => $line['qty']]);
                OrderLine::recordDelivery($pdo, (int) $line['order_line_id'], (int) $line['qty'], $issuedBy, $reference);
            }

            return $id;
        });
    }

    public static function setPdfPath(int $id, string $path): void
    {
        Database::query('UPDATE delivery_notes SET pdf_file_path = :path WHERE id = :id', ['path' => $path, 'id' => $id]);
    }
}
