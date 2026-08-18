<?php /** @var array $orders */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Orders</h1>
    <a href="<?= url('/orders/new') ?>" class="btn btn-primary">Place order</a>
</div>

<div class="card">
    <?php if ($orders === []): ?>
        <p class="empty-state">No orders yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Placed</th><th>Lines</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['order_number']) ?></td>
                        <td><?= format_date($order['placed_at']) ?></td>
                        <td><?= (int) $order['line_count'] ?></td>
                        <td><?= status_badge($order['rollup_status']) ?></td>
                        <td><a href="<?= url('/orders/' . $order['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
