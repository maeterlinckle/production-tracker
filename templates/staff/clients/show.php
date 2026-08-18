<?php /** @var array $client */ /** @var array $users */ /** @var array $parts */ /** @var array $orders */ ?>
<div class="card-header">
    <h1 class="mt-0 mb-0"><?= e($client['name']) ?></h1>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2 class="mt-0">Details</h2>
        <form method="post" action="<?= url('/staff/clients/' . $client['id']) ?>">
            <?= csrf_field() ?>
            <div class="field"><label for="name">Client name</label><input type="text" id="name" name="name" value="<?= e($client['name']) ?>" required></div>
            <div class="field"><label for="clearbooks_entity_id">Clear Books customer ID</label><input type="text" id="clearbooks_entity_id" name="clearbooks_entity_id" value="<?= e($client['clearbooks_entity_id'] ?? '') ?>"></div>
            <div class="form-row">
                <div class="field"><label for="address_line1">Address line 1</label><input type="text" id="address_line1" name="address_line1" value="<?= e($client['address_line1'] ?? '') ?>"></div>
                <div class="field"><label for="address_line2">Address line 2</label><input type="text" id="address_line2" name="address_line2" value="<?= e($client['address_line2'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="field"><label for="address_city">City</label><input type="text" id="address_city" name="address_city" value="<?= e($client['address_city'] ?? '') ?>"></div>
                <div class="field"><label for="address_postcode">Postcode</label><input type="text" id="address_postcode" name="address_postcode" value="<?= e($client['address_postcode'] ?? '') ?>"></div>
                <div class="field"><label for="address_country">Country</label><input type="text" id="address_country" name="address_country" value="<?= e($client['address_country'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="field"><label for="main_contact_name">Main contact</label><input type="text" id="main_contact_name" name="main_contact_name" value="<?= e($client['main_contact_name'] ?? '') ?>"></div>
                <div class="field"><label for="main_contact_email">Contact email</label><input type="email" id="main_contact_email" name="main_contact_email" value="<?= e($client['main_contact_email'] ?? '') ?>"></div>
                <div class="field"><label for="main_contact_phone">Contact phone</label><input type="text" id="main_contact_phone" name="main_contact_phone" value="<?= e($client['main_contact_phone'] ?? '') ?>"></div>
            </div>
            <div class="field"><label for="billing_email">Billing email</label><input type="email" id="billing_email" name="billing_email" value="<?= e($client['billing_email'] ?? '') ?>"></div>
            <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= e($client['notes'] ?? '') ?></textarea></div>
            <div class="field">
                <label><input type="checkbox" name="is_active" value="1" <?= $client['is_active'] ? 'checked' : '' ?> style="width:auto;min-height:auto"> Active</label>
            </div>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </div>

    <div>
        <div class="card">
            <h2 class="mt-0">Users</h2>
            <?php if ($users === []): ?>
                <p class="muted">Nobody at this client can sign in yet. Invite their administrator below — they can then invite the rest of their own team.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($users as $user): ?>
                        <li>
                            <span><?= e($user['name']) ?> <span class="muted"><?= e($user['email']) ?></span></span>
                            <span>
                                <?php if (!$user['has_password']): ?>
                                    <span class="badge badge-warn">Invited</span>
                                    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/users/' . $user['id'] . '/reinvite') ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm">Re-send</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?= $user['is_active'] ? 'badge-ok' : 'badge-muted' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/users') ?>" style="margin-top: var(--space-4)">
                <?= csrf_field() ?>
                <h3>Invite somebody</h3>
                <p class="notice-inline">
                    They are emailed a link and choose their own password. Give the first person at a new
                    client the Administrator role — they can then invite their own colleagues without
                    coming back to Junction.
                </p>
                <div class="field">
                    <label class="label" for="u_name">Name</label>
                    <input class="input" type="text" id="u_name" name="name" required>
                </div>
                <div class="field">
                    <label class="label" for="u_email">Email</label>
                    <input class="input" type="email" id="u_email" name="email" required>
                </div>
                <div class="field">
                    <label class="label">Roles</label>
                    <div class="check-grid">
                        <?php foreach ($clientRoles as $role): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="roles[]" value="<?= e($role['slug']) ?>"
                                    <?= $role['slug'] === 'client.admin' ? 'checked' : '' ?>>
                                <span><?= e($role['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Send invitation</button>
            </form>
        </div>

        <div class="card">
            <h2 class="mt-0">Parts (<?= count($parts) ?>)</h2>
            <ul class="file-list">
                <?php foreach (array_slice($parts, 0, 8) as $part): ?>
                    <li>
                        <span><?= e($part['cpn']) ?> — <?= e($part['name']) ?></span>
                        <a href="<?= url('/staff/parts/' . $part['id']) ?>" class="btn btn-sm">View</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <h2 class="mt-0">Orders (<?= count($orders) ?>)</h2>
            <ul class="file-list">
                <?php foreach (array_slice($orders, 0, 8) as $order): ?>
                    <li>
                        <span><?= e($order['order_number']) ?></span>
                        <a href="<?= url('/staff/orders/' . $order['id']) ?>" class="btn btn-sm">View</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top: var(--space-3); display:flex; gap: var(--space-2)">
                <a href="<?= url('/staff/clients/' . $client['id'] . '/free-issue-note/new') ?>" class="btn btn-sm">New free-issue note</a>
                <a href="<?= url('/staff/clients/' . $client['id'] . '/delivery-note/new') ?>" class="btn btn-sm">New delivery note</a>
            </div>
        </div>
    </div>
</div>
