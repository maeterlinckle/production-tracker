<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ReferenceNumber;
use PDO;

final class DeliveryNote
{
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

    /** Every delivery note (either type) that covers at least one line of this order -- for item 9's grouped order-page view. */
    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT DISTINCT dn.* FROM delivery_notes dn
             JOIN delivery_note_lines dnl ON dnl.delivery_note_id = dn.id
             JOIN order_lines ol ON ol.id = dnl.order_line_id
             WHERE ol.order_id = :order_id
             ORDER BY dn.issued_at',
            ['order_id' => $orderId]
        );
    }

    public static function lines(int $deliveryNoteId): array
    {
        return Database::all(
            'SELECT dnl.*, ol.qty_ordered, ol.unit_price, ol.part_id, p.cpn, p.name AS part_name, o.order_number
             FROM delivery_note_lines dnl
             JOIN order_lines ol ON ol.id = dnl.order_line_id
             JOIN parts p ON p.id = ol.part_id
             JOIN orders o ON o.id = ol.order_id
             WHERE dnl.delivery_note_id = :id',
            ['id' => $deliveryNoteId]
        );
    }

    /**
     * Free-issue delivery note: purely informational (expected material),
     * does not touch order_line quantities. $lines: [['order_line_id','qty']].
     */
    public static function createFreeIssueNote(int $clientId, array $lines, int $issuedBy, ?string $notes = null): int
    {
        return Database::transaction(static function (PDO $pdo) use ($clientId, $lines, $issuedBy, $notes): int {
            $reference = ReferenceNumber::next('FIDN', $pdo);

            $statement = $pdo->prepare(
                "INSERT INTO delivery_notes (type, client_id, reference, issued_by, notes)
                 VALUES ('free_issue_in', :client_id, :reference, :issued_by, :notes)"
            );
            $statement->execute(['client_id' => $clientId, 'reference' => $reference, 'issued_by' => $issuedBy, 'notes' => $notes]);
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
     * Completed-goods delivery note: increments qty_delivered on each covered
     * order line. $lines: [['order_line_id','qty']].
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
                OrderLine::recordDelivery($pdo, (int) $line['order_line_id'], (int) $line['qty']);
            }

            return $id;
        });
    }

    public static function setPdfPath(int $id, string $path): void
    {
        Database::query('UPDATE delivery_notes SET pdf_file_path = :path WHERE id = :id', ['path' => $path, 'id' => $id]);
    }
}
