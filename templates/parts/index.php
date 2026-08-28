<?php
/**
 * The client's own parts list.
 *
 * Same results partial as Junction's, minus the client column and the client
 * filter — there is only one client here.
 *
 * @var array  $result
 * @var array  $mainPhotos
 * @var bool   $showArchived
 * @var string $term
 * @var bool   $isStaff
 * @var bool   $showPricing
 * @var string $basePath
 * @var array  $query
 */
use App\Core\Auth;
?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Parts</h1>
    <?php if (Auth::can('manage_parts')): ?>
        <a href="<?= url('/parts/new') ?>" class="btn btn-primary">New part</a>
    <?php endif; ?>
</div>

<div style="display:flex; gap: var(--space-2); margin-bottom: var(--space-4)">
    <a href="<?= url('/parts') ?>" class="btn <?= !$showArchived ? 'btn-primary' : '' ?> btn-sm">Active</a>
    <a href="<?= url('/parts?filter=archived') ?>" class="btn <?= $showArchived ? 'btn-primary' : '' ?> btn-sm">Archived</a>
</div>

<form class="parts-search" method="get" action="<?= url('/parts') ?>" data-parts-search>
    <?php if ($showArchived): ?><input type="hidden" name="filter" value="archived"><?php endif; ?>
    <div class="field field-grow">
        <label class="sr-only" for="parts_q">Search parts</label>
        <input type="search" id="parts_q" name="q" value="<?= e($term) ?>" autocomplete="off"
               placeholder="Part number, name, alternate number, notes&hellip;"
               data-parts-query>
    </div>
    <button type="submit" class="btn btn-sm" data-parts-submit>Search</button>
</form>

<div class="card" data-parts-results>
    <?php if ($result['total'] === 0 && $term === '' && !$showArchived): ?>
        <p class="empty-state mb-0">
            No parts yet. <a href="<?= url('/parts/new') ?>">Create one</a> to request a quote.
        </p>
    <?php else: ?>
        <?= partial('partials/parts-results', [
            'result' => $result,
            'mainPhotos' => $mainPhotos,
            'isStaff' => $isStaff,
            'showPricing' => $showPricing,
            'basePath' => $basePath,
            'query' => $query,
        ]) ?>
    <?php endif; ?>
</div>
