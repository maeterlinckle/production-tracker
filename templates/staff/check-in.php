<?php
/** @var array $line */ /** @var array $order */ /** @var array $client */ /** @var array $part */
/** @var array $receipts */ /** @var array $rejections */ /** @var array|null $openDiscrepancy */
/** @var int $outstanding */ /** @var array|null $returnNote */
use App\Models\OrderLine;
use App\Models\Part;

$hasFreeIssue = Part::hasFreeIssue($part ?? []);
$usable = (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'];
$conversion = Part::conversionSentence($part ?? [], (int) $line['qty_ordered']);
?>
<h1 class="mt-0">Free-issue material</h1>
<p class="text-muted">
    <?= e($client['name']) ?> &middot; <?= e($order['order_number']) ?><?php if ($order['po_number'] !== ''): ?> &middot; PO <?= e($order['po_number']) ?><?php endif; ?>
</p>

<?php if ($returnNote !== null): ?>
    <div class="callout callout-ok">
        <p class="mb-1"><strong>Return note <?= e($returnNote['reference']) ?></strong> has been raised for the rejected material.</p>
        <a href="<?= url('/staff/delivery-notes/' . $returnNote['id'] . '/pdf') ?>" class="btn btn-sm" target="_blank" rel="noopener">View/print the return note</a>
        <a href="<?= url('/staff/delivery-notes/' . $returnNote['id']) ?>" class="btn btn-sm">Open it in the tracker</a>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h2>

    <?php if (!$hasFreeIssue): ?>
        <p class="text-muted">No free-issue material required for this part — there is nothing to check in.</p>
    <?php else: ?>
        <?= partial('partials/qty-bar', [
            'label' => 'Material received and usable',
            'done' => $usable,
            'total' => (int) $line['qty_free_issue_required'],
        ]) ?>
        <p style="margin-top: var(--space-3)"><?= e(OrderLine::freeIssueStatusSentence($line)) ?></p>
        <?php if ($conversion !== null): ?>
            <p class="field-hint"><?= e($conversion) ?></p>
        <?php endif; ?>

        <?php if ($openDiscrepancy !== null): ?>
            <div class="flash flash-danger" style="margin-top: var(--space-3)">
                <span>Open discrepancy: <?= e(OrderLine::DISCREPANCY_LABELS[$openDiscrepancy['discrepancy_type']]) ?><?= $openDiscrepancy['discrepancy_notes'] ? ' — ' . e($openDiscrepancy['discrepancy_notes']) : '' ?></span>
            </div>
            <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in/discrepancy/' . $openDiscrepancy['id'] . '/resolve') ?>" style="margin-top: var(--space-2)">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Mark discrepancy resolved</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($hasFreeIssue): ?>
<div class="card">
    <h2 class="mt-0">Check in a delivery</h2>
    <p class="text-muted">
        Whatever is accepted here goes straight to ready for production. There is no need to wait for the
        rest of the order — <?= (int) $outstanding ?> is still expected after this.
    </p>

    <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in') ?>" data-checkin-form>
        <?= csrf_field() ?>

        <div class="form-row">
            <div class="field">
                <label for="qty">Quantity received</label>
                <input type="number" min="1" id="qty" name="qty" required autofocus data-checkin-received>
                <div class="hint">Pieces of material, as counted off the lorry.</div>
            </div>
            <div class="field">
                <label for="notes">Notes (optional)</label>
                <input type="text" id="notes" name="notes">
            </div>
        </div>

        <fieldset class="field">
            <legend class="label">All received parts correct?</legend>
            <label class="checkbox-label">
                <input type="radio" name="all_correct" value="yes" data-checkin-correct>
                <span>Yes — all <span data-checkin-echo>of them</span> are good and can go into production</span>
            </label>
            <label class="checkbox-label">
                <input type="radio" name="all_correct" value="no" data-checkin-correct>
                <span>No — some are faulty, damaged or the wrong item</span>
            </label>
        </fieldset>

        <div data-checkin-rejections hidden>
            <h3>Rejected parts</h3>
            <p class="text-muted">
                Each entry goes on the return note the client reads, so the reason has to say something.
                Everything rejected here is added back to what this line still needs.
            </p>

            <div data-reject-rows>
                <div class="form-row reject-row">
                    <div class="field">
                        <label>Quantity rejected</label>
                        <input type="number" min="1" name="reject_qty[]" data-reject-qty>
                    </div>
                    <div class="field field-grow">
                        <label>Reason</label>
                        <input type="text" name="reject_reason[]" data-reject-reason placeholder="e.g. Bar cracked along its length">
                    </div>
                    <div class="field field-shrink">
                        <button type="button" class="btn btn-sm" data-reject-remove aria-label="Remove this entry">Remove</button>
                    </div>
                </div>
            </div>

            <button type="button" class="btn btn-sm" data-reject-add>Add another reason</button>
            <p class="error" data-checkin-error hidden></p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-checkin-submit disabled>Check in</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($rejections !== []): ?>
<div class="card">
    <h2 class="mt-0">Rejections</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Qty</th><th>Reason</th><th>Return note</th><th>Replacement on</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($rejections as $rejection): ?>
                <tr>
                    <td><?= format_datetime($rejection['rejected_at']) ?></td>
                    <td><?= (int) $rejection['qty_rejected'] ?></td>
                    <td class="wrap"><?= e($rejection['reason']) ?></td>
                    <td>
                        <?php if ($rejection['return_note_id']): ?>
                            <a href="<?= url('/staff/delivery-notes/' . $rejection['return_note_id']) ?>"><?= e($rejection['return_reference']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rejection['replacement_note_id']): ?>
                            <a href="<?= url('/staff/delivery-notes/' . $rejection['replacement_note_id']) ?>"><?= e($rejection['replacement_reference']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= e($rejection['rejected_by_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($receipts !== []): ?>
<div class="card">
    <h2 class="mt-0">Receipt history</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Qty</th><th>By</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $r): ?>
                <tr>
                    <td><?= format_datetime($r['received_at']) ?></td>
                    <td><?= (int) $r['qty_received'] ?></td>
                    <td><?= e($r['received_by_name']) ?></td>
                    <td class="wrap">
                        <?= e($r['notes'] ?? '') ?>
                        <?php if ($r['discrepancy_type'] !== 'none'): ?>
                            <div class="cell-sub"><?= e($r['discrepancy_notes'] ?? OrderLine::DISCREPANCY_LABELS[$r['discrepancy_type']]) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<p><a href="<?= url('/staff/orders/' . $order['id']) ?>">&larr; Back to order <?= e($order['order_number']) ?></a></p>
