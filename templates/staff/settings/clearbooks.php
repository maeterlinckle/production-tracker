<?php
/** @var bool $connected */ /** @var bool $configured */ /** @var array $problems */
/** @var string $clientId */ /** @var string $redirectUri */ /** @var bool $hasSecret */
/** @var array $clients each active client with the posting problems still outstanding on it */
/** @var array $scopes */
/** @var string $authorizeUrl */ /** @var string $apiBase */ /** @var array|null $token */
?>
<div class="page-head">
    <div>
        <h1>Clear Books</h1>
        <p class="muted">The accounting connection used to raise a sales invoice from a Completed Parts Sent note.</p>
    </div>
    <a href="<?= url('/staff/settings') ?>" class="btn">Back to settings</a>
</div>

<?php if ($problems !== []): ?>
    <div class="card card-warn">
        <h2 class="mt-0">Before anything can be raised</h2>
        <ul class="plain-list">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="card card-ok">
        <p class="mb-0">
            <span class="badge badge-ok">Connected</span>
            The connection is healthy. Whether a particular client can be invoiced depends on their own
            posting details — listed below.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/staff/settings/clearbooks') ?>" class="card form">
    <?= csrf_field() ?>
    <h2 class="mt-0">API client</h2>
    <p class="muted">
        Clear Books authenticates with OAuth 2 (authorization code grant, confidential client) — there is
        no static API key. Request API access and register a client at
        <a href="https://www.clearbooks.co.uk/support/api/" target="_blank" rel="noopener noreferrer">clearbooks.co.uk/support/api</a>,
        giving them the redirect URI below.
    </p>

    <div class="field">
        <label class="label" for="client_id">Client ID</label>
        <input class="input" type="text" id="client_id" name="client_id" value="<?= e($clientId) ?>">
    </div>

    <div class="field">
        <label class="label" for="client_secret">Client secret</label>
        <input class="input" type="password" id="client_secret" name="client_secret" autocomplete="off"
               placeholder="<?= $hasSecret ? 'Leave blank to keep the stored secret' : '' ?>">
        <p class="field-hint">Blank leaves the stored secret alone, so editing the redirect URI cannot wipe it.</p>
    </div>

    <div class="field">
        <label class="label" for="redirect_uri">Redirect URI</label>
        <input class="input" type="text" id="redirect_uri" name="redirect_uri" value="<?= e($redirectUri) ?>">
        <p class="field-hint">Must match what Clear Books has registered, character for character.</p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save API client</button>
    </div>
</form>

<div class="card">
    <h2 class="mt-0">Connection</h2>

    <?php if ($connected): ?>
        <p><span class="badge badge-ok">Connected</span></p>
        <?php if ($token !== null): ?>
            <p class="muted">Access token renews automatically; the current one expires <?= e(format_datetime($token['expires_at'])) ?>.</p>
        <?php endif; ?>
        <p class="field-hint">
            Clear Books allows one access token per user per application, so reconnecting revokes the
            token this application is using now. That is fine — it is issued a fresh pair immediately —
            but there is no reason to do it unless something is wrong.
        </p>
        <div class="form-actions">
            <a href="<?= url('/staff/settings/clearbooks/connect') ?>" class="btn">Reconnect</a>
            <form method="post" action="<?= url('/staff/settings/clearbooks/disconnect') ?>"
                  onsubmit="return confirm('Delete the stored Clear Books tokens? Invoicing stops working until somebody connects again.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Forget the stored tokens</button>
            </form>
        </div>
    <?php else: ?>
        <p><span class="badge badge-warn">Not connected</span></p>
        <?php if ($configured): ?>
            <p class="muted">
                Sends you to Clear Books to sign in and approve the connection, then back here. The
                request is protected with PKCE and asks for these scopes:
            </p>
            <ul class="permission-chips">
                <?php foreach ($scopes as $scope): ?>
                    <li class="chip mono"><?= e($scope) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="form-actions">
                <a href="<?= url('/staff/settings/clearbooks/connect') ?>" class="btn btn-primary">Connect Clear Books</a>
            </div>
        <?php else: ?>
            <p class="muted">Save a client ID, secret and redirect URI above first.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>


<?php /*
    Posting details used to live here, as one set of values applied to every
    client. They are per client now -- see the client's own page -- because
    Junction's customers do not agree with each other about the nominal code the
    work belongs to, the VAT treatment, or how long they have to pay. What is
    still global is above: one Clear Books account, one client secret, one token
    pair.

    This card is the index of that. Somebody who has just connected wants to
    know who can be invoiced, and the honest answer is "these clients, not
    those" rather than a single Ready badge.
*/ ?>
<div class="card">
    <h2 class="mt-0">Posting details, per client</h2>
    <p class="muted">
        Which business, which nominal code, which VAT treatment and rate, how long they have to pay,
        whether to send a due date at all, and the invoice summary are all set on
        <a href="<?= url('/staff/clients') ?>">the client's own page</a>, under Clear Books invoicing.
        So is the numeric ID of their Clear Books <em>customer</em> record — the number in the Clear
        Books URL when you open that customer, not their name or account code.
    </p>

    <?php if (!$connected): ?>
        <p class="field-hint mb-0">Connect above and the lists to choose from can be read from your own account.</p>
    <?php elseif ($clients === []): ?>
        <p class="field-hint mb-0">There are no active clients yet.</p>
    <?php else: ?>
        <ul class="file-list">
            <?php foreach ($clients as $row): ?>
                <li>
                    <span>
                        <?= e($row['name']) ?>
                        <?php if ($row['problems'] === []): ?>
                            <span class="badge badge-ok">Ready to invoice</span>
                        <?php else: ?>
                            <span class="badge badge-warn"><?= count($row['problems']) ?> to set</span>
                            <span class="muted"><?= e(implode(' ', $row['problems'])) ?></span>
                        <?php endif; ?>
                    </span>
                    <a href="<?= url('/staff/clients/' . $row['id']) ?>" class="btn btn-sm">Open</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>


<div class="card">
    <h2 class="mt-0">What this talks to</h2>
    <dl class="merge-fields">
        <dt>Authorisation</dt>
        <dd class="mono"><?= e($authorizeUrl) ?></dd>
        <dt>API</dt>
        <dd class="mono"><?= e($apiBase) ?></dd>
        <dt>Invoices</dt>
        <dd class="mono">POST <?= e($apiBase) ?>/accounting/sales/invoices</dd>
        <dt>PO attachments</dt>
        <dd class="mono">POST <?= e($apiBase) ?>/accounting/sales/invoices/{id}/attachments/{filename}</dd>
    </dl>
    <p class="field-hint mb-0">
        Taken from the published Clear Books OpenAPI description
        (<a href="https://api.clearbooks.co.uk/spec/v1.yaml" target="_blank" rel="noopener noreferrer">v1.yaml</a>),
        so they are fixed rather than configurable.
    </p>
</div>
