<?php
/** @var array $order */ /** @var array $notes */ /** @var array $queries */
use App\Core\Auth;
$canRaiseQuery = Auth::isStaff() || Auth::can('raise_queries');
$actionBase = Auth::isStaff() ? '/staff/orders/' : '/orders/';
?>
<div class="grid grid-2">
    <div class="card">
        <h2 class="mt-0">Notes</h2>
        <p class="text-muted">A timestamped log — not a conversation, just a record.</p>
        <?php if ($notes === []): ?>
            <p class="text-muted">No notes yet.</p>
        <?php else: ?>
            <ul class="file-list" style="align-items:flex-start">
                <?php foreach ($notes as $note): ?>
                    <li style="flex-direction:column; align-items:flex-start; gap:2px">
                        <span class="text-muted" style="font-size:0.8rem"><?= e($note['user_name']) ?> &middot; <?= format_datetime($note['created_at']) ?></span>
                        <span><?= nl2br(e($note['body'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="<?= url($actionBase . $order['id'] . '/notes') ?>" style="margin-top: var(--space-4)">
            <?= csrf_field() ?>
            <div class="field">
                <label for="note-body">Add a note</label>
                <textarea id="note-body" name="body" required></textarea>
            </div>
            <button type="submit" class="btn btn-sm">Add note</button>
        </form>
    </div>

    <div class="card">
        <h2 class="mt-0">Queries</h2>
        <p class="text-muted">Questions that expect a reply — tracked open or answered.</p>
        <?php if ($queries === []): ?>
            <p class="text-muted">No queries yet.</p>
        <?php else: ?>
            <?php foreach ($queries as $query): ?>
                <div style="border: 1px solid var(--border); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3)">
                    <div style="display:flex; justify-content:space-between; align-items:start; gap: var(--space-2)">
                        <strong><?= e($query['subject']) ?></strong>
                        <span class="badge <?= $query['status'] === 'answered' ? 'badge-ok' : 'badge-warn' ?>"><?= e(ucfirst($query['status'])) ?></span>
                    </div>
                    <p class="text-muted" style="font-size:0.82rem; margin: 2px 0 6px">By <?= e($query['raised_by_name']) ?> &middot; <?= format_datetime($query['created_at']) ?></p>
                    <p><?= nl2br(e($query['body'])) ?></p>
                    <?php foreach ($query['replies'] as $reply): ?>
                        <div style="margin-left: var(--space-4); padding-left: var(--space-3); border-left: 2px solid var(--border); margin-top: var(--space-2)">
                            <p class="text-muted" style="font-size:0.82rem; margin:0 0 2px">Reply by <?= e($reply['user_name']) ?> &middot; <?= format_datetime($reply['created_at']) ?></p>
                            <p style="margin:0"><?= nl2br(e($reply['body'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <form method="post" action="<?= url($actionBase . $order['id'] . '/queries/' . $query['id'] . '/reply') ?>" style="margin-top: var(--space-3)">
                        <?= csrf_field() ?>
                        <div class="field">
                            <textarea name="body" placeholder="Reply…" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm">Reply</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($canRaiseQuery): ?>
            <form method="post" action="<?= url($actionBase . $order['id'] . '/queries') ?>" style="margin-top: var(--space-4)">
                <?= csrf_field() ?>
                <div class="field"><label for="query-subject">Raise a query — subject</label><input type="text" id="query-subject" name="subject" required></div>
                <div class="field"><label for="query-body">Question</label><textarea id="query-body" name="body" required></textarea></div>
                <button type="submit" class="btn btn-sm">Raise query</button>
            </form>
        <?php endif; ?>
    </div>
</div>
