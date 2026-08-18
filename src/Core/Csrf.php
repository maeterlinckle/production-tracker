<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = '__csrf_token';

    public static function token(): string
    {
        if (!Session::has(self::SESSION_KEY)) {
            Session::put(self::SESSION_KEY, bin2hex(random_bytes(32)));
        }

        return (string) Session::get(self::SESSION_KEY);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function isValid(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::token(), $token);
    }

    public static function verify(): void
    {
        if (in_array(Request::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $token = $_POST['_token'] ?? Request::header('X-CSRF-Token');

        if (!self::isValid($token)) {
            if (Request::isAjax() || Request::isJson()) {
                Response::json(['error' => 'Your session has expired. Please refresh and try again.'], 419);
            }
            View::renderError(419, 'Session expired', 'Your session has expired. Please go back, refresh the page and try again.');
            exit;
        }
    }

    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
        self::token();
    }
}
