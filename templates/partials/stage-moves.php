<?php
/**
 * One row per stage that has quantity at it, each carrying the move it can make
 * from there (item 6).
 *
 * Advancing, moving back and failing are the same form with a different
 * destination, which is deliberate: giving failure its own button is how a
 * reason ends up being asked for on one path and not the others.
 *
 * 'Delivered' and 'invoiced' offer nothing — parts get there by appearing on a
 * delivery note or an invoice, and a second way of saying so would disagree
 * with the first within a week.
 *
 * @var array $line an order line with its distribution attached
 */
use App\Models\OrderLine;

$occupied = OrderLine::occupiedStages($line);
?>
<div class="stage-rows">
    <?php foreach ($occupied as $stage => $qty): $destinations = OrderLine::manualDestinations($line, $stage); ?>
        <div class="stage-row">
            <span class="stage-row-name"><?= e(OrderLine::STAGE_LABELS[$stage]) ?></span>
            <span class="stage-row-qty"><?= (int) $qty ?></span>

            <?php if ($destinations === []): ?>
                <span class="text-muted">
                    <?= $stage === 'invoiced' ? 'Nothing left to do.' : 'Moves on when it is invoiced.' ?>
                </span>
            <?php else: ?>
                <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/move') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="from_stage" value="<?= e($stage) ?>">
                    <div class="field">
                        <label for="qty_<?= (int) $line['id'] ?>_<?= e($stage) ?>" class="sr-only">Quantity to move</label>
                        <input type="number" id="qty_<?= (int) $line['id'] ?>_<?= e($stage) ?>"
                               name="qty" min="1" max="<?= (int) $qty ?>" value="<?= (int) $qty ?>" required>
                    </div>
                    <div class="field">
                        <label for="to_<?= (int) $line['id'] ?>_<?= e($stage) ?>" class="sr-only">Move to</label>
                        <select id="to_<?= (int) $line['id'] ?>_<?= e($stage) ?>" name="to_stage">
                            <?php foreach ($destinations as $destination): ?>
                                <option value="<?= e($destination) ?>">
                                    <?= e(match ($destination) {
                                        'failed' => 'Mark as failed',
                                        'cancelled' => 'Cancel off',
                                        default => 'to ' . OrderLine::STAGE_SENTENCE_LABELS[$destination],
                                    }) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="reason_<?= (int) $line['id'] ?>_<?= e($stage) ?>" class="sr-only">Reason</label>
                        <input type="text" id="reason_<?= (int) $line['id'] ?>_<?= e($stage) ?>"
                               name="reason" placeholder="Reason (required to fail parts)">
                    </div>
                    <button type="submit" class="btn btn-sm">Move</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
