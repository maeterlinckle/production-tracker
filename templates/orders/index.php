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
            <?php /* Same columns, in the same order and at the same widths, as
                     Junction's list of every order — see .table-orders. */ ?>
            <table class="table-orders">
                <colgroup>
                    <col class="col-order-ref">
                    <col class="col-order-date">
                    <col class="col-order-lines">
                    <col class="col-order-status">
                    <col class="col-order-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Placed</th>
                        <th scope="col" class="align-right">Lines</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['order_number']) ?></td>
                        <td><?= format_date($order['placed_at']) ?></td>
                        <td class="align-right"><?= (int) $order['line_count'] ?></td>
                        <td><?= status_badge($order['rollup_status']) ?></td>
                        <td><a href="<?= url('/orders/' . $order['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
