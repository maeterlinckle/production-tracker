<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Upload;
use App\Models\Setting;

/**
 * The uploaded logo. Two independently-optional variants (light/dark) so the
 * mark can differ per theme; if only one is set, it's used for both --
 * matches Kitwell's Branding service, reimplemented against this app's own
 * Setting/Upload classes.
 */
final class Branding
{
    public const VARIANTS = ['light', 'dark'];

    private const DIRECTORY = 'branding';

    public static function path(string $variant): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return null;
        }

        $path = Setting::get('logo_' . $variant . '_path');

        if ($path === null || $path === '') {
            return null;
        }

        return Upload::absolutePath($path) === null ? null : $path;
    }

    /** The variant actually used for a theme, falling back to the other. */
    public static function resolve(string $variant): ?string
    {
        $other = $variant === 'light' ? 'dark' : 'light';

        return self::path($variant) ?? self::path($other);
    }

    public static function hasAny(): bool
    {
        return self::path('light') !== null || self::path('dark') !== null;
    }

    public static function url(string $variant): ?string
    {
        $path = self::resolve($variant);

        return $path === null ? null : url('/branding/logo/' . $variant . '?v=' . substr(md5($path), 0, 8));
    }

    /** Absolute path of the light logo, for PDF paperwork and email (paper/email are white). */
    public static function printablePath(): ?string
    {
        $path = self::resolve('light');

        return $path === null ? null : Upload::absolutePath($path);
    }

    /** @return array{provided:bool,error:?string} */
    public static function acceptUpload(string $variant): array
    {
        $files = Upload::files('logo_' . $variant);

        if ($files === []) {
            return ['provided' => false, 'error' => null];
        }

        $error = self::store($variant, $files[0]);

        return ['provided' => true, 'error' => $error];
    }

    private static function store(string $variant, array $file): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return 'Unknown logo variant.';
        }

        $error = Upload::validate($file, Config::get('uploads.logo.extensions'), (int) Config::get('uploads.logo.max_bytes'));
        if ($error !== null) {
            return $error;
        }

        $stored = Upload::store($file, self::DIRECTORY);
        $previous = Setting::get('logo_' . $variant . '_path');

        Setting::put('logo_' . $variant . '_path', $stored);
        Setting::put('logo_' . $variant . '_mime', Upload::detectMime((string) Upload::absolutePath($stored)) ?? 'application/octet-stream');

        if ($previous !== null && $previous !== '' && $previous !== $stored) {
            Upload::delete($previous);
        }

        return null;
    }

    public static function remove(string $variant): void
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return;
        }

        $previous = Setting::get('logo_' . $variant . '_path');
        Setting::put('logo_' . $variant . '_path', null);
        Setting::put('logo_' . $variant . '_mime', null);

        if ($previous !== null && $previous !== '') {
            Upload::delete($previous);
        }
    }
}
