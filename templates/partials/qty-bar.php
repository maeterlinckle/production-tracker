<?php
/** @var string $label */ /** @var int $done */ /** @var int $total */
$done  = (int) $done;
$total = (int) $total;
$pct   = $total > 0 ? min(100, round(($done / $total) * 100)) : 0;
?>
<div>
    <div class="qty-bar" title="<?= e($label) ?>: <?= $done ?> of <?= $total ?>">
        <div class="qty-bar-fill<?= $total > 0 && $done >= $total ? ' is-complete' : '' ?>" style="width: <?= $pct ?>%"></div>
    </div>
    <div class="qty-bar-label"><?= e($label) ?>: <?= $done ?> / <?= $total ?></div>
</div>
