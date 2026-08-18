<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

require APP_ROOT . '/vendor/autoload.php';

Env::load(APP_ROOT . '/.env');
Config::load(APP_ROOT . '/config/config.php');

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

$logPath = rtrim((string) Config::get('storage.logs'), '/\\');
if (!is_dir($logPath)) {
    mkdir($logPath, 0775, true);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return true;
    }

    if ((bool) Config::get('app.debug', false)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    error_log("[warning] {$message} in {$file}:{$line}");

    return true;
});

set_exception_handler(static function (Throwable $e): void {
    $logPath = rtrim((string) Config::get('storage.logs'), '/\\') . '/app.log';
    $entry = sprintf(
        "[%s] %s: %s in %s:%d\n%s\n",
        date('c'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents($logPath, $entry, FILE_APPEND);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $entry);
        exit(1);
    }

    if ((bool) Config::get('app.debug', false)) {
        http_response_code(500);
        echo '<pre style="white-space:pre-wrap;padding:1rem;font:13px/1.5 monospace;">' . htmlspecialchars($entry) . '</pre>';

        return;
    }

    View::renderError(500, 'Something went wrong', 'An unexpected error occurred. It has been logged and Junction will look into it.');
});

if (PHP_SAPI !== 'cli') {
    Response::securityHeaders();
    Session::start();
    View::share('appName', Config::get('app.name'));
    View::share('appProduct', Config::get('app.product'));
    View::share('appVendor', Config::get('app.vendor'));
}
