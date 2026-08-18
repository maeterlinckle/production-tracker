<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function add(string $type, string $message): void
    {
        $messages = Session::get('__flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        Session::put('__flash', $messages);
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('danger', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /** One-shot: reads and clears. */
    public static function messages(): array
    {
        return Session::pull('__flash', []);
    }

    public static function setErrors(array $errors): void
    {
        Session::put('__errors', $errors);
    }

    public static function takeErrors(): array
    {
        return Session::pull('__errors', []);
    }

    public static function setOld(array $old): void
    {
        Session::put('__old', $old);
    }

    public static function takeOld(): array
    {
        return Session::pull('__old', []);
    }
}
