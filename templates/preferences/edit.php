<?php /** @var array $groups */ /** @var array $subscribed */ ?>
<div class="page-head">
    <div>
        <h1>Email notifications</h1>
        <p class="muted">Choose which emails you want to receive. Nothing is sent unless you tick it here.</p>
    </div>
</div>

<?php /*
    One column under headings, rather than two columns of everything.
    Alphabetical order in two columns meant the eye had to travel down, back up
    and across to find out whether it had already read a line — and none of the
    labels are short enough for two columns to buy anything.
*/ ?>
<form method="post" action="<?= url('/preferences') ?>" class="card form">
    <?= csrf_field() ?>

    <?php foreach ($groups as $group): ?>
        <fieldset class="pref-group">
            <legend class="pref-group-title"><?= e($group['label']) ?></legend>
            <?php foreach ($group['types'] as $key => $label): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="types[]" value="<?= e($key) ?>" <?= in_array($key, $subscribed, true) ? 'checked' : '' ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save preferences</button>
    </div>
</form>
