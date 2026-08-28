<?php
/**
 * The house figures every draft part quote starts from.
 *
 * @var array $defaults        from PartQuote::defaults()
 * @var int   $partsWithDraft
 * @var int   $partsOverriding
 */
?>
<?= partial('partials/back-link', ['href' => '/staff/settings', 'label' => 'Settings']) ?>

<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0">Quoting</h1>
        <p class="text-muted mb-0">
            What a draft part quote assumes before anybody changes it for a particular part.
        </p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= url('/staff/settings/quoting') ?>">
        <?= csrf_field() ?>

        <div class="form-row">
            <div class="field">
                <label for="machine_rate_per_minute">Machine cost per minute</label>
                <input type="number" step="0.0001" min="0" required
                       id="machine_rate_per_minute" name="machine_rate_per_minute"
                       value="<?= e(rtrim(rtrim(number_format($defaults['rate'], 4, '.', ''), '0'), '.')) ?>">
                <div class="hint">
                    Multiplied by a part's <strong>estimated</strong> build time — never the actual. A quote is
                    made before the work, and pricing a repeat order off how long it happened to take last
                    time would charge the client for a bad day.
                </div>
            </div>

            <div class="field">
                <label for="markup_percent">Mark-up %</label>
                <input type="number" step="0.01" min="0" required
                       id="markup_percent" name="markup_percent"
                       value="<?= e(rtrim(rtrim(number_format($defaults['markup'], 2, '.', ''), '0'), '.')) ?>">
                <div class="hint">Applied to machine time, material and any lines on the draft, together.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save quoting figures</button>
    </form>

    <?php /*
        Said here because it is the thing somebody changing these needs to
        know: this moves live drafts. Not quoted prices — no price anywhere
        changes because of this screen — but the working behind them does.
    */ ?>
    <p class="field-hint" style="margin-top: var(--space-4)">
        <?php if ($partsWithDraft === 0): ?>
            No part has a draft quote yet. These figures apply to the first one written.
        <?php else: ?>
            <?= $partsWithDraft ?> <?= $partsWithDraft === 1 ? 'part has' : 'parts have' ?> a draft quote.
            <?php if ($partsOverriding > 0): ?>
                <?= $partsOverriding ?> of <?= $partsOverriding === 1 ? 'them has' : 'them have' ?> a rate or
                mark-up set for <?= $partsOverriding === 1 ? 'that part' : 'those parts' ?> alone and will not
                move with these.
            <?php else: ?>
                All of them follow these figures, so all of them move when these do.
            <?php endif; ?>
            No quoted price changes either way — a draft is Junction's working, not a price.
        <?php endif; ?>
    </p>
</div>
