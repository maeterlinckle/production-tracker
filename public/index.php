<?php

declare(strict_types=1);

use App\Core\Request;

/*
 * Under PHP's built-in server this file is the router script, and a router
 * script is called for *every* request — including the stylesheet and the
 * JavaScript, which then reach a router that has no route for them and 404.
 * Returning false hands those back to the server to serve from disk.
 *
 * Guarded on the SAPI so it is dead code everywhere else: Apache and nginx
 * serve their own static files and never report `cli-server`.
 */
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . urldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

    if (is_file($requested)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$router = require dirname(__DIR__) . '/routes/web.php';
$router->dispatch(Request::method(), Request::path());
