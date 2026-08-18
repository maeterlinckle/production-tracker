<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Atomically generates sequential per-year reference numbers, e.g.
 * ORD-2026-0001, DN-2026-0001, FIDN-2026-0001, RC-2026-0001.
 */
final class ReferenceNumber
{
    /**
     * Pass the caller's PDO when already inside a Database::transaction()
     * closure (PDO doesn't support nested transactions) -- the SELECT ...
     * FOR UPDATE below relies on the caller's transaction for its lock.
     * With no $pdo, a short transaction is opened here instead.
     */
    public static function next(string $prefix, ?PDO $pdo = null): string
    {
        if ($pdo !== null) {
            return self::allocate($pdo, $prefix);
        }

        return Database::transaction(static fn (PDO $pdo) => self::allocate($pdo, $prefix));
    }

    private static function allocate(PDO $pdo, string $prefix): string
    {
        $year = date('Y');
        $key = strtolower($prefix) . '_' . $year;

        $statement = $pdo->prepare('SELECT next_number FROM reference_sequences WHERE sequence_key = :key FOR UPDATE');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch();

        if ($row === false) {
            $insert = $pdo->prepare('INSERT INTO reference_sequences (sequence_key, next_number) VALUES (:key, 2)');
            $insert->execute(['key' => $key]);
            $number = 1;
        } else {
            $number = (int) $row['next_number'];
            $update = $pdo->prepare('UPDATE reference_sequences SET next_number = :next WHERE sequence_key = :key');
            $update->execute(['next' => $number + 1, 'key' => $key]);
        }

        return sprintf('%s-%s-%04d', $prefix, $year, $number);
    }
}
