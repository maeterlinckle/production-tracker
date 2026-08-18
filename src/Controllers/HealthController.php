<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use Throwable;

/**
 * A liveness check for the installer, manage.sh and any monitoring in front of
 * the site.
 *
 * Deliberately unauthenticated, and just as deliberately uninformative: it
 * answers whether the application can serve a request and reach its database,
 * and nothing else. Version numbers, hostnames, table counts and error detail
 * are all things an unauthenticated endpoint has no business handing out —
 * `console.php doctor` is where the detail lives, and that needs a shell.
 *
 * A failure answers 503 rather than 500 so a proxy reads it as "not ready"
 * rather than "this request broke".
 */
final class HealthController
{
    public function index(): void
    {
        Response::noCache();

        try {
            Database::connection()->query('SELECT 1');
        } catch (Throwable) {
            Response::json(['status' => 'error', 'database' => false], 503);
        }

        Response::json([
            'status' => 'ok',
            'application' => (string) Config::get('app.product', 'Production Tracker'),
            'database' => true,
        ]);
    }
}
