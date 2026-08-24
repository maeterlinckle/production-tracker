<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Invoice
{
    /**
     * How the invoice came to exist.
     *
     * Both kinds settle the delivery note equally — it stops waiting to be
     * invoiced either way. The distinction is kept because only one of them can
     * be looked up in Clear Books by id, and somebody chasing a discrepancy
     * needs to know which they are looking at before they go hunting for a
     * record that was never created through the API.
     */
    public const SOURCE_CLEARBOOKS = 'clearbooks';
    public const SOURCE_MANUAL = 'manual';

    /** What the tracker believes a delivery note is worth, from the order's own prices. */
    public static function valueOfDeliveryNote(int $deliveryNoteId): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(dnl.qty * ol.unit_price), 0)
               FROM delivery_note_lines dnl
               JOIN order_lines ol ON ol.id = dnl.order_line_id
              WHERE dnl.delivery_note_id = :id',
            ['id' => $deliveryNoteId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM invoices WHERE id = :id', ['id' => $id]);
    }

    public static function forDeliveryNote(int $deliveryNoteId): ?array
    {
        return Database::one(
            'SELECT i.*, u.name AS raised_by_name
               FROM invoices i
               LEFT JOIN users u ON u.id = i.raised_by
              WHERE i.delivery_note_id = :id',
            ['id' => $deliveryNoteId]
        );
    }

    /**
     * Raises an invoice against a delivery note: records the Clear Books
     * reference, flags the delivery note invoiced, and rolls qty_invoiced
     * forward on every order line the note covers.
     */
    public static function raise(
        int $deliveryNoteId,
        ?string $clearbooksInvoiceId,
        string $clearbooksInvoiceNumber,
        float $amount,
        int $raisedBy,
        ?string $notes = null,
        string $source = self::SOURCE_CLEARBOOKS
    ): int {
        return Database::transaction(static function (PDO $pdo) use (
            $deliveryNoteId,
            $clearbooksInvoiceId,
            $clearbooksInvoiceNumber,
            $amount,
            $raisedBy,
            $notes,
            $source
        ): int {
            $insert = $pdo->prepare(
                'INSERT INTO invoices (
                    delivery_note_id, source, clearbooks_invoice_id, clearbooks_invoice_number,
                    amount, raised_by, notes
                ) VALUES (
                    :dn_id, :source, :cb_id, :cb_number, :amount, :raised_by, :notes
                )'
            );
            $insert->execute([
                'dn_id' => $deliveryNoteId,
                'source' => $source,
                'cb_id' => $clearbooksInvoiceId,
                'cb_number' => $clearbooksInvoiceNumber,
                'amount' => $amount,
                'raised_by' => $raisedBy,
                'notes' => $notes,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $markInvoiced = $pdo->prepare('UPDATE delivery_notes SET invoiced = 1, invoiced_at = NOW() WHERE id = :id');
            $markInvoiced->execute(['id' => $deliveryNoteId]);

            $lines = $pdo->prepare('SELECT order_line_id, qty FROM delivery_note_lines WHERE delivery_note_id = :id');
            $lines->execute(['id' => $deliveryNoteId]);

            foreach ($lines->fetchAll() as $line) {
                OrderLine::recordInvoice(
                    $pdo,
                    (int) $line['order_line_id'],
                    (int) $line['qty'],
                    $raisedBy,
                    $clearbooksInvoiceNumber
                );
            }

            return $invoiceId;
        });
    }
}
