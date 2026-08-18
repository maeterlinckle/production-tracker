<?php
/** @var array $order */ /** @var array $lines */ /** @var array $changeRequests */
/** @var array $deliveryNotes */ /** @var array $poDocuments */ /** @var array $notes */
/** @var array $queries */ /** @var string $rollupStatus */
use App\Core\Auth;
use App\Models\Order;
use App\Models\OrderLine;

$showPricing = Auth::can('view_pricing');
$canRequestChange = Auth::can('request_quantity_change');
$orderClosed = Order::isClosed($order);

$freeIssueNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'free_issue_in'));
$returnNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'material_return'));
$goodsOutNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'goods_out'));
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($order['order_number']) ?> <?= status_badge($rollupStatus) ?></h1>
        <p class="text-muted mb-0">
            Placed <?= format_date($order['placed_at']) ?>
            <?php if ($order['po_number'] !== ''): ?>&middot; Your PO <?= e($order['po_number']) ?><?php endif; ?>
        </p>
        <?php if ($orderClosed): ?>
            <p class="text-muted mb-0">Closed down <?= format_date($order['closed_at']) ?><?= $order['close_reason'] ? ' — ' . e($order['close_reason']) : '' ?></p>
        <?php endif; ?>
    </div>
    <a href="<?= url('/files/po/' . $order['id']) ?>" class="btn" target="_blank" rel="noopener">View purchase order</a>
</div>

<?php foreach ($lines as $line):
    $requests = $changeRequests[$line['id']] ?? [];
    $pending = null;
    foreach ($requests as $candidate) {
        if ($candidate['status'] === 'pending') {
            $pending = $candidate;
            break;
        }
    }
    $needsFreeIssue = OrderLine::needsFreeIssue($line);
    ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="mt-0 mb-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h3>
                <p class="text-muted mb-0">
                    Qty ordered: <?= (int) $line['qty_ordered'] ?>
                    <?php if ($showPricing): ?> &middot; Unit price: <?= format_money($line['unit_price']) ?><?php endif; ?>
                </p>
            </div>
        </div>

        <p class="mb-0"><?= status_badge(OrderLine::headlineStage($line)) ?>
            <span class="text-muted"><?= e(OrderLine::statusLabel($line)) ?></span></p>
        <?= partial('partials/stepper', ['line' => $line]) ?>

        <div style="margin-top: var(--space-4)">
            <?php if ($needsFreeIssue): ?>
                <?= partial('partials/qty-bar', [
                    'label' => 'Material received',
                    'done' => (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'],
                    'total' => (int) $line['qty_free_issue_required'],
                ]) ?>
                <p class="text-muted"><?= e(OrderLine::freeIssueStatusSentence($line)) ?></p>
            <?php else: ?>
                <p class="text-muted">No free-issue material required.</p>
            <?php endif; ?>
        </div>

        <?php if ($requests !== []): ?>
            <div style="margin-top: var(--space-4)">
                <h4 class="mt-0 mb-2">Quantity change requests</h4>
                <?php foreach ($requests as $request): ?>
                    <div class="callout">
                        <p class="mb-1">
                            <strong><?= (int) $request['qty_at_request'] ?> → <?= (int) $request['qty_requested'] ?></strong>
                            <span class="badge <?= $request['status'] === 'pending' ? 'badge-warn' : ($request['status'] === 'applied' ? 'badge-ok' : 'badge-muted') ?>">
                                <?= e(ucfirst($request['status'])) ?>
                            </span>
                            <span class="text-muted">asked <?= format_date($request['requested_at']) ?> by <?= e($request['requested_by_name']) ?></span>
                        </p>
                        <?php if ($request['reason']): ?><p class="mb-1"><?= nl2br(e($request['reason'])) ?></p><?php endif; ?>
                        <?php if ($request['reviewed_by_name']): ?>
                            <p class="text-muted mb-0">
                                <?= e(ucfirst($request['status'])) ?> by Junction, <?= format_date($request['reviewed_at']) ?><?= $request['review_notes'] ? ' — ' . e($request['review_notes']) : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canRequestChange && $pending === null && !$orderClosed): ?>
            <details style="margin-top: var(--space-4)">
                <summary>Ask to change this quantity</summary>
                <p class="text-muted" style="margin-top: var(--space-2)">
                    Junction may already have bought material or started work, so this is a request rather
                    than a change: nothing on the order moves until they confirm it. If your purchase order
                    has been amended, attach it here — it is added alongside the original, not in place of it.
                </p>
                <form method="post" action="<?= url('/orders/' . $order['id'] . '/lines/' . $line['id'] . '/change-request') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="field">
                            <label for="qty_requested_<?= (int) $line['id'] ?>">New quantity</label>
                            <input type="number" id="qty_requested_<?= (int) $line['id'] ?>" name="qty_requested"
                                   min="1" value="<?= (int) $line['qty_ordered'] ?>" required>
                        </div>
                        <div class="field">
                            <label for="change_po_number_<?= (int) $line['id'] ?>">Amended PO number (optional)</label>
                            <input type="text" id="change_po_number_<?= (int) $line['id'] ?>" name="po_number">
                        </div>
                    </div>
                    <div class="field">
                        <label for="change_reason_<?= (int) $line['id'] ?>">Reason (optional)</label>
                        <textarea id="change_reason_<?= (int) $line['id'] ?>" name="reason" rows="2"></textarea>
                    </div>
                    <div class="field">
                        <label for="change_po_<?= (int) $line['id'] ?>">Updated or additional PO document (optional)</label>
                        <input type="file" id="change_po_<?= (int) $line['id'] ?>" name="po">
                    </div>
                    <button type="submit" class="btn btn-primary">Send request to Junction</button>
                </form>
            </details>
        <?php elseif ($pending !== null): ?>
            <p class="text-muted" style="margin-top: var(--space-4)">
                A change to <?= (int) $pending['qty_requested'] ?> is with Junction. They will confirm or come back to you.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 class="mt-0">Free-issue material to send</h2>
    <?php if ($freeIssueNotes === []): ?>
        <p class="empty-state">Nothing on this order is made from material you supply.</p>
    <?php else: ?>
        <p class="text-muted">Print the note and enclose it with the material. Each one asks for whatever is still outstanding, so it is always worth reprinting rather than reusing an old copy.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>CPN</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($freeIssueNotes as $dn): ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= e($dn['cpns'] ?? '—') ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td><a href="<?= url('/delivery-notes/' . $dn['id'] . '/pdf') ?>" target="_blank" rel="noopener">View PDF</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($returnNotes !== []): ?>
