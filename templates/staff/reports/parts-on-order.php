<?php
/** @var array $parts */ /** @var array $totals */ /** @var int $ageingDays */
/** @var array $clients */ /** @var int|null $clientId */
use App\Services\PartsOnOrder;

$csvQuery = 'format=csv' . ($clientId !== null ? '&client_id=' . $clientId : '');
?>
<div class="page-head">
    <div>
        <h1>Parts on order</h1>
        <p class="muted">
            Everything still to be made, totalled per part. A part ordered on three purchase orders is one
            row here with three orders underneath it.
        </p>
    </div>
    <a href="<?= url('/staff/reports/parts-on-order?' . $csvQuery) ?>" class="btn">Export CSV</a>
</div>

<form method="get" action="<?= url('/staff/reports/parts-on-order') ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="label" for="client_id">Client</label>
        <select class="input" id="client_id" name="client_id">
            <option value="">Every client</option>
            <?php foreach ($clients as $client): ?>
                <option value="<?= (int) $client['id'] ?>" <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>>
                    <?= e($client['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Apply</button>
    <?php if ($clientId !== null): ?>
        <a href="<?= url('/staff/reports/parts-on-order') ?>" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
</form>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= (int) $totals['qty_outstanding'] ?></div>
        <div class="stat-label">Parts still to make</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $totals['parts'] ?></div>
        <div class="stat-label">Distinct part numbers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $totals['orders'] ?></div>
        <div class="stat-label">Orders involved</div>
    </div>
    <div class="stat-card<?= $totals['blocked'] > 0 ? ' stat-warn' : '' ?>">
        <div class="stat-value"><?= (int) $totals['blocked'] ?></div>
        <div class="stat-label">Lines held for free issue</div>
    </div>
    <div class="stat-card<?= $totals['ageing'] > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= (int) $totals['ageing'] ?></div>
        <div class="stat-label">Open more than <?= (int) $ageingDays ?> days</div>
    </div>
</div>

<?php if ($parts === []): ?>
    <div class="card">
        <p class="empty-state mb-0">Nothing outstanding — every ordered part has been made.</p>
    </div>
<?php else: ?>
    <?php foreach ($parts as $part): ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="mb-0">
                        <?= e($part['cpn']) ?> — <?= e($part['part_name']) ?>
                        <?php if ($part['blocked']): ?><span class="badge badge-warn">Held</span><?php endif; ?>
                        <?php if ($part['oldest_days'] > $ageingDays): ?><span class="badge badge-danger"><?= (int) $part['oldest_days'] ?> days</span><?php endif; ?>
                    </h2>
                    <p class="cell-sub mb-0">
                        <?= e($part['client_name']) ?>
                        <?php if ($part['base_material']): ?> &middot; <?= e($part['base_material']) ?><?php endif; ?>
                        &middot; across <?= (int) $part['order_count'] ?> <?= $part['order_count'] === 1 ? 'order' : 'orders' ?>
                    </p>
                </div>
                <div>
                    <div class="stat-value"><?= (int) $part['qty_outstanding'] ?></div>
                    <div class="stat-label">still to make</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table table-compact table-report">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Placed</th>
                            <th class="align-right">Ordered</th>
                            <th class="align-right">Made</th>
                            <th class="align-right">Outstanding</th>
                            <th class="align-right">Despatched</th>
                            <th>Stage</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($part['lines'] as $line): $hold = PartsOnOrder::holdReason($line); ?>
                        <tr>
                            <td>
                                <a href="<?= url('/staff/orders/' . $line['order_id']) ?>"><?= e($line['order_number']) ?></a>
                                <?php if ($hold !== ''): ?>
                                    <div class="cell-sub"><?= e($hold) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= format_date($line['placed_at']) ?>
                                <div class="cell-sub">
                                    <?php $days = (int) $line['days_open']; ?>
                                    <?= $days === 0 ? 'today' : ($days === 1 ? 'yesterday' : $days . ' days ago') ?>
                                </div>
                            </td>
                            <td class="align-right"><?= (int) $line['qty_ordered'] ?></td>
                            <td class="align-right"><?= (int) $line['qty_completed'] ?></td>
                            <td class="align-right"><strong><?= (int) $line['qty_outstanding'] ?></strong></td>
                            <td class="align-right"><?= (int) $line['qty_delivered'] ?></td>
                            <td><?= status_badge($line['stage']) ?></td>
                            <td class="actions">
                                <a href="<?= url('/staff/parts/' . $line['part_id']) ?>" class="btn btn-sm">Part</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (count($part['lines']) > 1): ?>
                        <tfoot>
                            <tr>
                                <th colspan="2">Total for this part</th>
                                <th class="align-right"><?= (int) $part['qty_ordered'] ?></th>
                                <th class="align-right"><?= (int) $part['qty_completed'] ?></th>
                                <th class="align-right"><?= (int) $part['qty_outstanding'] ?></th>
                                <th class="align-right"><?= (int) $part['qty_ordered'] - (int) $part['qty_outstanding'] - (int) $part['qty_awaiting_despatch'] ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ((int) $part['qty_awaiting_despatch'] > 0): ?>
                <p class="field-hint mb-0">
                    <?= (int) $part['qty_awaiting_despatch'] ?> already made and waiting to go out — raise a
                    delivery note rather than making more.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <p class="report-footnote muted">
        Outstanding is what has not yet come off a machine (ordered minus made). A line leaves this report
        when it is fully made or the order line is closed.
    </p>
<?php endif; ?>
