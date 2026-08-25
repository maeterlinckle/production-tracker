<?php
/**
 * One delivery note table, whichever of the four kinds it is holding.
 *
 * The four used to be written out four times, and they had drifted into four
 * different shapes: three columns here, five there, quantities in a different
 * place on each. Read down the consolidated card that was four sets of column
 * edges in a row.
 *
 * They are one partial now, with the columns declared once. Every table has the
 * same six in the same order at the same widths, so the reference column starts
 * in the same place whichever kind you are looking at. Where a kind has nothing
 * to say in a column it still occupies it — an aligned dash reads as "nothing
 * here", where a missing column shifts everything after it and reads as a
 * different table.
 *
 * The status column is the one that genuinely differs, so it carries its own
 * heading per kind rather than a generic word that would have to mean four
 * things at once.
 *
 * @var array         $notes       rows from DeliveryNote::forOrder(), of one type
 * @var string        $kind        the delivery-notes type key
 * @var callable      $noteHref    fn(array $note): string
 * @var bool          $isStaff
 * @var bool          $showPricing
 * @var bool          $canProduce
 * @var array         $invoicesByDn
 * @var array         $freeIssueTotals  note id => ['outstanding','required']
 * @var array         $returnedByNote   goods-out note id => qty returned against it
 */
$invoicesByDn = $invoicesByDn ?? [];
$freeIssueTotals = $freeIssueTotals ?? [];
$returnedByNote = $returnedByNote ?? [];
$canProduce = $canProduce ?? false;
$showPricing = $showPricing ?? false;

$statusHeading = match ($kind) {
    'goods_out' => $isStaff && $showPricing ? 'Invoiced' : 'Status',
    default => 'Status',
};
?>
<div class="table-wrap">
    <table class="dn-table">
        <colgroup>
            <col class="col-dn-ref">
            <col class="col-dn-cpn">
            <col class="col-dn-qty">
            <col class="col-dn-date">
            <col class="col-dn-status">
            <col class="col-dn-action">
        </colgroup>
        <thead>
            <tr>
                <th scope="col">Reference</th>
                <th scope="col">CPN</th>
                <th scope="col" class="align-right">Quantity</th>
                <th scope="col">Issued</th>
                <th scope="col"><?= e($statusHeading) ?></th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($notes as $dn):
            $id = (int) $dn['id'];
            $returned = (int) ($returnedByNote[$id] ?? 0);
            ?>
            <tr>
                <td><?= e($dn['reference']) ?></td>
                <td><?= e($dn['cpns'] ?? '—') ?></td>
                <td class="align-right">
                    <?php if ($kind === 'free_issue_in'):
                        // A standing request has no fixed quantity worth
                        // printing, so the pair is printed instead: what is
                        // still to come, over what the lines need in total.
                        $totals = $freeIssueTotals[$id] ?? ['outstanding' => 0, 'required' => 0];
                        ?>
                        <?= (int) $totals['outstanding'] ?>/<?= (int) $totals['required'] ?>
                    <?php else: ?>
                        <?= (int) $dn['qty_total'] ?>
                    <?php endif; ?>
                </td>
                <td><?= format_date($dn['issued_at']) ?></td>
                <td>
                    <?php switch ($kind):
                        case 'free_issue_in':
                            $outstanding = (int) ($freeIssueTotals[$id]['outstanding'] ?? 0); ?>
                            <span class="badge <?= $outstanding > 0 ? 'badge-warn' : 'badge-ok' ?>">
                                <?= $outstanding > 0 ? 'Outstanding' : 'All received' ?>
                            </span>
                            <?php break;

                        case 'material_return': ?>
                            <span class="badge badge-muted">Replacement requested</span>
                            <?php break;

                        case 'goods_out': ?>
                            <?php if ($isStaff && $showPricing): $invoice = $invoicesByDn[$id] ?? null; ?>
                                <span class="badge <?= $dn['invoiced'] ? 'badge-ok' : 'badge-warn' ?>">
                                    <?= $dn['invoiced'] ? e($invoice['clearbooks_invoice_number'] ?? 'Invoiced') : 'Not invoiced' ?>
                                </span>
                                <?php if (($invoice['source'] ?? '') === 'manual'): ?>
                                    <div class="cell-sub">Raised outside Clear Books</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-ok">Sent</span>
                            <?php endif; ?>
                            <?php if ($returned > 0): ?>
                                <div class="cell-sub"><?= $returned ?> rejected and returned</div>
                            <?php endif; ?>
                            <?php break;

                        case 'parts_return':
                            $declared = (int) $dn['qty_total'];
                            $in = (int) ($dn['qty_checked_in'] ?? 0); ?>
                            <?php if ($in >= $declared): ?>
                                <span class="badge badge-ok">Booked in</span>
                            <?php elseif ($in > 0): ?>
                                <span class="badge badge-warn"><?= $in ?> of <?= $declared ?> booked in</span>
                            <?php else: ?>
                                <span class="badge badge-warn">Awaiting arrival</span>
                            <?php endif; ?>
                            <?php if ($dn['related_reference']): ?>
                                <div class="cell-sub">Sent out on <?= e($dn['related_reference']) ?></div>
                            <?php endif; ?>
                            <?php break;

                        default: ?><span class="text-muted">—</span><?php
                    endswitch; ?>
                </td>
                <td class="dn-actions">
                    <a href="<?= url($noteHref($dn)) ?>" <?= $isStaff ? '' : 'target="_blank" rel="noopener"' ?>>
                        <?= $isStaff ? 'View' : 'View PDF' ?>
                    </a>
                    <?php if ($kind === 'parts_return' && $canProduce && (int) ($dn['qty_checked_in'] ?? 0) < (int) $dn['qty_total']): ?>
                        <a href="<?= url('/staff/parts-returns/' . $id . '/check-in') ?>" class="btn btn-sm btn-primary">Check in</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
