<?php
/**
 * The two house figures a draft quote is built on, inside the draft editor.
 *
 * Both boxes start empty when the part is following the central setting, and
 * the placeholder shows what that setting currently is. Empty is not zero
 * here: it means "whatever Settings says", so a part deliberately quoted at
 * the house rate and a part nobody has thought about stay distinguishable —
 * change the house rate and the second follows, the first does not.
 *
 * Pre-filling the boxes with the house numbers would have destroyed that
 * distinction the first time anybody pressed Save.
 *
 * @var array|null $draft    the part's own draft, or null if it has none yet
 * @var array      $defaults from PartQuote::defaults()
 */
$rate = $draft !== null && $draft['machine_rate_per_minute'] !== null
    ? rtrim(rtrim((string) $draft['machine_rate_per_minute'], '0'), '.')
    : '';
$markup = $draft !== null && $draft['markup_percent'] !== null
    ? rtrim(rtrim((string) $draft['markup_percent'], '0'), '.')
    : '';
?>
<fieldset class="row-editor-standard">
    <legend>Rates for this part</legend>
    <div class="form-row">
        <div class="field">
            <label for="machine_rate_per_minute">Machine cost per minute</label>
            <input type="number" step="0.0001" min="0" id="machine_rate_per_minute"
                   name="machine_rate_per_minute" value="<?= e($rate) ?>"
                   placeholder="<?= e(number_format($defaults['rate'], 2)) ?> (from Settings)">
        </div>
        <div class="field">
            <label for="markup_percent">Mark-up %</label>
            <input type="number" step="0.01" min="0" id="markup_percent"
                   name="markup_percent" value="<?= e($markup) ?>"
                   placeholder="<?= e(number_format($defaults['markup'], 2)) ?> (from Settings)">
        </div>
    </div>
    <p class="field-hint mb-0">
        Leave either blank to follow the figure in
        <a href="<?= url('/staff/settings/quoting') ?>">Settings &rarr; Quoting</a>, which then moves this
        draft when it changes. Fill one in and it is fixed for this part alone.
    </p>
</fieldset>

<div class="field">
    <label for="quote_notes">Notes on this quote (optional)</label>
    <textarea id="quote_notes" name="quote_notes" rows="2"
              placeholder="Anything the figures do not say — an assumption, who was asked for a price"><?= e($draft['notes'] ?? '') ?></textarea>
</div>
