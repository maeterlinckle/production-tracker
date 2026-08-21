<?php
/** @var array $deliveryNote */ /** @var array $lines */ /** @var array $client */ /** @var string $qrDataUri */
use App\Models\DeliveryNote;
use App\Services\FreeIssueNoteService;

$isFreeIssue = $deliveryNote['type'] === 'free_issue_in';
$isReturn = $deliveryNote['type'] === 'material_return';
$isPartsReturn = $deliveryNote['type'] === 'parts_return';

// The heading is the name the note goes by everywhere else. A document whose
// title does not match the row somebody clicked to reach it is a document they
// have to stop and work out.
$heading = DeliveryNote::TYPE_LABELS[$deliveryNote['type']] ?? 'Delivery Note';

$clientAddress = trim(implode("\n", array_filter([
    $client['address_line1'] ?? '',
    $client['address_line2'] ?? '',
    $client['address_city'] ?? '',
    $client['address_postcode'] ?? '',
])));
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #111; }
    .header { width: 100%; border-bottom: 3px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
    .header td { vertical-align: top; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .muted { color: #555; }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.meta td { border: 1px solid #ccc; padding: 6px 8px; }
    table.meta td.label { background: #f2f2f2; width: 25%; font-weight: bold; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.lines th, table.lines td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
    table.lines th { background: #f2f2f2; }
    td.fill-in { background: #fff; height: 26px; }
    .received-note { font-size: 10px; color: #555; }
    .footer { margin-top: 24px; font-size: 10px; color: #777; }
</style>
</head>
<body>
    <?php $logoPath = \App\Services\Branding::printablePath(); ?>
    <table class="header">
        <tr>
            <td>
                <?php if ($logoPath !== null): ?><img src="<?= e($logoPath) ?>" style="max-height:36px; margin-bottom:6px;"><?php endif; ?>
                <h1><?= e($heading) ?></h1>
                <div class="muted">Reference: <?= e($deliveryNote['reference']) ?> &middot; <?= format_date($deliveryNote['issued_at'], 'j M Y') ?></div>
            </td>
            <td style="width:120px; text-align:right;">
                <img src="<?= $qrDataUri ?>" width="100" height="100">
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="label">Client</td><td><?= e($client['name']) ?></td></tr>
        <?php if ($isFreeIssue): ?>
        <tr><td class="label">Purpose</td><td>Please enclose this note with the free-issue material listed below when it is sent to Junction. Write in the quantity you have actually sent for each line.</td></tr>
        <?php elseif ($isReturn): ?>
        <tr><td class="label">Purpose</td><td>The material listed below could not be used and is being returned to you. Replacement material has been requested against the same order line.</td></tr>
        <tr><td class="label">Return to</td><td><?= nl2br(e($clientAddress)) ?: '—' ?></td></tr>
        <?php elseif ($isPartsReturn): ?>
        <?php /*
            The only note in the family that travels towards the workshop, so
            the addresses are the other way round: it is enclosed with parts
            leaving the client. Junction's own address is not held anywhere in
            the tracker, so this names the destination rather than inventing one
            — the client knows where to send it, and a wrong address printed
            with authority is worse than none.
        */ ?>
        <tr><td class="label">Purpose</td><td>The parts listed below were rejected on inspection and are being returned to <?= e(config('app.vendor')) ?> to be remade. Please enclose this note with them.</td></tr>
        <tr><td class="label">Return to</td><td><?= e(config('app.vendor')) ?> — goods inwards</td></tr>
        <tr><td class="label">Returned by</td><td><?= e($client['name']) ?><?= $clientAddress !== '' ? '<br>' . nl2br(e($clientAddress)) : '' ?></td></tr>
        <?php if (($relatedNote ?? null) !== null): ?>
        <tr><td class="label">Sent out on</td><td><?= e($relatedNote['reference']) ?>, <?= format_date($relatedNote['issued_at'], 'j M Y') ?></td></tr>
        <?php endif; ?>
        <?php else: ?>
        <tr><td class="label">Deliver to</td><td><?= nl2br(e($clientAddress)) ?: '—' ?></td></tr>
        <?php endif; ?>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Order</th>
                <th>CPN</th>
                <th>Description</th>
                <?php if ($isPartsReturn): ?>
                    <th>Drawing</th>
                <?php endif; ?>
                <?php if ($isFreeIssue): ?>
                    <th>Quantity Required</th>
                    <th>Actual Quantity Sent</th>
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
                    <td>
                        <?= e($line['part_name']) ?>
                        <?php if ($isPartsReturn && trim((string) ($line['part_description'] ?? '')) !== ''): ?>
                            <div class="received-note"><?= e($line['part_description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if ($isPartsReturn): ?>
                        <td><?= e($line['drawing_reference'] ?? '') ?: '—' ?></td>
                    <?php endif; ?>
                    <?php if ($isFreeIssue): $figures = FreeIssueNoteService::noteLineFigures($line); ?>
                        <td>
                            <?= (int) $figures['required'] ?>
                            <?php if ($figures['received'] > 0 || $figures['rejected'] > 0): ?>
                                <div class="received-note"><?= e($figures['outstanding_sentence']) ?></div>
                            <?php endif; ?>
                        </td>
                        <!-- Left blank on purpose: the client writes in what they
                             actually packed, and that is what goods-in checks against. -->
                        <td class="fill-in"></td>
                    <?php else: ?>
                        <td><?= (int) $line['qty'] ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($deliveryNote['notes']): ?>
        <?php /*
            On a rejected-parts return this field is the whole point of the
            document — it is what the client says is wrong — so it is labelled
            as that rather than as an afterthought at the bottom of the page.
        */ ?>
        <div style="margin-top:14px">
            <strong><?= $isPartsReturn ? 'Problem reported:' : 'Notes:' ?></strong><br>
            <?= nl2br(e($deliveryNote['notes'])) ?>
        </div>
    <?php endif; ?>

    <div class="footer">Scan the QR code to view this note on the tracker.</div>
</body>
</html>
