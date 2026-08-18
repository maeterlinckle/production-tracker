<?php

use App\Services\Branding;

/**
 * The logo (or the fallback mark) plus the wordmark.
 *
 * Both variants are rendered when both exist and CSS picks by theme: the theme
 * lives in a `data-theme` attribute the user can flip without a page load, so a
 * server-side choice would show the wrong logo until the next navigation.
 *
 * A logo replaces the mark box; the wordmark stays either way.
 *
 * `$homeHref` makes the *logo* the link home. The wordmark sits outside it, so
 * there is one link with one accessible name.
 *
 * @var string      $appName
 * @var string|null $homeHref
 */
$light    = Branding::url('light');
$dark     = Branding::url('dark');
$name     = (string) ($appName ?? config('app.product', 'Production Tracker'));
$homeHref = isset($homeHref) ? (string) $homeHref : null;
$hasLogo  = $light !== null || $dark !== null;
?>
<span class="brand-stack<?= $hasLogo ? ' brand-stack-logo' : '' ?>">
    <?php if ($homeHref !== null): ?>
        <a class="brand-home" href="<?= e(url($homeHref)) ?>" aria-label="<?= e($name) ?> — dashboard">
    <?php endif; ?>

    <?php if ($hasLogo): ?>
        <span class="brand-logo-wrap">
            <?php if ($light !== null): ?>
                <img class="brand-logo brand-logo-light" src="<?= e($light) ?>"
                     alt="<?= $homeHref !== null ? '' : e($name) ?>" <?= $homeHref !== null ? 'aria-hidden="true"' : '' ?>>
            <?php endif; ?>
            <?php if ($dark !== null && $dark !== $light): ?>
                <img class="brand-logo brand-logo-dark" src="<?= e($dark) ?>"
                     alt="<?= $homeHref !== null ? '' : e($name) ?>" <?= $homeHref !== null ? 'aria-hidden="true"' : '' ?>>
            <?php endif; ?>
        </span>
    <?php else: ?>
        <span class="brand-mark" aria-hidden="true"><?= e(config('app.mark', 'PT')) ?></span>
    <?php endif; ?>

    <?php if ($homeHref !== null): ?>
        </a>
    <?php endif; ?>

    <span class="brand-name"><?= e($name) ?></span>
</span>
