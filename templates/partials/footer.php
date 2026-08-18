<?php
/**
 * Product branding, on every page.
 *
 * The *product* name rather than `app.name`: an instance can call itself
 * whatever it likes, but the thing in the footer is what the software is and
 * who made it.
 */
$user = auth_user();
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-brand">
            <span><?= e(config('app.full_name', 'Production Tracker by Junction')) ?></span>
            <span class="muted">
                <?= e(config('app.tagline', 'Job Shop Order Tracking')) ?>
                <?php if (config('app.vendor_url', '') !== ''): ?>
                    · <a href="<?= e(config('app.vendor_url')) ?>"
                         target="_blank" rel="noopener noreferrer"><?= e(config('app.vendor', 'Junction Inc Ltd')) ?></a>
                <?php else: ?>
                    · <?= e(config('app.vendor', 'Junction Inc Ltd')) ?>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($user !== null): ?>
            <span class="muted">Signed in as <?= e($user['name']) ?> · <?= e(role_summary()) ?></span>
        <?php endif; ?>
    </div>
</footer>
