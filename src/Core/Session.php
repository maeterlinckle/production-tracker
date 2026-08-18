<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name((string) Config::get('session.name', 'pt_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => Request::isSecure() || (bool) Config::get('session.secure_cookie', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) Config::get('session.lifetime', 480) * 60));

        session_start();

        $lifetimeSeconds = (int) Config::get('session.lifetime', 480) * 60;
        $lastActivity = self::get('__last_activity');

        if ($lastActivity !== null && (time() - $lastActivity) > $lifetimeSeconds) {
            self::destroy();
            session_start();
            self::put('__expired', true);
        }

        self::put('__last_activity', time());

        $fingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (self::has('__fingerprint')) {
            if (!hash_equals((string) self::get('__fingerprint'), $fingerprint)) {
                self::destroy();
                session_start();
                self::put('__fingerprint', $fingerprint);
            }
        } else {
            self::put('__fingerprint', $fingerprint);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }
}
