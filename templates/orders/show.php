<?php
/**
 * The order page, for both audiences.
 *
 * One template, rendered at `/orders/{id}` for the client and
 * `/staff/orders/{id}` for Junction — the same reasoning as the part page: the
 * staff-only actions have to sit behind the staff middleware, and every link
 * into the staff area already points there.
 *
 * @var array  $order
 * @var array  $client
 * @var array  $lines
 * @var array  $lineDetail
 * @var array  $parts
 * @var array  $deliveryNotes
 * @var array  $invoicesByDn
 * @var array  $poDocuments
 * @var array  $photos
 * @var array  $notes
 * @var array  $queries
 * @var string $rollupStatus
 */
use App\Core\Auth;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineChangeRequest;
use App\Models\Part;

$isStaff = Auth::isStaff();
$showPricing = Auth::can('view_pricing');
$canProduce = $isStaff && Auth::can('production_control');
$canApprove = $isStaff && Auth::can('approve_quantity_changes');
$canClose = $isStaff && Auth::can('close_orders');
$canRequestChange = !$isStaff && Auth::can('request_quantity_change');
$orderClosed = Order::isClosed($order);

$staffBase = '/staff/orders/' . $order['id'];
$clientBase = '/orders/' . $order['id'];
$base = $isStaff ? $staffBase : $clientBase;
$partHref = $isStaff ? '/staff/parts/' : '/parts/';

$freeIssueNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'free_issue_in'));
$returnNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'material_return'));
$goodsOutNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'goods_out'));

