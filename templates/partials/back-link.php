<?php
/**
 * The way back to whatever this page hangs off.
 *
 * Above the heading rather than below the content, because it is navigation
 * and not a conclusion: somebody who has landed in the wrong place wants out
 * before they read the page, not after.
 *
 * Deliberately a quiet text link rather than a button. It competes with the
 * real actions in the header otherwise, and going back is the one thing on the
 * page nobody needs persuading to do.
 *
 * Only worth rendering where the nav cannot already do the job. Every top-level
 * list is one click away in the sidebar, so a link to one of those earns its
 * place only when the page has no other parent; what the nav cannot offer is
 * the particular order or client this page came out of, and that is the link
 * worth having.
 *
 * @var string $href
 * @var string $label
 */
?>
<p class="back-link"><a href="<?= url($href) ?>">&larr; <?= e($label) ?></a></p>
