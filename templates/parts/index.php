<?php /** @var array $parts */ /** @var bool $showArchived */
use App\Core\Auth;
$showPricing = Auth::can('view_pricing');
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

<div class="card">
    <?php if ($parts === []): ?>
        <p class="empty-state"><?= $showArchived ? 'No archived parts.' : 'No parts yet. <a href="' . url('/parts/new') . '">Create one</a> to request a quote.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <?php /* Same columns, order and widths as Junction's parts list — see .table-parts. */ ?>
            <table class="table-parts">
                <colgroup>
                    <col class="col-part-cpn">
                    <col class="col-part-name">
                    <col class="col-part-status">
                    <?php if ($showPricing): ?><col class="col-part-price"><?php endif; ?>
                    <col class="col-part-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">CPN</th>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <?php if ($showPricing): ?><th scope="col" class="align-right">Quoted price</th><?php endif; ?>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><?= e($part['cpn']) ?></td>
                        <td class="wrap"><?= e($part['name']) ?></td>
                        <td><?= status_badge($part['status']) ?></td>
                        <?php if ($showPricing): ?>
                            <td class="align-right"><?= $part['status'] === 'quoted' ? format_money($part['quoted_price']) : '—' ?></td>
                        <?php endif; ?>
                        <td><a href="<?= url('/parts/' . $part['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
