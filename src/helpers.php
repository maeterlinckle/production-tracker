<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }

        return Request::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    /**
     * A full https://host/path address.
     *
     * Everything that leaves the application — an email link, a QR code on a
     * printed delivery note — needs one of these, because there is no current
     * request to resolve a relative path against. Falls back to url() when
     * APP_URL has not been set, which at least keeps a development box working.
     */
    function absolute_url(string $path = '/'): string
    {
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }

        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base === '' ? url($path) : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        $full = url($path);
        $file = Config::get('app.root') . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? filemtime($file) : time();

        return $full . '?v=' . $version;
    }
}

if (!function_exists('partial')) {
    function partial(string $template, array $data = []): string
    {
        return View::partial($template, $data);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('can')) {
    /** Capability check, for templates. Mirrors Auth::can(). */
    function can(string $capability): bool
    {
        return Auth::can($capability);
    }
}

if (!function_exists('role_summary')) {
    /**
     * The roles a user holds, as one readable line for the nav chip and the
     * footer. Several roles per user is normal here, so this is a list rather
     * than the single role name Kitwell can get away with.
     */
    function role_summary(?array $user = null): string
    {
        $slugs = $user === null ? Auth::roles() : ($user['roles'] ?? []);

        if ($slugs === []) {
            return 'No roles';
        }

        $names = array_map(static function (string $slug): string {
            $leaf = str_contains($slug, '.') ? substr($slug, strpos($slug, '.') + 1) : $slug;

            return ucfirst(str_replace('_', ' ', $leaf));
        }, $slugs);

        sort($names);

        return implode(', ', $names);
    }
}

if (!function_exists('old')) {
    function old(array $old, string $key, mixed $default = ''): string
    {
        return e($old[$key] ?? $default);
    }
}

if (!function_exists('is_active_path')) {
    function is_active_path(string $path): bool
    {
        return Request::path() === $path;
    }
}

if (!function_exists('active_path_score')) {
    function active_path_score(string $path): int
    {
        $current = Request::path();
        if ($path === '/') {
            return $current === '/' ? 1 : 0;
        }

        return str_starts_with($current, $path) ? strlen($path) : 0;
    }
}

if (!function_exists('active_path')) {
    function active_path(array $paths): ?string
    {
        $best = null;
        $bestScore = 0;
        foreach ($paths as $path) {
            $score = active_path_score($path);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $path;
            }
        }

        return $best;
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'j M Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000-00-00')) {
            return '—';
        }

        return date($format, strtotime($date));
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        return format_date($date, 'j M Y, H:i');
    }
}

if (!function_exists('format_money')) {
    function format_money(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return Config::get('app.currency_symbol', '£') . number_format((float) $amount, 2);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('status_badge')) {
    function status_badge(string $status): string
    {
        $labels = [
            'draft' => 'Draft',
            'quoted' => 'Quoted',
            'ordered' => 'Ordered',
            'awaiting_free_issue' => 'Awaiting free issue',
            'ready_for_production' => 'Ready for production',
            'in_production' => 'In production',
            'complete' => 'Complete',
            'partially_delivered' => 'Partially delivered',
            'delivered' => 'Delivered',
            'closed' => 'Closed',
        ];

        $classes = [
            'draft' => 'badge-muted',
            'quoted' => 'badge-info',
            'ordered' => 'badge-info',
            'awaiting_free_issue' => 'badge-warn',
            'ready_for_production' => 'badge-info',
            'in_production' => 'badge-info',
            'complete' => 'badge-ok',
            'partially_delivered' => 'badge-warn',
            'delivered' => 'badge-ok',
            'closed' => 'badge-ok',
        ];

        $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
        $class = $classes[$status] ?? 'badge-muted';

        return '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $length = 80): string
    {
        if ($value === null) {
            return '';
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
    }
}
