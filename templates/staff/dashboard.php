<?php /** @var array $uninvoiced */ /** @var array $unquoted */ /** @var array $awaitingFreeIssue */ /** @var array $recentOrders */ /** @var int $totalOrders */ ?>
<h1 class="mt-0">Staff dashboard</h1>

<div class="stat-grid">
    <div class="stat-card<?= $uninvoiced === [] ? '' : ' stat-warn' ?>">
        <div class="stat-value"><?= count($uninvoiced) ?></div>
        <div class="stat-label">Delivery notes not yet invoiced</div>
    </div>
    <div class="stat-card<?= $unquoted === [] ? '' : ' stat-info' ?>">
        <div class="stat-value"><?= count($unquoted) ?></div>
        <div class="stat-label">Parts awaiting a price</div>
    </div>
    <div class="stat-card<?= $awaitingFreeIssue === [] ? '' : ' stat-warn' ?>">
        <div class="stat-value"><?= count($awaitingFreeIssue) ?></div>
        <div class="stat-label">Lines awaiting free issue</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="mt-0 mb-0">Not yet invoiced</h2>
        <a href="<?= url('/staff/delivery-notes?filter=uninvoiced') ?>">View all &rarr;</a>
    </div>
    <?php if ($uninvoiced === []): ?>
        <p class="empty-state">Nothing outstanding — every goods-out delivery note has been invoiced.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Client</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($uninvoiced as $dn): ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= e($dn['client_name']) ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td><a href="<?= url('/staff/delivery-notes/' . $dn['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="mt-0 mb-0">Parts awaiting a price</h2>
        <a href="<?= url('/staff/parts?filter=unquoted') ?>">View all &rarr;</a>
    </div>
    <?php if ($unquoted === []): ?>
        <p class="empty-state">Nothing waiting on pricing.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>CPN</th><th>Client</th><th>Name</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($unquoted, 0, 10) as $part): ?>
                    <tr>
                        <td><?= e($part['cpn']) ?></td>
                        <td><?= e($part['client_name']) ?></td>
                        <td><?= e($part['name']) ?></td>
                        <td><a href="<?= url('/staff/parts/' . $part['id']) ?>">Price it</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="mt-0 mb-0">Recent orders</h2>
        <a href="<?= url('/staff/orders') ?>">View all (<?= $totalOrders ?>) &rarr;</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Order</th><th>Client</th><th>Placed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $order): ?>
                <tr>
                    <td><?= e($order['order_number']) ?></td>
                    <td><?= e($order['client_name']) ?></td>
                    <td><?= format_date($order['placed_at']) ?></td>
                    <td><a href="<?= url('/staff/orders/' . $order['id']) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
