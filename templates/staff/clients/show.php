<?php
/**
 * @var array      $client
 * @var array      $users
 * @var array      $parts
 * @var array      $orders
 * @var array|null $deactivation who switched the account off, and why
 * @var array      $purgeSummary what deleting the client would remove
 */
$isActive = (bool) $client['is_active'];
?>
<?= partial("partials/back-link", ["href" => "/staff/clients", "label" => "Back to clients"]) ?>

<div class="card-header">
    <h1 class="mt-0 mb-0">
        <?= e($client['name']) ?>
        <?php if (!$isActive): ?><span class="badge badge-muted">Account switched off</span><?php endif; ?>
    </h1>
</div>

<?php if (!$isActive): ?>
    <?php /*
        Said at the top, not buried in a panel further down: everything else on
        this page is about a company nobody can currently sign in as, and
        reading their order list without knowing that is how somebody spends
        ten minutes wondering why nothing is moving.
    */ ?>
    <div class="callout callout-warn">
        <p class="mb-1"><strong>This account is switched off.</strong></p>
        <p class="mb-0">
            Nobody on it can sign in, their orders are frozen where they stand, and their work is out of
            the day-to-day lists. Nothing has been deleted.
            <?php if ($deactivation !== null): ?>
                Switched off <?= e(format_datetime($deactivation['deactivated_at'])) ?><?php
                ?><?= $deactivation['deactivated_by_name'] ? ' by ' . e($deactivation['deactivated_by_name']) : '' ?><?php
                ?><?= $deactivation['deactivated_reason'] ? ' — ' . e($deactivation['deactivated_reason']) : '' ?>.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

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
                <div class="field"><label for="address_county">County</label><input type="text" id="address_county" name="address_county" value="<?= e($client['address_county'] ?? '') ?>"></div>
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
            <div class="form-row">
                <div class="field"><label for="vat_number">VAT number</label><input type="text" id="vat_number" name="vat_number" value="<?= e($client['vat_number'] ?? '') ?>"></div>
                <div class="field"><label for="company_number">Company number</label><input type="text" id="company_number" name="company_number" value="<?= e($client['company_number'] ?? '') ?>"></div>
            </div>
            <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= e($client['notes'] ?? '') ?></textarea></div>
            <?php /* Switching the account off is its own action, below. */ ?>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>

        <?php /*
            Its own form, outside the one above, because it is not an edit: it
            fetches and writes in one go. Nesting it would have meant one submit
            button quietly carrying the other form's half-typed values.

            On demand and never in the background — a client's address changing
            in Clear Books is not something this application should act on
            silently. The flash says which fields moved, because "updated" with
            nothing after it is a claim nobody can check.
        */ ?>
        <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/from-clearbooks') ?>"
              style="margin-top: var(--space-5)">
            <?= csrf_field() ?>
            <h3 class="line-section-title">Clear Books</h3>
            <?php if (empty($client['clearbooks_entity_id'])): ?>
                <p class="text-muted mb-0">
                    Set the Clear Books customer ID above and save, and their details can be pulled from
                    there rather than typed twice.
                </p>
            <?php else: ?>
                <p class="text-muted">
                    <?php if ($client['clearbooks_synced_at'] !== null): ?>
                        Last pulled <?= format_datetime($client['clearbooks_synced_at']) ?><?php
                        ?><?= $client['synced_by_name'] !== null ? ' by ' . e($client['synced_by_name']) : '' ?>.
                    <?php else: ?>
                        Never pulled from Clear Books — everything here was typed in.
                    <?php endif; ?>
                    Their record over there is the one accounts works from, so it is worth taking as the
                    truth for the address and the billing contact.
                </p>
                <button type="submit" class="btn">Update from Clear Books</button>
            <?php endif; ?>
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
                        <li class="user-row">
                            <span>
                                <?= e($user['name']) ?> <span class="muted"><?= e($user['email']) ?></span>
                                <?php if (!(bool) $user['is_active']): ?>
                                    <span class="badge badge-muted">Deactivated</span>
                                <?php endif; ?>

                                <?php /*
                                    Correcting a name or an email, and switching
                                    somebody off. Deactivating rather than
                                    deleting: everything they raised keeps their
                                    name on it.
                                */ ?>
                                <details class="caption-edit">
                                    <summary>Edit</summary>
                                    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/users/' . $user['id']) ?>">
                                        <?= csrf_field() ?>
                                        <div class="form-row">
                                            <div class="field">
                                                <label for="cu_name_<?= (int) $user['id'] ?>">Name</label>
                                                <input type="text" id="cu_name_<?= (int) $user['id'] ?>" name="name"
                                                       value="<?= e($user['name']) ?>" required>
                                            </div>
                                            <div class="field">
                                                <label for="cu_email_<?= (int) $user['id'] ?>">Email</label>
                                                <input type="email" id="cu_email_<?= (int) $user['id'] ?>" name="email"
                                                       value="<?= e($user['email']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="hint">Changing the email changes what they sign in with.</div>
                                        <button type="submit" class="btn btn-sm">Save details</button>
                                    </form>
                                </details>
                            </span>
                            <span class="media-actions">
                                <?php if (!$user['has_password']): ?>
                                    <span class="badge badge-warn">Invited</span>
                                    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/users/' . $user['id'] . '/reinvite') ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm">Re-send</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?= $user['is_active'] ? 'badge-ok' : 'badge-muted' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                                <?php endif; ?>
                                <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/users/' . $user['id'] . '/toggle-active') ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm"><?= $user['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                                </form>
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

        <?php /*
            Switching the whole account off. Its own panel with its own reason,
            rather than a checkbox on the details form somebody clears while
            editing a postcode — this stops a company working and is worth the
            deliberate act.
        */ ?>
        <div class="card">
            <h2 class="mt-0"><?= $isActive ? 'Switch this account off' : 'Switch this account back on' ?></h2>

            <?php if ($isActive): ?>
                <p class="text-muted">
                    For a client who has stopped trading with Junction. Their orders freeze where they
                    stand rather than being cancelled, their orders and parts drop out of the day-to-day
                    lists, and nobody on the account can sign in. Everything is kept and comes straight
                    back if the account is switched on again.
                </p>
                <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/active') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="active" value="0">
                    <div class="field">
                        <label for="deactivate_reason">Why</label>
                        <input type="text" id="deactivate_reason" name="reason" required
                               placeholder="e.g. Ceased trading, or moved to another supplier">
                        <div class="hint">Shown here afterwards. It is the only record of the decision.</div>
                    </div>
                    <button type="submit" class="btn">Switch off <?= e($client['name']) ?></button>
                </form>
            <?php else: ?>
                <p class="text-muted">
                    Everything comes back exactly as it was: the orders unfreeze at the stage they
                    stopped at, the parts return to the lists, and the people who could sign in before
                    can sign in again. Anybody deactivated individually stays deactivated.
                </p>
                <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/active') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="active" value="1">
                    <button type="submit" class="btn btn-primary">Reactivate <?= e($client['name']) ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php /*
            Deleting for good.

            Only offered once the account is off, because deciding to stop
            working with somebody and deciding to erase them are different
            decisions and should be made on different days. Folded away, and
            behind their name typed out in full: a button and an "are you sure"
            are two clicks in the same place, and there is no undo behind this
            one.
        */ ?>
        <?php if (!$isActive): ?>
        <div class="card danger-card">
            <h2 class="mt-0">Delete this client for good</h2>
            <p class="text-muted">
                Everything else in the tracker is archived rather than deleted, so that history stays
                readable. This is the exception, for an account Junction has finished with entirely.
            </p>

            <details class="disclosure-action">
                <summary class="btn">Delete <?= e($client['name']) ?> permanently</summary>

                <div style="margin-top: var(--space-3)">
                    <p class="mb-2"><strong>This cannot be undone.</strong> It will permanently remove:</p>

                    <ul class="purge-list">
                        <?php foreach ($purgeSummary as $label => $count): ?>
                            <li>
                                <strong><?= (int) $count ?></strong> <?= e($label) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="text-muted">
                        Their parts, drawings, orders, delivery notes, invoices, queries, photos and user
                        accounts all go, and every file listed above is deleted from disk. Nothing about
                        this client will be recoverable from the application afterwards — only from a
                        backup taken before now.
                    </p>

                    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/delete') ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="confirm_name">Type <strong><?= e($client['name']) ?></strong> to confirm</label>
                            <input type="text" id="confirm_name" name="confirm_name" autocomplete="off" required
                                   placeholder="<?= e($client['name']) ?>">
                        </div>
                        <button type="submit" class="btn btn-danger">Delete this client and all of their data</button>
                    </form>
                </div>
            </details>
        </div>
        <?php endif; ?>
    </div>
</div>
