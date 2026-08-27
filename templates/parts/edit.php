<?php
/**
 * The part edit form, for both audiences.
 *
 * One form, rendered at `/parts/{id}/edit` for the client and
 * `/staff/parts/{id}/edit` for Junction. Which fieldsets appear is decided by
 * what the person can actually change — and every one of those decisions is
 * made again on the server in App\Services\PartForm, because a hidden field is
 * not a locked one.
 *
 * @var array $part
 * @var array $errors
 * @var array $altNumbers
 * @var array $freeIssueMaterials
 */
use App\Core\Auth;
use App\Models\Part;
use App\Services\PartForm;

$isStaff = Auth::isStaff();
$canEditClientFields = PartForm::canEditClientFields();
$canEditWorkshop = PartForm::canEditWorkshopFields();
$canSetPricing = PartForm::canSetPricing();
$canSeePricing = Auth::can('view_pricing');

$action = $isStaff ? '/staff/parts/' . $part['id'] : '/parts/' . $part['id'];
$cancel = $action;
?>
<?php /* Back to the part itself — the only page this form is ever opened from. */ ?>
<?= partial('partials/back-link', ['href' => $cancel, 'label' => 'Back to ' . $part['cpn']]) ?>

<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0">Edit <?= e($part['cpn']) ?></h1>
        <p class="text-muted mb-0">
            <?php if ($isStaff): ?><?= e($part['client_name']) ?> — <?php endif; ?><?= e($part['name']) ?>
        </p>
    </div>
</div>

<form method="post" action="<?= url($action) ?>">
    <?= csrf_field() ?>

    <?php if ($canEditClientFields): ?>
        <div class="card">
            <h2 class="mt-0">Details</h2>
            <div class="form-row">
                <div class="field">
                    <label>Client part number (CPN)</label>
                    <input type="text" value="<?= e($part['cpn']) ?>" disabled>
                    <div class="hint">
                        The CPN cannot be changed once set — orders, drawings and paperwork all refer to it.
                        Archive this part and create a new one if it really has to change.
                    </div>
                </div>
                <div class="field">
                    <label for="name">Part Name</label>
                    <input type="text" id="name" name="name" value="<?= e($part['name']) ?>" required>
                    <div class="hint">A short name or description for the part. Do not use a part number here.</div>
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
                    <?php if (isset($errors['usual_order_qty'])): ?><div class="error"><?= e($errors['usual_order_qty']) ?></div><?php endif; ?>
                </div>
                <?php if ($canSeePricing): ?>
                    <div class="field">
                        <label for="target_price">Previous / target price</label>
                        <input type="number" step="0.01" min="0" id="target_price" name="target_price" value="<?= e((string) ($part['target_price'] ?? '')) ?>">
                        <?php /*
                            Editable whatever the quote says. It disappears from
                            the client's read-only view once Junction has priced
                            the part, because the quote supersedes it — but next
                            time round it is the useful number again, so this is
                            where it stays changeable.
                        */ ?>
                        <div class="hint">
                            What you hope to pay. Junction's quoted price is what an order is actually
                            priced at<?= $part['quoted_price'] !== null && !$isStaff ? ', and this is no longer shown on the part page now one is set' : '' ?>.
                        </div>
                        <?php if (isset($errors['target_price'])): ?><div class="error"><?= e($errors['target_price']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"><?= e($part['notes'] ?? '') ?></textarea>
                <?php if ($isStaff): ?><div class="hint">Client-visible. Junction's own notes are further down.</div><?php endif; ?>
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
                'hasFreeIssue' => Part::hasFreeIssue($part),
                'relationship' => $part['free_issue_relationship'],
                'factor' => (int) $part['free_issue_factor'],
                'materials' => $freeIssueMaterials,
                'idPrefix' => 'edit_fi',
                'showOverrideNote' => $isStaff,
            ]) ?>
        </div>
    <?php endif; ?>

    <?php if ($canSetPricing): ?>
        <div class="card">
            <h2 class="mt-0">Quoted price</h2>
            <div class="field">
                <label for="quoted_price">Quoted price</label>
                <input type="number" step="0.01" min="0" id="quoted_price" name="quoted_price" value="<?= e((string) ($part['quoted_price'] ?? '')) ?>">
                <div class="hint">
                    Client-visible once set, and what an order line is priced at. Setting it for the first
                    time is what makes the part orderable; clearing the box here does not un-quote it.
                </div>
            </div>

            <?php /*
                A warning rather than a change. The price above stays the price
                until somebody sets a new one — this just stops a client
                committing to a quantity in the belief that it is settled.
            */ ?>
            <label class="checkbox-label" for="price_under_review">
                <input type="checkbox" id="price_under_review" name="price_under_review" value="1"
                       <?= (bool) $part['price_under_review'] ? 'checked' : '' ?>>
                <span>Update price on next order</span>
            </label>
            <p class="field-hint">
                Flags to the client that this price may change next time they order. It does not alter the
                price, and it is shown to them on the part page and again as they add the part to an order.
                Clear it when the new price is set.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($canEditWorkshop): ?>
        <div class="card">
            <h2 class="mt-0">Junction-only workshop details</h2>
            <p class="text-muted">Never shown to the client.</p>
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
            <div class="field">
                <label for="internal_notes">Internal notes</label>
                <textarea id="internal_notes" name="internal_notes"><?= e($part['internal_notes'] ?? '') ?></textarea>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="<?= url($cancel) ?>" class="btn">Cancel</a>
    </div>
</form>
