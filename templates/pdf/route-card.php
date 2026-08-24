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
                <tr><td class="label">Where the material comes from</td><td><?= e($part['material_source'] ?: '—') ?></td></tr>
                <tr><td class="label">Estimated build time</td><td><?= $part['build_time_minutes'] ? e((string) $part['build_time_minutes']) . ' minutes each' : 'Not recorded' ?></td></tr>
                <?php if (!Part::hasFreeIssue($part)): ?>
                    <tr>
                        <td class="label">Free-issue material</td>
                        <td>None — Junction supplies the material for this part</td>
                    </tr>
                <?php else: $fi = OrderLine::freeIssueFigures($line); ?>
                    <?php /*
                        Three figures rather than one line of arithmetic, and all
                        three out of OrderLine::freeIssueFigures() so the card
                        cannot disagree with the order page about the same line.
                        This used to do its own subtraction here in the template,
                        and "9 required, 9 usable" told somebody at a machine
                        nothing about whether anything was still coming.

                        They reconcile by construction: accepted + still to come
                        = required, and where more has arrived than the job needs
                        the extra is named as spare rather than left looking like
                        an error.
                    */ ?>
                    <tr>
                        <td class="label">Free issue needed for this job</td>
                        <td><span class="qty"><?= $fi['required'] ?></span> <?= $fi['required'] === 1 ? 'piece' : 'pieces' ?></td>
                    </tr>
                    <tr>
                        <td class="label">Arrived and accepted</td>
                        <td>
                            <span class="qty"><?= $fi['accepted'] ?></span>
                            <?php if ($fi['rejected'] > 0): ?>
                                <div class="qty-sub">
                                    <?= $fi['received'] ?> delivered in total,
                                    <?= $fi['rejected'] ?> sent back as unusable.
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label"><?= $fi['surplus'] > 0 ? 'Spare on the shelf' : 'Still to come' ?></td>
                        <td>
                            <span class="qty"><?= $fi['surplus'] > 0 ? $fi['surplus'] : $fi['outstanding'] ?></span>
                            <div class="qty-sub">
                                <?php if ($fi['surplus'] > 0): ?>
                                    More has arrived than this job needs. Do not start the extra without asking.
                                <?php elseif ($fi['outstanding'] > 0): ?>
                                    Not here yet. Do not expect to finish the whole job until it arrives.
                                <?php else: ?>
                                    Everything this job needs is on site.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">Progress so far</td>
                    <td><?= e(OrderLine::statusLabel($line)) ?></td>
                </tr>
            </table>

            <div><strong>Notes on this part:</strong><br><?= nl2br(e($part['internal_notes'] ?: 'None')) ?></div>

            <div class="notes-box">
                <strong>Notes from the floor</strong>
            </div>

            <div class="footer">Scan the QR code to open this job on the tracker and update its status.</div>
        </div>
    <?php endforeach; ?>
</body>
</html>
