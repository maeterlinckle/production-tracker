<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * How long a part takes, broken into the jobs it is actually made of.
 *
 * Nobody knows that a part takes 140 minutes. They know it is 40 on the lathe,
 * 60 on the mill and 40 to fettle, and the 140 is what falls out of that. The
 * single number was the only thing stored, so when an estimate turned out
 * wrong there was nothing to say which operation had been misjudged — and the
 * next estimate for a similar part was made from the same blank.
 *
 * Two kinds, one table. An estimated row and an actual row are the same shape
 * and get read side by side: "40 estimated on the lathe against 55 actual" is
 * the comparison the pair exists for, and two tables would mean two of every
 * query that asks it.
 */
final class PartTimeEntry
{
    public const KINDS = ['estimated', 'actual'];

    public const KIND_LABELS = [
        'estimated' => 'Estimated Build Time',
        'actual' => 'Actual Build Time',
    ];

    /** Which cached total on `parts` each kind writes to. */
    private const TOTAL_COLUMNS = [
        'estimated' => 'estimated_build_time_minutes',
        'actual' => 'actual_build_time_minutes',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function forPart(int $partId, string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return [];
        }

        return Database::all(
            'SELECT e.*, u.name AS recorded_by_name
               FROM part_time_entries e
          LEFT JOIN users u ON u.id = e.recorded_by
              WHERE e.part_id = :part_id AND e.kind = :kind
              ORDER BY e.position, e.id',
            ['part_id' => $partId, 'kind' => $kind]
        );
    }

    /**
     * Both kinds at once, keyed by kind, for a page that shows the pair.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function bothForPart(int $partId): array
    {
        $out = array_fill_keys(self::KINDS, []);

        foreach (Database::all(
            'SELECT e.*, u.name AS recorded_by_name
               FROM part_time_entries e
          LEFT JOIN users u ON u.id = e.recorded_by
              WHERE e.part_id = :part_id
              ORDER BY e.kind, e.position, e.id',
            ['part_id' => $partId]
        ) as $row) {
            $out[$row['kind']][] = $row;
        }

        return $out;
    }

    /**
     * Replace the whole list for one kind.
     *
     * Replace rather than reconcile: the editor shows every row it holds, so
     * what comes back *is* the list, and a row somebody deleted has to
     * actually go. The same choice the alternate numbers and free-issue
     * materials already make.
     *
     * Rows with no task or no minutes are dropped rather than rejected — the
     * editor always carries a spare blank row, and refusing to save because
     * the spare is empty would be absurd.
     *
     * @param array<int,array{task:string,minutes:int|string}> $rows
     * @return int the new total in minutes
     */
    public static function replace(int $partId, string $kind, array $rows, int $userId): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            return 0;
        }

        $clean = [];
        foreach ($rows as $row) {
            $task = trim((string) ($row['task'] ?? ''));
            $minutes = (int) ($row['minutes'] ?? 0);

            if ($task === '' || $minutes <= 0) {
                continue;
            }

            $clean[] = ['task' => mb_substr($task, 0, 255), 'minutes' => $minutes];
        }

        Database::transaction(static function (PDO $pdo) use ($partId, $kind, $clean, $userId): void {
            $pdo->prepare('DELETE FROM part_time_entries WHERE part_id = :part_id AND kind = :kind')
                ->execute(['part_id' => $partId, 'kind' => $kind]);

            if ($clean === []) {
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO part_time_entries (part_id, kind, task, minutes, position, recorded_by)
                 VALUES (:part_id, :kind, :task, :minutes, :position, :recorded_by)'
            );

            foreach ($clean as $position => $row) {
                $statement->execute([
                    'part_id' => $partId,
                    'kind' => $kind,
                    'task' => $row['task'],
                    'minutes' => $row['minutes'],
                    'position' => $position,
                    'recorded_by' => $userId,
                ]);
            }
        });

        return self::recalculateTotal($partId, $kind);
    }

    /**
     * Rewrite the cached total on `parts` from the rows.
     *
     * Recalculated, never incremented — the same rule the order-line totals
     * follow, and for the same reason: a total that is added to drifts, and a
     * total that is rebuilt cannot.
     *
     * An empty list stores NULL rather than 0. "Nobody has estimated this" and
     * "this takes no time" are different statements, and only one of them is
     * ever true.
     */
    public static function recalculateTotal(int $partId, string $kind): int
    {
        $column = self::TOTAL_COLUMNS[$kind] ?? null;
        if ($column === null) {
            return 0;
        }

        $total = (int) Database::scalar(
            'SELECT COALESCE(SUM(minutes), 0) FROM part_time_entries WHERE part_id = :part_id AND kind = :kind',
            ['part_id' => $partId, 'kind' => $kind]
        );

        // The column name comes from a constant keyed by a validated kind, so
        // there is nothing here for a caller to interpolate into.
        Database::query(
            'UPDATE parts SET ' . $column . ' = :total WHERE id = :id',
            ['total' => $total > 0 ? $total : null, 'id' => $partId]
        );

        return $total;
    }

    /** "2 h 20 min", "45 min", or a dash when nobody has said. */
    public static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours . ' h' : $hours . ' h ' . $rest . ' min';
    }

    /**
     * How the actual compares with the estimate.
     *
     * Returned as a figure and a direction rather than a sentence, so the page
     * can decide how loud to be about it. Null whenever there is nothing to
     * compare — no estimate, or no actual yet.
     *
     * @return array{difference:int,percent:float,over:bool}|null
     */
    public static function variance(?int $estimated, ?int $actual): ?array
    {
        if ($estimated === null || $estimated <= 0 || $actual === null || $actual <= 0) {
            return null;
        }

        $difference = $actual - $estimated;

        return [
            'difference' => abs($difference),
            'percent' => round((abs($difference) / $estimated) * 100, 1),
            'over' => $difference > 0,
        ];
    }
}
