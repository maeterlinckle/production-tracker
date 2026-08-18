<?php /** @var array $types */ /** @var array $subscribed */ ?>
<div class="page-head">
    <div>
        <h1>Email notifications</h1>
        <p class="muted">Choose which emails you want to receive. Nothing is sent unless you tick it here.</p>
    </div>
</div>

<form method="post" action="<?= url('/preferences') ?>" class="card form">
    <?= csrf_field() ?>

    <div class="check-grid">
        <?php foreach ($types as $key => $label): ?>
            <label class="checkbox">
                <input type="checkbox" name="types[]" value="<?= e($key) ?>" <?= in_array($key, $subscribed, true) ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save preferences</button>
    </div>
</form>
