<?php /** @var array $template */ /** @var array $preview */ ?>
<?= partial("partials/back-link", ["href" => "/staff/settings/email/templates", "label" => "Back to email templates"]) ?>

<div class="page-head">
    <div>
        <h1><?= e($template['name']) ?></h1>
        <p class="muted"><?= e($template['description']) ?></p>
    </div>
    <a href="<?= url('/staff/settings/email/templates') ?>" class="btn">All templates</a>
</div>

<?php if ($template['is_customised'] === true): ?>
    <div class="card notice-card">
        <p>
            Edited<?= $template['updated_by_name'] !== null ? ' by ' . e((string) $template['updated_by_name']) : '' ?><?php
            ?><?= $template['updated_at'] !== null ? ' on ' . e(format_datetime((string) $template['updated_at'])) : '' ?>.
            Resetting puts back the wording the application ships with.
        </p>
        <form method="post" action="<?= url('/staff/settings/email/templates/' . $template['key'] . '/reset') ?>"
              onsubmit="return confirm('Discard your version and go back to the built-in wording?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Reset to the built-in wording</button>
        </form>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/staff/settings/email/templates/' . $template['key']) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label class="label" for="subject">Subject</label>
            <input class="input" type="text" id="subject" name="subject" maxlength="255" required
                   value="<?= e($template['subject']) ?>">
        </div>

        <div class="field">
            <label class="label" for="body">Message</label>
            <textarea class="input textarea-tall" id="body" name="body" rows="18" required
                      spellcheck="true"><?= e($template['body']) ?></textarea>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_html" value="1" <?= $template['is_html'] === true ? 'checked' : '' ?>>
                <span>This message is written in HTML</span>
            </label>
            <p class="field-hint">
                Leave this off unless you have written HTML above. A plain-text version is generated
                automatically and sent alongside, so the message stays readable in any mail client.
                Merged values are escaped, so an apostrophe in a company name cannot break the markup.
            </p>
        </div>

        <?php if ($template['can_be_disabled'] === true): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1" <?= $template['is_active'] === true ? 'checked' : '' ?>>
                    <span>Send this message</span>
                </label>
                <p class="field-hint">
                    Untick to suppress this one message without switching off email altogether.
                </p>
            </div>
        <?php else: ?>
            <p class="field-hint">
                This message carries a link somebody is waiting for, so it cannot be switched off on its
                own — the screen would go on promising an email that never arrived. Turn off email
                altogether in <a href="<?= url('/staff/settings/email') ?>">Settings &rarr; Email</a> if
                that is what you want, and set passwords directly instead.
            </p>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save template</button>
        <a class="btn btn-ghost" href="<?= url('/staff/settings/email/templates') ?>">Cancel</a>
    </div>
</form>

<div class="grid grid-2">
    <div class="card">
        <h2 class="mt-0">Merge fields</h2>
        <p class="muted">
            Type these anywhere in the subject or the message and they are replaced when it is sent.
            Anything not on this list is refused when you save, rather than coming out blank in
            somebody's inbox.
        </p>

        <dl class="merge-fields">
            <?php foreach ($template['fields'] as $field => $description): ?>
                <dt class="mono">{{<?= e($field) ?>}}</dt>
                <dd><?= e($description) ?></dd>
            <?php endforeach; ?>
        </dl>
    </div>

    <div class="card">
        <h2 class="mt-0">Preview</h2>
        <p class="muted">The wording as saved, filled in with example values.</p>

        <p><strong>Subject:</strong> <?= e($preview['subject']) ?></p>

        <?php if ($template['is_html'] === true): ?>
            <p class="field-hint">
                Shown as the plain-text version that goes out alongside the HTML, so the words can be
                judged without the markup getting in the way.
            </p>
        <?php endif; ?>

        <pre class="email-preview"><?= e($preview['body']) ?></pre>
    </div>
</div>
