<?php /** @var array|null $preselectPart */ ?>
<?= partial("partials/back-link", ["href" => "/orders", "label" => "Back to orders"]) ?>
<h1 class="mt-0">Place order</h1>

<?= partial('partials/order-builder', [
    'action' => '/orders',
    'searchUrl' => '/parts-search-orderable',
    'linkedUrlBase' => '/parts',
    'cancelUrl' => '/orders',
    'preselectPart' => $preselectPart,
    'poNumberHint' => 'Your own PO number. It goes on the invoice, so it is worth getting right.',
    'submitLabel' => 'Place order',
]) ?>
