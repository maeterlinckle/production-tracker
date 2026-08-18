<?php /** @var array $note */ /** @var array $client */ /** @var array $lines */ /** @var array|null $invoice */
use App\Core\Auth;
?>
<div class="card-header">
    <div>
        <h1 class="mt-0 mb-0"><?= e($note['reference']) ?></h1>
        <p class="text-muted mb-0"><?= e($client['name']) ?> &middot; <?= format_date($note['issued_at']) ?> &middot; <?= $note['type'] === 'free_issue_in' ? 'Free issue in' : 'Goods out' ?></p>
    </div>
    <a href="<?= url('/staff/delivery-notes/' . $note['id'] . '/pdf') ?>" class="btn btn-primary" target="_blank" rel="noopener">View PDF</a>
</div>

<div class="card">
    <h2 class="mt-0">Lines</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Order</th><th>CPN</th><th>Part</th><th>Quantity</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e($line['order_number']) ?></td>
                    <td><?= e($line['cpn']) ?></td>
                    <td class="wrap"><?= e($line['part_name']) ?></td>
                    <td><?= (int) $line['qty'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($note['type'] === 'goods_out' && Auth::can('view_pricing')): ?>
<div class="card">
    <h2 class="mt-0">Invoicing</h2>
    <?php if ($invoice !== null): ?>
        <p><span class="badge badge-ok">Invoiced</span></p>
        <p>Clear Books invoice <strong><?= e($invoice['clearbooks_invoice_number']) ?></strong> — <?= format_money($invoice['amount']) ?>, raised <?= format_date($invoice['raised_at']) ?>.</p>
    <?php else: ?>
        <p><span class="badge badge-warn">Not yet invoiced</span></p>
        <?php if (Auth::can('push_invoices')): ?>
            <form method="post" action="<?= url('/staff/delivery-notes/' . $note['id'] . '/invoice') ?>" onsubmit="return confirm('Raise a Clear Books invoice for this delivery note?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Raise invoice in Clear Books</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
