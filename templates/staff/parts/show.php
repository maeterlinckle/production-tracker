<?php
/** @var array $part */ /** @var array $files */ /** @var array|null $mainPhoto */
/** @var array $attachments */ /** @var array $altNumbers */
/** @var array $freeIssueMaterials */ /** @var array $linkedParts */ /** @var array $orderLines */
use App\Core\Auth;
use App\Models\OrderLine;
use App\Models\Part;
use App\Models\PartMedia;

$canEditWorkshop = Auth::can('edit_workshop_fields');
?>
<div class="card-header">
    <div style="display:flex; gap: var(--space-3); align-items:center">
        <?php if ($mainPhoto !== null): ?>
            <a href="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" target="_blank" rel="noopener">
                <img class="part-hero" src="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" alt="<?= e($part['cpn']) ?>">
            </a>
        <?php endif; ?>
        <div>
            <h1 class="mt-0 mb-0"><?= e($part['cpn']) ?> <?= status_badge($part['status']) ?></h1>
            <p class="text-muted mb-0"><?= e($part['client_name']) ?> — <?= e($part['name']) ?></p>
        </div>
    </div>
    <?php if (Auth::can('raise_orders') && $part['status'] === 'quoted' && !$part['is_archived']): ?>
        <a href="<?= url('/staff/orders/new?client_id=' . $part['client_id'] . '&part=' . $part['id']) ?>" class="btn btn-primary">
            Order this part
        </a>
    <?php endif; ?>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2 class="mt-0">Client-visible details</h2>
        <p><strong>Description:</strong><br><?= nl2br(e($part['description'] ?: '—')) ?></p>
        <p><strong>Usual order quantity:</strong> <?= e((string) ($part['usual_order_qty'] ?? '—')) ?></p>
        <?php if (Auth::can('view_pricing')): ?>
            <p><strong>Target price:</strong> <?= format_money($part['target_price']) ?></p>
        <?php endif; ?>
        <p><strong>Client notes:</strong><br><?= nl2br(e($part['notes'] ?: '—')) ?></p>

        <?php if ($altNumbers !== []): ?>
            <h3>Alternate numbers</h3>
            <ul><?php foreach ($altNumbers as $n): ?><li><?= e($n['number']) ?><?= $n['label'] ? ' (' . e($n['label']) . ')' : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3>Free-issue material</h3>
        <?php if (!Part::hasFreeIssue($part)): ?>
            <p class="text-muted">No free-issue material required.</p>
        <?php elseif ($freeIssueMaterials === []): ?>
            <p class="text-muted">Free-issue, but no source material has been named yet.</p>
        <?php else: ?>
            <ul><?php foreach ($freeIssueMaterials as $m): ?><li><?= e($m['reference']) ?><?= $m['notes'] ? ' — ' . e($m['notes']) : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <?php if ($linkedParts !== []): ?>
            <h3>Usually ordered with</h3>
            <ul><?php foreach ($linkedParts as $linked): ?><li><a href="<?= url('/staff/parts/' . $linked['id']) ?>"><?= e($linked['cpn']) ?> — <?= e($linked['name']) ?></a></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <h3>Drawings</h3>
        <?php if ($files === []): ?>
            <p class="text-muted">No drawings uploaded.</p>
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

        <?php if ($canEditWorkshop): ?>
            <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/drawings') ?>" enctype="multipart/form-data" style="margin-top: var(--space-3)">
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

    <div>
        <?php if (Auth::can('view_pricing')): ?>
        <div class="card">
            <h2 class="mt-0">Pricing (client-visible once set)</h2>
            <?php if (Auth::can('set_pricing')): ?>
                <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/price') ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="quoted_price">Quoted price</label>
                        <input type="number" step="0.01" min="0" id="quoted_price" name="quoted_price" value="<?= e((string) ($part['quoted_price'] ?? '')) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Set quoted price</button>
                </form>
            <?php else: ?>
                <p><?= format_money($part['quoted_price']) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (Auth::can('edit_workshop_fields')): ?>
        <div class="card">
            <h2 class="mt-0">Junction-only workshop details</h2>
            <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/workshop-fields') ?>">
                <?= csrf_field() ?>
                <div class="field"><label for="build_time_minutes">Build time (minutes)</label><input type="number" min="0" id="build_time_minutes" name="build_time_minutes" value="<?= e((string) ($part['build_time_minutes'] ?? '')) ?>"></div>
                <div class="field"><label for="base_material">Base material</label><input type="text" id="base_material" name="base_material" value="<?= e($part['base_material'] ?? '') ?>"></div>
                <div class="field"><label for="material_source">Material source</label><input type="text" id="material_source" name="material_source" value="<?= e($part['material_source'] ?? '') ?>"></div>
                <?php if (Auth::can('view_pricing')): ?>
                    <div class="field"><label for="material_cost">Material cost</label><input type="number" step="0.01" min="0" id="material_cost" name="material_cost" value="<?= e((string) ($part['material_cost'] ?? '')) ?>"></div>
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
        <?php endif; ?>

        <?php /*
            Reference material for the part rather than for one order. It lived
            on the order, where it was invisible to whoever set the same part up
            six months later — which is precisely when it is wanted.
        */ ?>
        <div class="card">
            <h2 class="mt-0" id="setup">Setup and reference</h2>
            <p class="text-muted">
                Everything here belongs to the part, so it is in front of whoever runs it next — every
                order of it, not just the one it was added on.
            </p>

            <?php if ($mainPhoto !== null): ?>
                <h3 class="mt-0">Main photo</h3>
                <div class="media-grid">
                    <figure class="media-tile">
                        <a href="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" target="_blank" rel="noopener">
                            <img src="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" alt="<?= e($part['cpn']) ?>">
                        </a>
                        <figcaption>
                            <?= e($mainPhoto['caption'] ?? '') ?: '<span class="text-muted">The finished part</span>' ?>
                            <?php if ($canEditWorkshop): ?>
                                <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/media/' . $mainPhoto['id'] . '/delete') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Remove</button>
                                </form>
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                </div>
            <?php endif; ?>

            <?php if ($attachments === [] && $mainPhoto === null): ?>
                <p class="empty-state mb-0">Nothing attached to this part yet.</p>
            <?php endif; ?>

            <?php foreach ($attachments as $kind => $items): ?>
                <h3><?= e(PartMedia::KIND_LABELS[$kind]) ?></h3>
                <?php $images = array_values(array_filter($items, static fn ($i) => PartMedia::isImage($i))); ?>
                <?php $others = array_values(array_filter($items, static fn ($i) => !PartMedia::isImage($i))); ?>

                <?php if ($images !== []): ?>
                    <div class="media-grid">
                        <?php foreach ($images as $item): ?>
                            <figure class="media-tile">
                                <a href="<?= url('/files/part-media/' . $item['id']) ?>" target="_blank" rel="noopener">
                                    <img src="<?= url('/files/part-media/' . $item['id']) ?>" alt="<?= e($item['caption'] ?? $item['original_filename']) ?>">
                                </a>
                                <figcaption>
                                    <?= e($item['caption'] ?? '') ?: e($item['original_filename']) ?>
                                    <?php if ($canEditWorkshop): ?>
                                        <span class="media-actions">
                                            <?php if ($item['kind'] === 'photo'): ?>
                                                <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/media/' . $item['id'] . '/main') ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm">Make main</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/media/' . $item['id'] . '/delete') ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm">Remove</button>
                                            </form>
                                        </span>
                                    <?php endif; ?>
                                </figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($others !== []): ?>
                    <ul class="file-list">
                        <?php foreach ($others as $item): ?>
                            <li>
                                <span>
                                    <?= e($item['original_filename']) ?>
                                    <?php if ($item['caption']): ?><span class="text-muted">— <?= e($item['caption']) ?></span><?php endif; ?>
                                </span>
                                <span class="media-actions">
                                    <a href="<?= url('/files/part-media/' . $item['id']) ?>" class="btn btn-sm" target="_blank" rel="noopener">Open</a>
                                    <?php if ($canEditWorkshop): ?>
                                        <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/media/' . $item['id'] . '/delete') ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($canEditWorkshop): ?>
                <form method="post" action="<?= url('/staff/parts/' . $part['id'] . '/media') ?>" enctype="multipart/form-data" style="margin-top: var(--space-5)">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="field">
                            <label for="media_kind">What is it?</label>
                            <select id="media_kind" name="kind" data-media-kind>
                                <?php foreach (PartMedia::KINDS as $kindOption): ?>
                                    <option value="<?= e($kindOption) ?>"><?= e(PartMedia::KIND_LABELS[$kindOption]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint" data-media-hint><?= e(PartMedia::KIND_HINTS['photo']) ?></div>
                        </div>
                        <div class="field">
                            <label for="media_caption">Caption (optional)</label>
                            <input type="text" id="media_caption" name="caption" placeholder="e.g. Op 20 fixture, soft jaws">
                        </div>
                    </div>
                    <div class="field">
                        <label for="media_files">File(s)</label>
                        <input type="file" id="media_files" name="files[]" multiple>
                    </div>
                    <label class="checkbox-label" data-media-main>
                        <input type="checkbox" name="is_main" value="1">
                        <span>Use as the part's main photo</span>
                    </label>
                    <button type="submit" class="btn" style="margin-top: var(--space-3)">Add to this part</button>
                </form>

                <script>
                (function () {
                    var hints = <?= json_encode(PartMedia::KIND_HINTS) ?>;
                    var select = document.querySelector('[data-media-kind]');
                    var hint = document.querySelector('[data-media-hint]');
                    var mainToggle = document.querySelector('[data-media-main]');
                    if (!select) return;

                    select.addEventListener('change', function () {
                        hint.textContent = hints[select.value] || '';
                        // Only a photo can be the part's representative image.
                        mainToggle.hidden = select.value !== 'photo';
                    });
                })();
                </script>
            <?php endif; ?>
        </div>

        <?php if ($orderLines !== []): ?>
        <div class="card">
            <h2 class="mt-0">Order history</h2>
            <ul class="file-list">
                <?php foreach ($orderLines as $line): ?>
                    <li><span><?= e($line['order_number']) ?> — qty <?= (int) $line['qty_ordered'] ?></span> <?= status_badge(OrderLine::headlineStage($line)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
