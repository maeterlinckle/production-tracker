<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Gates a route to Junction staff only. Implies auth (run AuthMiddleware first via MiddlewareRunner order). */
final class StaffMiddleware
{
    public static function handle(): void
    {
        AuthMiddleware::handle();

        if (Auth::isStaff()) {
            return;
        }

        if (Request::isAjax() || Request::isJson()) {
            Response::json(['error' => 'Staff access only.'], 403);
        }

        View::renderError(403, 'Staff access only', 'This page is only available to Junction staff.');
        exit;
    }
}
