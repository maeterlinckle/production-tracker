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
    /** Renders a headless (no app layout) template to PDF bytes. */
    public static function render(string $template, array $data): string
    {
        $html = View::capture($template, $data, null);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', Config::get('app.root'));

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
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
