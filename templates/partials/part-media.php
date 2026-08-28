<?php
/**
 * A part's media library: the main photo, then everything else under its kind,
 * and finally anything attached to an order that says it shows this part.
 *
 * Staff manage it; clients see it read-only, because a photo of the finished
 * part is often the clearest answer to "is this what you meant". `$canManage`
 * is what separates the two — there is no second copy of this for the client
 * side to drift away from.
 *
 * @var array      $part
 * @var array|null $mainPhoto
 * @var array      $attachments kind => items, from PartMedia::groupAttachments()
 * @var bool       $canManage
 * @var array      $orderMedia  attachments from orders, from OrderPhoto::forPart()
 */
use App\Models\OrderPhoto;
use App\Models\PartMedia;

$canManage = $canManage ?? false;
$orderMedia = $orderMedia ?? [];
$base = '/staff/parts/' . $part['id'];

/**
 * The description, and how to change it.
 *
 * Written once and used on every tile and every row, because a description
 * that is editable in the grid but not in the file list is a description
 * somebody will swear they cannot edit.
 */
$description = static function (array $item, string $fallback) use ($canManage, $base): void {
    $caption = trim((string) ($item['caption'] ?? ''));
    ?>
    <?= $caption !== '' ? e($caption) : $fallback ?>
    <?php if ($canManage): ?>
        <details class="caption-edit">
            <summary>Edit description</summary>
            <form method="post" action="<?= url($base . '/media/' . (int) $item['id'] . '/caption') ?>" class="action-row">
                <?= csrf_field() ?>
                <label class="sr-only" for="caption_<?= (int) $item['id'] ?>">Description</label>
                <input type="text" class="input-grow" id="caption_<?= (int) $item['id'] ?>"
                       name="caption" value="<?= e($caption) ?>" placeholder="What this shows">
                <button type="submit" class="btn btn-sm">Save</button>
            </form>
        </details>
    <?php endif;
};
?>
<?php if ($mainPhoto !== null): ?>
    <h3 class="mt-0">Main photo</h3>
    <div class="media-grid">
        <figure class="media-tile">
            <a href="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" target="_blank" rel="noopener">
                <img src="<?= url('/files/part-media/' . $mainPhoto['id'] . '/thumb') ?>" alt="<?= e($part['cpn']) ?>">
            </a>
            <figcaption>
                <?php $description($mainPhoto, '<span class="text-muted">The finished part</span>'); ?>
                <?php if ($canManage): ?>
                    <form method="post" action="<?= url($base . '/media/' . $mainPhoto['id'] . '/delete') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm">Remove</button>
                    </form>
                <?php endif; ?>
            </figcaption>
        </figure>
    </div>
<?php endif; ?>

<?php if ($attachments === [] && $mainPhoto === null && $orderMedia === []): ?>
    <p class="empty-state mb-0">Nothing attached to this part yet.</p>
<?php endif; ?>

