<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        echo self::capture($template, $data, $layout);
    }

    public static function capture(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $data = array_merge(self::$shared, [
            'errors' => Flash::takeErrors(),
            'old' => Flash::takeOld(),
        ], $data);

        $content = self::renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, array_merge(self::$shared, $data));
    }

    /**
     * The locals here are deliberately named `$__…`.
     *
     * `extract()` runs with EXTR_SKIP, so a view variable whose name collides
     * with one of this method's own locals is silently dropped and the method's
     * value leaks into the template instead. That is not a warning or an error —
     * it is a template that renders with the wrong data. It cost real time once
     * already: a view passed `template` and got this method's filename string
     * where it expected an array. Prefixing the locals means no name a template
     * would plausibly use can collide.
     */
    private static function renderFile(string $__template, array $__data): string
    {
        $__path = Config::get('app.root') . '/templates/' . ltrim($__template, '/') . '.php';

        if (!is_file($__path)) {
            throw new \RuntimeException("Template not found: {$__template}");
        }

        extract($__data, EXTR_SKIP);
        ob_start();
        require $__path;

        return (string) ob_get_clean();
    }

    public static function renderError(int $status, string $title, string $message): void
    {
        http_response_code($status);

        $layout = Auth::check() ? 'layouts/app' : 'layouts/auth';

        try {
            echo self::capture('errors/error', ['status' => $status, 'title' => $title, 'message' => $message], $layout);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
                . '<h1>' . htmlspecialchars((string) $status) . ' ' . htmlspecialchars($title) . '</h1>'
                . '<p>' . htmlspecialchars($message) . '</p>';
        }
    }
}
