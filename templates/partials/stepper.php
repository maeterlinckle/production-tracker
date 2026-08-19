<?php
/**
 * Where a line's quantity actually is, as one bar.
 *
 * This replaced the stepper of dots. A stepper can only show one position, and
 * a line that is twelve awaiting material and eight in production does not have
 * one — showing the furthest-on dot overstated progress and showing the
 * furthest-back understated it. A bar divided in proportion says both at once.
 *
 * It carries no figures of its own. The table underneath names every stage and
 * its quantity, and printing the same numbers twice on top of each other was
 * most of what made the line card hard to read.
 *
 * @var array $line an order line with its distribution attached
 */
use App\Models\OrderLine;

$occupied = OrderLine::occupiedStages($line);
$total = array_sum($occupied);
?>
<?php if ($total > 0): ?>
    <div class="stage-bar" title="<?= e(OrderLine::statusLabel($line)) ?>">
        <?php foreach ($occupied as $stage => $qty): ?>
            <div class="stage-bar-segment"
                 data-stage="<?= e($stage) ?>"
                 style="width: <?= round(($qty / $total) * 100, 2) ?>%"></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
