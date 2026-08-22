<?php /** @var array $parts */ /** @var bool $onlyUnquoted */
use App\Core\Auth;
$showPricing = Auth::can('view_pricing');
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

<div class="card">
    <?php if ($parts === []): ?>
        <p class="empty-state">No parts found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <?php /* The client's own parts list, plus the column saying whose part
                     it is. The price was missing here, so the quoting desk's own
                     list was the one that would not show what it had quoted. */ ?>
            <table class="table-parts table-parts-staff">
                <colgroup>
                    <col class="col-part-cpn">
                    <col class="col-part-client">
                    <col class="col-part-name">
                    <col class="col-part-status">
                    <?php if ($showPricing): ?><col class="col-part-price"><?php endif; ?>
                    <col class="col-part-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">CPN</th>
                        <th scope="col">Client</th>
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
                        <td class="wrap"><?= e($part['client_name']) ?></td>
                        <td class="wrap"><?= e($part['name']) ?></td>
                        <td><?= status_badge($part['status']) ?></td>
                        <?php if ($showPricing): ?>
                            <td class="align-right"><?= $part['status'] === 'quoted' ? format_money($part['quoted_price']) : '—' ?></td>
                        <?php endif; ?>
                        <td><a href="<?= url('/staff/parts/' . $part['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
