<?php
/** @var array $line */ /** @var array $order */ /** @var array $client */ /** @var array $part */
/** @var array $receipts */ /** @var array $rejections */ /** @var array|null $openDiscrepancy */
/** @var int $outstanding */
use App\Models\OrderLine;
use App\Models\Part;

$usable = (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'];
?>
<h1 class="mt-0">Free-issue material</h1>
<p class="text-muted"><?= e($client['name']) ?> &middot; <?= e($order['order_number']) ?><?php if ($order['po_number'] !== ''): ?> &middot; PO <?= e($order['po_number']) ?><?php endif; ?></p>

<div class="card">
    <h2 class="mt-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h2>

    <?php if (!Part::hasFreeIssue($part ?? [])): ?>
        <p class="text-muted">No free-issue material required for this part — there is nothing to check in.</p>
    <?php else: ?>
        <?= partial('partials/qty-bar', [
            'label' => 'Material received',
            'done' => $usable,
            'total' => (int) $line['qty_free_issue_required'],
        ]) ?>
        <p style="margin-top: var(--space-3)"><?= e(OrderLine::freeIssueStatusSentence($line)) ?></p>

        <p class="text-muted">
            Booking material in does not start any parts. Advance whatever the workshop is ready to start on
            from the <a href="<?= url('/staff/orders/' . $order['id']) ?>">order page</a> — partial material
            is enough to get partial production going.
        </p>

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

<?php if (Part::hasFreeIssue($part ?? [])): ?>
<div class="card">
    <h2 class="mt-0">Record receipt</h2>
    <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in') ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field">
                <label for="qty">Quantity received</label>
                <input type="number" min="1" id="qty" name="qty" required autofocus>
                <div class="hint"><?= (int) $outstanding ?> still expected.</div>
            </div>
            <div class="field">
                <label for="discrepancy_type">Is it correct?</label>
                <select id="discrepancy_type" name="discrepancy_type">
                    <?php foreach (OrderLine::DISCREPANCY_TYPES as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(OrderLine::DISCREPANCY_LABELS[$type]) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">"None" if the material matches what was expected.</div>
            </div>
        </div>
        <div class="field">
            <label for="discrepancy_notes">Discrepancy notes (if any)</label>
            <input type="text" id="discrepancy_notes" name="discrepancy_notes">
        </div>
        <div class="field">
            <label for="notes">Notes</label>
            <input type="text" id="notes" name="notes">
        </div>
        <button type="submit" class="btn btn-primary">Check in</button>
    </form>
</div>

<div class="card">
    <h2 class="mt-0">Reject material</h2>
    <p class="text-muted">
        For material that arrived and <strong>cannot be used</strong> — wrong grade, faulty, damaged. It is
        different from material simply not having turned up: a shortage is already covered by the free-issue
        note that is out, and needs nothing doing here.
    </p>
    <p class="text-muted">
        Rejecting raises a return note for what is going back and puts the same quantity back on to what this
        line still needs, so the client is asked for a replacement.
    </p>
    <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in/reject') ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field">
                <label for="reject_qty">Quantity to reject</label>
                <input type="number" id="reject_qty" name="qty" min="1" max="<?= max(0, $usable) ?>" required>
                <div class="hint"><?= max(0, $usable) ?> received and not already rejected.</div>
            </div>
            <div class="field">
                <label for="reject_reason">Reason</label>
                <input type="text" id="reject_reason" name="reason" required placeholder="Goes on the return note the client reads">
            </div>
        </div>
        <button type="submit" class="btn" <?= $usable <= 0 ? 'disabled' : '' ?>>Reject and raise return note</button>
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
                    <td><?= e($rejection['reason']) ?></td>
                    <td><?= $rejection['return_reference'] ? e($rejection['return_reference']) : '—' ?></td>
                    <td><?= $rejection['replacement_reference'] ? e($rejection['replacement_reference']) : '—' ?></td>
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
            <thead><tr><th>Date</th><th>Qty</th><th>By</th><th>Discrepancy</th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $r): ?>
                <tr>
                    <td><?= format_datetime($r['received_at']) ?></td>
                    <td><?= (int) $r['qty_received'] ?></td>
                    <td><?= e($r['received_by_name']) ?></td>
                    <td><?= $r['discrepancy_type'] !== 'none' ? e(OrderLine::DISCREPANCY_LABELS[$r['discrepancy_type']]) . ($r['resolved_at'] ? ' (resolved)' : '') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<p><a href="<?= url('/staff/orders/' . $order['id']) ?>">&larr; Back to order <?= e($order['order_number']) ?></a></p>
