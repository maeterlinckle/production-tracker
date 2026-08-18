<?php
/** @var bool $enabled */ /** @var int $intervalDays */ /** @var int $ageingDays */
/** @var array $recipients */ /** @var array|null $lastRun */ /** @var array $recentRuns */
/** @var bool $mailReady */ /** @var bool $templateActive */ /** @var string $cronCommand */
?>
<div class="page-head">
    <div>
        <h1>Reminders</h1>
        <p class="muted">A scheduled digest of parts still outstanding, sent to Junction staff who ask for it.</p>
    </div>
    <a href="<?= url('/staff/settings') ?>" class="btn">Back to settings</a>
</div>

<?php if (!$mailReady): ?>
    <div class="card card-danger">
        <p class="mb-0">
            Email is not working yet, so nothing can be sent whatever is set here.
            <a href="<?= url('/staff/settings/email') ?>">Fix the connection first</a>.
        </p>
    </div>
<?php elseif (!$templateActive): ?>
    <div class="card card-warn">
        <p class="mb-0">
            The “Parts outstanding digest” template is switched off, so the schedule will not send.
            <a href="<?= url('/staff/settings/email/templates/parts_outstanding') ?>">Switch it back on</a>.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/staff/settings/email/reminders') ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label class="checkbox">
            <input type="checkbox" name="reminders_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            <span>Send the outstanding-parts digest</span>
        </label>
        <p class="field-hint">
            Off by default. When there is nothing outstanding no message is sent at all — an empty
            digest teaches people the email is not worth opening.
        </p>
    </div>

    <div class="field-row">
        <div class="field">
            <label class="label" for="interval_days">Send at most every</label>
            <input class="input" type="number" id="interval_days" name="interval_days"
                   min="1" max="30" value="<?= (int) $intervalDays ?>">
            <p class="field-hint">
                In days. The schedule itself is cron's (below); this stops a second run inside the same
                window, so an extra cron entry or a re-run after a failure cannot double up.
            </p>
        </div>

        <div class="field">
            <label class="label" for="ageing_days">Call out lines open longer than</label>
            <input class="input" type="number" id="ageing_days" name="ageing_days"
                   min="1" max="365" value="<?= (int) $ageingDays ?>">
            <p class="field-hint">
                In days. Also drives the “open more than N days” figure on the parts-on-order report.
            </p>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>

<div class="card">
    <h2 class="mt-0">Who receives it</h2>

    <?php if ($recipients === []): ?>
        <p class="muted">
            Nobody yet. This one is opt-in like every other notification: each person ticks
            <em>“The scheduled digest of parts still outstanding”</em> on their own
            <a href="<?= url('/preferences') ?>">Email notifications</a> page. Only staff are offered it.
        </p>
    <?php else: ?>
        <ul class="file-list">
            <?php foreach ($recipients as $user): ?>
                <li>
                    <span><?= e($user['name']) ?> <span class="muted"><?= e($user['email']) ?></span></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?= url('/staff/settings/email/reminders/run') ?>" style="margin-top: var(--space-4)">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Send it now</button>
        <p class="field-hint">
            Runs exactly what cron runs, ignoring the interval. Still sends nothing if there is nothing
            outstanding.
        </p>
    </form>
</div>

<div class="card">
    <h2 class="mt-0">Running it on a schedule</h2>
    <p class="muted">
        Nothing sends by itself — the server has to call the script. Add one crontab line; the interval
        above decides whether a given call actually sends, so calling it daily is safe whatever you have
        set.
    </p>

    <pre class="email-preview">0 7 * * * <?= e($cronCommand) ?></pre>

    <p class="field-hint mb-0">
        That runs at 07:00 every day. The script writes nothing to standard output when there is nothing
        to do, so cron will not email you about it.
    </p>
</div>

<div class="card">
    <div class="card-head">
        <h2>Recent runs</h2>
        <?php if ($lastRun !== null): ?>
            <span class="muted">Last ran <?= e(format_datetime($lastRun['ran_at'])) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($recentRuns === []): ?>
        <p class="empty-state mb-0">It has never run.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead>
                    <tr><th>When</th><th class="align-right">Lines</th><th class="align-right">Sent</th><th class="align-right">Failed</th><th>Triggered by</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentRuns as $run): ?>
                    <tr>
                        <td><?= e(format_datetime($run['ran_at'])) ?></td>
                        <td class="align-right"><?= (int) $run['items'] ?></td>
                        <td class="align-right"><?= (int) $run['sent'] ?></td>
                        <td class="align-right"><?= (int) $run['failed'] > 0 ? '<span class="text-danger">' . (int) $run['failed'] . '</span>' : '0' ?></td>
                        <td><?= $run['triggered_by_name'] === null ? '<span class="muted">cron</span>' : e($run['triggered_by_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
