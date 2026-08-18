<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareRunner;

final class Router
{
    private array $routes = [];
    private array $named = [];
    private array $groupMiddleware = [];

    public function get(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    public function post(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    public function put(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $path, $handler, $middleware, $name);
    }

    public function delete(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    public function group(array $middleware, callable $callback): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);
        $callback($this);
        $this->groupMiddleware = $previous;
    }

    private function add(string $method, string $path, mixed $handler, array $middleware, ?string $name): void
    {
        $route = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'regex' => $this->toRegex($path),
        ];

        $this->routes[] = $route;

        if ($name !== null) {
            $this->named[$name] = $path;
        }
    }

    /**
     * Compile `/orders/{id:\d+}` into a named-capture regex.
     *
     * Scanned by hand rather than matched with a regex of its own, because the
     * obvious pattern for the constraint — everything up to the next `}` — is
     * wrong the moment a constraint contains braces. `{token:[a-f0-9]{64}}`
     * compiled to `[a-f0-9]{64` followed by a literal brace, and the route
     * silently never matched: a 404 with nothing in any log to say why. Counting
     * depth means a quantifier inside a constraint works as written.
     */
    private function toRegex(string $path): string
    {
        $pattern = '';
        $length = strlen($path);

        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '{') {
                $pattern .= preg_quote($path[$i], '#');
                continue;
            }

            // Find the `}` that closes this placeholder, not the first one.
            $depth = 1;
            $end = $i + 1;

            while ($end < $length && $depth > 0) {
                if ($path[$end] === '{') {
                    $depth++;
                } elseif ($path[$end] === '}') {
                    $depth--;
                }

                if ($depth > 0) {
                    $end++;
                }
            }

            if ($depth !== 0) {
                throw new \InvalidArgumentException("Unclosed route placeholder in: {$path}");
            }

            $placeholder = substr($path, $i + 1, $end - $i - 1);
            [$name, $constraint] = array_pad(explode(':', $placeholder, 2), 2, null);

            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', (string) $name)) {
                throw new \InvalidArgumentException("Invalid route placeholder name '{$name}' in: {$path}");
            }

            $pattern .= '(?P<' . $name . '>' . ($constraint ?? '[^/]+') . ')';
            $i = $end;
        }

        return '#^' . $pattern . '$#';
    }

    public static function path(string $name): string
    {
        return $name;
    }

    public function dispatch(string $method, string $path): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = array_filter(
                $matches,
                static fn ($key) => is_string($key),
                ARRAY_FILTER_USE_KEY
            );
            Request::setRouteParams($params);

            MiddlewareRunner::run($route['middleware']);

            $this->invoke($route['handler'], $params);

            return;
        }

        if ($allowedMethods !== []) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            View::renderError(405, 'Method not allowed', 'This action is not supported for that address.');

            return;
        }

        View::renderError(404, 'Page not found', 'The page you were looking for does not exist.');
    }

    private function invoke(mixed $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->{$method}(...array_values($params));

            return;
        }

        $handler(...array_values($params));
    }
}
