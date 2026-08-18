<?php /** @var int $status */ /** @var string $title */ /** @var string $message */ ?>
<div class="empty-state">
    <h1><?= e((string) $status) ?> — <?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a href="<?= url('/') ?>" class="btn btn-primary">Back to dashboard</a></p>
</div>
