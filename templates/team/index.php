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
                <tr>
                    <td>
                        <strong><?= e($user['name']) ?></strong>
                        <div class="cell-sub"><?= e($user['email']) ?></div>
                        <?php /*
                            Correcting a name or an email in place. People marry,
                            and companies change their email domain; neither
                            should mean deleting an account and losing everything
                            it raised.
                        */ ?>
                        <details class="caption-edit">
                            <summary>Edit details</summary>
                            <form method="post" action="<?= url('/team/' . $user['id']) ?>">
                                <?= csrf_field() ?>
                                <div class="field">
                                    <label class="sr-only" for="tu_name_<?= (int) $user['id'] ?>">Name</label>
                                    <input type="text" id="tu_name_<?= (int) $user['id'] ?>" name="name"
                                           value="<?= e($user['name']) ?>" placeholder="Name" required>
                                </div>
                                <div class="field">
                                    <label class="sr-only" for="tu_email_<?= (int) $user['id'] ?>">Email</label>
                                    <input type="email" id="tu_email_<?= (int) $user['id'] ?>" name="email"
                                           value="<?= e($user['email']) ?>" placeholder="Email" required>
                                </div>
                                <div class="hint">The email is what they sign in with.</div>
                                <button type="submit" class="btn btn-sm">Save details</button>
                            </form>
                        </details>
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
                    <?php /* The same column Junction's own user list carries. A
                             client administrator deciding whether a colleague still
                             needs an account is asking exactly this. */ ?>
                    <td><?= $user['last_login_at'] === null ? '<span class="muted">Never</span>' : e(format_datetime($user['last_login_at'])) ?></td>
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
