<?php /** @var array $client */ /** @var array $lines */ ?>
<h1 class="mt-0">New delivery note — <?= e($client['name']) ?></h1>
<p class="text-muted">Pick the lines and quantities being shipped in this batch. Partial quantities are fine — the remainder stays outstanding on the order.</p>

<?php if ($lines === []): ?>
    <div class="card"><p class="empty-state">No completed lines are ready to ship for this client.</p></div>
<?php else: ?>
    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/delivery-note') ?>">
        <?= csrf_field() ?>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Order</th><th>CPN</th><th>Part</th><th>Completed, not yet shipped</th><th>Quantity to ship</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $line): $shippable = (int) $line['qty_shippable']; ?>
                        <tr>
                            <td><?= e($line['order_number']) ?><input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>"></td>
                            <td><?= e($line['cpn']) ?></td>
                            <td class="wrap"><?= e($line['part_name']) ?></td>
                            <td><?= $shippable ?> of <?= (int) $line['qty_ordered'] ?> ordered</td>
                            <td style="min-width:120px"><input type="number" min="0" max="<?= $shippable ?>" name="qty[]" value="0"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"></textarea></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate delivery note</button>
            <a href="<?= url('/staff/clients/' . $client['id']) ?>" class="btn">Cancel</a>
        </div>
    </form>
<?php endif; ?>
