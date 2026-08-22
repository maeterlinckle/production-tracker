<?php
/**
 * Minimal layout for signed-out pages: sign in, and setting a password from an
 * invitation.
 *
 * @var string $content
 * @var string $title
 */
?>
<!doctype html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <!-- Two, so the browser chrome follows the theme instead of staying white. -->
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <title><?= isset($title) ? e($title) . ' · ' : '' ?><?= e($appProduct ?? config('app.product')) ?></title>
    <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
    <?= partial('partials/theme-init') ?>
</head>
<body class="body-centred">

<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-brand brand">
            <?= partial('partials/brand', ['appName' => $appProduct ?? config('app.product')]) ?>
        </div>

        <?= partial('partials/flash') ?>
        <?= $content ?>
    </div>

    <button type="button" class="btn btn-ghost theme-toggle-standalone" data-theme-toggle title="Switch between light and dark">
        <span class="theme-icon" aria-hidden="true"></span>
        <span data-theme-label>Dark mode</span>
    </button>
</div>

<?= partial('partials/footer') ?>

<script src="<?= asset_url('js/app.js') ?>" defer></script>
</body>
</html>
