<?php
/**
 * The parts listing, results and all: the count, the table, the pages.
 *
 * One partial for both audiences, and the whole of what the search replaces.
 * The controller renders exactly this fragment when the request is an AJAX one,
 * so what arrives after typing is produced by the same code as the page that
 * was there before it — there is no second renderer to drift.
 *
 * @var array  $result   from Part::search(): rows, total, page, pages, per_page
 * @var array  $mainPhotos part id => main photo row
 * @var bool   $isStaff
 * @var bool   $showPricing
 * @var string $basePath  '/staff/parts' or '/parts'
 * @var array  $query     the filters in force, for building page links
 */
$rows = $result['rows'];
$total = (int) $result['total'];
$page = (int) $result['page'];
$pages = (int) $result['pages'];
$perPage = (int) $result['per_page'];
$term = trim((string) ($query['q'] ?? ''));

$pageUrl = static function (int $number) use ($basePath, $query): string {
    $params = array_filter(
        $query,
        static fn ($value): bool => $value !== '' && $value !== null
    );
    if ($number > 1) {
        $params['page'] = $number;
    } else {
        unset($params['page']);
    }

    return url($basePath . ($params === [] ? '' : '?' . http_build_query($params)));
};

// The window of page numbers: always the first and last, always the one either
// side of where you are. Everything else is a gap. Twenty numbered links is a
// worse way to find page nine than two links and a gap.
$numbers = [];
if ($pages > 1) {
    foreach (range(1, $pages) as $candidate) {
        if ($candidate === 1 || $candidate === $pages || abs($candidate - $page) <= 1) {
            $numbers[] = $candidate;
        }
    }
}
?>
<?php if ($rows === []): ?>
    <p class="empty-state mb-0">
        <?php if ($term !== ''): ?>
            Nothing matches &ldquo;<?= e($term) ?>&rdquo;<?= $isStaff && !empty($query['client']) ? ' for that client' : '' ?>.
        <?php else: ?>
            No parts found.
        <?php endif; ?>
    </p>
<?php else: ?>
    <p class="result-count">
        <?php if ($total > $perPage): ?>
            Showing <?= (($page - 1) * $perPage) + 1 ?>&ndash;<?= min($total, $page * $perPage) ?>
            of <?= $total ?> parts<?= $term !== '' ? ' matching &ldquo;' . e($term) . '&rdquo;' : '' ?>.
        <?php else: ?>
            <?= $total ?> <?= $total === 1 ? 'part' : 'parts' ?><?= $term !== '' ? ' matching &ldquo;' . e($term) . '&rdquo;' : '' ?>.
        <?php endif; ?>
    </p>

    <div class="table-wrap">
        <?php /* Same columns, order and widths on both sides — see .table-parts. */ ?>
        <table class="table-parts<?= $isStaff ? ' table-parts-staff' : '' ?>">
            <colgroup>
                <col class="col-part-thumb">
                <col class="col-part-cpn">
                <?php if ($isStaff): ?><col class="col-part-client"><?php endif; ?>
                <col class="col-part-name">
                <col class="col-part-status">
                <?php if ($showPricing): ?><col class="col-part-price"><?php endif; ?>
                <col class="col-part-action">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col"><span class="sr-only">Photo</span></th>
                    <th scope="col">CPN</th>
                    <?php if ($isStaff): ?><th scope="col">Client</th><?php endif; ?>
                    <th scope="col">Name</th>
                    <th scope="col">Status</th>
                    <?php if ($showPricing): ?><th scope="col" class="align-right">Quoted price</th><?php endif; ?>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $part): ?>
                <tr>
                    <td class="part-thumb-cell">
                        <?php $photo = $mainPhotos[(int) $part['id']] ?? null; ?>
                        <?php if ($photo !== null): ?>
                            <?php /* The thumbnail route falls back to the full image when a
                                     photo predates the thumbnailing, so this is always safe. */ ?>
                            <img class="part-thumb" src="<?= url('/files/part-media/' . (int) $photo['id'] . '/thumb') ?>"
                                 alt="" loading="lazy" width="44" height="44">
                        <?php endif; ?>
                    </td>
                    <td><?= e($part['cpn']) ?></td>
                    <?php if ($isStaff): ?><td class="wrap"><?= e($part['client_name']) ?></td><?php endif; ?>
                    <td class="wrap">
                        <?= e($part['name']) ?>
                        <?php if ((bool) $part['is_archived']): ?><span class="badge badge-muted">Archived</span><?php endif; ?>
                    </td>
                    <td><?= status_badge($part['status']) ?></td>
                    <?php if ($showPricing): ?>
                        <td class="align-right"><?= $part['status'] === 'quoted' ? format_money($part['quoted_price']) : '—' ?></td>
                    <?php endif; ?>
                    <td><a href="<?= url($basePath . '/' . $part['id']) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <?php /* Real links, so the list still pages with JavaScript switched
                 off and a page of results can be sent to somebody. */ ?>
        <nav class="pagination" aria-label="Parts pages">
            <a class="btn btn-sm<?= $page <= 1 ? ' is-disabled' : '' ?>"
               href="<?= $pageUrl(max(1, $page - 1)) ?>"
               <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>

            <?php $previous = 0; ?>
            <?php foreach ($numbers as $number): ?>
                <?php if ($previous !== 0 && $number - $previous > 1): ?>
                    <span class="pagination-gap" aria-hidden="true">&hellip;</span>
                <?php endif; ?>
                <a class="btn btn-sm<?= $number === $page ? ' btn-primary' : '' ?>"
                   href="<?= $pageUrl($number) ?>"
                   <?= $number === $page ? 'aria-current="page"' : '' ?>><?= $number ?></a>
                <?php $previous = $number; ?>
            <?php endforeach; ?>

            <a class="btn btn-sm<?= $page >= $pages ? ' is-disabled' : '' ?>"
               href="<?= $pageUrl(min($pages, $page + 1)) ?>"
               <?= $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
        </nav>
    <?php endif; ?>
<?php endif; ?>
