<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * What a part costs at the quantities it actually gets ordered in.
 *
 * Freely settable pairs rather than fixed tiers, because the quantities that
 * matter are this client's. A part run in 12s and 250s wants breaks at 12 and
 * 250; a tier table of 1/10/50/100/500 would have neither and would invite
 * somebody to round the real quantity to fit it.
 *
 * Two kinds, one table. `target` is the client's statement of what they hope
 * to pay, `quoted` is Junction's answer, and they are read as a pair — the gap
 * between them is the conversation.
 *
 * A break's quantity is where its price *starts* applying, so the row for 100
 * governs 100 through to whatever the next break is. The part's own
 * `target_price` / `quoted_price` remains the price below the first break, and
 * stays the single figure everything that has not been taught about breaks
 * goes on reading.
 */
final class PartPriceBreak
{
    public const KINDS = ['target', 'quoted'];

    public const KIND_LABELS = [
        'target' => 'Target price breaks',
        'quoted' => 'Quoted price breaks',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function forPart(int $partId, string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return [];
        }

        return Database::all(
            'SELECT b.*, u.name AS set_by_name
               FROM part_price_breaks b
          LEFT JOIN users u ON u.id = b.set_by
              WHERE b.part_id = :part_id AND b.kind = :kind
              ORDER BY b.qty',
            ['part_id' => $partId, 'kind' => $kind]
        );
    }

    /**
     * Both kinds at once, keyed by kind.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function bothForPart(int $partId): array
    {
        $out = array_fill_keys(self::KINDS, []);

        foreach (Database::all(
            'SELECT b.*, u.name AS set_by_name
               FROM part_price_breaks b
          LEFT JOIN users u ON u.id = b.set_by
              WHERE b.part_id = :part_id
              ORDER BY b.kind, b.qty',
            ['part_id' => $partId]
        ) as $row) {
            $out[$row['kind']][] = $row;
        }

        return $out;
    }

    /**
     * Split a posted price ladder into the part's own price and its breaks.
     *
     * The pricing forms are one editor now: rows of "from this quantity, this
     * price each". But a part still has a single price column — `quoted_price`
     * is what makes it orderable, and half the application reads it — so the
     * ladder has to land in two places.
     *
     * The lowest quantity sets the part's price, and everything above it is a
     * break. A ladder of one row is therefore exactly the single figure this
     * replaced, stored exactly where it always was; a ladder of 1/50/200 is
     * that same figure plus two breaks. Storing the bottom row as a break as
     * well would double it up and leave the panel drawing a range of "1–0".
     *
     * @param array<int,array{qty:int|string,price:float|string}> $rows
     * @return array{base:float|null,breaks:array<int,array{qty:int,price:float}>}
     */
    public static function splitLadder(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            $qty = (int) ($row['qty'] ?? 0);
            $price = $row['price'] ?? '';

            if ($qty <= 0 || $price === '' || !is_numeric($price)) {
                continue;
            }

            $clean[$qty] = round((float) $price, 2);
        }

        if ($clean === []) {
            return ['base' => null, 'breaks' => []];
        }

        ksort($clean);

        $lowestQty = array_key_first($clean);
        $base = $clean[$lowestQty];
        unset($clean[$lowestQty]);

        $breaks = [];
        foreach ($clean as $qty => $price) {
            $breaks[] = ['qty' => $qty, 'price' => $price];
        }

        return ['base' => $base, 'breaks' => $breaks];
    }

    /**
     * The ladder as the editor wants it back: the part's own price as the
     * first row, then the breaks.
     *
     * The inverse of splitLadder(), so opening the editor shows exactly what
     * was saved rather than the breaks with the base price mysteriously
     * missing from the top.
     *
     * @param array<int,array<string,mixed>> $breaks
     * @return array<int,array{break_qty:int|string,break_price:string}>
     */
    public static function ladderRows(?float $basePrice, array $breaks): array
    {
        $rows = [];

        if ($basePrice !== null) {
            $rows[] = ['break_qty' => 1, 'break_price' => number_format($basePrice, 2, '.', '')];
        }

        foreach ($breaks as $break) {
            $rows[] = [
                'break_qty' => (int) $break['qty'],
                'break_price' => number_format((float) $break['price'], 2, '.', ''),
            ];
        }

        return $rows;
    }

    /**
     * Replace the whole list for one kind.
     *
     * Replace rather than reconcile, as everywhere else a form shows its full
     * list. Blank rows are dropped; the editor always carries a spare.
     *
     * Two rows at the same quantity would be a contradiction rather than a
     * break, and the unique key refuses them — so the last one entered wins
     * here, deliberately, rather than the save failing over a duplicate
     * somebody can see for themselves on the form.
     *
     * @param array<int,array{qty:int|string,price:float|string}> $rows
     */
    public static function replace(int $partId, string $kind, array $rows, int $userId): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            return;
        }

        $clean = [];
        foreach ($rows as $row) {
            $qty = (int) ($row['qty'] ?? 0);
            $price = $row['price'] ?? '';

            if ($qty <= 0 || $price === '' || !is_numeric($price)) {
                continue;
            }

            $clean[$qty] = round((float) $price, 2);
        }

        ksort($clean);

        Database::transaction(static function (PDO $pdo) use ($partId, $kind, $clean, $userId): void {
            $pdo->prepare('DELETE FROM part_price_breaks WHERE part_id = :part_id AND kind = :kind')
                ->execute(['part_id' => $partId, 'kind' => $kind]);

            if ($clean === []) {
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO part_price_breaks (part_id, kind, qty, price, set_by)
                 VALUES (:part_id, :kind, :qty, :price, :set_by)'
            );

            foreach ($clean as $qty => $price) {
                $statement->execute([
                    'part_id' => $partId,
                    'kind' => $kind,
                    'qty' => $qty,
                    'price' => $price,
                    'set_by' => $userId,
                ]);
            }
        });
    }

    /**
     * The price that applies at a given quantity: the highest break at or below
     * it, or the part's own price when nothing reaches that far down.
     *
     * **Nothing prices an order through this yet.** Order lines are still
     * priced at `parts.quoted_price`, as they always have been, and changing
     * that changes what gets invoiced — which is not a thing to do quietly as
     * a side effect of adding a table. This is here so that when it is done it
     * is one call in one place, and so the part page can say what a quantity
     * would cost.
     *
     * @param array<int,array<string,mixed>> $breaks from forPart(), ascending by qty
     */
    public static function priceAt(array $breaks, int $qty, ?float $basePrice): ?float
    {
        $price = $basePrice;

        foreach ($breaks as $break) {
            if ((int) $break['qty'] <= $qty) {
                $price = (float) $break['price'];
                continue;
            }
            break;
        }

        return $price;
    }

    /** The cheapest break there is, for a one-line "or £x at n+" note. */
    public static function best(array $breaks): ?array
    {
        $best = null;
        foreach ($breaks as $break) {
            if ($best === null || (float) $break['price'] < (float) $best['price']) {
                $best = $break;
            }
        }

        return $best;
    }
}
