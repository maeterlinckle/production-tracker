<?php /** @var array $parts */ /** @var bool $onlyUnquoted */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Parts</h1>
    <div style="display:flex; gap: var(--space-2)">
        <a href="<?= url('/staff/parts') ?>" class="btn <?= !$onlyUnquoted ? 'btn-primary' : '' ?> btn-sm">All</a>
        <a href="<?= url('/staff/parts?filter=unquoted') ?>" class="btn <?= $onlyUnquoted ? 'btn-primary' : '' ?> btn-sm">Awaiting price</a>
    </div>
</div>

<div class="card">
    <?php if ($parts === []): ?>
        <p class="empty-state">No parts found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>CPN</th><th>Client</th><th>Name</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><?= e($part['cpn']) ?></td>
                        <td><?= e($part['client_name']) ?></td>
                        <td><?= e($part['name']) ?></td>
                        <td><?= status_badge($part['status']) ?></td>
                        <td><a href="<?= url('/staff/parts/' . $part['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
