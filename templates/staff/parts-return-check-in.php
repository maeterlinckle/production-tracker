<?php
/**
 * Booking in finished parts a client has sent back.
 *
 * The mirror of the free-issue check-in, and a separate screen for the reason
 * given on StaffCheckInController::showPartsReturn: they count different things
 * and answer different questions. What they share is the shape — here is what
 * was declared, tell us what actually turned up — because that is the question
 * goods-in is always answering.
 *
 * @var array      $note
 * @var array|null $relatedNote
 * @var array      $line       the return note's single line
 * @var array      $orderLine
 * @var array      $order
 * @var array      $client
 * @var array      $receipts
 * @var int        $outstanding
 */
use App\Models\OrderLine;

$declared = (int) $line['qty'];
$bookedIn = $declared - $outstanding;
$delivered = OrderLine::qtyAt($orderLine, 'delivered');
$invoiced = OrderLine::qtyAt($orderLine, 'invoiced');
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($note['reference']) ?></h1>
        <p class="text-muted mb-0">
            <?= e($client['name']) ?> &middot; <?= e($order['order_number']) ?>
            &middot; raised <?= format_date($note['issued_at']) ?>
        </p>
    </div>
    <a href="<?= url('/staff/delivery-notes/' . $note['id'] . '/pdf') ?>" class="btn" target="_blank" rel="noopener">
        View/print the return note
    </a>
</div>

<div class="card">
    <h2 class="mt-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h2>

    <?= partial('partials/qty-bar', [
        'label' => 'Booked back in',
        'done' => $bookedIn,
        'total' => $declared,
    ]) ?>

    <p style="margin-top: var(--space-3)">
        <?= e($client['name']) ?> rejected <strong><?= $declared ?></strong> of these<?php
        ?><?= $relatedNote !== null
            ? ' from ' . e($relatedNote['reference']) . ', sent ' . format_date($relatedNote['issued_at'])
            : '' ?>.
    </p>

    <div class="callout">
        <p class="mb-1"><strong>What they say is wrong</strong></p>
        <p class="mb-0"><?= nl2br(e((string) $note['notes'])) ?></p>
    </div>

    <p class="field-hint" style="margin-top: var(--space-3)">
        Drawing: <?= e($line['drawing_reference'] ?? '') ?: 'none on file' ?>
    </p>
</div>

<div class="card">
    <h2 class="mt-0">Book the parts in</h2>

    <?php if ($outstanding <= 0): ?>
        <p class="empty-state mb-0">
            All <?= $declared ?> have been booked in. They are counted as failed on the order line, so they
            show as still owed rather than delivered.
        </p>
    <?php else: ?>
        <p class="text-muted">
            Count the finished parts out of the parcel and enter what actually arrived, which is not
            necessarily what was declared. What is booked in here moves out of
            <?= $delivered > 0 ? 'delivered' : 'whatever it was counted as' ?> and into
            <strong>failed</strong> on line <?= e($line['cpn']) ?> — so it stops counting as delivered,
            starts counting as still to be made, and the material needed to remake it is added to what
            the line is asking the client for.
        </p>

        <p class="text-muted">
            This line currently has <?= $delivered ?> delivered<?= $invoiced > 0 ? ' and ' . $invoiced . ' invoiced' : '' ?>.
            <?php if ($delivered < $outstanding && $invoiced > 0): ?>
                There is not enough uninvoiced quantity to cover the whole return, so some of it will come
                out of the invoiced total and will need a credit note raising.
            <?php endif; ?>
        </p>

        <form method="post" action="<?= url('/staff/parts-returns/' . $note['id'] . '/check-in') ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="field field-shrink">
                    <label for="qty">Quantity received</label>
                    <input type="number" class="input-qty" id="qty" name="qty" min="1"
                           max="<?= $outstanding ?>" value="<?= $outstanding ?>" required autofocus>
                    <div class="hint"><?= $outstanding ?> declared</div>
                </div>
                <div class="field field-grow">
                    <label for="notes">Notes (optional)</label>
                    <input type="text" id="notes" name="notes" placeholder="e.g. Two of the four were fine — kept for stock">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Book in and mark as failed</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($receipts !== []): ?>
<div class="card">
    <h2 class="mt-0">Receipt history</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th class="align-right">Qty</th><th>By</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $receipt): ?>
                <tr>
                    <td><?= format_datetime($receipt['received_at']) ?></td>
                    <td class="align-right"><?= (int) $receipt['qty_received'] ?></td>
                    <td><?= e($receipt['received_by_name']) ?></td>
                    <td class="wrap"><?= e($receipt['notes'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<p><a href="<?= url('/staff/orders/' . $order['id'] . '#delivery-notes') ?>">&larr; Back to order <?= e($order['order_number']) ?></a></p>
