<?php /** @var array $invite */ /** @var string $token */ ?>
<h1 class="auth-title">Set your password</h1>
<p class="auth-subtitle">One step and the account is yours. Nobody else ever sees what you choose here.</p>

<div class="notice-inline invite-summary">
    <strong><?= e($invite['name']) ?></strong><br>
    You will sign in with <?= e($invite['email']) ?><br>
    <?= e($invite['client_name'] ?? 'Junction Inc Ltd') ?>
</div>

<form method="post" action="<?= url('/invite/' . $token) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="password">Choose a password</label>
        <div class="input-with-button">
            <input class="input" type="password" id="password" name="password"
                   minlength="10" required autofocus autocomplete="new-password">
            <button type="button" class="btn btn-inline" data-toggle-password="password">Show</button>
        </div>
        <p class="field-hint">At least 10 characters. Length beats punctuation — three unrelated words is a good password.</p>
    </div>

    <div class="field">
        <label class="label" for="password_confirm">Type it again</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm"
               minlength="10" required autocomplete="new-password">
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Set password and sign in</button>
    </div>
</form>
