<?php
/** @var array $clients */ /** @var array $errors */ /** @var array $old */
?>
<?= partial("partials/back-link", ["href" => "/staff/parts", "label" => "Back to parts"]) ?>
<h1 class="mt-0">New part</h1>
<p class="text-muted">Raising a part on a client's behalf. It becomes an ordinary part on their account — they can see it, edit it and order against it exactly as if they had entered it themselves.</p>

<form method="post" action="<?= url('/staff/parts') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <h2 class="mt-0">Client</h2>
        <div class="field">
            <label for="client_id">Client</label>
            <select id="client_id" name="client_id" required>
                <option value="">Choose a client…</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>" <?= (int) ($old['client_id'] ?? 0) === (int) $client['id'] ? 'selected' : '' ?>>
                        <?= e($client['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['client_id'])): ?><div class="error"><?= e($errors['client_id']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2 class="mt-0">Details</h2>
        <div class="form-row">
            <div class="field">
                <label for="cpn">Client part number (CPN)</label>
                <input type="text" id="cpn" name="cpn" value="<?= old($old, 'cpn') ?>" required
                       autocomplete="off" data-cpn-check="<?= url('/staff/parts/cpn-check') ?>"
                       data-cpn-client="#client_id">
                <div class="hint">Their number for the part, as it appears on their drawing or purchase order.</div>
                <?php /* A CPN is unique per client, so this re-asks when the client above changes. */ ?>
                <div class="cpn-status" data-cpn-status role="status" aria-live="polite"></div>
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
                <div class="hint">The client-visible target, not the quoted price — set that from the part page once it exists.</div>
            </div>
        </div>
        <div class="field">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes"><?= old($old, 'notes') ?></textarea>
            <div class="hint">Client-visible. Workshop-only notes go on the part page after it is created.</div>
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
            'idPrefix' => 'staff_new_fi',
        ]) ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create part</button>
        <a href="<?= url('/staff/parts') ?>" class="btn">Cancel</a>
    </div>
</form>
