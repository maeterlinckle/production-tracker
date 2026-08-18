<?php
/** @var array $users */ /** @var array $roles */
use App\Core\Auth;
?>
<div class="page-head">
    <div>
        <h1>Team</h1>
        <p class="muted">Who at your company can sign in, and what they can do. This only ever affects your own company's users.</p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Users</h2>
        <span class="count-pill"><?= count($users) ?></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Access</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <strong><?= e($user['name']) ?></strong>
                        <div class="cell-sub"><?= e($user['email']) ?></div>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/team/' . $user['id'] . '/roles') ?>" class="form-inline">
                            <?= csrf_field() ?>
                            <?php foreach ($roles as $role): ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="roles[]" value="<?= e($role['slug']) ?>"
                                        <?= in_array($role['slug'], $user['roles'], true) ? 'checked' : '' ?>>
                                    <span><?= e($role['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-sm">Save</button>
                        </form>
                    </td>
                    <td>
                        <?php if (!$user['has_password']): ?>
                            <span class="badge badge-warn">Invited</span>
                            <?php if ($user['invite'] !== null): ?>
                                <div class="cell-sub">Link expires <?= format_datetime($user['invite']['expires_at']) ?></div>
                            <?php else: ?>
                                <div class="cell-sub">Link has expired</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge <?= $user['is_active'] ? 'badge-ok' : 'badge-muted' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if (!$user['has_password']): ?>
                            <form method="post" action="<?= url('/team/' . $user['id'] . '/reinvite') ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Re-send invitation</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) $user['id'] !== Auth::id()): ?>
                            <form method="post" action="<?= url('/team/' . $user['id'] . '/toggle-active') ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" action="<?= url('/team') ?>" class="card form">
    <?= csrf_field() ?>
    <h2 class="mt-0">Invite somebody</h2>

    <p class="notice-inline">
        They are emailed a link and choose their own password — you never see it, and nobody has to
        send a password around. The link works once and lasts seven days.
    </p>

    <div class="field-row">
        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input" type="text" id="name" name="name" required>
        </div>
        <div class="field">
            <label class="label" for="email">Email</label>
            <input class="input" type="email" id="email" name="email" required>
        </div>
    </div>

    <div class="field">
        <label class="label">Roles</label>
        <div class="check-grid">
            <?php foreach ($roles as $role): ?>
                <label class="checkbox">
                    <input type="checkbox" name="roles[]" value="<?= e($role['slug']) ?>"
                        <?= $role['slug'] === 'client.production' ? 'checked' : '' ?>>
                    <span><?= e($role['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="field-hint">
            Only a purchaser or an administrator can see prices or place orders. Somebody who just needs
            to follow progress wants Production and nothing else.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Send invitation</button>
    </div>
</form>
