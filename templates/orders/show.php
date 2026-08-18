<?php
/** @var array $order */ /** @var array $lines */ /** @var array $deliveryNotes */ /** @var array $notes */ /** @var array $queries */ /** @var string $rollupStatus */
use App\Core\Auth;
use App\Models\OrderLine;
$showPricing = Auth::can('view_pricing');
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($order['order_number']) ?> <?= status_badge($rollupStatus) ?></h1>
        <p class="text-muted mb-0">Placed <?= format_date($order['placed_at']) ?></p>
    </div>
    <a href="<?= url('/files/po/' . $order['id']) ?>" class="btn" target="_blank" rel="noopener">View purchase order</a>
</div>

<?php foreach ($lines as $line): ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="mt-0 mb-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h3>
                <p class="text-muted mb-0">
                    Qty ordered: <?= (int) $line['qty_ordered'] ?>
                    <?php if ($showPricing): ?> &middot; Unit price: <?= format_money($line['unit_price']) ?><?php endif; ?>
                </p>
            </div>
        </div>

        <?= partial('partials/stepper', ['line' => $line]) ?>
        <p class="text-muted"><?= e(OrderLine::statusLabel($line)) ?></p>

        <div class="grid grid-2" style="margin-top: var(--space-4)">
            <?php if ((int) $line['qty_free_issue_required'] > 0): ?>
                <?= partial('partials/qty-bar', ['label' => 'Free issue received', 'done' => $line['qty_free_issue_received'], 'total' => $line['qty_free_issue_required']]) ?>
            <?php endif; ?>
            <?= partial('partials/qty-bar', ['label' => 'Delivered', 'done' => $line['qty_delivered'], 'total' => $line['qty_ordered']]) ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 class="mt-0">Delivery notes</h2>
    <?php if ($deliveryNotes === []): ?>
        <p class="empty-state">None yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Type</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($deliveryNotes as $dn): ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= $dn['type'] === 'free_issue_in' ? 'Free issue — please send with material' : 'Goods received' ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td><a href="<?= url('/delivery-notes/' . $dn['id'] . '/pdf') ?>" target="_blank" rel="noopener">View PDF</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= partial('partials/order-notes-queries', ['order' => $order, 'notes' => $notes, 'queries' => $queries]) ?>
