<?php

declare(strict_types=1);

namespace App\Core;

use GdImage;

/**
 * Photo processing for uploads.
 *
 * Two jobs, both done the moment a file lands:
 *
 *   1. Straighten it. Phones record orientation in EXIF rather than rotating
 *      the pixels, so an unprocessed photo of a setup is very often sideways.
 *   2. Bring the size down. A phone camera produces 4–12 MB per shot, and a
 *      part page with a dozen of them was sending fifty megabytes to somebody
 *      on a workshop 4G connection to draw twelve 160px tiles.
 *
 * A thumbnail is written alongside for the grids. The full image stays
 * available behind it — the tile is a link to the real thing.
 *
 * Everything degrades: if GD is missing, or a particular format cannot be
 * read, the upload still succeeds and the original is served as-is. The photo
 * somebody just took is never the thing that gets lost, and a page of slightly
 * heavy images is a much smaller problem than a failed upload in a workshop.
 *
 * The approach follows Kitwell's — same sizes, same graceful-degradation rule —
 * re-implemented here against this application's own Upload and Config rather
 * than shared.
 */
final class Image
{
    /** Longest edge kept for the stored original, in pixels. */
    public const MAX_DIMENSION = 2400;

    /** Longest edge of a generated thumbnail, in pixels. */
    public const THUMB_DIMENSION = 480;

    public static function isSupported(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** The mime types this build of PHP can actually read and write. */
    public static function canProcess(?string $mime): bool
    {
        if ($mime === null || !self::isSupported()) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png' => function_exists('imagecreatefrompng'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default => false,
        };
    }

    /**
     * Straighten and, if it is oversized, shrink an image in place.
     *
     * Returns silently on anything it cannot handle: the caller's file is
     * already safely on disk and stays exactly as uploaded.
     */
    public static function normalise(string $relativePath, ?string $mime): void
    {
        if (!self::canProcess($mime)) {
            return;
        }

        $path = Upload::absolutePath($relativePath);
        if ($path === null || !is_file($path)) {
            return;
        }

        $image = self::load($path, (string) $mime);
        if ($image === null) {
            return;
        }

        $image = self::applyExifOrientation($image, $path, (string) $mime);

        $longest = max(imagesx($image), imagesy($image));
        if ($longest > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / $longest;
            $resized = self::resample($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
            imagedestroy($image);
            $image = $resized;
        }

        self::save($image, $path, (string) $mime);
        imagedestroy($image);
    }

    /**
     * Write a thumbnail beside the original.
     *
     * `photos/12/x.jpg` becomes `photos/12/thumbs/x.jpg`, so the two travel
     * together — a backup of the uploads directory brings both, and deleting
     * the original's directory takes the thumbnail with it.
     *
     * @return string|null the thumbnail's path relative to the uploads root,
     *                     or null when one could not be produced
     */
    public static function thumbnail(string $relativePath, ?string $mime): ?string
    {
        if (!self::canProcess($mime)) {
            return null;
        }

        $source = Upload::absolutePath($relativePath);
        if ($source === null || !is_file($source)) {
            return null;
        }

        $image = self::load($source, (string) $mime);
        if ($image === null) {
            return null;
        }

        $longest = max(imagesx($image), imagesy($image));
        $scale = $longest > self::THUMB_DIMENSION ? self::THUMB_DIMENSION / $longest : 1.0;

        $thumb = self::resample($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        imagedestroy($image);

        $relativeDirectory = trim(dirname($relativePath), '/') . '/thumbs';
        $absoluteDirectory = rtrim((string) Config::get('storage.uploads'), '/\\')
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($absoluteDirectory) && !@mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            error_log('Image: could not create the thumbnail directory ' . $absoluteDirectory);
            imagedestroy($thumb);

            return null;
        }

        $target = $absoluteDirectory . DIRECTORY_SEPARATOR . basename($relativePath);
        $written = self::save($thumb, $target, (string) $mime, 78);
        imagedestroy($thumb);

        if (!$written) {
            return null;
        }

        @chmod($target, 0640);

        return $relativeDirectory . '/' . basename($relativePath);
    }

    /**
     * Normalise and thumbnail in one call — what every upload path wants.
     *
     * @return string|null the thumbnail path, or null if there is none
     */
    public static function process(string $relativePath, ?string $mime): ?string
    {
        self::normalise($relativePath, $mime);

        return self::thumbnail($relativePath, $mime);
    }

    private static function load(string $path, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * Write an image to disk.
     *
     * Warnings are suppressed and turned into a false return. A failure here —
     * a full disk, awkward permissions, a host with a half-built GD — must not
     * cost somebody the photo they just took: the original upload is already on
     * disk, and the caller simply keeps it.
     */
    private static function save(GdImage $image, string $path, string $mime, int $quality = 82): bool
    {
        $ok = match ($mime) {
            'image/jpeg' => @imagejpeg($image, $path, $quality),
            'image/png' => @imagepng($image, $path, 6),
            'image/webp' => @imagewebp($image, $path, $quality),
            default => false,
        };

        if (!$ok) {
            error_log(sprintf('Image: could not write %s (%s). Keeping the original as uploaded.', $path, $mime));
        }

        return $ok;
    }

    private static function resample(GdImage $source, int $width, int $height): GdImage
    {
        $width = max(1, $width);
        $height = max(1, $height);

        $target = imagecreatetruecolor($width, $height);

        // Keep transparency rather than turning it black.
        imagealphablending($target, false);
        imagesavealpha($target, true);

        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    /**
     * Rotate and flip according to the EXIF orientation tag, so the pixels
     * match what the photographer saw.
     *
     * Silently skipped where the exif extension is absent — a sideways photo is
     * a nuisance, not a failure.
     */
    private static function applyExifOrientation(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if ($exif === false || empty($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];

        // imagerotate() turns anti-clockwise.
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated !== false && $rotated !== $image) {
            imagedestroy($image);
            $image = $rotated;
        }

        // The mirrored orientations are rare but cheap to put right.
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
