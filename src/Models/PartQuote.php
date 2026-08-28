<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * The quoting scratchpad: what Junction thinks a part ought to cost, and the
 * working that got there.
 *
 * Quoting happened on paper, or in somebody's head, and what reached the
 * system was the answer with none of the arithmetic. When a price turned out
 * to be wrong there was nothing to look at — no way to tell whether the time
 * was underestimated, the material had gone up, or the mark-up had simply been
 * forgotten. This keeps the working next to the part it belongs to.
 *
 * It is a scratchpad, not a history. There is one draft per part, and it is
 * the answer to "what should this cost?" asked afresh whenever anybody
 * wonders. What was thought last year is not evidence about this year's
 * material price, so it is overwritten rather than kept.
 *
 * **Nothing here sets a price.** The draft total is a figure to look at while
 * deciding; setting the quoted price is still the deliberate, separate,
 * client-visible act it always was.
 */
final class PartQuote
{
    public const SETTING_RATE = 'quoting.machine_rate_per_minute';
    public const SETTING_MARKUP = 'quoting.markup_percent';

    private const FALLBACK_RATE = 1.00;
    private const FALLBACK_MARKUP = 30.00;

    /**
     * The house figures, from Settings.
     *
     * A missing or unparseable setting falls back to a sane number rather than
     * to zero: a rate of nothing would quietly value every part's machine time
     * at zero, which is a wrong answer that looks like a right one.
     *
     * @return array{rate:float,markup:float}
     */
    public static function defaults(): array
    {
        $rate = Setting::get(self::SETTING_RATE);
        $markup = Setting::get(self::SETTING_MARKUP);

        return [
            'rate' => is_numeric($rate) ? (float) $rate : self::FALLBACK_RATE,
            'markup' => is_numeric($markup) ? (float) $markup : self::FALLBACK_MARKUP,
        ];
    }

    public static function saveDefaults(float $rate, float $markup): void
    {
        Setting::put(self::SETTING_RATE, number_format($rate, 4, '.', ''));
        Setting::put(self::SETTING_MARKUP, number_format($markup, 2, '.', ''));
    }

