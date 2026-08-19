<?php
/**
 * A part's media library: the main photo, then everything else under its kind.
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
 */
use App\Models\PartMedia;

$canManage = $canManage ?? false;
$base = '/staff/parts/' . $part['id'];
?>
<?php if ($mainPhoto !== null): ?>
    <h3 class="mt-0">Main photo</h3>
    <div class="media-grid">
        <figure class="media-tile">
            <a href="<?= url('/files/part-media/' . $mainPhoto['id']) ?>" target="_blank" rel="noopener">
                <img src="<?= url('/files/part-media/' . $mainPhoto['id'] . '/thumb') ?>" alt="<?= e($part['cpn']) ?>">
            </a>
            <figcaption>
                <?= e($mainPhoto['caption'] ?? '') ?: '<span class="text-muted">The finished part</span>' ?>
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

<?php if ($attachments === [] && $mainPhoto === null): ?>
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
                        <?= e($item['caption'] ?? '') ?: e($item['original_filename']) ?>
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
                        <?php if ($item['caption']): ?><span class="text-muted">— <?= e($item['caption']) ?></span><?php endif; ?>
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
