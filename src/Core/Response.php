<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path, int $status = 302): never
    {
        if (!preg_match('#^https?://#', $path)) {
            $path = Request::basePath() . '/' . ltrim($path, '/');
        }

        http_response_code($status);
        header('Location: ' . $path);
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $referer = Request::header('Referer');
        if ($referer !== null && parse_url($referer, PHP_URL_HOST) === parse_url(Request::fullUrl(), PHP_URL_HOST)) {
            self::redirect($referer);
        }

        self::redirect($fallback);
    }

    /**
     * The upload was bigger than PHP would accept, so the body never arrived.
     *
     * 413, and a message that says what actually happened. The old answer was
     * "your session has expired", which sent people off refreshing and signing
     * in again to fix something that had nothing to do with either.
     *
     * The limit is named because it is the one thing that makes the next
     * attempt succeed: knowing the file has to be under 8 MB is actionable,
     * "too large" on its own is not.
     */
    public static function payloadTooLarge(): never
    {
        $limit = Request::iniBytes('post_max_size');
        $mb = $limit > 0 ? round($limit / 1048576, 1) : 0;
        $size = $mb > 0 ? rtrim(rtrim(number_format($mb, 1), '0'), '.') . ' MB' : 'the configured limit';

        $message = 'That upload was too large to accept. Everything sent in one go — the file, or all the '
            . 'files together — has to come to less than ' . $size . '. Nothing was saved, and you are '
            . 'still signed in.';

        if (Request::isAjax() || Request::isJson()) {
            self::json(['error' => $message], 413);
        }

        // PHP prints its own "POST Content-Length exceeds the limit" warning
        // before any of this runs when display_errors is on, and headers are
        // gone by then. Setting the code would be a fatal on top of a warning,
        // so the message still gets printed and the status is skipped.
        if (!headers_sent()) {
            http_response_code(413);
        }

        View::renderError(413, 'Upload too large', $message);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Streams a stored file for viewing in the browser.
     *
     * Two things here are load-bearing, and both were getting PDFs downloaded
     * instead of opened:
     *
     *  - The content type has to be right. `securityHeaders()` sends
     *    `X-Content-Type-Options: nosniff`, so a file served as
     *    `application/octet-stream` is never sniffed back to a PDF — the
     *    browser has no choice but to save it. Where the stored MIME type is
     *    missing or generic we re-detect from the file itself and fall back to
     *    the extension.
     *  - The page CSP does not belong on a binary. `default-src 'self'` is
     *    aimed at the application's own HTML; applied to a PDF it constrains
     *    the viewer the browser wants to render it in. The file gets its own
     *    narrow policy instead.
     *
     * `$download` is there for the one case where saving really is the point
     * (a CSV export); everything else views inline.
     */
    public static function file(string $absolutePath, string $displayName, ?string $mimeType = null, bool $download = false): void
    {
        $type = self::resolveMime($absolutePath, $mimeType);

        // Narrow, but deliberately without a `sandbox` directive: the browser's
        // built-in PDF viewer is an embedded plugin, and sandboxing the response
        // is one of the things that makes a viewer give up and offer a download
        // instead — the exact behaviour this method exists to stop.
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; object-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'");
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
            . '; filename="' . str_replace(['"', "\r", "\n"], '', $displayName) . '"');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('X-Content-Type-Options: nosniff');

        readfile($absolutePath);
    }

    /**
     * The same thing for a document that was never written to disk.
     *
     * Route cards are built from the order line every time they are asked for,
     * so there is no file to point at — and writing one out purely to serve it
     * back would recreate the stale copy the on-the-fly generation exists to
     * avoid.
     */
    public static function inlineBytes(string $bytes, string $displayName, string $mimeType): void
    {
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; object-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'");
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $displayName) . '"');
        header('Content-Length: ' . (string) strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo $bytes;
    }

    /**
     * The stored MIME type is whatever the browser claimed at upload time, so
     * it is a hint rather than an answer: octet-stream and an empty string both
     * turn up. finfo reads the file, and the extension is the last resort for
     * the formats finfo has no magic number for (CAD, mostly).
     */
    private static function resolveMime(string $absolutePath, ?string $mimeType): string
    {
        if ($mimeType !== null && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        $detected = Upload::detectMime($absolutePath);
        if ($detected !== null && $detected !== 'application/octet-stream') {
            return $detected;
        }

        return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    public static function securityHeaders(): void
    {
        // Nothing to set if output has already begun. PHP prints its own
        // startup warning ahead of any application code when a POST is too
        // large and display_errors is on; without this guard each header() call
        // then warns in turn, and on a debug install those warnings are
        // exceptions, so a too-big upload ends as a fatal about headers rather
        // than the message explaining the size.
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(self), microphone=(), geolocation=()");
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'");

        if (Request::isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}
