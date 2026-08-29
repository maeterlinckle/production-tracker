<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * When the client needs the parts.
 *
 * A quantity and a date rather than one date per line, because that is how the
 * requirement actually arrives: "50 by the end of March and the rest by June"
 * is one order line and two dates, and forcing it into one loses the half that
 * is urgent. A line with a single requirement is simply one row.
 *
 * These change nothing about what is owed — the quantity on the order is still
 * the quantity on the order. They are a statement of need that Junction reads
 * to decide what to set up next, which is why everything here is about
 * surfacing the *next* one rather than the list.
 */
final class OrderLineDueDate
{
    /** @return array<int,array<string,mixed>> */
    public static function forLine(int $orderLineId): array
    {
        return Database::all(
            'SELECT d.*, u.name AS set_by_name
               FROM order_line_due_dates d
          LEFT JOIN users u ON u.id = d.set_by
              WHERE d.order_line_id = :id
              ORDER BY d.due_date',
            ['id' => $orderLineId]
        );
    }

    /**
     * Every requirement on several lines at once, keyed by line id.
     *
     * One query for a whole order, or a whole report, rather than one per line.
     *
     * @param array<int,int> $lineIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forLines(array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            return [];
        }

        // Cast to int immediately above, so interpolating is the same string a
        // placeholder list would have produced, and this stays one query.
        $rows = Database::all(
            'SELECT * FROM order_line_due_dates
              WHERE order_line_id IN (' . implode(',', $lineIds) . ')
              ORDER BY order_line_id, due_date'
        );

        $byLine = [];
        foreach ($rows as $row) {
            $byLine[(int) $row['order_line_id']][] = $row;
        }

        return $byLine;
    }

    /**
     * Replace the whole schedule for a line.
     *
     * Replace rather than reconcile, as everywhere else a form shows its full
     * list. Blank rows are dropped; the editor always carries a spare.
     *
     * Two requirements on the same date would be a contradiction rather than a
     * schedule, and the unique key refuses them — so the last one entered wins
     * here, deliberately, rather than the save failing over a duplicate
     * somebody can see for themselves on the form.
     *
     * @param array<int,array{qty:int|string,due_date:string,note?:string}> $rows
     */
    public static function replace(int $orderLineId, array $rows, int $userId): void
    {
        $clean = [];
        foreach ($rows as $row) {
            $qty = (int) ($row['qty'] ?? 0);
            $date = trim((string) ($row['due_date'] ?? ''));

            if ($qty <= 0 || !self::isDate($date)) {
                continue;
            }

            $clean[$date] = [
                'qty' => $qty,
                'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 255) ?: null,
            ];
        }

        ksort($clean);

        Database::transaction(static function (PDO $pdo) use ($orderLineId, $clean, $userId): void {
            $pdo->prepare('DELETE FROM order_line_due_dates WHERE order_line_id = :id')
                ->execute(['id' => $orderLineId]);

            if ($clean === []) {
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO order_line_due_dates (order_line_id, qty, due_date, note, set_by)
                 VALUES (:line, :qty, :due_date, :note, :set_by)'
            );
            foreach ($clean as $date => $row) {
                $statement->execute([
                    'line' => $orderLineId,
                    'qty' => $row['qty'],
                    'due_date' => $date,
                    'note' => $row['note'],
                    'set_by' => $userId,
                ]);
            }
        });
    }

    /**
     * The requirement worth showing on a collapsed line: the earliest one that
     * is not already satisfied.
     *
     * "Satisfied" is measured against what has been completed, not delivered.
     * A part that is made and waiting for a van is not a part anybody needs to
     * be chased about, and a due date that stays red until the courier has been
     * is a due date people learn to ignore.
     *
     * @param array<int,array<string,mixed>> $requirements ascending by date
     * @return array<string,mixed>|null
     */
    public static function next(array $requirements, int $qtyCompleted): ?array
    {
        $running = 0;

        foreach ($requirements as $requirement) {
            $running += (int) $requirement['qty'];
            if ($running > $qtyCompleted) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * How a date stands relative to today: 'overdue', 'soon' or 'ok'.
     *
     * Three states rather than a number of days, because the page uses it to
     * pick a colour and the colour is the whole message.
     */
    public static function urgency(string $dueDate, int $soonDays = 7): string
    {
        $due = strtotime($dueDate . ' 00:00:00');
        $today = strtotime(date('Y-m-d') . ' 00:00:00');

        if ($due === false) {
            return 'ok';
        }

        if ($due < $today) {
            return 'overdue';
        }

        return $due <= $today + ($soonDays * 86400) ? 'soon' : 'ok';
    }

    /** "overdue by 3 days", "due tomorrow", "due in 12 days". */
    public static function sentence(string $dueDate): string
    {
        $due = strtotime($dueDate . ' 00:00:00');
        $today = strtotime(date('Y-m-d') . ' 00:00:00');

        if ($due === false) {
            return '';
        }

        $days = (int) round(($due - $today) / 86400);

        if ($days < 0) {
            $days = abs($days);

            return 'overdue by ' . $days . ' ' . ($days === 1 ? 'day' : 'days');
        }

        return match ($days) {
            0 => 'due today',
            1 => 'due tomorrow',
            default => 'due in ' . $days . ' days',
        };
    }

    private static function isDate(string $value): bool
    {
        return $value !== ''
            && \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false;
    }
}
