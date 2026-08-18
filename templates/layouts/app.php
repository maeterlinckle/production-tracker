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
    <meta name="theme-color" content="#ffffff">
    <title><?= isset($title) ? e($title) . ' · ' : '' ?><?= e($appProduct ?? config('app.product')) ?></title>
    <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
    <?= partial('partials/theme-init') ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?= partial('partials/nav') ?>

<main id="main" class="container">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<?= partial('partials/footer') ?>

<script src="<?= asset_url('js/app.js') ?>" defer></script>
</body>
</html>
