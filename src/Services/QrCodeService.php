<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Generates QR codes that deep-link printed paperwork (route cards, delivery
 * notes) back to the relevant job on the site, for workshop staff to scan.
 */
final class QrCodeService
{
    public static function jobUrl(string $path): string
    {
        return rtrim((string) Config::get('app.url'), '/') . '/' . ltrim($path, '/');
    }

    /** Returns a data: URI PNG suitable for embedding directly in HTML/PDF output. */
    public static function pngDataUri(string $text, int $size = 220): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $text,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 6,
        );

        return $builder->build()->getDataUri();
    }
}
