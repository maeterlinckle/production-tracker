<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Upload;
use App\Models\Setting;
use App\Services\Branding;

/** Streams the uploaded logo. Public (no auth) -- needed on the login page too. */
final class BrandingController
{
    public function logo(string $variant): void
    {
        $path = Branding::resolve($variant);
        if ($path === null) {
            Response::json(['error' => 'No logo set'], 404);
        }

        $absolute = Upload::absolutePath($path);
        if ($absolute === null || !is_file($absolute)) {
            Response::json(['error' => 'No logo set'], 404);
        }

        $mime = Setting::get('logo_' . $variant . '_mime') ?? Setting::get('logo_' . ($variant === 'light' ? 'dark' : 'light') . '_mime') ?? 'image/png';

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=2592000');
        header('Content-Length: ' . filesize($absolute));
        readfile($absolute);
    }
}
