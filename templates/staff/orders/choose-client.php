<?php /** @var array $clients */ ?>
<?= partial("partials/back-link", ["href" => "/staff/orders", "label" => "Back to orders"]) ?>
<h1 class="mt-0">Place an order</h1>
<p class="text-muted">
    Choose whose order this is. Everything after that is the form the client would fill in themselves —
    the parts on offer, the free-issue quantities and the notes that go out are all theirs.
</p>

<div class="card">
    <?php if ($clients === []): ?>
        <p class="empty-state mb-0">There are no clients on the system yet.</p>
    <?php else: ?>
        <form method="get" action="<?= url('/staff/orders/new') ?>" class="action-row" data-client-jump>
            <label for="client_id">Client</label>
            <select id="client_id" name="client_id" class="input-grow" required>
                <option value="">Choose a client…</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    <?php endif; ?>
</div>
