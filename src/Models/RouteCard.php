<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ReferenceNumber;

final class RouteCard
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM route_cards WHERE id = :id', ['id' => $id]);
    }

    public static function latestForLine(int $orderLineId): ?array
    {
        return Database::one(
            'SELECT * FROM route_cards WHERE order_line_id = :line_id ORDER BY generated_at DESC LIMIT 1',
            ['line_id' => $orderLineId]
        );
    }

    public static function forLine(int $orderLineId): array
    {
        return Database::all(
            'SELECT * FROM route_cards WHERE order_line_id = :line_id ORDER BY generated_at DESC',
            ['line_id' => $orderLineId]
        );
    }

    public static function nextReference(): string
    {
        return ReferenceNumber::next('RC');
    }

    /** Row is only written once the PDF exists, so a render failure never leaves a filed-less row behind. */
    public static function createWithPdf(int $orderLineId, string $reference, int $generatedBy, string $pdfPath): int
    {
        return Database::insert(
            'INSERT INTO route_cards (order_line_id, reference, generated_by, pdf_file_path) VALUES (:line_id, :reference, :generated_by, :pdf_path)',
            ['line_id' => $orderLineId, 'reference' => $reference, 'generated_by' => $generatedBy, 'pdf_path' => $pdfPath]
        );
    }
}
