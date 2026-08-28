<?php
/**
 * The part page, for both audiences.
 *
 * One template, rendered at `/parts/{id}` for the client and
 * `/staff/parts/{id}` for Junction. What changes between them is which cards
 * appear and which buttons are live — not the page.
 *
 * @var array      $part
 * @var array      $files
 * @var array|null $mainPhoto
 * @var array      $attachments
 * @var array      $altNumbers
 * @var array      $freeIssueMaterials
 * @var array      $linkedParts
 * @var array      $orderLines
 * @var array      $orderMedia
 * @var array      $timeEntries   kind => rows, from PartTimeEntry::bothForPart()
 * @var array      $priceBreaks   kind => rows, from PartPriceBreak::bothForPart()
 * @var array|null $quoteDraft
 * @var array      $quoteLines
 * @var array      $quoteResult   the worked breakdown, from PartQuote::calculate()
 * @var array      $quoteDefaults the house rate and mark-up
 */
use App\Core\Auth;
use App\Models\OrderLine;
use App\Models\Part;
use App\Models\PartPriceBreak;
use App\Models\PartTimeEntry;

$isStaff = Auth::isStaff();
$canSeePricing = Auth::can('view_pricing');
$canEditWorkshop = $isStaff && Auth::can('edit_workshop_fields');
$canManageClientPart = !$isStaff && Auth::can('manage_parts');
$canManageStaffPart = $isStaff && Auth::can('create_client_parts');

// Every staff-only action lives under /staff, whichever door the page came in
// by; the client's own actions live under /parts.
$staffBase = '/staff/parts/' . $part['id'];
$clientBase = '/parts/' . $part['id'];
$here = $isStaff ? $staffBase : $clientBase;
?>
<div class="card-header">
    <div style="display:flex; gap: var(--space-3); align-items:center">
        <?php if ($mainPhoto !== null): ?>
            <a href="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" target="_blank" rel="noopener">
                <img class="part-hero" src="<?= url('/files/part-media/' . $mainPhoto['id'] . '/thumb') ?>" alt="<?= e($part['cpn']) ?>">
            </a>
        <?php endif; ?>
        <div>
            <h1 class="mt-0 mb-0">
                <?= e($part['cpn']) ?>
                <?= status_badge($part['status']) ?>
                <?php if ($part['is_archived']): ?><span class="badge badge-muted">Archived</span><?php endif; ?>
            </h1>
            <p class="text-muted mb-0">
                <?php if ($isStaff): ?><?= e($part['client_name']) ?> — <?php endif; ?><?= e($part['name']) ?>
            </p>
        </div>
    </div>
</div>

