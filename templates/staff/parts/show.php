<?php
/** @var array $part */ /** @var array $files */ /** @var array $photos */ /** @var array $altNumbers */
/** @var array $freeIssueMaterials */ /** @var array $linkedParts */ /** @var array $orderLines */
use App\Core\Auth;
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($part['cpn']) ?> <?= status_badge($part['status']) ?></h1>
        <p class="text-muted mb-0"><?= e($part['client_name']) ?> — <?= e($part['name']) ?></p>
    </div>
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
        <?php if ($freeIssueMaterials !== []): ?>
            <h3>Free-issue material</h3>
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
                    <li><span><?= e($file['original_filename']) ?> v<?= (int) $file['version_no'] ?></span>
                        <a href="<?= url('/files/drawings/' . $file['id']) ?>" class="btn btn-sm">View</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($photos !== []): ?>
            <h3>Photos</h3>
            <div style="display:flex; flex-wrap:wrap; gap: var(--space-3)">
                <?php foreach ($photos as $photo): ?>
                    <a href="<?= url('/files/part-photos/' . $photo['id']) ?>" target="_blank" rel="noopener">
                        <img src="<?= url('/files/part-photos/' . $photo['id']) ?>" alt="<?= e($part['cpn']) ?>" style="width:90px;height:90px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border)">
                    </a>
                <?php endforeach; ?>
            </div>
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

                <?= partial('partials/free-issue-relationship', [
                    'relationship' => $part['free_issue_relationship'],
                    'factor' => (int) $part['free_issue_factor'],
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

        <?php if ($orderLines !== []): ?>
        <div class="card">
            <h2 class="mt-0">Order history</h2>
            <ul class="file-list">
                <?php foreach ($orderLines as $line): ?>
                    <li><span><?= e($line['order_number']) ?> — qty <?= (int) $line['qty_ordered'] ?></span> <?= status_badge($line['stage']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
