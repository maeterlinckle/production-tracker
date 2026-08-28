<?php
/**
 * Junction's parts list.
 *
 * The table, the count and the pages come out of partials/parts-results, which
 * is also what the search asks for on its own — see App\Models\Part::search().
 *
 * @var array  $result
 * @var array  $mainPhotos
 * @var bool   $onlyUnquoted
 * @var array  $clients
 * @var ?int   $clientId
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
    <div style="display:flex; gap: var(--space-2)">
        <a href="<?= url('/staff/parts') ?>" class="btn <?= !$onlyUnquoted ? 'btn-primary' : '' ?> btn-sm">All</a>
        <a href="<?= url('/staff/parts?filter=unquoted') ?>" class="btn <?= $onlyUnquoted ? 'btn-primary' : '' ?> btn-sm">Awaiting price</a>
        <?php if (Auth::can('create_client_parts')): ?>
            <a href="<?= url('/staff/parts/new') ?>" class="btn btn-sm">New part</a>
        <?php endif; ?>
    </div>
</div>

<?php /*
    A real GET form. With JavaScript it never submits — the script asks for the
    results region and swaps it in — and without it the button is there and the
    page reloads, which is the same list by a slower route.
*/ ?>
<form class="parts-search" method="get" action="<?= url('/staff/parts') ?>" data-parts-search>
    <?php if ($onlyUnquoted): ?><input type="hidden" name="filter" value="unquoted"><?php endif; ?>
    <div class="field field-grow">
        <label class="sr-only" for="parts_q">Search parts</label>
        <input type="search" id="parts_q" name="q" value="<?= e($term) ?>" autocomplete="off"
               placeholder="Part number, name, alternate number, material, notes&hellip;"
               data-parts-query>
    </div>
    <div class="field">
        <label class="sr-only" for="parts_client">Client</label>
        <select id="parts_client" name="client" data-parts-filter>
            <option value="">All clients</option>
            <?php foreach ($clients as $client): ?>
                <option value="<?= (int) $client['id'] ?>" <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>>
                    <?= e($client['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-sm" data-parts-submit>Search</button>
</form>

<div class="card" data-parts-results>
    <?= partial('partials/parts-results', [
        'result' => $result,
        'mainPhotos' => $mainPhotos,
        'isStaff' => $isStaff,
        'showPricing' => $showPricing,
        'basePath' => $basePath,
        'query' => $query,
    ]) ?>
</div>
