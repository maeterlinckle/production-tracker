<?php
/**
 * Where a line's quantity is, and what can be done with it, as one table.
 *
 * Every stage that holds anything gets a row: the stage, how much is there, and
 * the one action available from it. Data on the left, actions on the right, so
 * the quantities can be read down the page without picking them out from
 * between input boxes.
 *
 * The first stage has no inputs at all. Material is booked in on the check-in
 * screen, which is also where anything wrong with it is dealt with, and this
 * row links there rather than offering a second way to record the same arrival.
 *
 * Quantities before completion are counted in pieces of material and after it
 * in finished parts, because for anything but a 1:1 part those are different
 * numbers — ten bars in the rack are twenty parts once they are through the
 * machine. The column says which is which.
 *
 * @var array $line an order line with its distribution attached
 * @var bool  $canProduce
 */
use App\Models\OrderLine;
use App\Models\Part;

$occupied = OrderLine::occupiedStages($line);
$converts = Part::convertsQuantity($line);
$canProduce = $canProduce ?? false;
?>
<div class="table-wrap">
    <table class="stage-table">
        <thead>
            <tr>
                <th scope="col">Stage</th>
                <th scope="col" class="align-right">Quantity</th>
                <?php if ($converts): ?><th scope="col">Counted in</th><?php endif; ?>
                <th scope="col" class="stage-table-actions">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($occupied as $stage => $qty):
            $destinations = OrderLine::manualDestinations($line, $stage);
            // Material in and out of the first stage belongs to check-in.
            $destinations = array_values(array_filter(
                $destinations,
                static fn (string $d): bool => !($stage === 'awaiting_free_issue' && $d === 'ready_for_production')
            ));
            $rowId = (int) $line['id'] . '_' . $stage;
            ?>
            <tr>
                <th scope="row" class="stage-name">
                    <span class="stage-dot" data-stage="<?= e($stage) ?>" aria-hidden="true"></span>
                    <?= e(OrderLine::STAGE_LABELS[$stage]) ?>
                </th>
                <?php $shown = OrderLine::displayQty($line, $stage); ?>
                <td class="align-right stage-qty"><?= $shown ?></td>
                <?php if ($converts): ?>
                    <td class="stage-unit"><?= e(OrderLine::unitNoun($line, $stage, $shown)) ?></td>
                <?php endif; ?>
                <td class="stage-table-actions">
                    <?php if (!$canProduce): ?>
                        <span class="text-muted">—</span>
                    <?php elseif ($stage === 'awaiting_free_issue'): ?>
                        <a href="<?= url('/staff/lines/' . $line['id'] . '/check-in') ?>" class="btn btn-sm btn-primary">
                            Check in or reject material
                        </a>
                    <?php elseif ($destinations === []): ?>
                        <span class="text-muted">
                            <?= $stage === 'invoiced' ? 'Nothing left to do' : 'Moves on when it is invoiced' ?>
                        </span>
                    <?php else: ?>
                        <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/move') ?>" class="action-row">
                            <?= csrf_field() ?>
                            <input type="hidden" name="from_stage" value="<?= e($stage) ?>">

                            <label class="sr-only" for="qty_<?= e($rowId) ?>">Quantity to move</label>
                            <input type="number" id="qty_<?= e($rowId) ?>" name="qty" class="input-qty"
                                   min="1" max="<?= $shown ?>" value="<?= $shown ?>" required>

                            <label class="sr-only" for="to_<?= e($rowId) ?>">Move to</label>
                            <select id="to_<?= e($rowId) ?>" name="to_stage" class="input-stage">
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

                            <label class="sr-only" for="reason_<?= e($rowId) ?>">Reason</label>
                            <input type="text" id="reason_<?= e($rowId) ?>" name="reason" class="input-reason"
                                   placeholder="Reason (required to fail parts)">

                            <button type="submit" class="btn btn-sm">Move</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
