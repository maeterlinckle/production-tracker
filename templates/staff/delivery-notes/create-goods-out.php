<?php
/**
 * @var array      $client
 * @var array|null $focusOrder  the order this page was opened from, if any
 * @var array      $focusLines  that order's shippable lines
 * @var array      $otherLines  everything else the client has ready
 * @var bool       $focusRequested whether an order was named in the query string
 * @var array      $lines       all of them, for the empty check
 */
?>
<?php /*
    Nine times out of ten this page is opened from an order, so that is where
    back goes. Reached from the client's own page instead, there is no order to
    return to and the client is the right destination. The mid-page "Back to the
    order" button in the focus card stays: it is the end of a block of reading,
    and this one is navigation before it.
*/ ?>
<?= partial('partials/back-link', $focusOrder !== null
    ? ['href' => '/staff/orders/' . $focusOrder['id'], 'label' => 'Back to order ' . $focusOrder['order_number']]
    : ['href' => '/staff/clients/' . $client['id'], 'label' => 'Back to ' . $client['name']]) ?>

<h1 class="mt-0">New delivery note — <?= e($client['name']) ?></h1>
<p class="text-muted">
    Pick the lines and quantities being shipped in this batch. Partial quantities are fine — the remainder
    stays outstanding on the order.
</p>

<?php if ($lines === []): ?>
    <div class="card"><p class="empty-state mb-0">No completed lines are ready to ship for this client.</p></div>
<?php else: ?>
    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/delivery-note') ?>">
        <?= csrf_field() ?>

        <?php
        /**
         * One table body, used twice. The focus order's rows come pre-filled
         * with everything that is ready, because despatching all of it is what
         * somebody arriving from that order almost always means; the other
         * orders start at zero and have to be asked for.
         */
        $rows = static function (array $lines, bool $prefill): void { ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th><th>CPN</th><th>Part</th>
                            <th>Completed, not yet shipped</th><th>Quantity to ship</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $line): $shippable = (int) $line['qty_shippable']; ?>
                        <tr>
                            <td>
                                <?= e($line['order_number']) ?>
                                <input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>">
                            </td>
                            <td><?= e($line['cpn']) ?></td>
                            <td class="wrap"><?= e($line['part_name']) ?></td>
                            <td><?= $shippable ?> of <?= (int) $line['qty_ordered'] ?> ordered</td>
                            <td style="min-width:120px">
                                <input type="number" min="0" max="<?= $shippable ?>" name="qty[]"
                                       value="<?= $prefill ? $shippable : 0 ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php };
        ?>

        <?php if ($focusOrder !== null && $focusLines !== []): ?>
            <div class="card card-focus">
                <div class="card-header">
                    <div>
                        <h2 class="mt-0 mb-0">
                            <?= e($focusOrder['order_number']) ?>
                            <span class="badge badge-info">This delivery</span>
                        </h2>
                        <p class="text-muted mb-0">
                            <?= count($focusLines) ?> line<?= count($focusLines) === 1 ? '' : 's' ?> ready to go
                            <?php if (($focusOrder['po_number'] ?? '') !== ''): ?>
                                &middot; PO <?= e($focusOrder['po_number']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="<?= url('/staff/orders/' . $focusOrder['id']) ?>" class="btn btn-sm">Back to the order</a>
                </div>
                <?php $rows($focusLines, true); ?>
            </div>

            <?php if ($otherLines !== []): ?>
                <div class="card">
                    <h2 class="mt-0">Also ready for <?= e($client['name']) ?></h2>
                    <p class="text-muted">
                        Other orders with finished parts waiting. Add any of them to this same delivery if they
                        are going in the same parcel — otherwise leave them at zero and they stay outstanding.
                    </p>
                    <?php $rows($otherLines, false); ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card">
                <?php if ($focusRequested): ?>
                    <p class="text-muted">
                        That order has nothing finished and waiting, so everything ready for this client is
                        listed below instead.
                    </p>
                <?php endif; ?>
                <?php $rows($lines, false); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"></textarea></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate delivery note</button>
            <a href="<?= url('/staff/clients/' . $client['id']) ?>" class="btn">Cancel</a>
        </div>
    </form>
<?php endif; ?>
