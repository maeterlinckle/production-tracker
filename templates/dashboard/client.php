<?php /** @var array $parts */ /** @var array $partsByStatus */ /** @var array $orders */ /** @var int $totalOrders */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Dashboard</h1>
    <div style="display:flex; gap: var(--space-2)">
        <a href="<?= url('/parts/new') ?>" class="btn btn-primary">New part</a>
        <a href="<?= url('/orders/new') ?>" class="btn">Place order</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-info">
        <div class="stat-value"><?= (int) ($partsByStatus['quoted'] ?? 0) ?></div>
        <div class="stat-label">Parts ready to order</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) ($partsByStatus['draft'] ?? 0) ?></div>
        <div class="stat-label">Parts awaiting a quote</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $totalOrders ?></div>
        <div class="stat-label">Orders placed</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="mt-0 mb-0">Recent orders</h2>
        <a href="<?= url('/orders') ?>">View all &rarr;</a>
    </div>
    <?php if ($orders === []): ?>
        <p class="empty-state">No orders yet. Place your first order once a part has been quoted.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Placed</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['order_number']) ?></td>
                        <td><?= format_date($order['placed_at']) ?></td>
                        <td><?= status_badge($order['rollup_status']) ?></td>
                        <td><a href="<?= url('/orders/' . $order['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="mt-0 mb-0">Your parts</h2>
        <a href="<?= url('/parts') ?>">View all &rarr;</a>
    </div>
    <?php if ($parts === []): ?>
        <p class="empty-state">No parts yet. <a href="<?= url('/parts/new') ?>">Create one</a> to request a quote.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>CPN</th><th>Name</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($parts, 0, 8) as $part): ?>
                    <tr>
                        <td><?= e($part['cpn']) ?></td>
                        <td><?= e($part['name']) ?></td>
                        <td><?= status_badge($part['status']) ?></td>
                        <td><a href="<?= url('/parts/' . $part['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
