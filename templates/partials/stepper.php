<?php
/** @var array $line order_line row (stage, qty_free_issue_required, qty_ordered, qty_delivered, qty_invoiced) */
$steps = [];
if ((int) $line['qty_free_issue_required'] > 0) {
    $steps[] = 'awaiting_free_issue';
}
$steps[] = 'ready_for_production';
$steps[] = 'in_production';
$steps[] = 'complete';
$steps[] = 'closed';

$currentIndex = array_search($line['stage'], $steps, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}
?>
<div class="stepper">
    <?php foreach ($steps as $i => $step): ?>
        <div class="step <?= $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : '') ?>">
            <span class="step-dot"><?= $i < $currentIndex ? '&check;' : $i + 1 ?></span>
            <span class="step-label"><?= e(\App\Models\OrderLine::STAGE_LABELS[$step]) ?></span>
        </div>
    <?php endforeach; ?>
</div>
