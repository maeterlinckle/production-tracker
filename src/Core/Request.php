<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static array $routeParams = [];

    public static function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public static function basePath(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    public static function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (self::trustProxy()) {
            $proto = self::header('X-Forwarded-Proto');
            if ($proto !== null) {
                return strtolower(explode(',', $proto)[0]) === 'https';
            }
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    private static function trustProxy(): bool
    {
        $trusted = Config::get('app.trusted_proxies', []);
        if ($trusted === ['*']) {
            return true;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($remote, $trusted, true);
    }

    public static function ip(): string
    {
        if (self::trustProxy()) {
            $forwarded = self::header('X-Forwarded-For');
            if ($forwarded !== null) {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$key] ?? null;
    }

    public static function isJson(): bool
    {
        return str_contains((string) self::header('Content-Type'), 'application/json');
    }

    public static function isAjax(): bool
    {
        return self::header('X-Requested-With') === 'XMLHttpRequest';
    }

    public static function host(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function fullUrl(): string
    {
        $scheme = self::isSecure() ? 'https' : 'http';

        return $scheme . '://' . self::host() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function boolean(string $key): bool
    {
        $value = strtolower((string) self::input($key, ''));

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function setRouteParams(array $params): void
    {
        self::$routeParams = $params;
    }

    public static function route(string $key, ?string $default = null): ?string
    {
        return self::$routeParams[$key] ?? $default;
    }

    /**
     * Did PHP throw this request's body away for being too big?
     *
     * When a POST exceeds `post_max_size`, PHP does not reject it — it discards
     * the body and carries on, so `$_POST` and `$_FILES` both arrive empty. The
     * request then looks exactly like one with no CSRF token on it, and the
     * first thing to notice is the CSRF check, which reports a session that has
     * expired. It has not: the session is fine and the token was sent. The file
     * was simply too large to be read.
     *
     * That is why the fault looked intermittent and looked like session state:
     * it is purely about size, it hits the biggest file somebody uploads (the
     * drawing, right after creating a part), and it goes away on a retry with
     * something smaller. Several files at once do it too, each one comfortably
     * under `upload_max_filesize` while the total is over `post_max_size`.
     *
     * Content-Length is what PHP itself compares, so it is what is compared
     * here. Both superglobals must also be empty, so an ordinary large-but-
     * allowed upload is never mistaken for a discarded one.
     */
    public static function bodyWasDiscarded(): bool
    {
        if (!in_array(self::method(), ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }

        $limit = self::iniBytes('post_max_size');
        if ($limit <= 0) {
            return false; // 0 or unset means no limit.
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        return $length > $limit && $_POST === [] && $_FILES === [];
    }

    /** An ini shorthand size ("8M", "512K", "1G") in bytes. */
    public static function iniBytes(string $directive): int
    {
        $value = trim((string) ini_get($directive));
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function routeInt(string $key): int
    {
        return (int) (self::$routeParams[$key] ?? 0);
    }
}
