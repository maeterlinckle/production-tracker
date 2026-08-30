<?php
/**
 * How this part is ordered: the usual multiple, what is expected next, and
 * what the last order was.
 *
 * Shared by the client's new-part form, their edit form, and Junction's
 * create-on-behalf form, so the three cannot drift into asking different
 * questions or labelling the same box two ways.
 *
 * All of it is the client's own reference data. Junction can set it on their
 * behalf, exactly as with the target price.
 *
 * @var array       $values     stored values, keyed by column name (edit forms)
 * @var array|null  $oldValues  what a rejected save sent back (create forms)
 * @var array       $errors     field => message (optional)
 * @var bool        $showPricing whether the money field is offered at all
 */
use App\Core\Auth;

$values = $values ?? [];
$oldValues = $oldValues ?? null;
$errors = $errors ?? [];
$showPricing = $showPricing ?? Auth::can('view_pricing');

/**
 * A value for the box, already escaped: whatever came back from a rejected
 * save, else what is stored. `old()` escapes on the way out, so this returns
 * markup-safe text either way and the fields below do not escape again.
 */
$value = static function (string $field) use ($values, $oldValues): string {
    return $oldValues !== null
        ? old($oldValues, $field)
        : e((string) ($values[$field] ?? ''));
};

$error = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="error">' . e($errors[$field]) . '</div>'
        : '';
};
?>
<div class="form-row">
    <div class="field">
        <label for="usual_order_multiple">Usual order multiple</label>
        <input type="number" min="1" id="usual_order_multiple" name="usual_order_multiple"
               value="<?= $value('usual_order_multiple') ?>">
        <div class="hint">The batch size this part is normally ordered in.</div>
        <?= $error('usual_order_multiple') ?>
    </div>

    <div class="field">
        <label for="expected_next_order_qty">Expected next order quantity</label>
        <input type="number" min="1" id="expected_next_order_qty" name="expected_next_order_qty"
               value="<?= $value('expected_next_order_qty') ?>">
        <div class="hint">What is likely to be ordered next, so Junction can see it coming.</div>
        <?= $error('expected_next_order_qty') ?>
    </div>
</div>

<?php /*
    Three boxes for one fact, so they are fenced together and labelled once.
    Recorded by hand and not taken from the order history in this system, which
    only knows about orders placed through it — a part machined for ten years
    before any of this existed has a last order, and it is not in `orders`.
*/ ?>
<fieldset class="field-set">
    <legend>Last order</legend>
    <p class="field-hint mb-0">
        The last time this part was bought. Fill these in yourself — they do not update on their own.
        Use them for orders placed before this system, or by phone or email. Orders placed here are
        already listed on the part page under Order history.
    </p>
    <div class="form-row">
        <?php if ($showPricing): ?>
            <div class="field">
                <label for="last_order_value">Value</label>
                <input type="number" step="0.01" min="0" id="last_order_value" name="last_order_value"
                       value="<?= $value('last_order_value') ?>">
                <?= $error('last_order_value') ?>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="last_order_qty">Quantity</label>
            <input type="number" min="1" id="last_order_qty" name="last_order_qty"
                   value="<?= $value('last_order_qty') ?>">
            <?= $error('last_order_qty') ?>
        </div>

        <div class="field">
            <label for="last_order_date">Date</label>
            <input type="date" id="last_order_date" name="last_order_date"
                   value="<?= $value('last_order_date') ?>">
            <?= $error('last_order_date') ?>
        </div>
    </div>
</fieldset>
