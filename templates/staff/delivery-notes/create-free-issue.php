<?php /** @var array $client */ /** @var array $lines */ ?>
<?= partial("partials/back-link", ["href" => "/staff/clients/" . $client["id"], "label" => "Back to " . $client["name"]]) ?>
<h1 class="mt-0">New free-issue delivery note — <?= e($client['name']) ?></h1>
<p class="text-muted">This generates a note for the client to enclose with the free-issue material they send. It does not record receipt — check the material in from the order once it actually arrives. Outstanding below includes anything rejected and sent back, since that has to come again.</p>

<?php if ($lines === []): ?>
    <div class="card"><p class="empty-state">No order lines are currently awaiting free-issue material for this client.</p></div>
<?php else: ?>
    <form method="post" action="<?= url('/staff/clients/' . $client['id'] . '/free-issue-note') ?>">
        <?= csrf_field() ?>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Order</th><th>CPN</th><th>Part</th><th>Outstanding</th><th>Quantity to list</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $line): $outstanding = \App\Models\OrderLine::freeIssueOutstanding($line); ?>
                        <tr>
                            <td><?= e($line['order_number']) ?><input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>"></td>
                            <td><?= e($line['cpn']) ?></td>
                            <td class="wrap"><?= e($line['part_name']) ?></td>
                            <td><?= $outstanding ?></td>
                            <td style="min-width:120px"><input type="number" min="0" max="<?= $outstanding ?>" name="qty[]" value="0"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"></textarea></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate note</button>
            <a href="<?= url('/staff/clients/' . $client['id']) ?>" class="btn">Cancel</a>
        </div>
    </form>
<?php endif; ?>
