<?php
/** @var bool $enabled */ /** @var string $host */ /** @var string $port */ /** @var string $encryption */
/** @var string $username */ /** @var string $fromAddress */ /** @var string $fromName */
/** @var string $passwordSource */ /** @var array $problems */ /** @var bool $cryptoOk */
/** @var array $encryptions */ /** @var array $recentLog */
?>
<h1 class="mt-0">Email settings</h1>
<p><a href="<?= url('/staff/settings') ?>">&larr; Settings</a></p>

<?php if ($problems !== []): ?>
    <div class="card" style="border-color: var(--danger)">
        <h2 class="mt-0">Not ready to send</h2>
        <ul>
            <?php foreach ($problems as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="mt-0">SMTP connection</h2>
    <form method="post" action="<?= url('/staff/settings/email') ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label><input type="checkbox" name="mail_enabled" value="1" style="width:auto;min-height:auto" <?= $enabled ? 'checked' : '' ?>> Send email from this application</label>
        </div>
        <div class="form-row">
            <div class="field"><label for="host">SMTP host</label><input type="text" id="host" name="host" value="<?= e($host) ?>"></div>
            <div class="field"><label for="port">Port</label><input type="number" id="port" name="port" value="<?= e($port) ?>"></div>
            <div class="field">
                <label for="encryption">Encryption</label>
                <select id="encryption" name="encryption">
                    <?php foreach ($encryptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $encryption === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" value="<?= e($username) ?>"></div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="<?= $passwordSource !== 'unset' ? 'Leave blank to keep the current password (' . $passwordSource . ')' : '' ?>">
                <?php if (!$cryptoOk): ?><div class="hint text-danger">APP_KEY / openssl unavailable — the password can't be saved to Settings; set MAIL_PASSWORD in .env instead.</div><?php endif; ?>
            </div>
        </div>
        <?php if ($passwordSource !== 'unset'): ?>
            <div class="field"><label><input type="checkbox" name="mail_password_clear" value="1" style="width:auto;min-height:auto"> Clear the stored password</label></div>
        <?php endif; ?>
        <div class="form-row">
            <div class="field"><label for="from_address">"From" address</label><input type="email" id="from_address" name="from_address" value="<?= e($fromAddress) ?>"></div>
            <div class="field"><label for="from_name">"From" name</label><input type="text" id="from_name" name="from_name" value="<?= e($fromName) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<div class="card">
    <h2 class="mt-0">Send a test message</h2>
    <form method="post" action="<?= url('/staff/settings/email/test') ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="test_email">Send to</label>
            <input type="email" id="test_email" name="test_email" required>
        </div>
        <button type="submit" class="btn">Send test</button>
    </form>
</div>

<div class="card">
    <h2 class="mt-0">Recent activity</h2>
    <?php if ($recentLog === []): ?>
        <p class="text-muted">No emails sent yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sent</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recentLog as $entry): ?>
                    <tr>
                        <td><?= format_datetime($entry['sent_at']) ?></td>
                        <td><?= e($entry['to_email']) ?></td>
                        <td class="wrap"><?= e($entry['subject']) ?></td>
                        <td><span class="badge <?= $entry['status'] === 'sent' ? 'badge-ok' : 'badge-danger' ?>"><?= e($entry['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
