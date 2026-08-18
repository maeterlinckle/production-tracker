<?php /** @var array $clients */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0">Clients</h1>
    <a href="<?= url('/staff/clients/new') ?>" class="btn btn-primary">New client</a>
</div>

<div class="card">
    <?php if ($clients === []): ?>
        <p class="empty-state">No clients yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Main contact</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?= e($client['name']) ?></td>
                        <td><?= e($client['main_contact_name'] ?: '—') ?></td>
                        <td><span class="badge <?= $client['is_active'] ? 'badge-ok' : 'badge-muted' ?>"><?= $client['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><a href="<?= url('/staff/clients/' . $client['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