<div class="detail-layout">
    <div class="detail-main">
        <div class="card">
            <h2 class="mt-0">Specification</h2>
            <p><strong>Description:</strong><br><?= nl2br(e($part['description'] ?: '—')) ?></p>
            <p><strong>Notes:</strong><br><?= nl2br(e($part['notes'] ?: '—')) ?></p>

            <?php if ($altNumbers !== []): ?>
                <h3>Alternate numbers</h3>
                <ul>
                    <?php foreach ($altNumbers as $n): ?>
                        <li><?= e($n['number']) ?><?= $n['label'] ? ' (' . e($n['label']) . ')' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Free-issue material</h3>
            <?php if (!Part::hasFreeIssue($part)): ?>
                <p class="text-muted mb-0">No free-issue material required — Junction supplies the material for this part.</p>
            <?php else: ?>
                <?php if ($freeIssueMaterials === []): ?>
                    <p class="text-muted">Free-issue, but no source material has been named yet.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($freeIssueMaterials as $m): ?>
                            <li><?= e($m['reference']) ?><?= $m['notes'] ? ' — ' . e($m['notes']) : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="text-muted mb-0">
                    <?php if ($part['free_issue_relationship'] === 'divide'): ?>
                        1 piece of free-issue material makes <?= (int) $part['free_issue_factor'] ?> of this part.
                    <?php elseif ($part['free_issue_relationship'] === 'multiply'): ?>
                        <?= (int) $part['free_issue_factor'] ?> pieces of free-issue material are needed per part.
                    <?php else: ?>
                        1 piece of free-issue material per part.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="mt-0">Drawings</h2>
            <?php if ($files === []): ?>
                <p class="text-muted">No drawings uploaded yet.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($files as $file): ?>
                        <li>
                            <span>
                                <?= e($file['original_filename']) ?>
                                <span class="text-muted">v<?= (int) $file['version_no'] ?></span>
                                <?php if ((bool) $file['is_current']): ?>
                                    <span class="badge badge-ok">Current</span>
                                <?php endif; ?>
                            </span>
                            <a href="<?= url('/files/drawings/' . $file['id']) ?>" class="btn btn-sm">View</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canEditWorkshop || $canManageClientPart): ?>
                <form method="post" action="<?= url($isStaff ? $staffBase . '/drawings' : $clientBase . '/files') ?>"
                      enctype="multipart/form-data" style="margin-top: var(--space-4)">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="drawings">Upload a new revision</label>
                        <input type="file" id="drawings" name="drawings[]" multiple>
                        <div class="hint">
                            Becomes the current revision. The one it replaces is kept and stays viewable —
                            parts already made were made to it.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm">Upload drawing</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="mt-0" id="setup">Setup and reference</h2>
            <p class="text-muted">
                <?php if ($isStaff): ?>
                    Everything here belongs to the part, so it is in front of whoever runs it next — every
                    order of it, not just the one it was added on.
                <?php else: ?>
                    Photos and reference material Junction keeps against this part.
                <?php endif; ?>
            </p>
            <?= partial('partials/part-media', [
                'part' => $part,
                'mainPhoto' => $mainPhoto,
                'attachments' => $attachments,
                'canManage' => $canEditWorkshop,
                // The order page's photos are Junction's, so their appearance
                // here is too.
                'orderMedia' => $isStaff ? $orderMedia : [],
            ]) ?>
        </div>

        <?php if ($canEditWorkshop): ?>
        <div class="card">
            <h2 class="mt-0">Junction-only workshop details</h2>
            <form method="post" action="<?= url($staffBase . '/workshop-fields') ?>">
                <?= csrf_field() ?>
                <?php /* Build time has its own card below: it is a list now, not a box. */ ?>
                <div class="form-row">
                    <div class="field"><label for="base_material">Base material</label><input type="text" id="base_material" name="base_material" value="<?= e($part['base_material'] ?? '') ?>"></div>
                    <div class="field"><label for="material_source">Material source</label><input type="text" id="material_source" name="material_source" value="<?= e($part['material_source'] ?? '') ?>"></div>
                </div>
                <?php if ($canSeePricing): ?>
                    <div class="form-row">
                        <div class="field"><label for="material_cost">Material cost</label><input type="number" step="0.01" min="0" id="material_cost" name="material_cost" value="<?= e((string) ($part['material_cost'] ?? '')) ?>"></div>
                    </div>
                <?php endif; ?>

                <?= partial('partials/free-issue-fields', [
                    'hasFreeIssue' => Part::hasFreeIssue($part),
                    'relationship' => $part['free_issue_relationship'],
                    'factor' => (int) $part['free_issue_factor'],
                    'materials' => $freeIssueMaterials,
                    'idPrefix' => 'staff_fi',
                    'showOverrideNote' => true,
                ]) ?>
                <?php if ($part['free_issue_updated_at'] !== null): ?>
                    <p class="field-hint">
                        Last changed <?= e(format_datetime($part['free_issue_updated_at'])) ?><?php
                        ?><?= $part['free_issue_updated_by_name'] !== null ? ' by ' . e($part['free_issue_updated_by_name']) : '' ?>.
                    </p>
                <?php endif; ?>

                <div class="field"><label for="internal_notes">Internal notes</label><textarea id="internal_notes" name="internal_notes"><?= e($part['internal_notes'] ?? '') ?></textarea></div>
                <button type="submit" class="btn">Save workshop details</button>
            </form>
        </div>

        <?php /*
            Build time, as the jobs it is made of.

            Nobody knows a part takes 140 minutes. They know it is 40 on the
            lathe, 60 on the mill and 40 to fettle, and 140 is what falls out.
            Storing only the total meant that when an estimate turned out wrong
            there was nothing to say which operation had been misjudged — and
            the next estimate for a similar part started from the same blank.

            The two sit side by side because the comparison is the point.
        */ ?>
        <div class="card" id="build-time">
            <h2 class="mt-0">Build time</h2>
            <p class="text-muted">
                What this part was expected to take, and what it actually took. Junction-only.
                Each is a list of jobs; the figure is their sum and is never typed in directly.
            </p>

            <div class="grid grid-2">
                <?php foreach (PartTimeEntry::KINDS as $kind):
                    $entries = $timeEntries[$kind] ?? [];
                    $total = (int) ($part[$kind === 'estimated' ? 'estimated_build_time_minutes' : 'actual_build_time_minutes'] ?? 0);
                    ?>
                    <div>
                        <h3 class="line-section-title"><?= e(PartTimeEntry::KIND_LABELS[$kind]) ?></h3>
                        <p class="build-time-total mb-0"><?= e(PartTimeEntry::formatMinutes($total ?: null)) ?></p>

                        <?php if ($entries === []): ?>
                            <p class="field-hint">
                                <?= $kind === 'estimated'
                                    ? 'Not estimated yet. The draft quote prices machine time from this figure.'
                                    : 'Nothing recorded yet. Add it once the job has run.' ?>
                            </p>
                        <?php else: ?>
                            <ul class="itemised-list">
                                <?php foreach ($entries as $entry): ?>
                                    <li>
                                        <span class="itemised-task"><?= e($entry['task']) ?></span>
                                        <span class="itemised-value"><?= (int) $entry['minutes'] ?> min</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($canEditWorkshop): ?>
                            <div style="margin-top: var(--space-3)">
                                <?= partial('partials/row-editor', [
                                    'id' => 'time_' . $kind,
                                    'title' => PartTimeEntry::KIND_LABELS[$kind] . ' — ' . $part['cpn'],
                                    'action' => $staffBase . '/time/' . $kind,
                                    'trigger' => $entries === [] ? 'Set ' . lcfirst(PartTimeEntry::KIND_LABELS[$kind]) : 'Edit',
                                    'intro' => $kind === 'estimated'
                                        ? 'One row per operation. The total is what the draft quote prices machine time from.'
                                        : 'One row per operation, as it actually ran. Compared against the estimate above.',
                                    'columns' => [
                                        ['name' => 'task', 'label' => 'Task', 'type' => 'text',
                                         'placeholder' => 'e.g. Op 10 — turn and face'],
                                        ['name' => 'minutes', 'label' => 'Minutes', 'type' => 'number',
                                         'min' => '0', 'step' => '1', 'width' => 'narrow', 'total' => true],
                                    ],
                                    'rows' => $entries,
                                    'totalLabel' => 'Total',
                                    'totalFormat' => 'minutes',
                                ]) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            $variance = PartTimeEntry::variance(
                $part['estimated_build_time_minutes'] !== null ? (int) $part['estimated_build_time_minutes'] : null,
                $part['actual_build_time_minutes'] !== null ? (int) $part['actual_build_time_minutes'] : null
            );
            ?>
            <?php if ($variance !== null): ?>
                <?php /*
                    Stated once the pair exists, because it is the only reason
                    to record both. Over is worth a colour; under is simply the
                    job going well.
                */ ?>
                <p class="field-hint" style="margin-top: var(--space-4)">
                    Actual is
                    <strong class="<?= $variance['over'] ? 'variance-over' : 'variance-under' ?>">
                        <?= e(PartTimeEntry::formatMinutes($variance['difference'])) ?>
                        (<?= e((string) $variance['percent']) ?>%) <?= $variance['over'] ? 'over' : 'under' ?>
                    </strong>
                    the estimate.
                    <?= $variance['over']
                        ? 'Worth correcting the estimate before this part is quoted again.'
                        : '' ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php /*
            The quoting scratchpad.

            Quoting happened on paper or in somebody's head, and what reached
            the system was the answer with none of the arithmetic. When a price
            turned out wrong there was nothing to look at — no way to tell
            whether the time was underestimated, the material had gone up, or
            the mark-up had been forgotten.

            It sets nothing. Putting the figure into the quoted price is still
            the deliberate, separate, client-visible act it always was, and the
            client sees none of this.
        */ ?>
        <?php if ($isStaff && Auth::can('set_pricing')): ?>
        <div class="card" id="draft-quote">
            <div class="card-header">
                <div>
                    <h2 class="mt-0 mb-0">Draft Part Quote</h2>
                    <p class="text-muted mb-0">
                        Junction's working towards a price. Never shown to the client, and it sets nothing
                        on its own.
                    </p>
                </div>
                <div>
                    <?= partial('partials/row-editor', [
                        'id' => 'quote_draft',
                        'title' => 'Draft quote — ' . $part['cpn'],
                        'action' => $staffBase . '/quote-draft',
                        'trigger' => $quoteDraft === null && $quoteLines === [] ? 'Start a draft' : 'Edit draft',
                        'intro' => 'The two figures at the top come from Settings unless you change them here. '
                            . 'Everything below is whatever these numbers do not already cover.',
                        'extra' => partial('partials/quote-standard-inputs', [
                            'draft' => $quoteDraft,
                            'defaults' => $quoteDefaults,
                        ]),
                        'columns' => [
                            ['name' => 'label', 'label' => 'What for', 'type' => 'text',
                             'placeholder' => 'e.g. Subcontract plating, or "less agreed discount"'],
                            ['name' => 'amount', 'label' => 'Amount', 'type' => 'number',
                             'step' => '0.01', 'width' => 'narrow', 'total' => true],
                        ],
                        'rows' => array_map(
                            static fn (array $line): array => ['label' => $line['label'], 'amount' => $line['amount']],
                            $quoteLines
                        ),
                        'totalLabel' => 'Lines total',
                        'totalFormat' => 'money',
                        'footnote' => 'An amount may be negative — a discount is a line like any other, and a '
                            . 'separate discount box would be a second place to look for it.',
                    ]) ?>
                </div>
            </div>

            <?php if ($quoteDraft === null && $quoteLines === []): ?>
                <p class="empty-state mb-0">
                    Nothing drafted for this part yet. A draft costs the machine time, the material and
                    anything else, then applies the mark-up.
                </p>
            <?php else: ?>
                <table class="quote-breakdown">
                    <tbody>
                        <tr>
                            <td>
                                Machine time
                                <span class="quote-detail">
                                    <?= $quoteResult['missing_time']
                                        ? 'no estimated build time recorded'
                                        : $quoteResult['minutes'] . ' min at ' . format_money($quoteResult['rate']) . '/min'
                                          . ($quoteResult['rate_is_default'] ? ' (house rate)' : ' (set for this part)') ?>
                                </span>
                            </td>
                            <td><?= format_money($quoteResult['machine_cost']) ?></td>
                        </tr>
                        <tr>
                            <td>Material <span class="quote-detail">from the workshop details above</span></td>
                            <td><?= format_money($quoteResult['material_cost']) ?></td>
                        </tr>
                        <?php foreach ($quoteLines as $line): ?>
                            <tr>
                                <td><?= e($line['label']) ?></td>
                                <td><?= format_money($line['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="is-subtotal">
                            <td>Subtotal</td>
                            <td><?= format_money($quoteResult['subtotal']) ?></td>
                        </tr>
                        <tr>
                            <td>
                                Mark-up
                                <span class="quote-detail">
                                    <?= e((string) $quoteResult['markup']) ?>%<?= $quoteResult['markup_is_default'] ? ' (house figure)' : ' (set for this part)' ?>
                                </span>
                            </td>
                            <td><?= format_money($quoteResult['markup_amount']) ?></td>
                        </tr>
                        <tr class="is-total">
                            <td>Draft Part Quote</td>
                            <td><?= format_money($quoteResult['total']) ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($quoteResult['missing_time']): ?>
                    <p class="field-hint">
                        <span class="badge badge-warn">No estimate</span>
                        There is no estimated build time on this part, so the draft contains no machine time
                        at all. <a href="#build-time">Set one</a> and this figure moves.
                    </p>
                <?php endif; ?>

                <?php if ($quoteDraft !== null && $quoteDraft['notes'] !== null && $quoteDraft['notes'] !== ''): ?>
                    <p class="field-hint" style="margin-top: var(--space-3)"><?= nl2br(e($quoteDraft['notes'])) ?></p>
                <?php endif; ?>

                <?php if ($quoteDraft !== null): ?>
                    <p class="field-hint">
                        Last worked on <?= e(format_datetime($quoteDraft['updated_at'])) ?><?php
                        ?><?= $quoteDraft['updated_by_name'] !== null ? ' by ' . e($quoteDraft['updated_by_name']) : '' ?>.
                        <?php if ($part['quoted_price'] !== null): ?>
                            The quoted price is <?= format_money($part['quoted_price']) ?>; set it in the Pricing panel.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php /* A client's own order history for their own part is theirs to see. */ ?>
        <div class="card">
            <h2 class="mt-0">Order history</h2>
            <?php if ($orderLines === []): ?>
                <p class="empty-state mb-0">This part has never been ordered.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($orderLines as $line): ?>
                        <li>
                            <span>
                                <a href="<?= url(($isStaff ? '/staff/orders/' : '/orders/') . $line['order_id']) ?>"><?= e($line['order_number']) ?></a>
                                — qty <?= (int) $line['qty_ordered'] ?>
                            </span>
                            <?= status_badge(OrderLine::headlineStage($line)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="detail-rail">
        <div class="card">
            <h2 class="mt-0">At a glance</h2>
            <div class="summary-list">
                <?php if ($isStaff): ?>
                    <div class="summary-row">
                        <span class="summary-key">Client</span>
                        <span class="summary-value"><a href="<?= url('/staff/clients/' . $part['client_id']) ?>"><?= e($part['client_name']) ?></a></span>
                    </div>
                <?php endif; ?>
                <div class="summary-row">
                    <span class="summary-key">Status</span>
                    <span class="summary-value"><?= status_badge($part['status']) ?></span>
                </div>
                <?php if ($canSeePricing): ?>
                    <div class="summary-row">
                        <span class="summary-key">Quoted price</span>
                        <span class="summary-value">
                            <?= $part['quoted_price'] !== null ? format_money($part['quoted_price']) : '<span class="text-muted">not yet quoted</span>' ?>
                            <?php /*
                                Sits with the price, because a price shown
                                without it is the thing being warned about.
                            */ ?>
                            <?php if ((bool) $part['price_under_review']): ?>
                                <span class="badge badge-warn">Under review</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ((bool) $part['price_under_review']): ?>
                        <p class="field-hint mb-0">
                            Junction expects this price to change on the next order. The figure above still
                            applies to anything ordered now.
                        </p>
                    <?php endif; ?>

                    <?php /*
                        The client's own target price disappears from their view
                        once Junction has quoted: the quote supersedes it, and
                        two prices side by side is the sort of thing somebody
                        reads the wrong one of. It stays editable on the edit
                        form, because next time round it is the useful number
                        again. Junction always sees both — the gap between what
                        was hoped for and what was quoted is the conversation.
                    */ ?>
                    <?php if ($isStaff || $part['quoted_price'] === null): ?>
                        <div class="summary-row">
                            <span class="summary-key">Target price</span>
                            <span class="summary-value"><?= $part['target_price'] !== null ? format_money($part['target_price']) : '—' ?></span>
                        </div>
                    <?php endif; ?>

                    <?php
                    /*
                        The cheapest break, stated beside the headline price so
                        the price in the rail is not quietly wrong for anybody
                        ordering in quantity. The full list is in the Pricing
                        panel; this is the one line that says there is one.
                    */
                    $bestQuoted = PartPriceBreak::best($priceBreaks['quoted'] ?? []);
                    ?>
                    <?php if ($bestQuoted !== null): ?>
                        <div class="summary-row">
                            <span class="summary-key">At quantity</span>
                            <span class="summary-value">
                                from <?= format_money($bestQuoted['price']) ?> at <?= (int) $bestQuoted['qty'] ?>+
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="summary-row">
                    <span class="summary-key">Usual order qty</span>
                    <span class="summary-value"><?= e((string) ($part['usual_order_qty'] ?? '—')) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-key">Free issue</span>
                    <span class="summary-value"><?= Part::hasFreeIssue($part) ? 'Yes' : 'No' ?></span>
                </div>
            </div>
        </div>

        <?php if ($canSeePricing): ?>
        <div class="card" id="pricing">
            <h2 class="mt-0">Pricing</h2>

            <?php if ($isStaff): ?>
                <?php if (Auth::can('set_pricing')): ?>
                    <form method="post" action="<?= url($staffBase . '/price') ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="quoted_price">Quoted price</label>
                            <input type="number" step="0.01" min="0" id="quoted_price" name="quoted_price" value="<?= e((string) ($part['quoted_price'] ?? '')) ?>" required>
                            <div class="hint">Client-visible once set, and what an order line is priced at.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Set quoted price</button>
                    </form>
                <?php else: ?>
                    <p class="mb-0"><?= format_money($part['quoted_price']) ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            /*
                Price breaks, both kinds, using the same editor as the build
                times. Freely settable pairs rather than fixed tiers: a part run
                in 12s and 250s wants breaks at 12 and 250, and a tier table of
                1/10/50/100/500 would have neither.

                A break's quantity is where its price starts applying. The
                part's own price still governs below the first break, and is
                still what an order line is priced at — see the note under the
                list.
            */
            $breakKinds = [];
            if ($isStaff) {
                // Junction may set the client's target as it may set the
                // target price itself, and its own quote.
                if (Auth::can('create_client_parts')) {
                    $breakKinds['target'] = $staffBase . '/price-breaks/target';
                }
                if (Auth::can('set_pricing')) {
                    $breakKinds['quoted'] = $staffBase . '/price-breaks/quoted';
                }
            } elseif ($canManageClientPart) {
                $breakKinds['target'] = $clientBase . '/price-breaks';
            }

            // Staff read both; a client reads their own target, and Junction's
            // quoted breaks once there is a quote to go with them.
            $visibleKinds = $isStaff ? PartPriceBreak::KINDS : ['target', 'quoted'];
            ?>

            <?php foreach ($visibleKinds as $kind):
                $breaks = $priceBreaks[$kind] ?? [];
                $basePrice = $kind === 'target' ? $part['target_price'] : $part['quoted_price'];

                // A client has no business seeing an empty quoted-breaks
                // section on a part Junction has not priced.
                if (!$isStaff && $kind === 'quoted' && $breaks === []) {
                    continue;
                }
                ?>
                <div class="line-section">
                    <h3 class="line-section-title"><?= e(PartPriceBreak::KIND_LABELS[$kind]) ?></h3>

                    <?php if ($breaks === []): ?>
                        <p class="text-muted mb-0">
                            One price at any quantity<?= $basePrice !== null ? ' — ' . format_money($basePrice) : '' ?>.
                        </p>
                    <?php else: ?>
                        <ul class="price-break-list">
                            <?php if ($basePrice !== null): ?>
                                <li>
                                    <span class="price-break-qty">1<?= (int) $breaks[0]['qty'] > 2 ? '–' . ((int) $breaks[0]['qty'] - 1) : '' ?></span>
                                    <span><?= format_money($basePrice) ?></span>
                                </li>
                            <?php endif; ?>
                            <?php foreach ($breaks as $index => $break):
                                $next = $breaks[$index + 1] ?? null;
                                $range = $next === null
                                    ? (int) $break['qty'] . '+'
                                    : (int) $break['qty'] . '–' . ((int) $next['qty'] - 1);
                                ?>
                                <li>
                                    <span class="price-break-qty"><?= e($range) ?></span>
                                    <span><?= format_money($break['price']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (isset($breakKinds[$kind])): ?>
                        <div style="margin-top: var(--space-3)">
                            <?= partial('partials/row-editor', [
                                'id' => 'breaks_' . $kind,
                                'title' => PartPriceBreak::KIND_LABELS[$kind] . ' — ' . $part['cpn'],
                                'action' => $breakKinds[$kind],
                                'trigger' => $breaks === [] ? 'Add price breaks' : 'Edit price breaks',
                                'intro' => 'A quantity, and the price each from that quantity upward. '
                                    . 'Set whatever quantities are actually ordered — there are no fixed tiers.',
                                'columns' => [
                                    ['name' => 'break_qty', 'label' => 'From quantity', 'type' => 'number', 'placeholder' => 'Qty',
                                     'min' => '1', 'step' => '1', 'width' => 'narrow'],
                                    ['name' => 'break_price', 'label' => 'Price each', 'type' => 'number', 'placeholder' => 'Price',
                                     'step' => '0.01', 'min' => '0', 'width' => 'narrow'],
                                ],
                                'rows' => array_map(
                                    static fn (array $b): array => ['break_qty' => $b['qty'], 'break_price' => $b['price']],
                                    $breaks
                                ),
                                'footnote' => 'Two rows at the same quantity is a contradiction rather than a break, '
                                    . 'so the last one entered wins.',
                            ]) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($isStaff && ($priceBreaks['quoted'] ?? []) !== []): ?>
                <?php /*
                    Said plainly rather than left to be discovered on an
                    invoice. Applying a break to an order line changes what is
                    billed, which is not a thing to start doing quietly as a
                    side effect of adding a table.
                */ ?>
                <p class="field-hint">
                    <span class="badge badge-warn">Not applied automatically</span>
                    Order lines are still priced at the quoted price above. These breaks are recorded and
                    shown; nothing prices an order from them yet.
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2 class="mt-0">Actions</h2>
            <div class="rail-actions">
                <?php if (!$isStaff && $part['status'] === 'quoted' && !$part['is_archived'] && Auth::can('place_orders')): ?>
                    <a href="<?= url('/orders/new?part=' . $part['id']) ?>" class="btn btn-primary">Order this part</a>
                <?php endif; ?>
                <?php if ($isStaff && Auth::can('raise_orders') && $part['status'] === 'quoted' && !$part['is_archived']): ?>
                    <a href="<?= url('/staff/orders/new?client_id=' . $part['client_id'] . '&part=' . $part['id']) ?>" class="btn btn-primary">
                        Order this part
                    </a>
                <?php endif; ?>

                <?php if (!$part['is_archived'] && \App\Services\PartForm::canEditAnything()): ?>
                    <a href="<?= url($here . '/edit') ?>" class="btn">Edit part</a>
                <?php endif; ?>

                <a href="<?= url($isStaff ? '/staff/parts' : '/parts') ?>" class="btn">All parts</a>

                <?php if ($canManageClientPart || $canManageStaffPart): ?>
                    <form method="post" action="<?= url($here . '/archive') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn"><?= $part['is_archived'] ? 'Unarchive' : 'Archive' ?></button>
                    </form>
                    <form method="post" action="<?= url($here . '/delete') ?>"
                          onsubmit="return confirm('Delete this part permanently? This only works if it has never been ordered.');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($linkedParts !== []): ?>
        <div class="card">
            <h2 class="mt-0">Usually ordered with</h2>
            <ul class="plain-list">
                <?php foreach ($linkedParts as $linked): ?>
                    <li>
                        <a href="<?= url(($isStaff ? '/staff/parts/' : '/parts/') . $linked['id']) ?>">
                            <?= e($linked['cpn']) ?> — <?= e($linked['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2 class="mt-0">Housekeeping</h2>
            <div class="summary-list">
                <div class="summary-row">
                    <span class="summary-key">Created</span>
                    <span class="summary-value"><?= format_datetime($part['created_at']) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-key">Created by</span>
                    <span class="summary-value"><?= e($part['created_by_name'] ?? '—') ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-key">Last modified</span>
                    <span class="summary-value"><?= format_datetime($part['updated_at']) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-key">Modified by</span>
                    <span class="summary-value">
                        <?= $part['updated_by_name'] !== null
                            ? e($part['updated_by_name'])
                            : '<span class="text-muted">not recorded</span>' ?>
                    </span>
                </div>
                <?php if ($canSeePricing && $part['quoted_price_set_at'] !== null): ?>
                    <div class="summary-row">
                        <span class="summary-key">Priced</span>
                        <span class="summary-value">
                            <?= format_date($part['quoted_price_set_at']) ?>
                            <?= $part['quoted_price_set_by_name'] !== null ? 'by ' . e($part['quoted_price_set_by_name']) : '' ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
