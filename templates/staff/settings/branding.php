<?php /** @var array $logos */ ?>
<div class="page-head">
    <div>
        <h1>Logo</h1>
        <p class="muted">Shown in the top navigation, the sign-in page, printed paperwork and outbound email.</p>
    </div>
    <a href="<?= url('/staff/settings') ?>" class="btn">Back to settings</a>
</div>

<div class="card">
    <p class="muted">
        Light and dark variants are independent — if only one is set, it is used for both themes.
        PNG, JPEG or WEBP, up to 2&nbsp;MB. Each preview below sits on the background the logo will
        actually be seen against, so a white-on-transparent mark is not judged against a white card.
    </p>

    <div class="logo-grid">
        <?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $variant => $label): ?>
            <div class="logo-slot">
                <h3 class="mb-0"><?= e($label) ?></h3>
                <div class="logo-preview logo-preview-<?= e($variant) ?>">
                    <?php if ($logos[$variant] !== null): ?>
                        <img src="<?= e($logos[$variant]) ?>" alt="<?= e($label) ?> logo">
                    <?php else: ?>
                        <span class="muted">Nothing uploaded</span>
                    <?php endif; ?>
                </div>
                <?php if ($logos[$variant] !== null): ?>
                    <form method="post" action="<?= url('/staff/settings/logo/' . $variant . '/remove') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm">Remove</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<form method="post" action="<?= url('/staff/settings/logo') ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h2 class="mt-0">Upload</h2>
    <div class="field-row">
        <div class="field">
            <label class="label" for="logo_light">Light-mode logo</label>
            <input class="input" type="file" id="logo_light" name="logo_light" accept="image/png,image/jpeg,image/webp">
            <p class="field-hint">Used on the light theme, on paperwork and in email.</p>
        </div>
        <div class="field">
            <label class="label" for="logo_dark">Dark-mode logo</label>
            <input class="input" type="file" id="logo_dark" name="logo_dark" accept="image/png,image/jpeg,image/webp">
            <p class="field-hint">Used on the dark theme only.</p>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save logo</button>
    </div>
</form>
