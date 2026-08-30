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
 * @var array  $dueDates
 * @var array  $parts
 * @var array  $deliveryNotes
 * @var array  $invoicesByDn
 * @var array  $freeIssueTotals
 * @var array  $returnableLines
 * @var array  $poDocuments
 * @var array  $photos
 * @var array  $photoParts
 * @var array  $notes
 * @var array  $queries
 * @var string $rollupStatus
 */
use App\Core\Auth;
use App\Models\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderLineChangeRequest;
use App\Models\OrderLineDueDate;
use App\Models\OrderPhoto;
use App\Models\Part;

$isStaff = Auth::isStaff();
$showPricing = Auth::can('view_pricing');
$canProduce = $isStaff && Auth::can('production_control');
$canApprove = $isStaff && Auth::can('approve_quantity_changes');
$canClose = $isStaff && Auth::can('close_orders');
$canRequestChange = !$isStaff && Auth::can('request_quantity_change');
$orderClosed = Order::isClosed($order);

// Saying when parts are needed is the client's. There is nothing to schedule on
// an order that has been closed down, or for a client whose account is off.
$canSetDueDates = !$isStaff && Auth::can('set_due_dates')
    && !$orderClosed && (bool) $client['is_active'];

$staffBase = '/staff/orders/' . $order['id'];
$clientBase = '/orders/' . $order['id'];
$base = $isStaff ? $staffBase : $clientBase;
$partHref = $isStaff ? '/staff/parts/' : '/parts/';

$freeIssueNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'free_issue_in'));
$returnNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'material_return'));
$goodsOutNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'goods_out'));
$partsReturnNotes = array_values(array_filter($deliveryNotes, static fn ($dn) => $dn['type'] === 'parts_return'));

// How much of each despatch has since come back, so the row for the delivery
// says so rather than leaving the two facts on separate tables.
$returnedByNote = [];
foreach ($partsReturnNotes as $dn) {
    $related = (int) ($dn['related_note_id'] ?? 0);
    if ($related > 0) {
        $returnedByNote[$related] = ($returnedByNote[$related] ?? 0) + (int) $dn['qty_total'];
    }
}

