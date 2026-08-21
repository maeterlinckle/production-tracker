<?php
/** @var bool $connected */ /** @var bool $configured */ /** @var array $problems */
/** @var string $clientId */ /** @var string $redirectUri */ /** @var bool $hasSecret */
/** @var int|null $businessId */ /** @var int|null $accountCode */
/** @var string $vatTreatment */ /** @var string $vatRateKey */ /** @var int $paymentTermsDays */
/** @var array $businesses */ /** @var array $accountCodes */
/** @var array $vatTreatments */ /** @var array $vatRates */
/** @var string|null $lookupError */ /** @var array $scopes */
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
        <h2 class="mt-0">Before an invoice can be raised</h2>
        <ul class="plain-list">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="card card-ok">
        <p class="mb-0"><span class="badge badge-ok">Ready</span> Invoices can be raised from a delivery note.</p>
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

<?php if ($connected): ?>
    <?php if ($lookupError !== null): ?>
        <div class="card card-danger">
            <h2 class="mt-0">Could not read your Clear Books setup</h2>
            <p class="mb-0"><?= e($lookupError) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= url('/staff/settings/clearbooks/posting') ?>" class="card form">
        <?= csrf_field() ?>
        <h2 class="mt-0">Posting details</h2>
        <p class="muted">
            An invoice line cannot be posted without a nominal code and a VAT rate, and the API rejects
            the document without a VAT treatment. These lists come from your own Clear Books account.
        </p>

        <div class="field">
            <label class="label" for="business_id">Business</label>
            <select class="input" id="business_id" name="business_id">
                <option value="">Not set — only works if the login has a single business</option>
                <?php foreach ($businesses as $business): ?>
                    <option value="<?= (int) $business['id'] ?>" <?= $businessId === (int) $business['id'] ? 'selected' : '' ?>>
                        <?= e($business['name'] ?? ('Business ' . $business['id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Sent as the X-Business-ID header. Required only where the login has access to more than one.</p>
        </div>

        <div class="field">
            <label class="label" for="account_code">Sales account code</label>
            <select class="input" id="account_code" name="account_code">
                <option value="">Choose a nominal code</option>
                <?php foreach ($accountCodes as $code): ?>
                    <option value="<?= (int) $code['id'] ?>" <?= $accountCode === (int) $code['id'] ? 'selected' : '' ?>>
                        <?= e($code['name'] ?? ('Code ' . $code['id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Every invoice line is posted to this code. Only codes marked as sales codes are listed.</p>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="vat_treatment">VAT treatment</label>
                <select class="input" id="vat_treatment" name="vat_treatment">
                    <option value="">Choose a treatment</option>
                    <?php foreach ($vatTreatments as $treatment): ?>
                        <option value="<?= e($treatment['key'] ?? '') ?>" <?= $vatTreatment === ($treatment['key'] ?? '') ? 'selected' : '' ?>>
                            <?= e($treatment['name'] ?? $treatment['key'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Save this first — the rates below are the ones valid for the treatment.</p>
            </div>

            <div class="field">
                <label class="label" for="vat_rate_key">VAT rate</label>
                <select class="input" id="vat_rate_key" name="vat_rate_key">
                    <option value="">Choose a rate</option>
                    <?php foreach ($vatRates as $rate): ?>
                        <option value="<?= e($rate['key'] ?? '') ?>" <?= $vatRateKey === ($rate['key'] ?? '') ? 'selected' : '' ?>>
                            <?= e($rate['name'] ?? $rate['key'] ?? '') ?><?= isset($rate['rate']) ? ' (' . e((string) $rate['rate']) . '%)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label" for="payment_terms_days">Payment terms</label>
            <input class="input" type="number" id="payment_terms_days" name="payment_terms_days"
                   min="0" max="365" value="<?= (int) $paymentTermsDays ?>">
            <p class="field-hint">Days from the invoice date to the due date.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save posting details</button>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">Per-client customer mapping</h2>
    <p class="muted">
        Each client company needs the numeric ID of its Clear Books <em>customer</em> record before an
        invoice can be raised for it — set that on
        <a href="<?= url('/staff/clients') ?>">the client's own page</a>. It is the number in the Clear
        Books URL when you open that customer, not their name or account code.
    </p>
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
    </dl>
    <p class="field-hint mb-0">
        Taken from the published Clear Books OpenAPI description
        (<a href="https://api.clearbooks.co.uk/spec/v1.yaml" target="_blank" rel="noopener noreferrer">v1.yaml</a>),
        so they are fixed rather than configurable.
    </p>
</div>
