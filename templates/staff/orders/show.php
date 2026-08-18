<?php
/** @var array $order */ /** @var array $client */ /** @var array $lines */ /** @var array $routeCards */
/** @var array $deliveryNotes */ /** @var array $invoicesByDn */ /** @var array $photos */
/** @var array $notes */ /** @var array $queries */ /** @var string $rollupStatus */
use App\Core\Auth;
use App\Models\OrderLine;
$showPricing = Auth::can('view_pricing');
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($order['order_number']) ?> <?= status_badge($rollupStatus) ?></h1>
        <p class="text-muted mb-0"><?= e($client['name']) ?> &middot; Placed <?= format_date($order['placed_at']) ?></p>
    </div>
    <div style="display:flex; gap: var(--space-2)">
        <a href="<?= url('/files/po/' . $order['id']) ?>" class="btn" target="_blank" rel="noopener">View PO</a>
        <?php if (Auth::can('issue_delivery_notes')): ?>
            <a href="<?= url('/staff/clients/' . $client['id'] . '/delivery-note/new') ?>" class="btn btn-primary">Create delivery note</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($lines as $line): $routeCard = $routeCards[$line['id']] ?? null; ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="mt-0 mb-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h3>
                <p class="text-muted mb-0">
                    Qty ordered: <?= (int) $line['qty_ordered'] ?>
                    <?php if ($showPricing): ?> &middot; Unit price: <?= format_money($line['unit_price']) ?><?php endif; ?>
                </p>
            </div>
            <a href="<?= url('/staff/parts/' . $line['part_id']) ?>" class="btn btn-sm">View part</a>
        </div>

        <?= partial('partials/stepper', ['line' => $line]) ?>
        <p><?= status_badge($line['stage']) ?> <span class="text-muted"><?= e(OrderLine::statusLabel($line)) ?></span></p>

        <div class="grid grid-2" style="margin: var(--space-4) 0">
            <?php if ((int) $line['qty_free_issue_required'] > 0): ?>
                <?= partial('partials/qty-bar', ['label' => 'Free issue received', 'done' => $line['qty_free_issue_received'], 'total' => $line['qty_free_issue_required']]) ?>
            <?php endif; ?>
            <?= partial('partials/qty-bar', ['label' => 'Completed', 'done' => $line['qty_completed'], 'total' => $line['qty_ordered']]) ?>
            <?= partial('partials/qty-bar', ['label' => 'Delivered', 'done' => $line['qty_delivered'], 'total' => $line['qty_ordered']]) ?>
            <?php if ($showPricing): ?>
                <?= partial('partials/qty-bar', ['label' => 'Invoiced', 'done' => $line['qty_invoiced'], 'total' => $line['qty_delivered']]) ?>
            <?php endif; ?>
        </div>

        <?php if (Auth::can('production_control')): ?>
        <div class="grid grid-3">
            <?php if ((int) $line['qty_free_issue_required'] > (int) $line['qty_free_issue_received']): ?>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom: var(--space-1); font-size:0.9rem">Free issue</label>
                    <a href="<?= url('/staff/lines/' . $line['id'] . '/check-in') ?>" class="btn btn-sm">Check in free issue</a>
                </div>
            <?php endif; ?>

            <?php if ($line['stage'] === 'in_production' && (int) $line['qty_completed'] < (int) $line['qty_ordered']): ?>
                <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/completion') ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label>Record parts completed</label>
                        <input type="number" min="1" max="<?= (int) $line['qty_ordered'] - (int) $line['qty_completed'] ?>" name="qty" required placeholder="Qty completed">
                    </div>
                    <button type="submit" class="btn btn-sm">Record completion</button>
                </form>
            <?php endif; ?>

            <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/stage') ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Update production status</label>
                    <select name="stage">
                        <?php foreach (OrderLine::STAGES as $stage): ?>
                            <option value="<?= e($stage) ?>" <?= $line['stage'] === $stage ? 'selected' : '' ?>><?= e(OrderLine::STAGE_LABELS[$stage]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Update status</button>
            </form>

            <div>
                <label style="display:block; font-weight:600; margin-bottom: var(--space-1); font-size:0.9rem">Route card</label>
                <?php if ($routeCard !== null): ?>
                    <a href="<?= url('/staff/route-cards/' . $routeCard['id'] . '/pdf') ?>" class="btn btn-sm" target="_blank" rel="noopener"><?= e($routeCard['reference']) ?> PDF</a>
                <?php endif; ?>
                <form method="post" action="<?= url('/staff/lines/' . $line['id'] . '/route-card') ?>" style="margin-top: var(--space-2)">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm"><?= $routeCard !== null ? 'Regenerate' : 'Generate' ?> route card</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 class="mt-0">Delivery notes</h2>
    <p class="text-muted">Free-issue material in, and finished goods out, for this order.</p>
    <?php if ($deliveryNotes === []): ?>
        <p class="empty-state">None yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Type</th><th>Issued</th><th>Invoiced</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($deliveryNotes as $dn): $invoice = $invoicesByDn[$dn['id']] ?? null; ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= $dn['type'] === 'free_issue_in' ? 'Free issue in' : 'Goods out' ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td>
                            <?php if ($dn['type'] === 'goods_out'): ?>
                                <span class="badge <?= $dn['invoiced'] ? 'badge-ok' : 'badge-warn' ?>"><?= $dn['invoiced'] ? 'Invoiced' : 'Not invoiced' ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= url('/staff/delivery-notes/' . $dn['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="mt-0">Photos</h2>
    <p class="text-muted">Progress and setup/tooling photos, staff-only.</p>
    <?php if ($photos === []): ?>
        <p class="text-muted">No photos uploaded yet.</p>
    <?php else: ?>
        <div style="display:flex; flex-wrap:wrap; gap: var(--space-3)">
            <?php foreach ($photos as $photo): ?>
                <div style="text-align:center; max-width:120px">
                    <a href="<?= url('/files/order-photos/' . $photo['id']) ?>" target="_blank" rel="noopener">
                        <img src="<?= url('/files/order-photos/' . $photo['id']) ?>" alt="" style="width:110px;height:110px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border)">
                    </a>
                    <?php if ($photo['caption']): ?><div class="text-muted" style="font-size:0.8rem"><?= e($photo['caption']) ?></div><?php endif; ?>
                    <?php if (Auth::can('production_control')): ?>
                        <form method="post" action="<?= url('/staff/orders/' . $order['id'] . '/photos/' . $photo['id'] . '/delete') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm">Remove</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (Auth::can('production_control')): ?>
        <form method="post" action="<?= url('/staff/orders/' . $order['id'] . '/photos') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="field">
                    <label for="order_line_id">Line (optional)</label>
                    <select id="order_line_id" name="order_line_id">
                        <option value="">Whole order</option>
                        <?php foreach ($lines as $line): ?>
                            <option value="<?= (int) $line['id'] ?>"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="caption">Caption (optional)</label><input type="text" id="caption" name="caption"></div>
            </div>
            <div class="field">
                <label for="photos">Upload photo(s)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
            </div>
            <button type="submit" class="btn">Upload</button>
        </form>
    <?php endif; ?>
</div>

<?= partial('partials/order-notes-queries', ['order' => $order, 'notes' => $notes, 'queries' => $queries]) ?>
