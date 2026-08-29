<?php /** @var array $errors */ /** @var array $old */ ?>
<?= partial("partials/back-link", ["href" => "/parts", "label" => "Back to parts"]) ?>
<h1 class="mt-0">New part</h1>

<form method="post" action="<?= url('/parts') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="mt-0">Details</h2>
        <div class="form-row">
            <div class="field">
                <label for="cpn">Part number</label>
                <input type="text" id="cpn" name="cpn" value="<?= old($old, 'cpn') ?>" required
                       autocomplete="off" data-cpn-check="<?= url('/parts/cpn-check') ?>">
                <div class="hint">Your part number. This must be unique for any part in this system.</div>
                <?php /* Filled in by the live check as the number is typed — see app.js. */ ?>
                <div class="cpn-status" data-cpn-status role="status" aria-live="polite"></div>
                <?php if (isset($errors['cpn'])): ?><div class="error"><?= e($errors['cpn']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label for="name">Part Name</label>
                <input type="text" id="name" name="name" value="<?= old($old, 'name') ?>" required>
                <div class="hint">A short name or description for the part. Do not use a part number here.</div>
                <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label for="description">Further description</label>
            <textarea id="description" name="description"><?= old($old, 'description') ?></textarea>
        </div>
        <div class="field">
            <label for="target_price">Previous / target price</label>
            <input type="number" step="0.01" min="0" id="target_price" name="target_price" value="<?= old($old, 'target_price') ?>">
            <div class="hint">Informational — Junction will still set the official quoted price.</div>
        </div>

        <?= partial('partials/order-reference-fields', ['oldValues' => $old]) ?>
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

    <?php /*
        One drawing here, named. A part can carry several — a general
        arrangement and a detail per sub-component — but they are added from
        the part page afterwards, where each has room to say what it is. Asking
        for all of them on the form that creates the part would mean a set of
        repeating name-and-file pairs before anybody has even saved a CPN.
    */ ?>
    <div class="card">
        <h2 class="mt-0">Drawing</h2>
        <div class="field">
            <label for="drawing_name">What is it of?</label>
            <input type="text" id="drawing_name" name="drawing_name" maxlength="120"
                   value="<?= old($old, 'drawing_name', 'Main drawing') ?>"
                   placeholder="e.g. General arrangement">
            <div class="hint">
                A short name. More drawings can be added to this part once it exists, each with its own
                revisions.
            </div>
        </div>
        <div class="field">
            <label for="drawings">Upload drawing file(s)</label>
            <input type="file" id="drawings" name="drawings[]" multiple>
            <div class="hint">PDF, DWG, DXF, STEP, IGES, Word, Excel or image files, up to 25 MB each.</div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create part</button>
        <a href="<?= url('/parts') ?>" class="btn">Cancel</a>
    </div>
</form>
