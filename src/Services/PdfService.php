<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Upload;
use App\Core\View;
use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfService
{
    /**
     * Where dompdf keeps its parsed font metrics.
     *
     * This is the single biggest thing between pressing "view" and seeing a
     * PDF. Dompdf reads DejaVu's `.ufm` metrics and writes the parsed result
     * back beside them as JSON; on the next render it reads the JSON instead,
     * which is roughly twenty times quicker. By default that cache lives in
     * `vendor/dompdf/dompdf/lib/fonts`, and on a deployed server `vendor/` is
     * owned by whoever ran composer rather than by the web user — so the cache
     * can never be written, every request re-parses every font from scratch,
     * and every request pays for it. Measured on an otherwise empty document:
     * 1052 ms with a cache it cannot reuse against 58 ms with a warm one.
     *
     * Pointing it at the application's own storage fixes that for good. The
     * fonts themselves are still read from dompdf's directory, which only needs
     * to be readable; nothing here needs `vendor/` to be writable, and nothing
     * is lost when composer next replaces it.
     */
    private const FONT_CACHE_DIR = 'cache/dompdf-fonts';

    /** Renders a headless (no app layout) template to PDF bytes. */
    public static function render(string $template, array $data): string
    {
        $html = View::capture($template, $data, null);

        $dompdf = new Dompdf(self::options());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Render a trivial document so the font cache is written.
     *
     * Called after an install or an update, so the first person to open a route
     * card is not the one who pays to build the cache. Safe to run at any time
     * and cheap once it is warm.
     *
     * The cache is per *face*, not per family, so this has to use the same ones
     * the PDFs do or it warms the wrong files and leaves the real ones cold.
     * The delivery note and the route card both set `font-family: "DejaVu Sans"`
     * and both use bold for headings and labels; neither uses italic, because
     * everything they print is escaped text rather than markup.
     */
    public static function warmFontCache(): bool
    {
        $dompdf = new Dompdf(self::options());
        $dompdf->loadHtml(
            '<div style="font-family: \'DejaVu Sans\', sans-serif">'
            . '<p>warm</p><p style="font-weight:bold">warm</p>'
            . '</div>'
        );
        $dompdf->render();
        $dompdf->output();

        return self::fontCacheIsWarm();
    }

    /**
     * Has anything been cached yet? `doctor` asks, so a cold cache is visible.
     *
     * `glob` returns false rather than an empty array when it cannot read the
     * directory at all, which is exactly the state this is meant to catch — so
     * that has to be flattened to empty before the comparison.
     */
    public static function fontCacheIsWarm(): bool
    {
        return (glob(self::fontCachePath() . '/*.json') ?: []) !== [];
    }

    public static function fontCachePath(): string
    {
        return rtrim((string) Config::get('storage.uploads'), '/\\') . '/' . self::FONT_CACHE_DIR;
    }

    private static function options(): Options
    {
        $cache = self::fontCachePath();
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', Config::get('app.root'));

        // Only the cache moves. `fontDir` stays wherever dompdf keeps the
        // actual font files, which it only ever reads.
        if (is_dir($cache) && is_writable($cache)) {
            $options->set('fontCache', $cache);
        }

        return $options;
    }

    /** Renders and saves under storage/uploads/$relativeDirectory, returns the relative path. */
    public static function renderAndStore(string $template, array $data, string $relativeDirectory, string $filename): string
    {
        $pdf = self::render($template, $data);

        $relativeDirectory = trim($relativeDirectory, '/');
        $targetDir = rtrim((string) Config::get('storage.uploads'), '/\\') . '/' . $relativeDirectory;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $relativePath = $relativeDirectory . '/' . $filename;
        file_put_contents($targetDir . '/' . $filename, $pdf);

        return $relativePath;
    }
}
