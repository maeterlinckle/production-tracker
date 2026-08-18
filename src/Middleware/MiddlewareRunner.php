<?php

declare(strict_types=1);

namespace App\Middleware;

final class MiddlewareRunner
{
    public static function run(array $middleware): void
    {
        foreach ($middleware as $entry) {
            [$name, $param] = str_contains($entry, ':') ? explode(':', $entry, 2) : [$entry, null];

            match ($name) {
                'auth' => AuthMiddleware::handle(),
                'guest' => GuestMiddleware::handle(),
                'staff' => StaffMiddleware::handle(),
                'csrf' => CsrfMiddleware::handle(),
                default => throw new \RuntimeException("Unknown middleware: {$name}"),
            };
        }
    }
}
