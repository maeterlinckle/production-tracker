<?php
/** @var bool $clearBooksConnected */ /** @var bool $hasLogo */ /** @var int $clientCount */
/** @var int $staffCount */ /** @var int $customisedTemplates */ /** @var bool $remindersEnabled */
?>
<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Configuration for the whole tracker. Visible to staff administrators only.</p>
    </div>
</div>

<h2 class="section-title">People</h2>

<div class="card-grid">
    <a href="<?= url('/staff/clients') ?>" class="card report-card">
        <h3>Clients</h3>
        <p>The client companies Junction works for, their Clear Books reference, and the people who can sign in for each one.</p>
        <span class="report-card-go muted"><?= (int) $clientCount ?> <?= $clientCount === 1 ? 'client' : 'clients' ?> &rarr;</span>
    </a>

    <a href="<?= url('/staff/settings/users') ?>" class="card report-card">
        <h3>Users</h3>
        <p>Junction's own staff accounts. Invite a colleague, change which roles they hold, or take away access.</p>
        <span class="report-card-go muted"><?= (int) $staffCount ?> staff <?= $staffCount === 1 ? 'account' : 'accounts' ?> &rarr;</span>
    </a>
</div>

<h2 class="section-title">Appearance</h2>

<div class="card-grid">
    <a href="<?= url('/staff/settings/branding') ?>" class="card report-card">
        <h3>Logo</h3>
        <p>Shown in the top navigation, on the sign-in page, on printed paperwork and in outbound email.</p>
        <span class="report-card-go muted"><?= $hasLogo ? 'Uploaded' : 'Not set — using the text mark' ?> &rarr;</span>
    </a>
</div>

<h2 class="section-title">Email</h2>

<div class="card-grid">
    <a href="<?= url('/staff/settings/email') ?>" class="card report-card">
        <h3>Connection</h3>
        <p>The SMTP server every notification is sent through, plus a test message and the recent send log.</p>
        <span class="report-card-go muted">Configure &rarr;</span>
    </a>

    <a href="<?= url('/staff/settings/email/templates') ?>" class="card report-card">
        <h3>Templates</h3>
        <p>The wording of each notification. Edit the subject and body, use merge fields, and revert to the built-in text at any time.</p>
        <span class="report-card-go muted">
            <?= $customisedTemplates === 0 ? 'All using the built-in wording' : $customisedTemplates . ' edited' ?> &rarr;
        </span>
    </a>

    <a href="<?= url('/staff/settings/email/reminders') ?>" class="card report-card">
        <h3>Reminders</h3>
        <p>The scheduled digest of parts still outstanding, and who at Junction receives it.</p>
        <span class="report-card-go muted"><?= $remindersEnabled ? 'On' : 'Off' ?> &rarr;</span>
    </a>
</div>

<h2 class="section-title">Accounting</h2>

<div class="card-grid">
    <a href="<?= url('/staff/settings/clearbooks') ?>" class="card report-card">
        <h3>Clear Books <span class="badge <?= $clearBooksConnected ? 'badge-ok' : 'badge-warn' ?>"><?= $clearBooksConnected ? 'Connected' : 'Not connected' ?></span></h3>
        <p>API client credentials and the OAuth connection used to raise sales invoices from a delivery note.</p>
        <span class="report-card-go muted">Configure &rarr;</span>
    </a>
</div>
