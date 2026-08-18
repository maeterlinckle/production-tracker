<?php
/** @var array $deliveryNote */ /** @var array $lines */ /** @var array $client */ /** @var string $qrDataUri */
$isFreeIssue = $deliveryNote['type'] === 'free_issue_in';
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
    .footer { margin-top: 24px; font-size: 10px; color: #777; }
</style>
</head>
<body>
    <?php $logoPath = \App\Services\Branding::printablePath(); ?>
    <table class="header">
        <tr>
            <td>
                <?php if ($logoPath !== null): ?><img src="<?= e($logoPath) ?>" style="max-height:36px; margin-bottom:6px;"><?php endif; ?>
                <h1><?= $isFreeIssue ? 'Free-Issue Material Delivery Note' : 'Delivery Note' ?></h1>
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
        <tr><td class="label">Purpose</td><td>Please enclose this note with the free-issue material listed below when it is sent to Junction.</td></tr>
        <?php else: ?>
        <tr><td class="label">Deliver to</td><td><?= nl2br(e(trim(implode("\n", array_filter([$client['address_line1'] ?? '', $client['address_line2'] ?? '', $client['address_city'] ?? '', $client['address_postcode'] ?? ''])))) ?: '—') ?></td></tr>
        <?php endif; ?>
    </table>

    <table class="lines">
        <thead>
            <tr><th>Order</th><th>CPN</th><th>Description</th><th>Quantity</th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e($line['order_number']) ?></td>
                    <td><?= e($line['cpn']) ?></td>
                    <td><?= e($line['part_name']) ?></td>
                    <td><?= (int) $line['qty'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($deliveryNote['notes']): ?>
        <div style="margin-top:14px"><strong>Notes:</strong><br><?= nl2br(e($deliveryNote['notes'])) ?></div>
    <?php endif; ?>

    <div class="footer">Scan the QR code to view this delivery note on the tracker.</div>
</body>
</html>
