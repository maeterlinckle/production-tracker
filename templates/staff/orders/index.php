<?php
/**
 * @var array $orders
 * @var bool  $includeClosed whether orders on switched-off accounts are shown
 * @var int   $hiddenCount   how many are being left out
 */
?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Orders</h1>
    <?php /*
        Orders on a switched-off account are out of the list rather than gone.
        The toggle only appears when there are some to show, so the ordinary
        case is not carrying a control for a situation that does not exist.
    */ ?>
    <?php if ($includeClosed || $hiddenCount > 0): ?>
        <div style="display:flex; gap: var(--space-2); align-items:center">
            <?php if (!$includeClosed): ?>
                <span class="text-muted"><?= $hiddenCount ?> on switched-off accounts</span>
                <a href="<?= url('/staff/orders?accounts=all') ?>" class="btn btn-sm">Show them</a>
            <?php else: ?>
                <a href="<?= url('/staff/orders') ?>" class="btn btn-sm">Hide switched-off accounts</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

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
                        <td class="wrap"><?= e($order['client_name']) ?><?php if (isset($order['client_is_active']) && !(bool) $order['client_is_active']): ?> <span class="badge badge-muted">Off</span><?php endif; ?></td>
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
