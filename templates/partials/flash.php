<?php

use App\Core\Flash;

/**
 * One-shot messages.
 *
 * Only **confirmations** time out. A success banner says "the thing you just
 * did worked", and its result is on the page behind it — nobody needs to read
 * that twice. An error, a warning or a piece of information is the opposite:
 * it is usually the only place the problem is stated, and a warning that
 * removes itself before it is read is worse than no warning at all. So the
 * timer is attached per message, not to the stack.
 *
 * The dismiss button stays on every message whatever the timer says.
 */
$messages = Flash::messages();

if ($messages === []) {
    return;
}

$seconds = 6;
?>
<div class="flash-stack" role="status" aria-live="polite">
    <?php foreach ($messages as $message): ?>
        <?php $autoHide = $message['type'] === 'success'; ?>
        <div class="flash flash-<?= e($message['type']) ?>"
            <?= $autoHide ? 'data-flash-autohide="' . (int) $seconds . '"' : '' ?>>
            <span class="flash-text"><?= e($message['message']) ?></span>
            <button type="button" class="flash-close" data-dismiss aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
