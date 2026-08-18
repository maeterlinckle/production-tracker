<?php
/**
 * The free-issue quantity relationship: one dropdown for the direction, one for
 * the number, and the number's dropdown only appears once a direction is chosen.
 *
 * Shared between the client's own part form and Junction's workshop details, so
 * the two cannot drift into offering different ranges or different wording. The
 * client sets it from the quote stage; Junction can correct it later.
 *
 * @var string $relationship 'none' | 'divide' | 'multiply'
 * @var int    $factor
 * @var string $idPrefix      distinguishes the controls when two forms are on one page
 * @var bool   $showOverrideNote
 */
$relationship = $relationship ?? 'none';
$factor = (int) ($factor ?? 1);
$idPrefix = $idPrefix ?? 'fi';
$showOverrideNote = $showOverrideNote ?? false;

$select   = $idPrefix . '_relationship';
$divide   = $idPrefix . '_divide';
$multiply = $idPrefix . '_multiply';
?>
<div class="field">
    <label class="label" for="<?= e($select) ?>">Free-issue quantity relationship</label>
    <select class="input" id="<?= e($select) ?>" name="free_issue_relationship"
            data-fi-select data-fi-divide="<?= e($divide) ?>" data-fi-multiply="<?= e($multiply) ?>">
        <option value="none" <?= $relationship === 'none' ? 'selected' : '' ?>>1:1 — one piece of material per part</option>
        <option value="divide" <?= $relationship === 'divide' ? 'selected' : '' ?>>Divide — one piece of material makes several parts</option>
        <option value="multiply" <?= $relationship === 'multiply' ? 'selected' : '' ?>>Multiply — several pieces of material make one part</option>
    </select>
    <p class="field-hint">
        This is how much material has to arrive for a given order quantity, and it is what the free-issue
        figure on an order is worked out from. If one length of bar yields four parts, choose
        <strong>divide by 4</strong>: an order for 20 needs 5 lengths. If a part is built from three
        castings, choose <strong>multiply by 3</strong>: an order for 20 needs 60. Leave it at 1:1 if one
        piece makes one part.
    </p>
</div>

<div class="field" id="<?= e($divide) ?>" <?= $relationship === 'divide' ? '' : 'hidden' ?>>
    <label class="label" for="<?= e($divide) ?>_input">One piece of material makes</label>
    <select class="input" id="<?= e($divide) ?>_input" name="free_issue_factor_divide">
        <?php foreach (range(2, 10) as $n): ?>
            <option value="<?= $n ?>" <?= $relationship === 'divide' && $factor === $n ? 'selected' : '' ?>><?= $n ?> parts</option>
        <?php endforeach; ?>
    </select>
</div>

<div class="field" id="<?= e($multiply) ?>" <?= $relationship === 'multiply' ? '' : 'hidden' ?>>
    <label class="label" for="<?= e($multiply) ?>_input">Each part needs</label>
    <select class="input" id="<?= e($multiply) ?>_input" name="free_issue_factor_multiply">
        <?php foreach (range(2, 10) as $n): ?>
            <option value="<?= $n ?>" <?= $relationship === 'multiply' && $factor === $n ? 'selected' : '' ?>><?= $n ?> pieces of material</option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($showOverrideNote): ?>
    <p class="field-hint">
        The client sets this when they ask for a quote. Changing it here overrides their value for every
        future order — worth a note to them if it was not simply a mistake.
    </p>
<?php endif; ?>
