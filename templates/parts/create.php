<?php /** @var array $errors */ /** @var array $old */ ?>
<?= partial("partials/back-link", ["href" => "/parts", "label" => "Back to parts"]) ?>
<h1 class="mt-0">New part</h1>

<form method="post" action="<?= url('/parts') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="mt-0">Details</h2>
        <div class="form-row">
            <div class="field">
                <label for="cpn">Client part number (CPN)</label>
                <input type="text" id="cpn" name="cpn" value="<?= old($old, 'cpn') ?>" required>
                <?php if (isset($errors['cpn'])): ?><div class="error"><?= e($errors['cpn']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label for="name">Name / description</label>
                <input type="text" id="name" name="name" value="<?= old($old, 'name') ?>" required>
                <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label for="description">Further description</label>
            <textarea id="description" name="description"><?= old($old, 'description') ?></textarea>
        </div>
        <div class="form-row">
            <div class="field">
                <label for="usual_order_qty">Usual order quantity</label>
                <input type="number" min="1" id="usual_order_qty" name="usual_order_qty" value="<?= old($old, 'usual_order_qty') ?>">
            </div>
            <div class="field">
                <label for="target_price">Previous / target price</label>
                <input type="number" step="0.01" min="0" id="target_price" name="target_price" value="<?= old($old, 'target_price') ?>">
                <div class="hint">Informational — Junction will still set the official quoted price.</div>
            </div>
        </div>
        <div class="field">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes"><?= old($old, 'notes') ?></textarea>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Alternate / additional part numbers</h2>
        <div class="form-row">
            <div class="field"><label>Number</label><input type="text" name="alt_number[]"></div>
            <div class="field"><label>Label (optional)</label><input type="text" name="alt_label[]" placeholder="e.g. Drawing no."></div>
        </div>
        <div class="form-row">
            <div class="field"><input type="text" name="alt_number[]"></div>
            <div class="field"><input type="text" name="alt_label[]" placeholder="e.g. Drawing no."></div>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Free-issue material</h2>
        <?= partial('partials/free-issue-fields', [
            'hasFreeIssue' => !empty($old['has_free_issue']),
            'relationship' => $old['free_issue_relationship'] ?? 'none',
            'factor' => (int) ($old['free_issue_factor'] ?? 1),
            'materials' => [],
            'idPrefix' => 'client_fi',
        ]) ?>
    </div>

    <div class="card">
        <h2 class="mt-0">Drawing(s)</h2>
        <div class="field">
            <label for="drawings">Upload drawing file(s)</label>
            <input type="file" id="drawings" name="drawings[]" multiple>
            <div class="hint">PDF, DWG, DXF, STEP, IGES or image files, up to 25 MB each.</div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create part</button>
        <a href="<?= url('/parts') ?>" class="btn">Cancel</a>
    </div>
</form>
