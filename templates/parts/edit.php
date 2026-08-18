<?php /** @var array $part */ /** @var array $errors */ /** @var array $altNumbers */ /** @var array $freeIssueMaterials */ ?>
<h1 class="mt-0">Edit <?= e($part['cpn']) ?></h1>

<form method="post" action="<?= url('/parts/' . $part['id']) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="mt-0">Details</h2>
        <div class="form-row">
            <div class="field">
                <label>Client part number (CPN)</label>
                <input type="text" value="<?= e($part['cpn']) ?>" disabled>
                <div class="hint">CPN can't be changed once set. Archive and create a new part if it needs to change.</div>
            </div>
            <div class="field">
                <label for="name">Name / description</label>
                <input type="text" id="name" name="name" value="<?= e($part['name']) ?>" required>
                <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label for="description">Further description</label>
            <textarea id="description" name="description"><?= e($part['description'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
            <div class="field">
                <label for="usual_order_qty">Usual order quantity</label>
                <input type="number" min="1" id="usual_order_qty" name="usual_order_qty" value="<?= e((string) ($part['usual_order_qty'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="target_price">Previous / target price</label>
                <input type="number" step="0.01" min="0" id="target_price" name="target_price" value="<?= e((string) ($part['target_price'] ?? '')) ?>">
            </div>
        </div>
        <div class="field">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes"><?= e($part['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Alternate / additional part numbers</h2>
        <p class="text-muted">Saving replaces the full list below.</p>
        <?php $rows = $altNumbers !== [] ? $altNumbers : [['number' => '', 'label' => '']]; ?>
        <?php foreach ($rows as $n): ?>
            <div class="form-row">
                <div class="field"><input type="text" name="alt_number[]" value="<?= e($n['number']) ?>" placeholder="Number"></div>
                <div class="field"><input type="text" name="alt_label[]" value="<?= e($n['label'] ?? '') ?>" placeholder="e.g. Drawing no."></div>
            </div>
        <?php endforeach; ?>
        <div class="form-row">
            <div class="field"><input type="text" name="alt_number[]" placeholder="Number"></div>
            <div class="field"><input type="text" name="alt_label[]" placeholder="e.g. Drawing no."></div>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Free-issue material</h2>
        <?= partial('partials/free-issue-fields', [
            'hasFreeIssue' => (bool) $part['has_free_issue'],
            'relationship' => $part['free_issue_relationship'],
            'factor' => (int) $part['free_issue_factor'],
            'materials' => $freeIssueMaterials,
            'idPrefix' => 'client_fi',
        ]) ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="<?= url('/parts/' . $part['id']) ?>" class="btn">Cancel</a>
    </div>
</form>
