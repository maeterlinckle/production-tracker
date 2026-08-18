<?php /** @var array $orders */ ?>
<h1 class="mt-0">Orders</h1>

<div class="card">
    <?php if ($orders === []): ?>
        <p class="empty-state">No orders yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Client</th><th>Placed</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
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
    <?php endif; ?>
</div>