<?php foreach ($attachments as $kind => $items): ?>
    <h3><?= e(PartMedia::KIND_LABELS[$kind]) ?></h3>
    <?php $images = array_values(array_filter($items, static fn ($i) => PartMedia::isImage($i))); ?>
    <?php $others = array_values(array_filter($items, static fn ($i) => !PartMedia::isImage($i))); ?>

    <?php if ($images !== []): ?>
        <div class="media-grid">
            <?php foreach ($images as $item): ?>
                <figure class="media-tile">
                    <a href="<?= url('/files/part-media/' . $item['id']) ?>" target="_blank" rel="noopener">
                        <img src="<?= url('/files/part-media/' . $item['id'] . '/thumb') ?>" alt="<?= e($item['caption'] ?? $item['original_filename']) ?>">
                    </a>
                    <figcaption>
                        <?php $description($item, e($item['original_filename'])); ?>
                        <?php if ($canManage): ?>
                            <span class="media-actions">
                                <?php if ($item['kind'] === 'photo'): ?>
                                    <form method="post" action="<?= url($base . '/media/' . $item['id'] . '/main') ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm">Make main</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= url($base . '/media/' . $item['id'] . '/delete') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Remove</button>
                                </form>
                            </span>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($others !== []): ?>
        <ul class="file-list">
            <?php foreach ($others as $item): ?>
                <li>
                    <span>
                        <?= e($item['original_filename']) ?>
                        <?php if (trim((string) ($item['caption'] ?? '')) !== ''): ?>
                            <span class="text-muted">— <?= e($item['caption']) ?></span>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <?php /* The name is already on the row, so the editor
                                     offers only the description. */ ?>
                            <details class="caption-edit">
                                <summary>Edit description</summary>
                                <form method="post" action="<?= url($base . '/media/' . (int) $item['id'] . '/caption') ?>" class="action-row">
                                    <?= csrf_field() ?>
                                    <label class="sr-only" for="doc_caption_<?= (int) $item['id'] ?>">Description</label>
                                    <input type="text" class="input-grow" id="doc_caption_<?= (int) $item['id'] ?>"
                                           name="caption" value="<?= e($item['caption'] ?? '') ?>" placeholder="What this is">
                                    <button type="submit" class="btn btn-sm">Save</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </span>
                    <span class="media-actions">
                        <a href="<?= url('/files/part-media/' . $item['id']) ?>" class="btn btn-sm" target="_blank" rel="noopener">Open</a>
                        <?php if ($canManage): ?>
                            <form method="post" action="<?= url($base . '/media/' . $item['id'] . '/delete') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Remove</button>
                            </form>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($orderMedia !== []): ?>
    <?php /*
        Attached to an order, tagged as being about this part.

        Kept visibly apart from the library above rather than mixed into it,
        because the two answer different questions. The library describes the
        part: it is true every time the part runs. These describe one batch on
        one order — a mark on that run, the packing for that despatch — and
        reading them as if they described the part would be wrong.

        They are here at all because the alternative is remembering which order
        a photo was taken on, which is exactly what nobody does.
    */ ?>
    <h3>From orders <span class="badge badge-muted">On one batch, not the part</span></h3>
    <p class="text-muted">
        Attached to an order and tagged as showing this part. Each one belongs to the order it names,
        and lives there — this is a way of finding it, not a second copy.
    </p>

    <?php $orderImages = array_values(array_filter($orderMedia, static fn ($i) => OrderPhoto::isImage($i))); ?>
    <?php $orderFiles = array_values(array_filter($orderMedia, static fn ($i) => !OrderPhoto::isImage($i))); ?>

    <?php if ($orderImages !== []): ?>
        <div class="media-grid">
            <?php foreach ($orderImages as $item): ?>
                <figure class="media-tile media-tile-order">
                    <a href="<?= url('/files/order-photos/' . $item['id']) ?>" target="_blank" rel="noopener">
                        <img src="<?= url('/files/order-photos/' . $item['id'] . '/thumb') ?>"
                             alt="<?= e($item['caption'] ?? $item['original_filename']) ?>">
                    </a>
                    <figcaption>
                        <?= trim((string) ($item['caption'] ?? '')) !== '' ? e($item['caption']) : e($item['original_filename']) ?>
                        <span class="media-origin">
                            <a href="<?= url('/staff/orders/' . (int) $item['order_id']) ?>#photos"><?= e($item['order_number']) ?></a>
                            &middot; <?= format_date($item['uploaded_at']) ?>
                        </span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($orderFiles !== []): ?>
        <ul class="file-list">
            <?php foreach ($orderFiles as $item): ?>
                <li>
                    <span>
                        <?= e($item['original_filename']) ?>
                        <?php if (trim((string) ($item['caption'] ?? '')) !== ''): ?>
                            <span class="text-muted">— <?= e($item['caption']) ?></span>
                        <?php endif; ?>
                        <span class="media-origin">
                            <a href="<?= url('/staff/orders/' . (int) $item['order_id']) ?>#photos"><?= e($item['order_number']) ?></a>
                            &middot; <?= format_date($item['uploaded_at']) ?>
                        </span>
                    </span>
                    <span class="media-actions">
                        <a href="<?= url('/files/order-photos/' . $item['id']) ?>" class="btn btn-sm" target="_blank" rel="noopener">Open</a>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php if ($canManage): ?>
    <form method="post" action="<?= url($base . '/media') ?>" enctype="multipart/form-data" style="margin-top: var(--space-5)">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field">
                <label for="media_kind">What is it?</label>
                <select id="media_kind" name="kind" data-media-kind>
                    <?php foreach (PartMedia::KINDS as $kindOption): ?>
                        <option value="<?= e($kindOption) ?>"><?= e(PartMedia::KIND_LABELS[$kindOption]) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint" data-media-hint><?= e(PartMedia::KIND_HINTS['photo']) ?></div>
            </div>
            <div class="field">
                <label for="media_caption">Caption (optional)</label>
                <input type="text" id="media_caption" name="caption" placeholder="e.g. Op 20 fixture, soft jaws">
            </div>
        </div>
        <div class="field">
            <label for="media_files">File(s)</label>
            <input type="file" id="media_files" name="files[]" multiple>
        </div>
        <label class="checkbox-label" data-media-main>
            <input type="checkbox" name="is_main" value="1">
            <span>Use as the part's main photo</span>
        </label>
        <button type="submit" class="btn" style="margin-top: var(--space-3)">Add to this part</button>
    </form>

    <script>
    (function () {
        var hints = <?= json_encode(PartMedia::KIND_HINTS) ?>;
        var select = document.querySelector('[data-media-kind]');
        var hint = document.querySelector('[data-media-hint]');
        var mainToggle = document.querySelector('[data-media-main]');
        if (!select) return;

        select.addEventListener('change', function () {
            hint.textContent = hints[select.value] || '';
            // Only a photo can be the part's representative image.
            mainToggle.hidden = select.value !== 'photo';
        });
    })();
    </script>
<?php endif; ?>
