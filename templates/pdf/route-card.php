<?php
/**
 * One or more route cards, one to a page.
 *
 * The same template does a single line and a whole order, because they are the
 * same document printed once or several times — keeping two would be keeping
 * two chances to change one and not the other.
 *
 * @var array<int,array{routeCard:array,line:array,order:array,part:array,client:array,qrDataUri:string}> $cards
 */
use App\Models\OrderLine;
use App\Models\Part;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #111; }
    .card-page { page-break-after: always; }
    .card-page:last-child { page-break-after: auto; }
    .header { width: 100%; border-bottom: 3px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
    .header td { vertical-align: top; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .muted { color: #555; }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.meta td { border: 1px solid #ccc; padding: 6px 8px; font-size: 12px; }
    table.meta td.label { background: #f2f2f2; width: 32%; font-weight: bold; }
    .qty { font-size: 24px; font-weight: bold; }
    .qty-sub { font-size: 11px; font-weight: normal; color: #555; }
    .notes-box { border: 1px solid #ccc; min-height: 120px; padding: 8px; margin-top: 12px; }
    .footer { margin-top: 24px; font-size: 10px; color: #777; }
</style>
</head>
<body>
    <?php $logoPath = \App\Services\Branding::printablePath(); ?>
    <?php foreach ($cards as $card): ?>
        <?php
        $routeCard = $card['routeCard'];
        $line = $card['line'];
        $order = $card['order'];
        $part = $card['part'];
        $client = $card['client'];
        $conversion = Part::conversionSentence($part, (int) $line['qty_ordered']);
        ?>
        <div class="card-page">
            <table class="header">
                <tr>
                    <td>
                        <?php if ($logoPath !== null): ?><img src="<?= e($logoPath) ?>" style="max-height:36px; margin-bottom:6px;"><?php endif; ?>
                        <h1>Workshop Route Card</h1>
                        <div class="muted">Reference: <?= e($routeCard['reference']) ?> &middot; Generated <?= format_date($routeCard['generated_at'], 'j M Y H:i') ?></div>
                    </td>
                    <td style="width:120px; text-align:right;">
                        <img src="<?= $card['qrDataUri'] ?>" width="100" height="100">
                    </td>
                </tr>
            </table>

            <table class="meta">
                <tr><td class="label">Client</td><td><?= e($client['name']) ?></td></tr>
                <tr><td class="label">Order</td><td><?= e($order['order_number']) ?><?= $order['po_number'] !== '' ? ' &middot; PO ' . e($order['po_number']) : '' ?></td></tr>
                <tr><td class="label">CPN</td><td><?= e($part['cpn']) ?></td></tr>
                <tr><td class="label">Part name</td><td><?= e($part['name']) ?></td></tr>
                <tr>
                    <td class="label">Quantity ordered</td>
                    <td>
                        <span class="qty"><?= (int) $line['qty_ordered'] ?></span>
                        <?php if ($conversion !== null): ?>
                            <div class="qty-sub"><?= e($conversion) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="label">Base material</td><td><?= e($part['base_material'] ?: '—') ?></td></tr>
                <tr><td class="label">Material source</td><td><?= e($part['material_source'] ?: '—') ?></td></tr>
                <tr><td class="label">Build time (est.)</td><td><?= $part['build_time_minutes'] ? e((string) $part['build_time_minutes']) . ' minutes' : '—' ?></td></tr>
                <tr>
                    <td class="label">Free issue</td>
                    <td>
                        <?php if (!Part::hasFreeIssue($part)): ?>
                            No free-issue material required
                        <?php else: ?>
                            <?= (int) $line['qty_free_issue_required'] ?> required,
                            <?= (int) $line['qty_free_issue_received'] - (int) $line['qty_free_issue_rejected'] ?> usable on site
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Where the quantity is</td>
                    <td><?= e(OrderLine::statusLabel($line)) ?></td>
                </tr>
            </table>

            <div><strong>Internal notes:</strong><br><?= nl2br(e($part['internal_notes'] ?: '—')) ?></div>

            <div class="notes-box">
                <strong>Workshop notes</strong>
            </div>

            <div class="footer">Scan the QR code to open this job on the tracker and update its status.</div>
        </div>
    <?php endforeach; ?>
</body>
</html>