<div class="card">
    <h2 class="mt-0">Material returned to you</h2>
    <p class="text-muted">Material that arrived but could not be used. Replacement material has been asked for on the free-issue note above.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Reference</th><th>CPN</th><th>Qty</th><th>Issued</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($returnNotes as $dn): ?>
                <tr>
                    <td><?= e($dn['reference']) ?></td>
                    <td><?= e($dn['cpns'] ?? '—') ?></td>
                    <td><?= (int) $dn['qty_total'] ?></td>
                    <td><?= format_date($dn['issued_at']) ?></td>
                    <td><a href="<?= url('/delivery-notes/' . $dn['id'] . '/pdf') ?>" target="_blank" rel="noopener">View PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">Goods delivered</h2>
    <?php if ($goodsOutNotes === []): ?>
        <p class="empty-state">Nothing despatched yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>CPN</th><th>Qty</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($goodsOutNotes as $dn): ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= e($dn['cpns'] ?? '—') ?></td>
                        <td><?= (int) $dn['qty_total'] ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td><a href="<?= url('/delivery-notes/' . $dn['id'] . '/pdf') ?>" target="_blank" rel="noopener">View PDF</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="mt-0">Purchase orders</h2>
    <p class="text-muted">Everything sent for this order, oldest first.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>PO number</th><th>Document</th><th>Added</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($poDocuments as $document): ?>
                <tr>
                    <td><?= $document['po_number'] !== '' ? e($document['po_number']) : '—' ?></td>
                    <td>
                        <a href="<?= url('/files/po-documents/' . $document['id']) ?>" target="_blank" rel="noopener"><?= e($document['original_filename']) ?></a>
                        <?php if ((bool) $document['is_original']): ?><span class="badge badge-muted">Original</span><?php endif; ?>
                    </td>
                    <td><?= format_date($document['uploaded_at']) ?></td>
                    <td><?= e($document['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (Auth::can('place_orders') && !$orderClosed): ?>
        <form method="post" action="<?= url('/orders/' . $order['id'] . '/po-documents') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="field"><label for="add_po_number">PO number (optional)</label><input type="text" id="add_po_number" name="po_number"></div>
                <div class="field"><label for="add_po_note">Note (optional)</label><input type="text" id="add_po_note" name="note"></div>
            </div>
            <div class="field">
                <label for="add_po">Add an amended or additional purchase order</label>
                <input type="file" id="add_po" name="po">
            </div>
            <button type="submit" class="btn">Add document</button>
        </form>
    <?php endif; ?>
</div>

<?= partial('partials/order-notes-queries', ['order' => $order, 'notes' => $notes, 'queries' => $queries]) ?>
