<?php
/** @var array $users */ /** @var array $roles */
use App\Core\Auth;
?>
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p class="muted">Junction's own accounts. Client-side logins are managed on each client's page.</p>
    </div>
    <a href="<?= url('/staff/settings') ?>" class="btn">Back to settings</a>
</div>

<div class="card">
    <div class="card-head">
        <h2>Staff accounts</h2>
        <span class="count-pill"><?= count($users) ?></span>
    </div>

    <div class="table-wrap">
        <table class="table table-people">
        <colgroup>
            <col class="col-person-name">
            <col class="col-person-roles">
            <col class="col-person-status">
            <col class="col-person-seen">
            <col class="col-person-action">
        </colgroup>
            <thead>
                <tr><th scope="col">Name</th><th scope="col">Roles</th><th scope="col">Status</th><th scope="col">Last signed in</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr<?= $user['is_active'] ? '' : ' class="row-muted"' ?>>
                    <td>
                        <strong><?= e($user['name']) ?></strong>
                        <div class="cell-sub"><?= e($user['email']) ?></div>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/staff/settings/users/' . $user['id'] . '/roles') ?>" class="form-inline">
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
                            <div class="cell-sub">
                                <?= $user['invite'] !== null
                                    ? 'Link expires ' . e(format_datetime($user['invite']['expires_at']))
                                    : 'Link has expired' ?>
                            </div>
                        <?php else: ?>
                            <span class="badge <?= $user['is_active'] ? 'badge-ok' : 'badge-muted' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $user['last_login_at'] === null ? '<span class="muted">Never</span>' : e(format_datetime($user['last_login_at'])) ?></td>
                    <td class="actions">
                        <?php if (!$user['has_password']): ?>
                            <form method="post" action="<?= url('/staff/settings/users/' . $user['id'] . '/reinvite') ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Re-send invitation</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) $user['id'] !== Auth::id()): ?>
                            <form method="post" action="<?= url('/staff/settings/users/' . $user['id'] . '/toggle-active') ?>" class="inline-form">
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

<form method="post" action="<?= url('/staff/settings/users') ?>" class="card form">
    <?= csrf_field() ?>
    <h2 class="mt-0">Invite a colleague</h2>

    <p class="notice-inline">
        They are emailed a link and choose their own password — nobody else ever knows it. The link works
        once and lasts seven days; if it lapses, re-send from the table above.
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
                    <input type="checkbox" name="roles[]" value="<?= e($role['slug']) ?>">
                    <span><?= e($role['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="field-hint">
            Give somebody more than one where the job needs it — quoting and production together is
            normal in a small shop. Administrator includes everything, Settings included.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Send invitation</button>
    </div>
</form>
