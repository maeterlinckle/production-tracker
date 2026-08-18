<?php

declare(strict_types=1);

use App\Core\Request;

require dirname(__DIR__) . '/src/bootstrap.php';

$router = require dirname(__DIR__) . '/routes/web.php';
$router->dispatch(Request::method(), Request::path());
