<?php
/** @var array $part */ /** @var array $files */ /** @var array $photos */ /** @var array $altNumbers */
/** @var array $freeIssueMaterials */ /** @var array $linkedParts */ /** @var bool $canManage */
use App\Core\Auth;
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($part['cpn']) ?> <?= status_badge($part['status']) ?> <?php if ($part['is_archived']): ?><span class="badge badge-muted">Archived</span><?php endif; ?></h1>
        <p class="text-muted mb-0"><?= e($part['name']) ?></p>
    </div>
    <div style="display:flex; gap: var(--space-2)">
        <?php if ($part['status'] === 'quoted' && !Auth::isStaff() && !$part['is_archived']): ?>
            <a href="<?= url('/orders/new?part=' . $part['id']) ?>" class="btn btn-primary">Order this part</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a href="<?= url('/parts/' . $part['id'] . '/edit') ?>" class="btn">Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2 class="mt-0">Details</h2>
        <p><strong>Description:</strong><br><?= nl2br(e($part['description'] ?: '—')) ?></p>
        <p><strong>Usual order quantity:</strong> <?= e((string) ($part['usual_order_qty'] ?? '—')) ?></p>
        <?php if (Auth::can('view_pricing')): ?>
            <p><strong>Target price:</strong> <?= format_money($part['target_price']) ?></p>
            <?php if ($part['status'] === 'quoted'): ?>
                <p><strong>Quoted price:</strong> <?= format_money($part['quoted_price']) ?></p>
            <?php else: ?>
                <p class="text-muted">Awaiting a quoted price from Junction.</p>
            <?php endif; ?>
        <?php endif; ?>
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
        <?php if (!\App\Models\Part::hasFreeIssue($part)): ?>
            <p class="text-muted">No free-issue material required — Junction supplies the material for this part.</p>
        <?php else: ?>
            <?php if ($freeIssueMaterials !== []): ?>
                <ul>
                    <?php foreach ($freeIssueMaterials as $m): ?>
                        <li><?= e($m['reference']) ?><?= $m['notes'] ? ' — ' . e($m['notes']) : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="text-muted">
                <?php if ($part['free_issue_relationship'] === 'divide'): ?>
                    1 piece of free-issue material makes <?= (int) $part['free_issue_factor'] ?> of this part.
                <?php elseif ($part['free_issue_relationship'] === 'multiply'): ?>
                    <?= (int) $part['free_issue_factor'] ?> pieces of free-issue material are needed per part.
                <?php else: ?>
                    1 piece of free-issue material per part.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($canManage && !$part['is_archived']): ?>
            <div style="margin-top: var(--space-5); display:flex; gap: var(--space-2)">
                <form method="post" action="<?= url('/parts/' . $part['id'] . '/archive') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Archive</button>
                </form>
                <form method="post" action="<?= url('/parts/' . $part['id'] . '/delete') ?>" onsubmit="return confirm('Delete this part permanently? This only works if it has never been ordered.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </div>
        <?php elseif ($canManage && $part['is_archived']): ?>
            <form method="post" action="<?= url('/parts/' . $part['id'] . '/archive') ?>" style="margin-top: var(--space-5)">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Unarchive</button>
            </form>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <h2 class="mt-0">Drawings</h2>
            <?php if ($files === []): ?>
                <p class="text-muted">No drawings uploaded yet.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($files as $file): ?>
                        <li>
                            <span><?= e($file['original_filename']) ?> <span class="text-muted">v<?= (int) $file['version_no'] ?></span></span>
                            <a href="<?= url('/files/drawings/' . $file['id']) ?>" class="btn btn-sm">View</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <form method="post" action="<?= url('/parts/' . $part['id'] . '/files') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="drawings">Upload a new drawing / version</label>
                        <input type="file" id="drawings" name="drawings[]" multiple>
                    </div>
                    <button type="submit" class="btn">Upload</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="mt-0">Photos</h2>
            <p class="text-muted">Photos of the physical part, to help identify it.</p>
            <?php if ($photos === []): ?>
                <p class="text-muted">No photos uploaded yet.</p>
            <?php else: ?>
                <div style="display:flex; flex-wrap:wrap; gap: var(--space-3)">
                    <?php foreach ($photos as $photo): ?>
                        <div style="text-align:center">
                            <a href="<?= url('/files/part-media/' . $photo['id']) ?>" target="_blank" rel="noopener">
                                <img src="<?= url('/files/part-media/' . $photo['id']) ?>" alt="<?= e($part['cpn']) ?>" style="width:100px;height:100px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border)">
                            </a>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= url('/parts/' . $part['id'] . '/photos/' . $photo['id'] . '/delete') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <form method="post" action="<?= url('/parts/' . $part['id'] . '/photos') ?>" enctype="multipart/form-data" style="margin-top: var(--space-4)">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="photos">Upload photo(s)</label>
                        <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
                    </div>
                    <button type="submit" class="btn">Upload</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="mt-0">Usually ordered with</h2>
            <p class="text-muted">Parts your company normally orders alongside this one.</p>
            <?php if ($linkedParts === []): ?>
                <p class="text-muted">No linked parts yet.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($linkedParts as $linked): ?>
                        <li>
                            <a href="<?= url('/parts/' . $linked['id']) ?>"><?= e($linked['cpn']) ?> — <?= e($linked['name']) ?></a>
                            <?php if ($canManage): ?>
                                <form method="post" action="<?= url('/parts/' . $part['id'] . '/links/' . $linked['id'] . '/delete') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Unlink</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <div class="combobox" data-combobox data-url="<?= url('/parts/' . $part['id'] . '/link-search') ?>" style="margin-top: var(--space-4)">
                    <label for="link-search">Link another part</label>
                    <input type="text" id="link-search" data-combobox-input placeholder="Search by CPN or name…">
                    <div class="combobox-results" data-combobox-results></div>
                </div>
                <form method="post" action="<?= url('/parts/' . $part['id'] . '/links') ?>" id="link-part-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="linked_part_id" id="link-part-id">
                </form>
                <script>
                    document.querySelector('[data-combobox][data-url$="link-search"]')?.addEventListener('combobox:select', function (e) {
                        document.getElementById('link-part-id').value = e.detail.id;
                        document.getElementById('link-part-form').submit();
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
