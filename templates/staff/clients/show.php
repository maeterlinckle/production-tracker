<?php
/**
 * @var array      $client
 * @var array      $users
 * @var array      $parts
 * @var array      $orders
 * @var array|null $deactivation who switched the account off, and why
 * @var array      $purgeSummary what deleting the client would remove
 * @var bool       $canInvoice   whether the viewer holds staff.invoicing
 * @var \App\Services\ClearBooksPosting $posting how this client's invoices are posted
 * @var array|null $clearbooks   the live lists to choose from, or null without the role
 */

use App\Services\ClearBooksPosting;

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
    <?php /* The left column is a wrapper rather than a single card: the details
       and the invoicing settings are two cards that belong under each other. */ ?>
    <div>
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

    <?php /*
        How this client's invoices are posted.

        These were one global set under Settings until it turned out that
        Junction's clients do not agree with each other about any of it —
        different nominal codes for different kinds of work, different VAT
        treatments for the export customers, and payment terms that are a
        negotiation rather than a house rule. One set of values applied to
        everybody was wrong for everybody but whoever it was first set up for.

        Its own form and its own endpoint, under staff.invoicing rather than
        manage_clients: choosing the nominal code every invoice lands on is
        accounts work. Sharing the details form's submit button would have meant
        whoever corrects a postcode silently re-saving the VAT treatment too.
    */ ?>
    <?php if ($canInvoice): ?>
    <div class="card">
        <h2 class="mt-0">Clear Books invoicing</h2>

        <?php if ($clearbooks['connected'] === false): ?>
            <p class="text-muted mb-0">
                Clear Books is not connected. A staff administrator connects it once, under
                <a href="<?= url('/staff/settings/clearbooks') ?>">Settings</a>, and the lists to choose
                from below are then read from your own account.
            </p>
        <?php else: ?>
            <?php if ($clearbooks['lookupError'] !== null): ?>
                <div class="callout callout-warn">
                    <p class="mb-0">
                        Could not read your Clear Books setup: <?= e($clearbooks['lookupError']) ?>
                        The saved values below are still what will be sent.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($posting->problems() !== []): ?>
                <div class="callout callout-warn">
                    <p class="mb-1"><strong>Before this client can be invoiced</strong></p>
                    <ul class="plain-list mb-0">
                        <?php foreach ($posting->problems() as $problem): ?>
                            <li><?= e($problem) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <p class="mb-2"><span class="badge badge-ok">Ready</span> Invoices can be raised for this client.</p>
            <?php endif; ?>

            <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/clearbooks') ?>">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="business_id">Business</label>
                    <select id="business_id" name="business_id">
                        <option value="">Not set — only works if the login has a single business</option>
                        <?php foreach ($clearbooks['businesses'] as $business): ?>
                            <option value="<?= (int) $business['id'] ?>" <?= $posting->businessId === (int) $business['id'] ? 'selected' : '' ?>>
                                <?= e($business['name'] ?? ('Business ' . $business['id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">
                        Sent as the X-Business-ID header. Save a change here first — the codes and rates
                        below belong to a business, so they are re-read once this is stored.
                    </div>
                </div>

                <div class="field">
                    <label for="account_code">Sales account code</label>
                    <select id="account_code" name="account_code">
                        <option value="">Choose a nominal code</option>
                        <?php foreach ($clearbooks['accountCodes'] as $code): ?>
                            <option value="<?= (int) $code['id'] ?>" <?= $posting->accountCode === (int) $code['id'] ? 'selected' : '' ?>>
                                <?= e($code['name'] ?? ('Code ' . $code['id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Every line of this client's invoices is posted to this code. Only sales codes are listed.</div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="vat_treatment">VAT treatment</label>
                        <select id="vat_treatment" name="vat_treatment">
                            <option value="">Choose a treatment</option>
                            <?php foreach ($clearbooks['vatTreatments'] as $treatment): ?>
                                <option value="<?= e($treatment['key'] ?? '') ?>" <?= $posting->vatTreatment === ($treatment['key'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($treatment['name'] ?? $treatment['key'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">Save this first — the rates beside it are the ones valid for the treatment.</div>
                    </div>

                    <div class="field">
                        <label for="vat_rate_key">VAT rate</label>
                        <select id="vat_rate_key" name="vat_rate_key">
                            <option value="">Choose a rate</option>
                            <?php foreach ($clearbooks['vatRates'] as $rate): ?>
                                <option value="<?= e($rate['key'] ?? '') ?>" <?= $posting->vatRateKey === ($rate['key'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($rate['name'] ?? $rate['key'] ?? '') ?><?= isset($rate['rate']) ? ' (' . e((string) $rate['rate']) . '%)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php /*
                    Whether to send a due date at all.

                    The due-date rules in the Clear Books interface are richer
                    than the single date the API accepts — end of month
                    following, and the like. Where the API cannot reproduce what
                    this client's terms actually are, a date worked out here is
                    worse than none: leaving it off lets Clear Books apply that
                    contact's own default, which is where the real rule lives.
                */ ?>
                <h3 class="line-section-title">Due date</h3>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="send_due_date" value="1" <?= $posting->sendDueDate ? 'checked' : '' ?>>
                        <span>Send a due date on the invoice</span>
                    </label>
                    <div class="hint">
                        Unticked, no due date is sent and Clear Books falls back to this contact's own
                        default over there. That is the better answer for a client whose terms are
                        something the API cannot express — end of the month following, say — because the
                        rule is already set up correctly in Clear Books.
                    </div>
                </div>

                <div class="field">
                    <label for="payment_terms_days">Payment terms</label>
                    <input type="number" id="payment_terms_days" name="payment_terms_days"
                           min="0" max="365" value="<?= (int) $posting->paymentTermsDays ?>">
                    <div class="hint">Days from the invoice date to the due date. Ignored while the box above is unticked.</div>
                </div>

                <?php /*
                    The invoice summary. Clear Books calls this field
                    `description` in the API and "Summary" in their interface —
                    their own spec says so — and it is the line the client's
                    accounts payable reads before anything else.

                    A template rather than a fixed string, because the useful
                    version of it names this invoice: their PO number, the order
                    it came from. Written once here and rendered per invoice.
                */ ?>
                <h3 class="line-section-title">Invoice summary</h3>
                <div class="field">
                    <label for="invoice_summary">Summary written on every invoice</label>
                    <input type="text" id="invoice_summary" name="invoice_summary" maxlength="255"
                           value="<?= e($client['clearbooks_invoice_summary'] ?? '') ?>"
                           placeholder="<?= e(ClearBooksPosting::exampleSummary()) ?>">
                    <div class="hint">
                        Goes into the invoice's <strong>Summary</strong> field in Clear Books. Leave it
                        blank and no summary is sent. Placeholders in curly brackets are replaced when
                        the invoice is raised:
                    </div>
                    <dl class="merge-fields">
                        <?php foreach (ClearBooksPosting::PLACEHOLDERS as $token => $meaning): ?>
                            <dt class="mono">{<?= e($token) ?>}</dt>
                            <dd><?= e($meaning) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <div class="hint">
                        Anything else in curly brackets is left on the invoice exactly as typed, so a
                        misspelt placeholder shows up rather than quietly disappearing.
                    </div>
                </div>

                <p class="text-muted">
                    The purchase orders behind an invoice are attached to it in Clear Books
                    automatically — every PO document on every order the delivery note covers, the
                    amendments included.
                </p>

                <button type="submit" class="btn btn-primary">Save Clear Books settings</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
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
