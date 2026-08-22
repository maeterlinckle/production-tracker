<?php /** @var array $orders */ ?>
<h1 class="mt-0">Orders</h1>

<div class="card">
    <?php if ($orders === []): ?>
        <p class="empty-state">No orders yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <?php /* The client's own order list, plus the column saying whose
                     order it is. Lines and Status were missing here, so the one
                     list covering every order in the shop was the one place you
                     could not see what state an order was in. */ ?>
            <table class="table-orders table-orders-staff">
                <colgroup>
                    <col class="col-order-ref">
                    <col class="col-order-client">
                    <col class="col-order-date">
                    <col class="col-order-lines">
                    <col class="col-order-status">
                    <col class="col-order-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Client</th>
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
                        <td class="wrap"><?= e($order['client_name']) ?></td>
                        <td><?= format_date($order['placed_at']) ?></td>
                        <td class="align-right"><?= (int) $order['line_count'] ?></td>
                        <td><?= status_badge($order['rollup_status']) ?></td>
                        <td><a href="<?= url('/staff/orders/' . $order['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
