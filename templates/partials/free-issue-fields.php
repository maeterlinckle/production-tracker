<?php
/**
 * The whole free-issue question on a part form: does this part have any, and if
 * so what material and in what ratio.
 *
 * The toggle comes first because it is the answer everything else depends on
 * (item 2). With it off, the material rows and the ratio are hidden here and
 * the server ignores them — so an unchecked box cannot leave a part quietly
 * carrying a ratio for material it does not use.
 *
 * Shared by the client's part form, their edit form, and Junction's
 * create-on-behalf form, so the three cannot drift into asking different
 * questions.
 *
 * @var bool   $hasFreeIssue
 * @var string $relationship 'none' | 'divide' | 'multiply'
 * @var int    $factor
 * @var array  $materials      rows of ['reference', 'notes'], may be empty
 * @var string $idPrefix       distinguishes the controls when two forms are on one page
 * @var bool   $showOverrideNote
 */
$hasFreeIssue = (bool) ($hasFreeIssue ?? false);
$relationship = $relationship ?? 'none';
$factor = (int) ($factor ?? 1);
$materials = $materials ?? [];
$idPrefix = $idPrefix ?? 'fi';
$showOverrideNote = $showOverrideNote ?? false;

$toggleId = $idPrefix . '_has';
$panelId = $idPrefix . '_panel';
$rows = $materials !== [] ? $materials : [['reference' => '', 'notes' => '']];
?>
<div class="field">
    <label class="checkbox-label" for="<?= e($toggleId) ?>">
        <input type="checkbox" id="<?= e($toggleId) ?>" name="has_free_issue" value="1"
               <?= $hasFreeIssue ? 'checked' : '' ?>
               data-toggle-panel="<?= e($panelId) ?>">
        <span>This part is made from free-issue material</span>
    </label>
    <p class="field-hint">
        Tick this if Junction machines or builds this part from stock you supply. Leave it clear if
        Junction buys the material — nothing about free issue will then be asked for, shown or
        chased anywhere for this part.
    </p>
</div>

<div id="<?= e($panelId) ?>" <?= $hasFreeIssue ? '' : 'hidden' ?>>
    <p class="text-muted">Your own part number for the raw material, or a plain description if it isn't something you track. Saving replaces the full list.</p>
    <?php foreach ($rows as $material): ?>
        <div class="form-row">
            <div class="field">
                <input type="text" name="free_issue_ref[]" value="<?= e($material['reference'] ?? '') ?>" placeholder="Material reference">
            </div>
            <div class="field">
                <input type="text" name="free_issue_notes[]" value="<?= e($material['notes'] ?? '') ?>" placeholder="Notes (optional)">
            </div>
        </div>
    <?php endforeach; ?>
    <div class="form-row">
        <div class="field"><input type="text" name="free_issue_ref[]" placeholder="Material reference"></div>
        <div class="field"><input type="text" name="free_issue_notes[]" placeholder="Notes (optional)"></div>
    </div>

    <?= partial('partials/free-issue-relationship', [
        'relationship' => $relationship,
        'factor' => $factor,
        'idPrefix' => $idPrefix,
        'showOverrideNote' => $showOverrideNote,
    ]) ?>
</div>