    /** @return array<string,mixed>|null */
    public static function draft(int $partId): ?array
    {
        return Database::one(
            'SELECT d.*, u.name AS updated_by_name
               FROM part_quote_drafts d
          LEFT JOIN users u ON u.id = d.updated_by
              WHERE d.part_id = :part_id',
            ['part_id' => $partId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function lines(int $partId): array
    {
        return Database::all(
            'SELECT * FROM part_quote_lines WHERE part_id = :part_id ORDER BY position, id',
            ['part_id' => $partId]
        );
    }

    /**
     * Save the whole scratchpad: the two rates, the freeform lines, the note.
     *
     * A rate box left empty stores NULL, which means "follow the setting" — as
     * distinct from a rate that happens to equal the setting today. A part
     * deliberately quoted at the house rate and a part nobody has thought
     * about are the same number and not the same fact, and only the second
     * should move when the house rate changes.
     *
     * @param array<int,array{label:string,amount:float|string}> $lines
     */
    public static function save(
        int $partId,
        ?float $rate,
        ?float $markup,
        array $lines,
        ?string $notes,
        int $userId
    ): void {
        $clean = [];
        foreach ($lines as $line) {
            $label = trim((string) ($line['label'] ?? ''));
            $amount = $line['amount'] ?? '';

            // A line needs a name and a number. Blank rows are the editor's
            // spare, not an error.
            if ($label === '' || $amount === '' || !is_numeric($amount)) {
                continue;
            }

            $clean[] = ['label' => mb_substr($label, 0, 255), 'amount' => round((float) $amount, 2)];
        }

        Database::transaction(static function (PDO $pdo) use ($partId, $rate, $markup, $clean, $notes, $userId): void {
            $pdo->prepare(
                'INSERT INTO part_quote_drafts (part_id, machine_rate_per_minute, markup_percent, notes, updated_by)
                 VALUES (:part_id, :rate, :markup, :notes, :updated_by)
                 ON DUPLICATE KEY UPDATE
                    machine_rate_per_minute = :rate2,
                    markup_percent = :markup2,
                    notes = :notes2,
                    updated_by = :updated_by2'
            )->execute([
                'part_id' => $partId,
                'rate' => $rate,
                'markup' => $markup,
                'notes' => $notes,
                'updated_by' => $userId,
                'rate2' => $rate,
                'markup2' => $markup,
                'notes2' => $notes,
                'updated_by2' => $userId,
            ]);

            $pdo->prepare('DELETE FROM part_quote_lines WHERE part_id = :part_id')
                ->execute(['part_id' => $partId]);

            if ($clean === []) {
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO part_quote_lines (part_id, label, amount, position)
                 VALUES (:part_id, :label, :amount, :position)'
            );
            foreach ($clean as $position => $line) {
                $statement->execute([
                    'part_id' => $partId,
                    'label' => $line['label'],
                    'amount' => $line['amount'],
                    'position' => $position,
                ]);
            }
        });

        self::recalculateTotal($partId);
    }

    /**
     * Work out the draft, and show every step of it.
     *
     * Returned as a breakdown rather than a number because the number on its
     * own is exactly what this feature exists to replace. Somebody looking at
     * £236.93 needs to see that £132 of it is machine time before they can
     * agree or disagree with it.
     *
     * Machine time is the *estimated* build time, never the actual: a quote is
     * made before the work, and pricing a repeat order off how long it
     * happened to take last time would charge the client for Junction's bad
     * day. The actual is there to correct the estimate, which then moves this.
     *
     * @param array<string,mixed>            $part
     * @param array<string,mixed>|null       $draft
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public static function calculate(array $part, ?array $draft, array $lines): array
    {
        $defaults = self::defaults();

        $rate = $draft !== null && $draft['machine_rate_per_minute'] !== null
            ? (float) $draft['machine_rate_per_minute']
            : $defaults['rate'];
        $markup = $draft !== null && $draft['markup_percent'] !== null
            ? (float) $draft['markup_percent']
            : $defaults['markup'];

        $minutes = (int) ($part['estimated_build_time_minutes'] ?? 0);
        $machineCost = round($minutes * $rate, 2);
        $materialCost = $part['material_cost'] !== null ? (float) $part['material_cost'] : 0.0;

        $linesTotal = 0.0;
        foreach ($lines as $line) {
            $linesTotal += (float) $line['amount'];
        }
        $linesTotal = round($linesTotal, 2);

        $subtotal = round($machineCost + $materialCost + $linesTotal, 2);
        $markupAmount = round($subtotal * ($markup / 100), 2);

        return [
            'rate' => $rate,
            'rate_is_default' => $draft === null || $draft['machine_rate_per_minute'] === null,
            'markup' => $markup,
            'markup_is_default' => $draft === null || $draft['markup_percent'] === null,
            'minutes' => $minutes,
            'machine_cost' => $machineCost,
            'material_cost' => round($materialCost, 2),
            'lines_total' => $linesTotal,
            'subtotal' => $subtotal,
            'markup_amount' => $markupAmount,
            'total' => round($subtotal + $markupAmount, 2),
            // Without an estimate there is no machine time in the figure at
            // all, which is worth saying out loud rather than leaving somebody
            // to wonder why the draft looks cheap.
            'missing_time' => $minutes <= 0,
        ];
    }

    /**
     * Rewrite the cached draft total.
     *
     * Recalculated from the parts, never accumulated, like every other cached
     * total here. It exists so a listing can show the figure without
     * assembling the whole breakdown; the breakdown remains the truth.
     */
    public static function recalculateTotal(int $partId): void
    {
        $part = Part::find($partId);
        if ($part === null) {
            return;
        }

        $draft = self::draft($partId);
        if ($draft === null) {
            return;
        }

        $result = self::calculate($part, $draft, self::lines($partId));

        Database::query(
            'UPDATE part_quote_drafts SET draft_total = :total WHERE part_id = :part_id',
            ['total' => $result['total'], 'part_id' => $partId]
        );
    }

    /**
     * The draft has to be recomputed when something it reads changes — the
     * estimated build time and the material cost both live on the part, and
     * both move without anybody opening the scratchpad.
     */
    public static function refreshForPart(int $partId): void
    {
        self::recalculateTotal($partId);
    }

    /**
     * Recompute every draft that follows the house figures.
     *
     * Called when those figures change in Settings. A draft with its own rate
     * is left alone — that is the whole point of having stored one — so this
     * touches only the drafts that said "whatever the house says" and would
     * otherwise still be showing yesterday's answer to it.
     *
     * @return int how many were recomputed
     */
    public static function recalculateFollowers(): int
    {
        $rows = Database::all(
            'SELECT part_id FROM part_quote_drafts
              WHERE machine_rate_per_minute IS NULL OR markup_percent IS NULL'
        );

        foreach ($rows as $row) {
            self::recalculateTotal((int) $row['part_id']);
        }

        return count($rows);
    }

    /** How many parts have a scratchpad at all. */
    public static function draftCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM part_quote_drafts');
    }

    /** How many have set their own rate or mark-up rather than following these. */
    public static function overrideCount(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM part_quote_drafts
              WHERE machine_rate_per_minute IS NOT NULL OR markup_percent IS NOT NULL'
        );
    }
}