// Only despatches with something left to send back. A part already returned in
// full is not a choice, and offering it as one — greyed out, or worse, as the
// only option on a required select — is offering a form that cannot be sent.
$returnable = array_values(array_filter(
    $returnableLines,
    static fn (array $l): bool => (int) $l['qty_sent'] - (int) $l['qty_already_returned'] > 0
));
$canReturnParts = !$isStaff && Auth::can('return_rejected_parts') && $returnable !== [];

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
    <?php /*
        Each line is a disclosure, closed to start with.

        An order of eight lines was eight full cards deep — the production
        table, the free-issue figures, the change requests and two history
        panels each — and finding the one line somebody was asking about meant
        scrolling past everything about the other seven. Closed, a line is its
        number, its name and where its quantity has got to, which is what the
        page is scanned for; open, it is exactly what it always was.

        The bar lives in the summary rather than being repeated below, so it
        does not move when the card opens.
    */ ?>
    <?php
    /*
        When the client needs these parts.

        On the summary rather than inside the card, because the card is closed
        by default and "what is due next" is exactly the question somebody
        scans an order for. Measured against what has been *completed*, not
        delivered: a part that is made and waiting for a van is not one anybody
        needs chasing about, and a date that stays red until the courier has
        been is a date people learn to ignore.
    */
    $lineDue = $dueDates[$lineId] ?? [];
    $nextDue = OrderLineDueDate::next($lineDue, (int) $line['qty_completed']);
    ?>
    <details class="card line-card" id="line-<?= $lineId ?>" data-line-card>
        <summary class="line-card-summary">
            <?php /* Title and due badge share the first column, so the caret
                     stays at the far right whether or not there is a date. */ ?>
            <span class="line-card-heading">
                <h3 class="line-card-title"><?= e($line['cpn']) ?> — <?= e($line['part_name']) ?></h3>
                <?php if ($nextDue !== null): ?>
                    <span class="due-badge due-<?= e(OrderLineDueDate::urgency($nextDue['due_date'])) ?>">
                        <?= (int) $nextDue['qty'] ?> by <?= e(format_date($nextDue['due_date'])) ?>
                        <span class="due-when"><?= e(OrderLineDueDate::sentence($nextDue['due_date'])) ?></span>
                    </span>
                <?php endif; ?>
            </span>
            <span class="caret" aria-hidden="true"></span>
            <?= partial('partials/stepper', ['line' => $line]) ?>
        </summary>

        <div class="line-card-body">
        <div class="card-header">
            <div>
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

        <?php if ($lineClosed): ?>
            <p class="text-muted mb-0">
                Closed down <?= format_date($line['closed_at']) ?><?= $line['close_reason'] ? ' — ' . e($line['close_reason']) : '' ?>.
                Cancelled quantity no longer counts as outstanding.
            </p>
        <?php endif; ?>

        <?php /*
            Required by.

            Above production status because it is what production status is
            measured against. The client says when they need parts; Junction
            reads it. Nothing here changes what is owed — the quantity on the
            order is still the quantity on the order.
        */ ?>
        <?php if ($lineDue !== [] || $canSetDueDates): ?>
            <div class="line-section">
                <h4 class="line-section-title">Required by</h4>

                <?php if ($lineDue === []): ?>
                    <p class="text-muted mb-0">No dates set. Junction works to the order as a whole.</p>
                <?php else: ?>
                    <?php
                    // Running total, so each row can say whether it is already
                    // covered by what has been made.
                    $covered = 0;
                    ?>
                    <ul class="due-list">
                        <?php foreach ($lineDue as $requirement):
                            $covered += (int) $requirement['qty'];
                            $met = $covered <= (int) $line['qty_completed'];
                            $urgency = $met ? 'met' : OrderLineDueDate::urgency($requirement['due_date']);
                            ?>
                            <li>
                                <span class="due-badge due-<?= e($urgency) ?>">
                                    <?= (int) $requirement['qty'] ?> by <?= e(format_date($requirement['due_date'])) ?>
                                </span>
                                <span class="text-muted">
                                    <?= $met ? 'made' : e(OrderLineDueDate::sentence($requirement['due_date'])) ?><?php
                                    ?><?= $requirement['note'] ? ' — ' . e($requirement['note']) : '' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($canSetDueDates): ?>
                    <div style="margin-top: var(--space-3)">
                        <?= partial('partials/row-editor', [
                            'id' => 'due_' . $lineId,
                            'title' => 'Required by — ' . $line['cpn'],
                            'action' => $clientBase . '/lines/' . $lineId . '/due-dates',
                            'trigger' => $lineDue === [] ? 'Set when you need these' : 'Edit dates',
                            'intro' => 'How many you need, and by when. Several rows if it is staged — '
                                . '50 by the end of March and the rest by June is two rows, not one.',
                            'columns' => [
                                ['name' => 'due_qty', 'label' => 'Quantity', 'type' => 'number',
                                 'min' => '1', 'step' => '1', 'width' => 'narrow', 'placeholder' => 'Qty'],
                                ['name' => 'due_date', 'label' => 'Needed by', 'type' => 'date',
                                 'width' => 'narrow', 'placeholder' => 'Date'],
                                ['name' => 'due_note', 'label' => 'Note', 'type' => 'text',
                                 'placeholder' => 'Why, or what it is for (optional)'],
                            ],
                            'rows' => array_map(static fn (array $d): array => [
                                'due_qty' => $d['qty'],
                                'due_date' => $d['due_date'],
                                'due_note' => $d['note'],
                            ], $lineDue),
                            'footnote' => 'Two rows on the same date is a contradiction rather than a schedule, '
                                . 'so the last one entered wins.',
                        ]) ?>
                    </div>
                <?php endif; ?>
            </div>
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
                    <?php if ($isStaff && ($detail['failures'] ?? []) !== []): ?>
                        Why, and at which stage, is under <em>Failed part history</em> at the foot of this line.
                    <?php endif; ?>
                </p>

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

        <?php /*
            Junction's record of what has been condemned on this line, and why.
            Shown whenever anything has ever failed here — not only while the
            bucket still holds something — because a failure put back into
            production leaves the bucket and stays on the record, and "what went
            wrong with this job" is a question asked long after the remake.

            Collapsed, beside the movement history, because it is the same kind
            of thing: a log somebody opens when they have a reason to, rather
            than a figure they need at a glance. The quantity itself is still on
            show in the failed section above, and in the stage table.
        */ ?>
        <?php if ($isStaff && ($detail['failures'] ?? []) !== []):
            $failureTotal = array_sum(array_map(static fn (array $f): int => (int) $f['qty'], $detail['failures']));
            ?>
            <details style="margin-top: var(--space-4)">
                <summary>Failed part history (<?= count($detail['failures']) ?>)</summary>
                <?php
                // The log and the bucket are different numbers whenever anything
                // has been put back into production, so the difference is named
                // rather than left as an arithmetic puzzle. When the bucket is
                // empty there is no failed section above to compare against, so
                // the sentence says where everything went instead.
                $stillFailed = OrderLine::qtyAt($line, 'failed');
                $returned = $failureTotal - $stillFailed;
                $hasHave = static fn (int $n): string => $n === 1 ? 'has' : 'have';
                ?>
                <?php if ($returned > 0): ?>
                    <p class="text-muted" style="margin-top: var(--space-2)">
                        <?php if ($stillFailed > 0): ?>
                            <?= $failureTotal ?> have failed on this line in total, of which
                            <?= $returned ?> <?= $hasHave($returned) ?> since gone back into production.
                            That is why this list totals more than the <?= $stillFailed ?> failed above.
                        <?php else: ?>
                            <?= $failureTotal ?> <?= $hasHave($failureTotal) ?> failed on this line at one time
                            or another, and <?= $failureTotal === 1 ? 'it has' : 'they have all' ?> since gone
                            back into production. Nothing is sitting in the failed stage now.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="table-wrap" style="margin-top: var(--space-2)">
                    <table class="failed-table">
                        <colgroup>
                            <col class="col-fail-date">
                            <col class="col-fail-qty">
                            <col class="col-fail-stage">
                            <col class="col-fail-reason">
                            <col class="col-fail-by">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Failed</th>
                                <th scope="col" class="align-right">Qty</th>
                                <th scope="col">At stage</th>
                                <th scope="col">Reason</th>
                                <th scope="col">By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php /* Already newest-first out of failureHistory(), unlike the movement log below. */ ?>
                        <?php foreach ($detail['failures'] as $failure): ?>
                            <tr>
                                <td><?= format_date($failure['moved_at']) ?></td>
                                <td class="align-right"><?= (int) $failure['qty'] ?></td>
                                <td><?= e(OrderLine::STAGE_SENTENCE_LABELS[$failure['from_stage']] ?? 'an unknown stage') ?></td>
                                <td class="wrap"><?= e($failure['reason'] ?? '') ?: '<span class="text-muted">No reason recorded</span>' ?></td>
                                <td><?= e($failure['moved_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
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
    </details>
<?php endforeach; ?>

<?php /*
    The rest of the page folds away too, on the same principle as the lines:
    an order is scanned for one thing at a time, and four full cards of
    paperwork below the lines is a lot of scrolling past what you did not come
    for. Same markup, same caret, same behaviour.

    Notes and queries are deliberately left open. They are a conversation
    rather than a record, and a message nobody sees because it is behind a
    heading is a message that does not get answered.
*/ ?>
<details class="card panel-card">
    <summary class="panel-card-summary">
        <h2 class="panel-card-title">Purchase orders</h2>
        <span class="caret" aria-hidden="true"></span>
    </summary>
    <div class="panel-card-body">
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
</details>

<?php /*
    Every piece of paper on this order, under one heading.
 *
    They were three cards and a fourth was about to be added, scattered down the
    page with the purchase orders and the photos between them. Somebody asking
    "what has moved between us on this job?" was reading four separate lists
    and holding the answer in their head. The four movements are one subject:
    material in, material back, parts out, parts back.
*/ ?>
<details class="card panel-card" id="delivery-notes">
    <summary class="panel-card-summary">
        <h2 class="panel-card-title">Delivery Notes</h2>
        <span class="caret" aria-hidden="true"></span>
    </summary>
    <div class="panel-card-body">
    <p class="text-muted">
        Everything that has travelled between <?= $isStaff ? e($client['name']) . ' and Junction' : 'you and Junction' ?>
        on this order, in both directions.
    </p>

    <div class="line-section">
        <h3 class="line-section-title"><?= e(DeliveryNote::TYPE_LABELS['free_issue_in']) ?></h3>
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
                The quantity column reads <em>still to come</em> over <em>needed in total</em>.
            </p>
            <?= partial('partials/delivery-note-table', [
                'notes' => $freeIssueNotes,
                'kind' => 'free_issue_in',
                'noteHref' => $noteHref,
                'isStaff' => $isStaff,
                'showPricing' => $showPricing,
                'freeIssueTotals' => $freeIssueTotals,
            ]) ?>
        <?php endif; ?>
    </div>

    <?php if ($returnNotes !== []): ?>
        <div class="line-section">
            <h3 class="line-section-title"><?= e(DeliveryNote::TYPE_LABELS['material_return']) ?></h3>
            <p class="text-muted">
                Free-issue material that arrived and could not be used, going back to
                <?= $isStaff ? 'the client' : 'you' ?>. A replacement was asked for at the same time,
                on the free-issue note above.
            </p>
            <?= partial('partials/delivery-note-table', [
                'notes' => $returnNotes,
                'kind' => 'material_return',
                'noteHref' => $noteHref,
                'isStaff' => $isStaff,
                'showPricing' => $showPricing,
            ]) ?>
        </div>
    <?php endif; ?>

    <div class="line-section">
        <h3 class="line-section-title"><?= e(DeliveryNote::TYPE_LABELS['goods_out']) ?></h3>
        <?php if ($goodsOutNotes === []): ?>
            <p class="empty-state mb-0">Nothing despatched yet.</p>
        <?php else: ?>
            <p class="text-muted">Finished parts that have gone out to <?= $isStaff ? 'the client' : 'you' ?>.</p>
            <?= partial('partials/delivery-note-table', [
                'notes' => $goodsOutNotes,
                'kind' => 'goods_out',
                'noteHref' => $noteHref,
                'isStaff' => $isStaff,
                'showPricing' => $showPricing,
                'invoicesByDn' => $invoicesByDn,
                'returnedByNote' => $returnedByNote,
            ]) ?>
        <?php endif; ?>

        <?php if ($canReturnParts): ?>
            <?php /*
                Sits directly under the despatches because that is what it acts
                on: you cannot send a part back without saying which parcel it
                came out of, and the list of parcels is the table immediately
                above.
            */ ?>
            <details class="disclosure-action">
                <summary class="btn">Return rejected parts to Junction</summary>
                <p class="text-muted" style="margin-top: var(--space-3)">
                    For finished parts that failed your own inspection. Raising this prints a note to
                    enclose with the parts; Junction books them in when they arrive, and only then do they
                    stop counting as delivered and start counting as still to be made.
                </p>
                <form method="post" action="<?= url($clientBase . '/parts-returns') ?>">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="field field-grow">
                            <label for="return_target">Which delivery, and which part</label>
                            <select id="return_target" name="return_target" required>
                                <?php foreach ($returnable as $candidate):
                                    $left = (int) $candidate['qty_sent'] - (int) $candidate['qty_already_returned'];
                                    ?>
                                    <option value="<?= (int) $candidate['note_id'] ?>:<?= (int) $candidate['order_line_id'] ?>"><?=
                                        e($candidate['reference']) . ' — ' . e($candidate['cpn']) . ' ' . e($candidate['part_name'])
                                        . ' (' . $left . ' of ' . (int) $candidate['qty_sent'] . ' still returnable)'
                                    ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field field-shrink">
                            <label for="return_qty">How many</label>
                            <input type="number" class="input-qty" id="return_qty" name="qty" min="1" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="return_problem">What is wrong with them</label>
                        <textarea id="return_problem" name="problem" rows="3" required
                                  placeholder="e.g. Bore undersize on three of them — will not take the bearing"></textarea>
                        <div class="hint">This is what Junction reads, and it is printed on the note. Say enough to act on.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Raise return note</button>
                </form>
            </details>
        <?php endif; ?>
    </div>

    <?php if ($partsReturnNotes !== []): ?>
        <div class="line-section">
            <h3 class="line-section-title"><?= e(DeliveryNote::TYPE_LABELS['parts_return']) ?></h3>
            <p class="text-muted">
                Finished parts <?= $isStaff ? 'the client has' : 'you have' ?> rejected and sent back to be
                remade. Nothing moves on the order until Junction books them in; once booked in they count
                as failed, which means still owed rather than delivered.
            </p>
            <?= partial('partials/delivery-note-table', [
                'notes' => $partsReturnNotes,
                'kind' => 'parts_return',
                'noteHref' => $noteHref,
                'isStaff' => $isStaff,
                'showPricing' => $showPricing,
                'canProduce' => $canProduce,
            ]) ?>
        </div>
    <?php endif; ?>
    </div>
</details>

<?php if ($canClose && !$orderClosed): ?>
<details class="card panel-card">
    <summary class="panel-card-summary">
        <h2 class="panel-card-title">Close the order down</h2>
        <span class="caret" aria-hidden="true"></span>
    </summary>
    <div class="panel-card-body">
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
</details>
<?php endif; ?>

<?php if ($isStaff): ?>
<?php
/**
 * Which parts an attachment may be tagged with: the ones on this order.
 *
 * Rendered from `$lines`, so a part that appears on two lines of the same
 * order is offered once — the tag is about the part, not the line.
 */
$taggableParts = [];
foreach ($lines as $taggableLine) {
    $taggableParts[(int) $taggableLine['part_id']] = $taggableLine['cpn'] . ' — ' . $taggableLine['part_name'];
}

$partCheckboxes = static function (string $idPrefix, array $checked) use ($taggableParts): void {
    ?>
    <fieldset class="part-tag-set">
        <legend>Which part does this show?</legend>
        <?php foreach ($taggableParts as $taggablePartId => $label): ?>
            <label class="checkbox-label">
                <input type="checkbox" name="part_ids[]" value="<?= (int) $taggablePartId ?>"
                       id="<?= e($idPrefix) ?>_part_<?= (int) $taggablePartId ?>"
                       <?= in_array((int) $taggablePartId, $checked, true) ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
        <p class="hint mb-0">
            Tick any it shows and it will be findable from those parts as well as from here. Leave them
            all clear if it is about the order rather than a particular part.
        </p>
    </fieldset>
    <?php
};
?>
<details class="card panel-card" id="photos">
    <summary class="panel-card-summary">
        <h2 class="panel-card-title">Photos and documents</h2>
        <span class="caret" aria-hidden="true"></span>
    </summary>
    <div class="panel-card-body">
    <p class="text-muted">
        Staff-only, and specific to how <em>this</em> order went — a mark on one batch, a packing shot,
        an inspection report. Anything that describes the part itself belongs on the part, where it is
        in front of whoever runs it next:
        <?php foreach ($lines as $photoLine): ?>
            <a href="<?= url('/staff/parts/' . $photoLine['part_id']) ?>#setup"><?= e($photoLine['cpn']) ?></a><?= $photoLine === end($lines) ? '' : ', ' ?>
        <?php endforeach; ?>.
        Tagging one with a part puts it on that part's page too, marked as being about one batch.
    </p>
    <?php if ($photos === []): ?>
        <p class="text-muted">Nothing uploaded yet.</p>
    <?php else: ?>
        <div class="photo-grid">
            <?php foreach ($photos as $photo):
                $taggedIds = array_map(
                    static fn (array $tagged): int => (int) $tagged['id'],
                    $photoParts[(int) $photo['id']] ?? []
                );
                ?>
                <div class="photo-tile">
                    <?php if (OrderPhoto::isImage($photo)): ?>
                        <a href="<?= url('/files/order-photos/' . $photo['id']) ?>" target="_blank" rel="noopener">
                            <img src="<?= url('/files/order-photos/' . $photo['id'] . '/thumb') ?>" alt="">
                        </a>
                    <?php else: ?>
                        <?php /* No picture to show, so the tile is the file itself. */ ?>
                        <a class="photo-file" href="<?= url('/files/order-photos/' . $photo['id']) ?>" target="_blank" rel="noopener">
                            <span class="photo-file-name"><?= e($photo['original_filename']) ?></span>
                        </a>
                    <?php endif; ?>

                    <?php if ($photo['caption']): ?><div class="photo-caption"><?= e($photo['caption']) ?></div><?php endif; ?>

                    <?php if ($taggedIds !== []): ?>
                        <div class="photo-tags">
                            <?php foreach ($photoParts[(int) $photo['id']] as $taggedPart): ?>
                                <a class="badge badge-muted" href="<?= url('/staff/parts/' . (int) $taggedPart['id']) ?>#setup"><?= e($taggedPart['cpn']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canProduce): ?>
                        <details class="caption-edit">
                            <summary>Edit</summary>
                            <form method="post" action="<?= url($staffBase . '/photos/' . $photo['id']) ?>">
                                <?= csrf_field() ?>
                                <div class="field">
                                    <label for="photo_caption_<?= (int) $photo['id'] ?>">Description</label>
                                    <input type="text" id="photo_caption_<?= (int) $photo['id'] ?>" name="caption"
                                           value="<?= e($photo['caption'] ?? '') ?>" placeholder="What this shows">
                                </div>
                                <?php $partCheckboxes('photo_' . (int) $photo['id'], $taggedIds); ?>
                                <button type="submit" class="btn btn-sm">Save</button>
                            </form>
                        </details>
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
            <?php $partCheckboxes('upload', []); ?>
            <div class="field">
                <label for="order_files">Upload photo(s) or document(s)</label>
                <input type="file" id="order_files" name="photos[]" multiple>
                <div class="hint">Pictures, PDFs and office documents. Everything uploaded at once gets the same tags.</div>
            </div>
            <button type="submit" class="btn">Upload</button>
        </form>
    <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?php /* Left open on purpose — see the note above the purchase orders card. */ ?>
<?= partial('partials/order-notes-queries', ['order' => $order, 'notes' => $notes, 'queries' => $queries]) ?>
