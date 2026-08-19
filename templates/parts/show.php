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
 */
use App\Core\Auth;
use App\Models\OrderLine;
use App\Models\Part;

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
            ]) ?>
        </div>

        <?php if ($canEditWorkshop): ?>
        <div class="card">
            <h2 class="mt-0">Junction-only workshop details</h2>
            <form method="post" action="<?= url($staffBase . '/workshop-fields') ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="field"><label for="build_time_minutes">Build time (minutes)</label><input type="number" min="0" id="build_time_minutes" name="build_time_minutes" value="<?= e((string) ($part['build_time_minutes'] ?? '')) ?>"></div>
                    <div class="field"><label for="base_material">Base material</label><input type="text" id="base_material" name="base_material" value="<?= e($part['base_material'] ?? '') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="field"><label for="material_source">Material source</label><input type="text" id="material_source" name="material_source" value="<?= e($part['material_source'] ?? '') ?>"></div>
                    <?php if ($canSeePricing): ?>
                        <div class="field"><label for="material_cost">Material cost</label><input type="number" step="0.01" min="0" id="material_cost" name="material_cost" value="<?= e((string) ($part['material_cost'] ?? '')) ?>"></div>
                    <?php endif; ?>
                </div>

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

        <?php if ($isStaff && $canSeePricing): ?>
        <div class="card">
            <h2 class="mt-0">Pricing</h2>
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
