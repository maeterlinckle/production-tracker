<?php /** @var array $grouped */ /** @var int $customisedCount */ ?>
<div class="page-head">
    <div>
        <h1>Email templates</h1>
        <p class="muted">The wording of everything the tracker sends. Edit any of it; reset any of it back.</p>
    </div>
    <a href="<?= url('/staff/settings') ?>" class="btn">Back to settings</a>
</div>

<div class="card notice-card">
    <p class="mb-0">
        <?php if ($customisedCount === 0): ?>
            Every message is using the built-in wording. Editing one stores your version; the original is
            always one button away.
        <?php else: ?>
            <strong><?= (int) $customisedCount ?></strong> of these have been edited. The rest use the
            built-in wording.
        <?php endif; ?>
    </p>
</div>

<?php foreach ($grouped as $group => $templates): ?>
    <h2 class="section-title"><?= e($group) ?></h2>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Message</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($templates as $template): ?>
                <tr>
                    <td>
                        <strong><?= e($template['name']) ?></strong>
                        <div class="cell-sub"><?= e($template['description']) ?></div>
                    </td>
                    <td class="wrap"><span class="mono"><?= e($template['subject']) ?></span></td>
                    <td>
                        <?php if ($template['is_active'] !== true): ?>
                            <span class="badge badge-warn">Not sending</span>
                        <?php elseif ($template['is_customised'] === true): ?>
                            <span class="badge badge-role">Edited</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Built-in</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="<?= url('/staff/settings/email/templates/' . $template['key']) ?>" class="btn btn-sm">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
