<?php
/** @var array $client */ /** @var array|null $preselectPart */
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0">Place order for <?= e($client['name']) ?></h1>
        <p class="text-muted mb-0">
            Raised on their behalf. It becomes an ordinary order on their account — they can see it, track
            it and raise queries against it exactly as if they had placed it themselves, and they will be
            emailed the confirmation.
        </p>
    </div>
    <a href="<?= url('/staff/orders/new') ?>" class="btn btn-sm">Change client</a>
</div>

<?= partial('partials/order-builder', [
    'action' => '/staff/clients/' . $client['id'] . '/orders',
    'searchUrl' => '/staff/clients/' . $client['id'] . '/parts-search-orderable',
    'linkedUrlBase' => '/staff/parts',
    'cancelUrl' => '/staff/orders',
    'preselectPart' => $preselectPart,
    'poNumberHint' => 'The number on the client\'s purchase order. It goes on the invoice, so it is worth getting right.',
    'submitLabel' => 'Place order for ' . $client['name'],
]) ?>
