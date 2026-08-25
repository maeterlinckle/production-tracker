<?php /** @var array $note */ /** @var array $client */ /** @var array $lines */ /** @var array|null $invoice */
use App\Core\Auth;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\FreeIssueNoteService;

$isFreeIssue = $note['type'] === 'free_issue_in';
$isPartsReturn = $note['type'] === 'parts_return';

// Where "back" goes. A note is nearly always opened from the order it belongs
// to, and the order is the one destination the sidebar cannot offer — the
// delivery-notes list is already one click away from anywhere. So the link
// points at the order when the note covers exactly one, which is every
// free-issue, return and parts-return note and most despatches. A goods-out
// note can cover several orders at once, and there is no single order to go
// back to, so that one falls back to the list.
$noteOrders = [];
foreach ($lines as $noteLine) {
    $noteOrders[(int) $noteLine['order_id']] = $noteLine['order_number'];
}

if (count($noteOrders) === 1) {
    $backHref = '/staff/orders/' . array_key_first($noteOrders) . '#delivery-notes';
    $backLabel = 'Back to order ' . reset($noteOrders);
} else {
    $backHref = '/staff/delivery-notes';
    $backLabel = 'Back to delivery notes';
}
?>
<?= partial('partials/back-link', ['href' => $backHref, 'label' => $backLabel]) ?>

<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($note['reference']) ?></h1>
        <p class="text-muted mb-0"><?= e($client['name']) ?> &middot; <?= format_date($note['issued_at']) ?> &middot; <?= e(DeliveryNote::TYPE_LABELS[$note['type']] ?? $note['type']) ?></p>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap: var(--space-2)">
        <?php if ($isPartsReturn && Auth::can('production_control')): ?>
            <a href="<?= url('/staff/parts-returns/' . $note['id'] . '/check-in') ?>" class="btn">Book the parts in</a>
        <?php endif; ?>
        <a href="<?= url('/staff/delivery-notes/' . $note['id'] . '/pdf') ?>" class="btn btn-primary" target="_blank" rel="noopener">View PDF</a>
    </div>
</div>

<?php if (trim((string) $note['notes']) !== ''): ?>
    <?php /*
        Shown here as well as on the PDF. On a rejected-parts return it is the
        substance of the document — what the client says is wrong — and opening
        the note from the list to find only a quantity was sending somebody to
        the PDF to read the one thing they came for.
    */ ?>
    <div class="card">
        <h2 class="mt-0"><?= $isPartsReturn ? 'Problem reported' : 'Notes' ?></h2>
        <p class="mb-0"><?= nl2br(e($note['notes'])) ?></p>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">Lines</h2>
    <?php if ($isFreeIssue): ?>
        <p class="text-muted">
            A standing request: the quantity is worked out fresh every time the note is printed, so it
            always asks for whatever the line still needs today.
        </p>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order</th><th>CPN</th><th>Part</th>
                    <?php if ($isFreeIssue): ?>
                        <th>Still required</th><th>Received against this line</th>
                    <?php else: ?>
                        <th>Quantity</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e($line['order_number']) ?></td>
                    <td><?= e($line['cpn']) ?></td>
                    <td class="wrap"><?= e($line['part_name']) ?></td>
                    <?php if ($isFreeIssue): $figures = FreeIssueNoteService::noteLineFigures($line); ?>
                        <td><?= (int) $figures['required'] ?> <span class="cell-sub">of <?= (int) $figures['original'] ?> asked for</span></td>
                        <td><?= e($figures['outstanding_sentence']) ?></td>
                    <?php else: ?>
                        <td><?= (int) $line['qty'] ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($note['type'] === 'goods_out' && Auth::can('view_pricing')): ?>
<div class="card">
    <h2 class="mt-0">Invoicing</h2>
    <?php if ($invoice !== null): $manual = ($invoice['source'] ?? Invoice::SOURCE_CLEARBOOKS) === Invoice::SOURCE_MANUAL; ?>
        <p>
            <span class="badge badge-ok">Invoiced</span>
            <?php if ($manual): ?><span class="badge badge-muted">Raised outside Clear Books</span><?php endif; ?>
        </p>
        <p>
            Invoice <strong><?= e($invoice['clearbooks_invoice_number']) ?></strong>
            — <?= format_money($invoice['amount']) ?>,
            recorded <?= format_date($invoice['raised_at']) ?>
            <?= $invoice['raised_by_name'] !== null ? 'by ' . e($invoice['raised_by_name']) : '' ?>.
        </p>
        <?php if ($manual): ?>
            <p class="text-muted mb-0">
                This one was raised somewhere else and entered here, so there is no Clear Books record
                behind it to look up by ID — the invoice number above is what to search for.
                <?= $invoice['notes'] ? '<br>' . e($invoice['notes']) : '' ?>
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p><span class="badge badge-warn">Not yet invoiced</span></p>
        <?php if (Auth::can('push_invoices')): ?>
            <form method="post" action="<?= url('/staff/delivery-notes/' . $note['id'] . '/invoice') ?>" onsubmit="return confirm('Raise a Clear Books invoice for this delivery note?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Raise invoice in Clear Books</button>
            </form>

            <?php /*
                The way out when Clear Books cannot be reached, is not connected
                yet, or the invoice was simply typed straight into their own
                interface. The delivery note settles either way — what differs
                is that this one has no Clear Books id behind it, and the record
                says so rather than pretending otherwise.
            */ ?>
            <details class="disclosure-action">
                <summary class="btn">Raise invoice manually</summary>
                <p class="text-muted" style="margin-top: var(--space-3)">
                    For an invoice raised outside this application — in Clear Books' own interface, or
                    anywhere else. It settles this delivery note exactly as an API invoice would, and is
                    labelled so it is obvious later which is which.
                </p>
                <form method="post" action="<?= url('/staff/delivery-notes/' . $note['id'] . '/invoice-manually') ?>">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="field field-shrink">
                            <label for="invoice_number">Invoice number</label>
                            <input type="text" id="invoice_number" name="invoice_number" required
                                   placeholder="e.g. INV-1042">
                            <div class="hint">Required</div>
                        </div>
                        <div class="field field-shrink">
                            <label for="amount">Amount</label>
                            <input type="number" step="0.01" min="0" id="amount" name="amount"
                                   value="<?= number_format(Invoice::valueOfDeliveryNote((int) $note['id']), 2, '.', '') ?>">
                            <div class="hint">What this note is worth at order prices — correct it if the invoice said otherwise</div>
                        </div>
                        <div class="field field-grow">
                            <label for="invoice_notes">Note (optional)</label>
                            <input type="text" id="invoice_notes" name="notes"
                                   placeholder="e.g. Raised in Clear Books by hand while the API was down">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Record this invoice</button>
                </form>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
