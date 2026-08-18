<?php
/** @var array $routeCard */ /** @var array $line */ /** @var array $order */ /** @var array $part */ /** @var array $client */ /** @var string $qrDataUri */
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
    table.meta td { border: 1px solid #ccc; padding: 6px 8px; font-size: 12px; }
    table.meta td.label { background: #f2f2f2; width: 32%; font-weight: bold; }
    .qty { font-size: 24px; font-weight: bold; }
    .notes-box { border: 1px solid #ccc; min-height: 120px; padding: 8px; margin-top: 12px; }
    .footer { margin-top: 24px; font-size: 10px; color: #777; }
</style>
</head>
<body>
    <?php $logoPath = \App\Services\Branding::printablePath(); ?>
    <table class="header">
        <tr>
            <td>
                <?php if ($logoPath !== null): ?><img src="<?= e($logoPath) ?>" style="max-height:36px; margin-bottom:6px;"><?php endif; ?>
                <h1>Workshop Route Card</h1>
                <div class="muted">Reference: <?= e($routeCard['reference']) ?> &middot; Generated <?= format_date($routeCard['generated_at'], 'j M Y H:i') ?></div>
            </td>
            <td style="width:120px; text-align:right;">
                <img src="<?= $qrDataUri ?>" width="100" height="100">
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="label">Client</td><td><?= e($client['name']) ?></td></tr>
        <tr><td class="label">Order</td><td><?= e($order['order_number']) ?></td></tr>
        <tr><td class="label">CPN</td><td><?= e($part['cpn']) ?></td></tr>
        <tr><td class="label">Part name</td><td><?= e($part['name']) ?></td></tr>
        <tr><td class="label">Quantity ordered</td><td class="qty"><?= (int) $line['qty_ordered'] ?></td></tr>
        <tr><td class="label">Base material</td><td><?= e($part['base_material'] ?: '—') ?></td></tr>
        <tr><td class="label">Material source</td><td><?= e($part['material_source'] ?: '—') ?></td></tr>
        <tr><td class="label">Build time (est.)</td><td><?= $part['build_time_minutes'] ? e((string) $part['build_time_minutes']) . ' minutes' : '—' ?></td></tr>
        <tr>
            <td class="label">Free issue</td>
            <td>
                <?php if (!\App\Models\Part::hasFreeIssue($part)): ?>
                    No free-issue material required
                <?php else: ?>
                    <?= (int) $line['qty_free_issue_required'] ?> required,
                    <?= (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'] ?> usable on site
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="label">Where the quantity is</td>
            <td><?= e(\App\Models\OrderLine::statusLabel($line)) ?></td>
        </tr>
    </table>

    <div><strong>Internal notes:</strong><br><?= nl2br(e($part['internal_notes'] ?: '—')) ?></div>

    <div class="notes-box">
        <strong>Workshop notes</strong>
    </div>

    <div class="footer">Scan the QR code to open this job on the tracker and update its status.</div>
</body>
</html>
