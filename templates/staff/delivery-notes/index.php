<?php /** @var array $notes */ /** @var string|null $filter */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Delivery notes</h1>
    <div style="display:flex; gap: var(--space-2)">
        <a href="<?= url('/staff/delivery-notes') ?>" class="btn <?= $filter !== 'uninvoiced' ? 'btn-primary' : '' ?> btn-sm">All</a>
        <a href="<?= url('/staff/delivery-notes?filter=uninvoiced') ?>" class="btn <?= $filter === 'uninvoiced' ? 'btn-primary' : '' ?> btn-sm">Not yet invoiced</a>
    </div>
</div>

<div class="card">
    <?php if ($notes === []): ?>
        <p class="empty-state">No delivery notes found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Type</th><th>Client</th><th>Issued</th><th>Invoiced</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($notes as $note): ?>
                    <tr>
                        <td><?= e($note['reference']) ?></td>
                        <td><?= e(\App\Models\DeliveryNote::TYPE_LABELS[$note['type']] ?? $note['type']) ?></td>
                        <td><?= e($note['client_name']) ?></td>
                        <td><?= format_date($note['issued_at']) ?></td>
                        <td>
                            <?php if ($note['type'] === 'goods_out'): ?>
                                <span class="badge <?= $note['invoiced'] ? 'badge-ok' : 'badge-warn' ?>"><?= $note['invoiced'] ? 'Invoiced' : 'Not invoiced' ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= url('/staff/delivery-notes/' . $note['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