// Clients read a delivery note straight as a PDF; staff have a page for it
// with the invoicing controls on.
$noteHref = static fn (array $dn): string => $isStaff
    ? '/staff/delivery-notes/' . $dn['id']
    : '/delivery-notes/' . $dn['id'] . '/pdf';
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($order['order_number']) ?> <?= status_badge($rollupStatus) ?></h1>
        <p class="text-muted mb-0">
            <?php if ($isStaff): ?><?= e($client['name']) ?> &middot; <?php endif; ?>
            Placed <?= format_date($order['placed_at']) ?>
            &middot; <?= $isStaff ? 'PO' : 'Your PO' ?>
            <?= $order['po_number'] !== '' ? e($order['po_number']) : '<span class="text-muted">not recorded</span>' ?>
        </p>
        <?php if ($orderClosed): ?>
            <p class="text-muted mb-0">Closed down <?= format_date($order['closed_at']) ?><?= $order['close_reason'] ? ' — ' . e($order['close_reason']) : '' ?></p>
        <?php endif; ?>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap: var(--space-2)">
        <a href="<?= url('/files/po/' . $order['id']) ?>" class="btn" target="_blank" rel="noopener">View purchase order</a>
        <?php if ($canProduce && $lines !== []): ?>
            <a href="<?= url($staffBase . '/route-cards') ?>" class="btn" target="_blank" rel="noopener">View/print all route cards</a>
        <?php endif; ?>
        <?php if ($isStaff && Auth::can('issue_delivery_notes')): ?>
            <a href="<?= url('/staff/clients/' . $client['id'] . '/delivery-note/new?order=' . $order['id']) ?>" class="btn btn-primary">
                Create delivery note
            </a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($lines as $line):
    $lineId = (int) $line['id'];
    $detail = $lineDetail[$lineId] ?? [];
    $part = $parts[$lineId] ?? [];
    $needsFreeIssue = Part::hasFreeIssue($part);
    $lineClosed = OrderLine::isClosed($line);
    $requests = $detail['change_requests'] ?? [];

    $pendingChange = null;
    foreach ($requests as $candidate) {
        if ($candidate['status'] === 'pending') {
            $pendingChange = $candidate;
            break;
        }
    }
    $conversion = Part::conversionSentence($part, (int) $line['qty_ordered']);
    ?>
    <div class="card" id="line-<?= $lineId ?>">
        <div class="card-header">
            <div>
                <h3 class="mt-0 mb-0"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h3>
                <p class="text-muted mb-0">
                    Qty ordered: <?= (int) $line['qty_ordered'] ?>
                    <?php if ($showPricing): ?> &middot; Unit price: <?= format_money($line['unit_price']) ?><?php endif; ?>
                    <?php if ($lineClosed): ?> &middot; <span class="badge badge-muted">Closed down</span><?php endif; ?>
                </p>
                <?php if ($conversion !== null): ?>
                    <p class="field-hint mb-0"><?= e($conversion) ?></p>
                <?php endif; ?>
            </div>
            <div style="display:flex; gap: var(--space-2)">
                <a href="<?= url($partHref . $line['part_id']) ?>" class="btn btn-sm">View part</a>
                <?php if ($canProduce): ?>
                    <a href="<?= url('/staff/lines/' . $lineId . '/route-card') ?>" class="btn btn-sm" target="_blank" rel="noopener">
                        View/print route card
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <p class="line-summary mb-2"><?= status_badge(OrderLine::headlineStage($line)) ?>
            <span class="text-muted"><?= e(OrderLine::statusLabel($line)) ?></span></p>
        <?= partial('partials/stepper', ['line' => $line]) ?>

        <?php if ($lineClosed): ?>
            <p class="text-muted mb-0">
                Closed down <?= format_date($line['closed_at']) ?><?= $line['close_reason'] ? ' — ' . e($line['close_reason']) : '' ?>.
                Cancelled quantity no longer counts as outstanding.
            </p>
        <?php endif; ?>

        <?php /* The client sees the same breakdown, without the controls. */ ?>
        <div class="line-section">
            <h4 class="line-section-title">Production status</h4>
            <?= partial('partials/stage-moves', ['line' => $line, 'canProduce' => $canProduce]) ?>
        </div>

        <div class="line-section">
            <h4 class="line-section-title">Free-issue material</h4>
            <?php if (!$needsFreeIssue): ?>
                <p class="text-muted mb-0">No free-issue material required.</p>
            <?php else: ?>
                <?= partial('partials/qty-bar', [
                    'label' => 'Material received',
                    'done' => (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'],
                    'total' => (int) $line['qty_free_issue_required'],
                ]) ?>
                <p class="text-muted"><?= e(OrderLine::freeIssueStatusSentence($line)) ?></p>

                <?php if ($isStaff && ($detail['open_discrepancy'] ?? null) !== null): ?>
                    <p><span class="badge badge-warn">Discrepancy unresolved</span>
                        <?= e(OrderLine::DISCREPANCY_LABELS[$detail['open_discrepancy']['discrepancy_type']]) ?>
                        <?= $detail['open_discrepancy']['discrepancy_notes'] ? '— ' . e($detail['open_discrepancy']['discrepancy_notes']) : '' ?>
                    </p>
                <?php endif; ?>

                <?php if (($detail['rejections'] ?? []) !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Rejected</th><th>Qty</th><th>Reason</th><th>Return note</th><th>Replacement asked for on</th></tr></thead>
                            <tbody>
                            <?php foreach ($detail['rejections'] as $rejection): ?>
                                <tr>
                                    <td><?= format_date($rejection['rejected_at']) ?></td>
                                    <td><?= (int) $rejection['qty_rejected'] ?></td>
                                    <td class="wrap"><?= e($rejection['reason']) ?></td>
                                    <td><?= $rejection['return_reference'] ? e($rejection['return_reference']) : '—' ?></td>
                                    <td><?= $rejection['replacement_reference'] ? e($rejection['replacement_reference']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (OrderLine::qtyAt($line, 'failed') > 0): ?>
            <div class="line-section">
                <h4 class="line-section-title"><?= OrderLine::qtyAt($line, 'failed') ?> failed</h4>
                <p class="text-muted">
                    Still owed on this line: failed parts are parked rather than deducted, and go back into the
                    flow once there is something to remake them from.
                </p>
                <?php if ($isStaff && ($detail['failures'] ?? []) !== []): ?>
                    <ul class="plain-list">
                        <?php foreach ($detail['failures'] as $failure): ?>
                            <li>
                                <?= (int) $failure['qty'] ?> at <?= e(OrderLine::STAGE_SENTENCE_LABELS[$failure['from_stage']] ?? 'an unknown stage') ?>
                                — <?= e($failure['reason'] ?? 'no reason recorded') ?>
                                <span class="text-muted">(<?= e($failure['moved_by_name']) ?>, <?= format_date($failure['moved_at']) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($canProduce && $needsFreeIssue):
                    $replacementUnits = OrderLine::replacementUnitsForFailures($line);
                    $yield = Part::finalPartsFor($part, $replacementUnits);
                    ?>
                    <p class="text-muted">
                        Making up the shortfall needs <strong><?= $replacementUnits ?></strong>
                        more <?= $replacementUnits === 1 ? 'piece' : 'pieces' ?> of material<?php
                        ?><?= $yield > (int) $line['qty_failed'] ? ', which yields ' . $yield . ' and leaves ' . ($yield - (int) $line['qty_failed']) . ' spare' : '' ?>.
                        The figure is worked out from the current shortfall each time, so it moves on its own
                        if anything else fails before the material arrives.
                    </p>
                    <form method="post" action="<?= url('/staff/lines/' . $lineId . '/replacement-material') ?>" class="action-row">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm">Ask the client for <?= $replacementUnits ?> more</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($requests !== []): ?>
            <div class="line-section">
                <h4 class="line-section-title">Quantity change requests</h4>
                <?php foreach ($requests as $request): ?>
                    <div class="callout">
                        <p class="mb-1">
                            <strong><?= (int) $request['qty_at_request'] ?> → <?= (int) $request['qty_requested'] ?></strong>
                            <span class="badge <?= $request['status'] === 'pending' ? 'badge-warn' : ($request['status'] === 'applied' ? 'badge-ok' : 'badge-muted') ?>">
                                <?= e(ucfirst($request['status'])) ?>
                            </span>
                            <span class="text-muted">
                                <?= $request['initiated_by'] === 'staff'
                                    ? ($isStaff ? 'set by ' . e($request['requested_by_name']) . ' at Junction' : 'applied by Junction')
                                    : 'asked by ' . e($request['requested_by_name']) ?>,
                                <?= format_date($request['requested_at']) ?>
                            </span>
                        </p>
                        <?php if ($request['reason']): ?><p class="mb-1"><?= nl2br(e($request['reason'])) ?></p><?php endif; ?>
                        <?php if ($request['reviewed_by_name']): ?>
                            <p class="text-muted mb-0">
                                <?= e(ucfirst($request['status'])) ?> by
                                <?= $isStaff ? e($request['reviewed_by_name']) : 'Junction' ?>,
                                <?= format_date($request['reviewed_at']) ?><?= $request['review_notes'] ? ' — ' . e($request['review_notes']) : '' ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($request['status'] === 'pending' && $canApprove):
                            $limits = OrderLineChangeRequest::reducibleQty($line);
                            $isDecrease = (int) $request['qty_requested'] < (int) $line['qty_ordered'];
                            $tooLow = $isDecrease && (int) $request['qty_requested'] < $limits['floor'];
                            ?>
                            <?php if ($isDecrease): ?>
                                <p class="<?= $tooLow ? 'error' : 'text-muted' ?>">
                                    <?= (int) $line['qty_completed'] ?> of this line has already been made,
                                    <?= (int) $line['qty_delivered'] ?> delivered and <?= (int) $line['qty_invoiced'] ?> invoiced.
                                    The lowest this line can go is <?= (int) $limits['floor'] ?>.
                                    <?= $tooLow ? 'Applying this request would take it below that and will be refused.' : '' ?>
                                </p>
                            <?php endif; ?>

                            <div style="display:flex; flex-wrap:wrap; gap: var(--space-2); align-items:flex-end">
                                <form method="post" action="<?= url($staffBase . '/change-requests/' . $request['id'] . '/apply') ?>" class="action-row">
                                    <?= csrf_field() ?>
                                    <label for="apply_notes_<?= (int) $request['id'] ?>" class="sr-only">Note to the client</label>
                                    <input type="text" class="input-grow" id="apply_notes_<?= (int) $request['id'] ?>" name="review_notes" placeholder="Note to the client (optional)">
                                    <button type="submit" class="btn btn-sm btn-primary" <?= $tooLow ? 'disabled' : '' ?>>Apply</button>
                                </form>
                                <form method="post" action="<?= url($staffBase . '/change-requests/' . $request['id'] . '/decline') ?>" class="action-row">
                                    <?= csrf_field() ?>
                                    <label for="decline_notes_<?= (int) $request['id'] ?>" class="sr-only">Reason for declining</label>
                                    <input type="text" class="input-grow" id="decline_notes_<?= (int) $request['id'] ?>" name="review_notes" placeholder="Why not (optional)">
                                    <button type="submit" class="btn btn-sm">Decline</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canRequestChange && $pendingChange === null && !$orderClosed): ?>
            <div class="line-section">
                <details>
                    <summary>Ask to change this quantity</summary>
                    <p class="text-muted" style="margin-top: var(--space-2)">
                        Junction may already have bought material or started work, so this is a request rather
                        than a change: nothing on the order moves until they confirm it. If your purchase order
                        has been amended, attach it here — it is added alongside the original, not in place of it.
                    </p>
                    <form method="post" action="<?= url($clientBase . '/lines/' . $lineId . '/change-request') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <div class="form-row">
                            <div class="field">
                                <label for="qty_requested_<?= $lineId ?>">New quantity</label>
                                <input type="number" id="qty_requested_<?= $lineId ?>" name="qty_requested"
                                       min="1" value="<?= (int) $line['qty_ordered'] ?>" required>
                            </div>
                            <div class="field">
                                <label for="change_po_number_<?= $lineId ?>">Amended PO number (optional)</label>
                                <input type="text" id="change_po_number_<?= $lineId ?>" name="po_number">
                            </div>
                        </div>
                        <div class="field">
                            <label for="change_reason_<?= $lineId ?>">Reason (optional)</label>
                            <textarea id="change_reason_<?= $lineId ?>" name="reason" rows="2"></textarea>
                        </div>
                        <div class="field">
                            <label for="change_po_<?= $lineId ?>">Updated or additional PO document (optional)</label>
                            <input type="file" id="change_po_<?= $lineId ?>" name="po">
                        </div>
                        <button type="submit" class="btn btn-primary">Send request to Junction</button>
                    </form>
                </details>
            </div>
        <?php elseif (!$isStaff && $pendingChange !== null): ?>
            <p class="text-muted" style="margin-top: var(--space-4)">
                A change to <?= (int) $pendingChange['qty_requested'] ?> is with Junction. They will confirm or come back to you.
            </p>
        <?php endif; ?>

        <?php if ($canClose): ?>
            <div class="line-section">
                <?php if ($lineClosed): ?>
                    <form method="post" action="<?= url('/staff/lines/' . $lineId . '/reopen') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm">Reopen line</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= url('/staff/lines/' . $lineId . '/close') ?>" class="action-row">
                        <?= csrf_field() ?>
                        <label for="close_<?= $lineId ?>">Close this line down</label>
                        <input type="text" class="input-grow" id="close_<?= $lineId ?>" name="reason"
                               placeholder="Why — this is the record of it" required>
                        <button type="submit" class="btn btn-sm">Cancel what is left</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isStaff && ($detail['moves'] ?? []) !== []): ?>
            <details style="margin-top: var(--space-4)">
                <summary>Movement history (<?= count($detail['moves']) ?>)</summary>
                <div class="table-wrap" style="margin-top: var(--space-2)">
                    <table>
                        <thead><tr><th>When</th><th>Qty</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($detail['moves']) as $move): ?>
                            <tr>
                                <td><?= format_datetime($move['moved_at']) ?></td>
                                <td><?= (int) $move['qty'] ?></td>
                                <td><?= $move['from_stage'] ? e(OrderLine::STAGE_LABELS[$move['from_stage']]) : '<span class="text-muted">added to the line</span>' ?></td>
                                <td><?= $move['to_stage'] ? e(OrderLine::STAGE_LABELS[$move['to_stage']]) : '<span class="text-muted">removed from the line</span>' ?></td>
                                <td class="wrap"><?= e($move['reason'] ?? '') ?></td>
                                <td><?= e($move['moved_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 class="mt-0">Purchase orders</h2>
    <p class="text-muted">Every PO document sent for this order, oldest first. Nothing is ever replaced — an amended PO is added alongside the original.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>PO number</th><th>Document</th><th>Added</th><?php if ($isStaff): ?><th>By</th><?php endif; ?><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($poDocuments as $document): ?>
                <tr>
                    <td><?= $document['po_number'] !== '' ? e($document['po_number']) : '—' ?></td>
                    <td>
                        <a href="<?= url('/files/po-documents/' . $document['id']) ?>" target="_blank" rel="noopener"><?= e($document['original_filename']) ?></a>
                        <?php if ((bool) $document['is_original']): ?><span class="badge badge-muted">Original</span><?php endif; ?>
                    </td>
                    <td><?= format_date($document['uploaded_at']) ?></td>
                    <?php if ($isStaff): ?><td><?= e($document['uploaded_by_name']) ?></td><?php endif; ?>
                    <td class="wrap"><?= e($document['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canApprove): ?>
        <form method="post" action="<?= url($staffBase . '/po-number') ?>" class="action-row" style="margin-top: var(--space-4)">
            <?= csrf_field() ?>
            <label for="po_number">PO number on this order</label>
            <input type="text" class="input-grow" id="po_number" name="po_number" value="<?= e($order['po_number']) ?>" required>
            <button type="submit" class="btn btn-sm">Save PO number</button>
        </form>
        <p class="field-hint">Sent to Clear Books as the invoice reference.</p>

        <form method="post" action="<?= url($staffBase . '/po-documents') ?>" enctype="multipart/form-data" style="margin-top: var(--space-5)">
            <?= csrf_field() ?>
            <h3 class="line-section-title">Add an amended or additional purchase order</h3>
            <div class="form-row">
                <div class="field"><label for="new_po_number">PO number (optional)</label><input type="text" id="new_po_number" name="po_number"></div>
                <div class="field"><label for="po_note">Note (optional)</label><input type="text" id="po_note" name="note"></div>
            </div>
            <div class="field">
                <label for="po">Purchase order document</label>
                <input type="file" id="po" name="po">
            </div>

            <?php if (!$orderClosed && $lines !== []): ?>
                <h3 class="line-section-title" style="margin-top: var(--space-4)">Quantities on this order</h3>
                <p class="text-muted">
                    Change any of these to match the purchase order. A line cannot go below what has
                    already been made, delivered or invoiced on it.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Part</th><th class="align-right">Ordered</th><th class="align-right">Lowest it can go</th><th>New quantity</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $poLine):
                            $floor = OrderLineChangeRequest::reducibleQty($poLine)['floor'];
                            ?>
                            <tr>
                                <td><?= e($poLine['cpn']) ?> <span class="text-muted"><?= e($poLine['part_name']) ?></span></td>
                                <td class="align-right"><?= (int) $poLine['qty_ordered'] ?></td>
                                <td class="align-right"><?= (int) $floor ?></td>
                                <td>
                                    <label class="sr-only" for="line_qty_<?= (int) $poLine['id'] ?>">New quantity for <?= e($poLine['cpn']) ?></label>
                                    <input type="number" class="input-qty" id="line_qty_<?= (int) $poLine['id'] ?>"
                                           name="line_qty[<?= (int) $poLine['id'] ?>]"
                                           min="<?= (int) $floor ?>" value="<?= (int) $poLine['qty_ordered'] ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="field">
                    <label for="quantity_reason">Why (optional)</label>
                    <input type="text" id="quantity_reason" name="quantity_reason"
                           placeholder="Defaults to a note naming the purchase order">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn">Save purchase order and quantities</button>
        </form>
    <?php elseif (!$isStaff && Auth::can('place_orders') && !$orderClosed): ?>
        <form method="post" action="<?= url($clientBase . '/po-documents') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
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

<div class="card">
    <h2 class="mt-0"><?= $isStaff ? 'Free-issue material notes' : 'Free-issue material to send' ?></h2>
    <?php if ($freeIssueNotes === []): ?>
        <p class="empty-state mb-0">Nothing on this order is made from free-issue material.</p>
    <?php else: ?>
        <p class="text-muted">
            <?php if ($isStaff): ?>
                Standing requests for material the client has to send in. Each one asks for whatever is still outstanding today.
            <?php else: ?>
                Print the note and enclose it with the material. Each one asks for whatever is still outstanding,
                so it is always worth reprinting rather than reusing an old copy.
            <?php endif; ?>
        </p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>CPN</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($freeIssueNotes as $dn): ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= e($dn['cpns'] ?? '—') ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <td><a href="<?= url($noteHref($dn)) ?>" <?= $isStaff ? '' : 'target="_blank" rel="noopener"' ?>><?= $isStaff ? 'View' : 'View PDF' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($returnNotes !== []): ?>
<div class="card">
    <h2 class="mt-0"><?= $isStaff ? 'Material returned' : 'Material returned to you' ?></h2>
    <p class="text-muted">
        Free-issue material that arrived and could not be used. A replacement was asked for at the same time,
        on the free-issue note above.
    </p>
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
                    <td><a href="<?= url($noteHref($dn)) ?>" <?= $isStaff ? '' : 'target="_blank" rel="noopener"' ?>><?= $isStaff ? 'View' : 'View PDF' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0"><?= $isStaff ? 'Goods out' : 'Goods delivered' ?></h2>
    <?php if ($goodsOutNotes === []): ?>
        <p class="empty-state mb-0">Nothing despatched yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Reference</th><th>CPN</th><th>Qty</th><th>Issued</th>
                        <?php if ($isStaff && $showPricing): ?><th>Invoiced</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($goodsOutNotes as $dn): $invoice = $invoicesByDn[$dn['id']] ?? null; ?>
                    <tr>
                        <td><?= e($dn['reference']) ?></td>
                        <td><?= e($dn['cpns'] ?? '—') ?></td>
                        <td><?= (int) $dn['qty_total'] ?></td>
                        <td><?= format_date($dn['issued_at']) ?></td>
                        <?php if ($isStaff && $showPricing): ?>
                            <td>
                                <span class="badge <?= $dn['invoiced'] ? 'badge-ok' : 'badge-warn' ?>">
                                    <?= $dn['invoiced'] ? e($invoice['clearbooks_invoice_number'] ?? 'Invoiced') : 'Not invoiced' ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <td><a href="<?= url($noteHref($dn)) ?>" <?= $isStaff ? '' : 'target="_blank" rel="noopener"' ?>><?= $isStaff ? 'View' : 'View PDF' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canClose && !$orderClosed): ?>
<div class="card">
    <h2 class="mt-0">Close the order down</h2>
    <p class="text-muted">
        Cancels off everything still to be issued, received or made, across every line. It is recorded as
        cancelled, not deleted, and stops counting as outstanding from that point. Parts already made still
        have to go out and still have to be invoiced.
    </p>
    <form method="post" action="<?= url($staffBase . '/close') ?>" class="action-row">
        <?= csrf_field() ?>
        <label for="close_order_reason">Reason</label>
        <input type="text" class="input-grow" id="close_order_reason" name="reason" required
               placeholder="e.g. Client cancelled the programme">
        <button type="submit" class="btn">Close the order down</button>
    </form>
</div>
<?php endif; ?>

<?php if ($isStaff): ?>
<div class="card">
    <h2 class="mt-0">Photos</h2>
    <p class="text-muted">
        Staff-only, and specific to how <em>this</em> order went — a mark on one batch, a packing shot.
        Anything that describes the part itself belongs on the part, where it is in front of whoever runs
        it next:
        <?php foreach ($lines as $photoLine): ?>
            <a href="<?= url('/staff/parts/' . $photoLine['part_id']) ?>#setup"><?= e($photoLine['cpn']) ?></a><?= $photoLine === end($lines) ? '' : ', ' ?>
        <?php endforeach; ?>.
    </p>
    <?php if ($photos === []): ?>
        <p class="text-muted">No photos uploaded yet.</p>
    <?php else: ?>
        <div class="photo-grid">
            <?php foreach ($photos as $photo): ?>
                <div class="photo-tile">
                    <a href="<?= url('/files/order-photos/' . $photo['id']) ?>" target="_blank" rel="noopener">
                        <img src="<?= url('/files/order-photos/' . $photo['id'] . '/thumb') ?>" alt="">
                    </a>
                    <?php if ($photo['caption']): ?><div class="photo-caption"><?= e($photo['caption']) ?></div><?php endif; ?>
                    <?php if ($canProduce): ?>
                        <form method="post" action="<?= url($staffBase . '/photos/' . $photo['id'] . '/delete') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm">Remove</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($canProduce): ?>
        <form method="post" action="<?= url($staffBase . '/photos') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="field">
                    <label for="order_line_id">Line (optional)</label>
                    <select id="order_line_id" name="order_line_id">
                        <option value="">Whole order</option>
                        <?php foreach ($lines as $photoLine): ?>
                            <option value="<?= (int) $photoLine['id'] ?>"><?= e($photoLine['cpn']) ?> — <?= e($photoLine['part_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="caption">Caption (optional)</label><input type="text" id="caption" name="caption"></div>
            </div>
            <div class="field">
                <label for="photos">Upload photo(s)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
            </div>
            <button type="submit" class="btn">Upload</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?= partial('partials/order-notes-queries', ['order' => $order, 'notes' => $notes, 'queries' => $queries]) ?>
