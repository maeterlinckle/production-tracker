<?php /** @var array $errors */ /** @var array $old */ ?>
<h1 class="auth-title">Sign in</h1>
<p class="auth-subtitle">Enter your email and password to continue.</p>

<form method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="email">Email</label>
        <input class="input" type="email" id="email" name="email" value="<?= old($old, 'email') ?>"
               required autofocus autocomplete="username">
    </div>

    <div class="field">
        <label class="label" for="password">Password</label>
        <div class="input-with-button">
            <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="btn btn-inline" data-toggle-password="password">Show</button>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
    </div>
</form>

<p class="auth-help muted">
    No account yet? Access is by invitation — ask your company's administrator, or Junction, to send you one.
</p>
