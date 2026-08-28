<?php
/**
 * A popup holding a list of rows that add up.
 *
 * Four things on the part page are the same shape: an estimated build time, an
 * actual build time, the lines of a draft quote, and a set of price breaks.
 * Each is a list somebody adds to, each row is a label beside a number, and in
 * three of the four the numbers make a total. Building four of these would
 * have produced four slightly different ideas of what "add a row" looks like.
 *
 * Without JavaScript it is an ordinary form. `<dialog>` with no `open`
 * attribute is hidden by the user agent, so a `<noscript>` rule in the layout
 * puts it back in the flow; the trigger button is hidden the other way round.
 * Nothing here needs the script to save.
 *
 * @var string $id       unique on the page; the trigger points at it
 * @var string $title    heading inside the popup
 * @var string $action   where the form posts
 * @var string $intro    a sentence under the heading (optional)
 * @var array  $columns  each: name, label, type, and optionally placeholder,
 *                       step, min, width ('narrow'|'wide'), total (bool)
 * @var array  $rows     existing rows, keyed by column name
 * @var string $trigger  wording on the button that opens it
 * @var string $totalLabel   wording beside the running total (optional)
 * @var string $totalFormat  'minutes' | 'money' — how to render the running total
 * @var string $extra    markup rendered above the rows, inside the form (optional)
 * @var string $footnote a sentence under the rows (optional)
 */
$intro = $intro ?? '';
$extra = $extra ?? '';
$footnote = $footnote ?? '';
$rows = $rows ?? [];
$totalLabel = $totalLabel ?? 'Total';
$totalFormat = $totalFormat ?? '';
$trigger = $trigger ?? 'Edit';

// Always one spare row on the end. Without the script that spare is the only
// way to add anything at all, and with it the editor never opens on a list
// with nowhere to type.
$rendered = $rows;
$rendered[] = [];

$totalColumn = null;
foreach ($columns as $column) {
    if (!empty($column['total'])) {
        $totalColumn = $column['name'];
    }
}
?>
<?php /*
    Hidden until the script un-hides it. A button whose only job is to open a
    dialog is a button that does nothing when there is no script to open one,
    and the form it would have opened is already on the page in that case.
*/ ?>
<button type="button" class="btn btn-sm" hidden data-row-editor-open="<?= e($id) ?>"><?= e($trigger) ?></button>

<dialog class="row-editor" id="<?= e($id) ?>" data-row-editor
        <?= $totalFormat !== '' ? 'data-row-total-format="' . e($totalFormat) . '"' : '' ?>>
    <form method="post" action="<?= url($action) ?>">
        <?= csrf_field() ?>

        <div class="row-editor-head">
            <h2 class="mt-0 mb-0"><?= e($title) ?></h2>
            <button type="button" class="btn btn-sm" data-row-editor-close aria-label="Close">&times;</button>
        </div>

        <?php if ($intro !== ''): ?>
            <p class="text-muted"><?= $intro ?></p>
        <?php endif; ?>

        <?= $extra ?>

        <div class="row-editor-rows" data-rows>
            <?php foreach ($rendered as $index => $row): ?>
                <div class="editor-row" data-row>
                    <?php foreach ($columns as $column):
                        $name = $column['name'];
                        $fieldId = $id . '_' . $name . '_' . $index;
                        $value = $row[$name] ?? '';
                        ?>
                        <div class="field <?= ($column['width'] ?? '') === 'narrow' ? 'field-narrow' : 'field-grow' ?>">
                            <label class="sr-only" for="<?= e($fieldId) ?>"><?= e($column['label']) ?></label>
                            <input type="<?= e($column['type']) ?>"
                                   id="<?= e($fieldId) ?>"
                                   name="<?= e($name) ?>[]"
                                   value="<?= e((string) $value) ?>"
                                   placeholder="<?= e($column['placeholder'] ?? $column['label']) ?>"
                                   <?= isset($column['step']) ? 'step="' . e($column['step']) . '"' : '' ?>
                                   <?= isset($column['min']) ? 'min="' . e($column['min']) . '"' : '' ?>
                                   <?= !empty($column['total']) ? 'data-row-amount' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-sm editor-row-remove" data-row-remove
                            aria-label="Remove this row">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row-editor-foot">
            <button type="button" class="btn btn-sm" data-row-add>Add a row</button>
            <?php if ($totalColumn !== null): ?>
                <p class="row-editor-total mb-0">
                    <?= e($totalLabel) ?>
                    <strong data-row-total>—</strong>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($footnote !== ''): ?>
            <p class="field-hint"><?= $footnote ?></p>
        <?php endif; ?>

        <?php /*
            A row emptied of its text is a row deleted. Saying so matters:
            without it, somebody clears a line, sees it still sitting there,
            and hunts for a delete button that is right in front of them.
        */ ?>
        <p class="field-hint">Saving replaces the whole list. An empty row is dropped.</p>

        <div class="row-editor-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn" data-row-editor-close>Cancel</button>
        </div>
    </form>
</dialog>
