<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Invoice
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM invoices WHERE id = :id', ['id' => $id]);
    }

    public static function forDeliveryNote(int $deliveryNoteId): ?array
    {
        return Database::one('SELECT * FROM invoices WHERE delivery_note_id = :id', ['id' => $deliveryNoteId]);
    }

    /**
     * Raises an invoice against a delivery note: records the Clear Books
     * reference, flags the delivery note invoiced, and rolls qty_invoiced
     * forward on every order line the note covers.
     */
    public static function raise(
        int $deliveryNoteId,
        string $clearbooksInvoiceId,
        string $clearbooksInvoiceNumber,
        float $amount,
        int $raisedBy,
        ?string $notes = null
    ): int {
        return Database::transaction(static function (PDO $pdo) use (
            $deliveryNoteId,
            $clearbooksInvoiceId,
            $clearbooksInvoiceNumber,
            $amount,
            $raisedBy,
            $notes
        ): int {
            $insert = $pdo->prepare(
                'INSERT INTO invoices (
                    delivery_note_id, clearbooks_invoice_id, clearbooks_invoice_number, amount, raised_by, notes
                ) VALUES (
                    :dn_id, :cb_id, :cb_number, :amount, :raised_by, :notes
                )'
            );
            $insert->execute([
                'dn_id' => $deliveryNoteId,
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
                OrderLine::recordInvoice($pdo, (int) $line['order_line_id'], (int) $line['qty']);
            }

            return $invoiceId;
        });
    }
}
