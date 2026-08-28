<?php
/**
 * Main application layout.
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
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?php /*
    A <dialog> with no `open` attribute is hidden by the user agent, and only
    a script can open one. With no script there is nothing to open it and the
    form inside would be unreachable — so it goes back into the flow instead,
    as an ordinary section of the page. See partials/row-editor.php.
*/ ?>
<noscript>
    <style>
        dialog.row-editor { display: block; position: static; width: auto; margin: 0 0 var(--space-4); }
        dialog.row-editor [data-row-editor-close] { display: none; }
    </style>
</noscript>

<?= partial('partials/nav') ?>

<main id="main" class="container">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<?= partial('partials/footer') ?>

<script src="<?= asset_url('js/app.js') ?>" defer></script>
</body>
</html>
