<?php
/** @var array $line */ /** @var array $order */ /** @var array $client */ /** @var array $receipts */ /** @var array|null $openDiscrepancy */
use App\Models\OrderLine;
?>
<h1 class="mt-0">Check in free issue</h1>
<p class="text-muted"><?= e($client['name']) ?> &middot; <?= e($order['order_number']) ?></p>

<div class="card">
    <h2 class="mt-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h2>
    <?= partial('partials/qty-bar', ['label' => 'Free issue received', 'done' => $line['qty_free_issue_received'], 'total' => $line['qty_free_issue_required']]) ?>
    <p style="margin-top: var(--space-3)"><?= status_badge($line['stage']) ?></p>

    <?php if ($openDiscrepancy !== null): ?>
        <div class="flash flash-danger" style="margin-top: var(--space-3)">
            <span>Open discrepancy: <?= e(OrderLine::DISCREPANCY_LABELS[$openDiscrepancy['discrepancy_type']]) ?><?= $openDiscrepancy['discrepancy_notes'] ? ' — ' . e($openDiscrepancy['discrepancy_notes']) : '' ?></span>
        </div>
        <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in/discrepancy/' . $openDiscrepancy['id'] . '/resolve') ?>" style="margin-top: var(--space-2)">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm">Mark discrepancy resolved</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="mt-0">Record receipt</h2>
    <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/check-in') ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field">
                <label for="qty">Quantity received</label>
                <input type="number" min="1" id="qty" name="qty" required autofocus>
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
